<?php

declare(strict_types=1);

namespace App\Fiscal\Parsers;

use App\Core\InvalidXmlException;
use App\Core\Money;

final class SpedFiscalParser
{
    /** @return list<array<string, mixed>> */
    public function parseArquivo(string $caminho): array
    {
        $bruto = file_get_contents($caminho);
        if ($bruto === false || $bruto === '') {
            throw new InvalidXmlException('Arquivo SPED vazio.');
        }
        if (!mb_check_encoding($bruto, 'UTF-8')) {
            $bruto = mb_convert_encoding($bruto, 'UTF-8', 'ISO-8859-1');
        }
        $ncmPorCodigo = [];
        $descricaoPorCodigo = [];
        $pessoas = [];
        $documentos = [];
        $atual = null;
        foreach (preg_split("/\r\n|\n|\r/", $bruto) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || !str_starts_with($linha, '|')) {
                continue;
            }
            $partes = explode('|', $linha);
            $registro = $partes[1] ?? '';
            if ($registro === '0150') {
                $pessoas[$partes[2] ?? ''] = [
                    'nome' => $partes[3] ?? '',
                    'doc' => ($partes[5] ?? '') !== '' ? $partes[5] : ($partes[6] ?? ''),
                ];
            } elseif ($registro === '0200') {
                $ncmPorCodigo[$partes[2] ?? ''] = preg_replace('/\D/', '', $partes[8] ?? '') ?? '';
                $descricaoPorCodigo[$partes[2] ?? ''] = $partes[3] ?? '';
            } elseif ($registro === 'C100') {
                if ($atual !== null) {
                    $documentos[] = $this->fechar($atual);
                }
                $pessoa = $pessoas[$partes[4] ?? ''] ?? ['nome' => '', 'doc' => ''];
                $entrada = ($partes[2] ?? '1') === '0';
                $atual = [
                    'especie' => 'sped',
                    'modelo' => $partes[5] ?? '55',
                    'chave' => preg_replace('/\D/', '', $partes[9] ?? '') ?? '',
                    'serie' => $partes[7] ?? '',
                    'numero' => $partes[8] ?? '',
                    'emissao' => $this->data($partes[10] ?? ''),
                    'tipo_nf' => $entrada ? '0' : '1',
                    'emitente_doc' => $entrada ? $pessoa['doc'] : '',
                    'emitente_nome' => $entrada ? $pessoa['nome'] : '',
                    'destinatario_doc' => $entrada ? '' : $pessoa['doc'],
                    'destinatario_nome' => $entrada ? '' : $pessoa['nome'],
                    'valor_produtos_centavos' => $this->centavos($partes[16] ?? '0'),
                    'valor_nota_centavos' => $this->centavos($partes[12] ?? '0'),
                    'valor_ibs_centavos' => 0,
                    'valor_cbs_centavos' => 0,
                    'itens' => [],
                ];
            } elseif ($registro === 'C170' && $atual !== null) {
                $codigo = $partes[3] ?? '';
                $atual['itens'][] = [
                    'n_item' => (int) ($partes[2] ?? 1),
                    'codigo' => $codigo,
                    'descricao' => ($partes[4] ?? '') !== '' ? $partes[4] : ($descricaoPorCodigo[$codigo] ?? 'Item do SPED'),
                    'ncm' => $ncmPorCodigo[$codigo] ?? '',
                    'cfop' => $partes[11] ?? '',
                    'cst' => '',
                    'cclass_trib' => '',
                    'reducao_ibs' => '',
                    'reducao_cbs' => '',
                    'quantidade' => str_replace(',', '.', $partes[5] ?? '1'),
                    'valor_centavos' => $this->centavos($partes[7] ?? '0'),
                    'base_centavos' => $this->centavos($partes[7] ?? '0'),
                    'aliquota_ibs' => '',
                    'aliquota_cbs' => '',
                    'valor_ibs_centavos' => 0,
                    'valor_cbs_centavos' => 0,
                ];
            }
        }
        if ($atual !== null) {
            $documentos[] = $this->fechar($atual);
        }
        if ($documentos === []) {
            throw new InvalidXmlException('O SPED não contém registros C100.');
        }
        return $documentos;
    }

    /** @param array<string, mixed> $documento @return array<string, mixed> */
    private function fechar(array $documento): array
    {
        if ($documento['itens'] === []) {
            $documento['itens'][] = [
                'n_item' => 1,
                'codigo' => '',
                'descricao' => 'Documento fiscal sem itens C170',
                'ncm' => '',
                'cfop' => '',
                'cst' => '',
                'cclass_trib' => '',
                'reducao_ibs' => '',
                'reducao_cbs' => '',
                'quantidade' => '1',
                'valor_centavos' => $documento['valor_nota_centavos'],
                'base_centavos' => $documento['valor_nota_centavos'],
                'aliquota_ibs' => '',
                'aliquota_cbs' => '',
                'valor_ibs_centavos' => 0,
                'valor_cbs_centavos' => 0,
            ];
        }
        return $documento;
    }

    private function data(string $valor): string
    {
        $valor = preg_replace('/\D/', '', $valor) ?? '';
        if (strlen($valor) !== 8) {
            return '';
        }
        return substr($valor, 4, 4) . '-' . substr($valor, 2, 2) . '-' . substr($valor, 0, 2);
    }

    private function centavos(string $valor): int
    {
        $valor = trim(str_replace(',', '.', $valor));
        if ($valor === '' || !preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            return 0;
        }
        return Money::fromDecimal($valor)->cents();
    }
}
