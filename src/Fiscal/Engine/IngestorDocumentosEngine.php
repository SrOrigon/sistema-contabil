<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Database;
use App\Core\Format;
use App\Core\Money;

/**
 * Módulo de Ingestão e Validação Fiscal Massiva.
 * Processa com tolerância a falhas qualquer arquivo enviado (NF-e, NFS-e, CT-e, OFX, SPED Fiscal),
 * valida integridade, detecta duplicidade via checksum SHA-256 e chave de acesso,
 * e isola metadados tributários para alimentar os agentes de compliance e contabilidade.
 */
final class IngestorDocumentosEngine
{
    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Identifica o formato do documento inspecionando a assinatura de seu conteúdo.
     */
    public function identificarTipo(string $conteudo): string
    {
        $inicio = substr($conteudo, 0, 4096);

        // 1. XMLs Fiscais
        if (str_contains($inicio, '<nfeProc') || str_contains($inicio, '<NFe') || str_contains($inicio, '<infNFe')) {
            return 'NFE_XML';
        }
        if (str_contains($inicio, '<cteProc') || str_contains($inicio, '<CTe') || str_contains($inicio, '<infCte')) {
            return 'CTE_XML';
        }
        if (str_contains($inicio, '<CompNfse') || str_contains($inicio, '<Nfse') || str_contains($inicio, '<Rps') || str_contains($inicio, '<ConsultarNfseResposta')) {
            return 'NFSE_XML';
        }

        // 2. Extratos Bancários
        if (str_contains($conteudo, 'OFXHEADER') || str_contains($conteudo, '<STMTTRN>') || str_contains($conteudo, '<OFX>')) {
            return 'EXTRATO_OFX';
        }

        // 3. SPED Fiscal / Contribuições
        if (str_contains($inicio, '|0000|') && str_contains($conteudo, '|9999|')) {
            return 'SPED_FISCAL';
        }

        // 4. Extrato CSV
        if (preg_match('/(?:data|dt|valor|historico|descri[cç][aã]o).*[;,].*(?:valor|saldo)/i', $inicio)) {
            return 'EXTRATO_CSV';
        }

        return 'DESCONHECIDO';
    }

    /**
     * Processa e valida qualquer arquivo fiscal ou bancário a partir do caminho do arquivo.
     * @param string $caminhoArquivo Caminho absoluto no disco
     * @param string|null $cnpjEsperado CNPJ da empresa ativa para validação de direção e divergência
     * @return array<string, mixed>
     */
    public function processar(string $caminhoArquivo, ?string $cnpjEsperado = null): array
    {
        if (!is_file($caminhoArquivo)) {
            return [
                'status' => 'erro',
                'motivo' => 'Arquivo não encontrado no caminho especificado: ' . basename($caminhoArquivo),
                'arquivo' => basename($caminhoArquivo),
            ];
        }

        $conteudo = file_get_contents($caminhoArquivo);
        if ($conteudo === false || trim($conteudo) === '') {
            return [
                'status' => 'corrompido',
                'motivo' => 'O arquivo está vazio ou ilegível no disco.',
                'arquivo' => basename($caminhoArquivo),
            ];
        }

        return $this->processarConteudo($conteudo, basename($caminhoArquivo), $cnpjEsperado);
    }

    /**
     * Processa e valida o conteúdo bruto de um arquivo.
     * @return array<string, mixed>
     */
    public function processarConteudo(string $conteudo, string $nomeOriginal = 'arquivo', ?string $cnpjEsperado = null): array
    {
        $checksum = hash('sha256', $conteudo);
        $tipo = $this->identificarTipo($conteudo);
        $cnpjLimpo = $cnpjEsperado !== null ? preg_replace('/\D/', '', $cnpjEsperado) : null;

        // Verificação prévia de duplicidade pelo checksum no banco de dados
        if ($this->db !== null) {
            $existente = $this->db->one(
                'SELECT id, numero, emissao, modelo FROM documentos_fiscais WHERE arquivo_nome = :chk LIMIT 1',
                [':chk' => $checksum]
            );
            if ($existente !== null) {
                return [
                    'status' => 'duplicado',
                    'tipo' => $tipo,
                    'arquivo' => $nomeOriginal,
                    'checksum' => $checksum,
                    'documento_existente_id' => $existente['id'],
                    'motivo' => "O arquivo {$nomeOriginal} já foi importado anteriormente (Documento nº {$existente['numero']}).",
                ];
            }
        }

        try {
            return match ($tipo) {
                'NFE_XML' => $this->parseXmlNfe($conteudo, $nomeOriginal, $checksum, $cnpjLimpo),
                'CTE_XML' => $this->parseXmlCte($conteudo, $nomeOriginal, $checksum, $cnpjLimpo),
                'NFSE_XML' => $this->parseXmlNfse($conteudo, $nomeOriginal, $checksum, $cnpjLimpo),
                'EXTRATO_OFX' => $this->parseOfx($conteudo, $nomeOriginal, $checksum),
                'SPED_FISCAL' => $this->parseSped($conteudo, $nomeOriginal, $checksum, $cnpjLimpo),
                default => [
                    'status' => 'formato_invalido',
                    'tipo' => 'DESCONHECIDO',
                    'arquivo' => $nomeOriginal,
                    'checksum' => $checksum,
                    'motivo' => 'Formato de documento não suportado ou sem assinatura fiscal reconhecida.',
                ],
            };
        } catch (\Throwable $e) {
            return [
                'status' => 'corrompido',
                'tipo' => $tipo,
                'arquivo' => $nomeOriginal,
                'checksum' => $checksum,
                'motivo' => 'Falha ao processar estrutura interna: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Processa um lote massivo de arquivos e retorna sumário consolidado para o dashboard.
     * @param list<string> $caminhosArquivos
     * @param string|null $cnpjEsperado
     * @return array<string, mixed>
     */
    public function processarLote(array $caminhosArquivos, ?string $cnpjEsperado = null): array
    {
        $sucesso = 0;
        $duplicados = 0;
        $falhas = 0;
        $documentos = [];

        foreach ($caminhosArquivos as $caminho) {
            $resultado = $this->processar($caminho, $cnpjEsperado);
            $documentos[] = $resultado;

            match ($resultado['status'] ?? 'erro') {
                'sucesso' => $sucesso++,
                'duplicado' => $duplicados++,
                default => $falhas++,
            };
        }

        $total = count($caminhosArquivos);
        $resumoLeigo = "Processamento em lote finalizado: {$sucesso} documentos importados com sucesso de um total de {$total}.";
        if ($duplicados > 0) {
            $resumoLeigo .= " ({$duplicados} arquivos duplicados foram ignorados para evitar erro contábil).";
        }
        if ($falhas > 0) {
            $resumoLeigo .= " ({$falhas} arquivos apresentaram erros ou formato incompatível).";
        }

        return [
            'total_arquivos' => $total,
            'sucesso' => $sucesso,
            'duplicados' => $duplicados,
            'falhas' => $falhas,
            'resumo_leigo' => $resumoLeigo,
            'detalhes' => $documentos,
        ];
    }

    /**
     * Parser resiliente para NF-e / NFC-e (modelo 55 e 65).
     */
    private function parseXmlNfe(string $xmlContent, string $nomeOriginal, string $checksum, ?string $cnpjEsperado): array
    {
        $dom = new \DOMDocument();
        $libxmlState = libxml_use_internal_errors(true);
        $carregou = $dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($libxmlState);

        if (!$carregou) {
            return [
                'status' => 'corrompido',
                'tipo' => 'NFE_XML',
                'arquivo' => $nomeOriginal,
                'checksum' => $checksum,
                'motivo' => 'XML malformado ou corrompido.',
            ];
        }

        // Chave de acesso de 44 dígitos
        $chave = '';
        $infNFe = $dom->getElementsByTagName('infNFe')->item(0);
        if ($infNFe !== null) {
            $chave = preg_replace('/\D/', '', (string) $infNFe->getAttribute('Id'));
        }
        if ($chave === '') {
            $chNFeTag = $dom->getElementsByTagName('chNFe')->item(0);
            if ($chNFeTag !== null) {
                $chave = preg_replace('/\D/', '', (string) $chNFeTag->nodeValue);
            }
        }

        // Checagem de duplicidade por chave no banco
        if ($chave !== '' && $this->db !== null) {
            $existenteChave = $this->db->one('SELECT id, numero FROM documentos_fiscais WHERE chave = :chave LIMIT 1', [':chave' => $chave]);
            if ($existenteChave !== null) {
                return [
                    'status' => 'duplicado',
                    'tipo' => 'NFE_XML',
                    'chave' => $chave,
                    'arquivo' => $nomeOriginal,
                    'checksum' => $checksum,
                    'motivo' => "Nota Fiscal chave {$chave} (nº {$existenteChave['numero']}) já consta no sistema.",
                ];
            }
        }

        $numero = $dom->getElementsByTagName('nNF')->item(0)?->nodeValue ?? '';
        $serie = $dom->getElementsByTagName('serie')->item(0)?->nodeValue ?? '1';
        $modelo = $dom->getElementsByTagName('mod')->item(0)?->nodeValue ?? '55';
        $emissao = substr((string) ($dom->getElementsByTagName('dhEmi')->item(0)?->nodeValue ?? $dom->getElementsByTagName('dEmi')->item(0)?->nodeValue ?? date('Y-m-d')), 0, 10);

        // Emitente
        $emit = $dom->getElementsByTagName('emit')->item(0);
        $cnpjEmitente = $emit?->getElementsByTagName('CNPJ')->item(0)?->nodeValue ?? $emit?->getElementsByTagName('CPF')->item(0)?->nodeValue ?? '';
        $cnpjEmitenteLimpo = preg_replace('/\D/', '', (string) $cnpjEmitente);
        $nomeEmitente = $emit?->getElementsByTagName('xNome')->item(0)?->nodeValue ?? 'Emitente';

        // Destinatário
        $dest = $dom->getElementsByTagName('dest')->item(0);
        $cnpjDest = $dest?->getElementsByTagName('CNPJ')->item(0)?->nodeValue ?? $dest?->getElementsByTagName('CPF')->item(0)?->nodeValue ?? '';
        $cnpjDestLimpo = preg_replace('/\D/', '', (string) $cnpjDest);
        $nomeDest = $dest?->getElementsByTagName('xNome')->item(0)?->nodeValue ?? 'Destinatário';

        // Valores e Impostos
        $vNF = $dom->getElementsByTagName('vNF')->item(0)?->nodeValue ?? '0.00';
        $vProd = $dom->getElementsByTagName('vProd')->item(0)?->nodeValue ?? '0.00';
        $vICMS = $dom->getElementsByTagName('vICMS')->item(0)?->nodeValue ?? '0.00';
        $vPIS = $dom->getElementsByTagName('vPIS')->item(0)?->nodeValue ?? '0.00';
        $vCOFINS = $dom->getElementsByTagName('vCOFINS')->item(0)?->nodeValue ?? '0.00';
        $vIBS = $dom->getElementsByTagName('vIBS')->item(0)?->nodeValue ?? '0.00';
        $vCBS = $dom->getElementsByTagName('vCBS')->item(0)?->nodeValue ?? '0.00';

        $totalCentavos = $this->toCentavos((string) $vNF);
        $prodCentavos = $this->toCentavos((string) $vProd);
        $icmsCentavos = $this->toCentavos((string) $vICMS);
        $pisCentavos = $this->toCentavos((string) $vPIS);
        $cofinsCentavos = $this->toCentavos((string) $vCOFINS);
        $ibsCentavos = $this->toCentavos((string) $vIBS);
        $cbsCentavos = $this->toCentavos((string) $vCBS);

        // Direção da Operação
        $direcao = 'indeterminada';
        $cnpjDivergente = false;
        if ($cnpjEsperado !== null && $cnpjEsperado !== '') {
            if ($cnpjDestLimpo === $cnpjEsperado) {
                $direcao = 'entrada';
            } elseif ($cnpjEmitenteLimpo === $cnpjEsperado) {
                $direcao = 'saida';
            } else {
                $cnpjDivergente = true;
            }
        }

        $itensCount = $dom->getElementsByTagName('det')->length;

        return [
            'status' => 'sucesso',
            'tipo' => 'NFE_XML',
            'modelo' => $modelo,
            'chave' => $chave,
            'numero' => $numero,
            'serie' => $serie,
            'emissao' => $emissao,
            'arquivo' => $nomeOriginal,
            'checksum' => $checksum,
            'direcao' => $direcao,
            'cnpj_divergente' => $cnpjDivergente,
            'emitente' => ['doc' => $cnpjEmitenteLimpo, 'nome' => $nomeEmitente],
            'destinatario' => ['doc' => $cnpjDestLimpo, 'nome' => $nomeDest],
            'valores' => [
                'total_centavos' => $totalCentavos,
                'produtos_centavos' => $prodCentavos,
                'icms_centavos' => $icmsCentavos,
                'pis_centavos' => $pisCentavos,
                'cofins_centavos' => $cofinsCentavos,
                'ibs_centavos' => $ibsCentavos,
                'cbs_centavos' => $cbsCentavos,
            ],
            'quantidade_itens' => $itensCount,
        ];
    }

    /**
     * Parser resiliente para CT-e (Conhecimento de Transporte Eletrônico - modelo 57).
     */
    private function parseXmlCte(string $xmlContent, string $nomeOriginal, string $checksum, ?string $cnpjEsperado): array
    {
        $dom = new \DOMDocument();
        $libxmlState = libxml_use_internal_errors(true);
        $carregou = $dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($libxmlState);

        if (!$carregou) {
            return ['status' => 'corrompido', 'tipo' => 'CTE_XML', 'arquivo' => $nomeOriginal, 'checksum' => $checksum, 'motivo' => 'CT-e XML corrompido.'];
        }

        $chave = '';
        $infCte = $dom->getElementsByTagName('infCte')->item(0);
        if ($infCte !== null) {
            $chave = preg_replace('/\D/', '', (string) $infCte->getAttribute('Id'));
        }

        $numero = $dom->getElementsByTagName('nCT')->item(0)?->nodeValue ?? '';
        $serie = $dom->getElementsByTagName('serie')->item(0)?->nodeValue ?? '1';
        $emissao = substr((string) ($dom->getElementsByTagName('dhEmi')->item(0)?->nodeValue ?? date('Y-m-d')), 0, 10);

        $emit = $dom->getElementsByTagName('emit')->item(0);
        $cnpjEmit = preg_replace('/\D/', '', (string) ($emit?->getElementsByTagName('CNPJ')->item(0)?->nodeValue ?? ''));
        $nomeEmit = $emit?->getElementsByTagName('xNome')->item(0)?->nodeValue ?? 'Transportadora';

        $dest = $dom->getElementsByTagName('dest')->item(0);
        $cnpjDest = preg_replace('/\D/', '', (string) ($dest?->getElementsByTagName('CNPJ')->item(0)?->nodeValue ?? ''));
        $nomeDest = $dest?->getElementsByTagName('xNome')->item(0)?->nodeValue ?? 'Destinatário Frete';

        $vTPrest = $dom->getElementsByTagName('vTPrest')->item(0)?->nodeValue ?? '0.00';
        $totalCentavos = $this->toCentavos((string) $vTPrest);

        return [
            'status' => 'sucesso',
            'tipo' => 'CTE_XML',
            'modelo' => '57',
            'chave' => $chave,
            'numero' => $numero,
            'serie' => $serie,
            'emissao' => $emissao,
            'arquivo' => $nomeOriginal,
            'checksum' => $checksum,
            'emitente' => ['doc' => $cnpjEmit, 'nome' => $nomeEmit],
            'destinatario' => ['doc' => $cnpjDest, 'nome' => $nomeDest],
            'valores' => [
                'total_centavos' => $totalCentavos,
                'frete_centavos' => $totalCentavos,
            ],
            'quantidade_itens' => 1,
        ];
    }

    /**
     * Parser resiliente para NFS-e (Nota Fiscal de Serviços Eletrônica).
     */
    private function parseXmlNfse(string $xmlContent, string $nomeOriginal, string $checksum, ?string $cnpjEsperado): array
    {
        $dom = new \DOMDocument();
        $libxmlState = libxml_use_internal_errors(true);
        $carregou = $dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($libxmlState);

        if (!$carregou) {
            return ['status' => 'corrompido', 'tipo' => 'NFSE_XML', 'arquivo' => $nomeOriginal, 'checksum' => $checksum, 'motivo' => 'NFS-e XML corrompido.'];
        }

        $numero = $dom->getElementsByTagName('Numero')->item(0)?->nodeValue ?? 's/n';
        $emissao = substr((string) ($dom->getElementsByTagName('DataEmissao')->item(0)?->nodeValue ?? date('Y-m-d')), 0, 10);
        $vServ = $dom->getElementsByTagName('ValorServicos')->item(0)?->nodeValue ?? '0.00';
        $vIss = $dom->getElementsByTagName('ValorIss')->item(0)?->nodeValue ?? '0.00';

        $totalCentavos = $this->toCentavos((string) $vServ);
        $issCentavos = $this->toCentavos((string) $vIss);

        return [
            'status' => 'sucesso',
            'tipo' => 'NFSE_XML',
            'modelo' => 'NFS-e',
            'chave' => '',
            'numero' => $numero,
            'serie' => '1',
            'emissao' => $emissao,
            'arquivo' => $nomeOriginal,
            'checksum' => $checksum,
            'valores' => [
                'total_centavos' => $totalCentavos,
                'servicos_centavos' => $totalCentavos,
                'iss_centavos' => $issCentavos,
            ],
            'quantidade_itens' => 1,
        ];
    }

    /**
     * Parser para extratos bancários OFX.
     */
    private function parseOfx(string $ofxContent, string $nomeOriginal, string $checksum): array
    {
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $ofxContent, $matches);
        $blocos = $matches[1] ?? [];

        $transacoes = [];
        $totalEntradas = 0;
        $totalSaidas = 0;

        foreach ($blocos as $bloco) {
            preg_match('/<TRNAMT>([^<\r\n]+)/i', $bloco, $mValor);
            preg_match('/<DTPOSTED>([^<\r\n]+)/i', $bloco, $mData);
            preg_match('/<MEMO>([^<\r\n]+)/i', $bloco, $mMemo);
            preg_match('/<FITID>([^<\r\n]+)/i', $bloco, $mFitid);

            $valorStr = trim($mValor[1] ?? '');
            $dataStr = trim($mData[1] ?? '');

            if ($valorStr !== '' && $dataStr !== '') {
                $centavos = $this->toCentavos(str_replace(',', '.', $valorStr));
                if ($centavos >= 0) {
                    $totalEntradas += $centavos;
                } else {
                    $totalSaidas += abs($centavos);
                }

                $dataFmt = substr($dataStr, 0, 4) . '-' . substr($dataStr, 4, 2) . '-' . substr($dataStr, 6, 2);
                $transacoes[] = [
                    'data' => $dataFmt,
                    'valor_centavos' => $centavos,
                    'descricao' => trim($mMemo[1] ?? 'Lançamento OFX'),
                    'fitid' => trim($mFitid[1] ?? ''),
                ];
            }
        }

        return [
            'status' => 'sucesso',
            'tipo' => 'EXTRATO_OFX',
            'arquivo' => $nomeOriginal,
            'checksum' => $checksum,
            'quantidade_transacoes' => count($transacoes),
            'total_entradas_centavos' => $totalEntradas,
            'total_saidas_centavos' => $totalSaidas,
            'transacoes' => $transacoes,
        ];
    }

    /**
     * Parser para arquivos SPED Fiscal (EFD ICMS/IPI e EFD Contribuições).
     */
    private function parseSped(string $spedContent, string $nomeOriginal, string $checksum, ?string $cnpjEsperado): array
    {
        $linhas = preg_split("/\r\n|\n|\r/", $spedContent) ?: [];
        $cnpjSped = '';
        $dtIni = '';
        $dtFin = '';
        $totalNotasC100 = 0;
        $valorTotalNotas = 0;

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || !str_starts_with($linha, '|')) {
                continue;
            }
            $campos = explode('|', $linha);
            $reg = $campos[1] ?? '';

            if ($reg === '0000') {
                $cnpjSped = preg_replace('/\D/', '', (string) ($campos[7] ?? ''));
                $dtIni = (string) ($campos[4] ?? '');
                $dtFin = (string) ($campos[5] ?? '');
            } elseif ($reg === 'C100') {
                $totalNotasC100++;
                $vNF = str_replace(',', '.', (string) ($campos[12] ?? '0'));
                $valorTotalNotas += $this->toCentavos($vNF);
            }
        }

        $cnpjDivergente = ($cnpjEsperado !== null && $cnpjEsperado !== '' && $cnpjSped !== $cnpjEsperado);

        return [
            'status' => 'sucesso',
            'tipo' => 'SPED_FISCAL',
            'arquivo' => $nomeOriginal,
            'checksum' => $checksum,
            'cnpj_escriturado' => $cnpjSped,
            'cnpj_divergente' => $cnpjDivergente,
            'periodo' => ['inicio' => $dtIni, 'fim' => $dtFin],
            'quantidade_notas' => $totalNotasC100,
            'valor_total_documentos_centavos' => $valorTotalNotas,
        ];
    }

    /**
     * Converte valor string em decimal/pontuado para centavos inteiros com precisão.
     */
    private function toCentavos(string $valor): int
    {
        $valor = trim(str_replace(['R$', ' '], '', $valor));
        if ($valor === '') {
            return 0;
        }
        // Se usar vírgula como decimal
        if (str_contains($valor, ',') && !str_contains($valor, '.')) {
            $valor = str_replace(',', '.', $valor);
        } elseif (str_contains($valor, ',') && str_contains($valor, '.')) {
            // Formato 1.234,56
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        }

        return (int) round(((float) $valor) * 100);
    }
}
