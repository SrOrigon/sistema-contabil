<?php

declare(strict_types=1);

namespace App\Fiscal\Parsers;

use App\Core\InvalidXmlException;
use App\Core\Money;
use App\Core\Xml;

final class NfeXmlStreamParser
{
    /** @return list<array<string, mixed>> */
    public function parseArquivo(string $caminho): array
    {
        if (!is_file($caminho)) {
            throw new InvalidXmlException('Arquivo não encontrado.');
        }
        $leitor = new \XMLReader();
        if (!$leitor->open($caminho, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new InvalidXmlException('XML inválido ou ilegível.');
        }
        $documento = $this->vazio();
        $encontrou = false;
        while ($leitor->read()) {
            if ($leitor->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            $nome = $leitor->localName;
            if ($nome === 'infNFe' || $nome === 'infCte') {
                $id = (string) $leitor->getAttribute('Id');
                $documento['chave'] = preg_replace('/\D/', '', $id) ?? '';
                $documento['especie'] = $nome === 'infCte' ? 'cte' : 'nfe';
                $encontrou = true;
            }
            if (in_array($nome, ['ide', 'emit', 'dest', 'total', 'det', 'vPrest', 'infCarga', 'protNFe'], true)) {
                $this->consumir($nome, $leitor->readOuterXml(), $documento);
            }
        }
        $leitor->close();
        if (!$encontrou) {
            return [$this->parseNfse($caminho)];
        }
        if ($documento['numero'] === '' && $documento['itens'] === []) {
            throw new InvalidXmlException('O XML não contém NF-e, NFC-e, CT-e nem NFS-e reconhecidos.');
        }
        if ($documento['especie'] === 'cte' && $documento['itens'] === []) {
            $documento['itens'][] = $this->item('1', 'FRETE', 'Prestação de serviço de transporte', '', '', '', '', '0', '0', $documento['valor_nota_centavos']);
        }
        $documento['modelo'] = $documento['modelo'] !== '' ? $documento['modelo'] : ($documento['especie'] === 'cte' ? '57' : '55');
        $ibs = 0;
        $cbs = 0;
        foreach ($documento['itens'] as $item) {
            $ibs += (int) $item['valor_ibs_centavos'];
            $cbs += (int) $item['valor_cbs_centavos'];
        }
        $documento['valor_ibs_centavos'] = $ibs;
        $documento['valor_cbs_centavos'] = $cbs;
        if ($documento['valor_nota_centavos'] === 0) {
            foreach ($documento['itens'] as $item) {
                $documento['valor_nota_centavos'] += (int) $item['valor_centavos'];
            }
            $documento['valor_produtos_centavos'] = $documento['valor_nota_centavos'];
        }
        return [$documento];
    }

    /** @return array<string, mixed> */
    private function vazio(): array
    {
        return [
            'especie' => 'nfe',
            'modelo' => '',
            'chave' => '',
            'serie' => '',
            'numero' => '',
            'emissao' => '',
            'tipo_nf' => '1',
            'emitente_doc' => '',
            'emitente_nome' => '',
            'destinatario_doc' => '',
            'destinatario_nome' => '',
            'valor_produtos_centavos' => 0,
            'valor_nota_centavos' => 0,
            'valor_ibs_centavos' => 0,
            'valor_cbs_centavos' => 0,
            'itens' => [],
        ];
    }

    /** @param array<string, mixed> $documento */
    private function consumir(string $nome, string $xml, array &$documento): void
    {
        if (trim($xml) === '') {
            return;
        }
        $dom = Xml::documento($xml);
        if ($nome === 'ide') {
            $documento['modelo'] = Xml::texto($dom, 'mod');
            $documento['serie'] = Xml::texto($dom, 'serie');
            $documento['numero'] = Xml::texto($dom, 'nNF') ?: Xml::texto($dom, 'nCT');
            $emissao = Xml::texto($dom, 'dhEmi') ?: Xml::texto($dom, 'dEmi');
            $documento['emissao'] = substr($emissao, 0, 10);
            $tipo = Xml::texto($dom, 'tpNF');
            if ($tipo !== '') {
                $documento['tipo_nf'] = $tipo;
            }
        } elseif ($nome === 'emit') {
            $documento['emitente_doc'] = Xml::texto($dom, 'CNPJ') ?: Xml::texto($dom, 'CPF');
            $documento['emitente_nome'] = Xml::texto($dom, 'xNome');
        } elseif ($nome === 'dest') {
            $documento['destinatario_doc'] = Xml::texto($dom, 'CNPJ') ?: Xml::texto($dom, 'CPF');
            $documento['destinatario_nome'] = Xml::texto($dom, 'xNome');
        } elseif ($nome === 'total') {
            $documento['valor_produtos_centavos'] = $this->centavos(Xml::texto($dom, 'vProd'));
            $documento['valor_nota_centavos'] = $this->centavos(Xml::texto($dom, 'vNF'));
        } elseif ($nome === 'vPrest') {
            $documento['valor_nota_centavos'] = $this->centavos(Xml::texto($dom, 'vTPrest'));
            $documento['valor_produtos_centavos'] = $documento['valor_nota_centavos'];
        } elseif ($nome === 'protNFe' && $documento['chave'] === '') {
            $documento['chave'] = preg_replace('/\D/', '', Xml::texto($dom, 'chNFe')) ?? '';
        } elseif ($nome === 'det') {
            $documento['itens'][] = $this->itemDoDet($dom);
        }
    }

    /** @return array<string, mixed> */
    private function itemDoDet(\DOMDocument $dom): array
    {
        $raiz = $dom->documentElement;
        $numero = $raiz?->getAttribute('nItem') ?: '1';
        $ibs = Xml::elemento($dom, 'IBSCBS') ?? Xml::elemento($dom, 'gIBSCBS');
        $cst = '';
        $cclass = '';
        $base = '';
        $pIbs = '0';
        $pCbs = '0';
        $vIbs = 0;
        $vCbs = 0;
        $redIbs = '';
        $redCbs = '';
        if ($ibs !== null) {
            $cst = Xml::texto($ibs, 'CST');
            $cclass = Xml::texto($ibs, 'cClassTrib');
            $base = Xml::texto($ibs, 'vBC');
            $pIbsDireto = Xml::texto($ibs, 'pIBS');
            $pIbs = $pIbsDireto !== ''
                ? $pIbsDireto
                : bcadd(Money::rate(Xml::texto($ibs, 'pIBSUF') ?: '0'), Money::rate(Xml::texto($ibs, 'pIBSMun') ?: '0'), 4);
            $pCbs = Xml::texto($ibs, 'pCBS');
            $vIbs = $this->somarTag($ibs, ['vIBS']) ?: ($this->centavos(Xml::texto($ibs, 'vIBSUF')) + $this->centavos(Xml::texto($ibs, 'vIBSMun')));
            $vCbs = $this->centavos(Xml::texto($ibs, 'vCBS'));
            $redIbs = Xml::texto($ibs, 'pRedIBS') ?: Xml::texto($ibs, 'pRedAliq');
            $redCbs = Xml::texto($ibs, 'pRedCBS') ?: Xml::texto($ibs, 'pRedAliq');
        }
        return $this->item(
            $numero,
            Xml::texto($dom, 'cProd'),
            Xml::texto($dom, 'xProd') ?: 'Item sem descrição',
            Xml::texto($dom, 'NCM'),
            Xml::texto($dom, 'CFOP'),
            $cst,
            $cclass,
            $redIbs,
            $redCbs,
            $this->centavos(Xml::texto($dom, 'vProd')),
            $this->centavos($base),
            $pIbs,
            $pCbs,
            $vIbs,
            $vCbs,
            Xml::texto($dom, 'qCom'),
        );
    }

    /** @return array<string, mixed> */
    private function parseNfse(string $caminho): array
    {
        $xml = file_get_contents($caminho);
        if ($xml === false || !str_contains($xml, '<')) {
            throw new InvalidXmlException('O arquivo não é um XML fiscal reconhecido.');
        }
        $dom = Xml::documento($xml);
        $valor = Xml::texto($dom, 'vServ') ?: Xml::texto($dom, 'vLiq') ?: Xml::texto($dom, 'ValorServicos');
        if ($valor === '') {
            throw new InvalidXmlException('O XML não contém NF-e, NFC-e, CT-e nem NFS-e reconhecidos.');
        }
        $documento = $this->vazio();
        $documento['especie'] = 'nfse';
        $documento['modelo'] = 'nfse';
        $documento['numero'] = Xml::texto($dom, 'nNFSe') ?: Xml::texto($dom, 'nDPS') ?: Xml::texto($dom, 'Numero');
        $emissao = Xml::texto($dom, 'dhEmi') ?: Xml::texto($dom, 'dhProc') ?: Xml::texto($dom, 'DataEmissao');
        $documento['emissao'] = substr($emissao, 0, 10);
        $documento['emitente_doc'] = Xml::texto($dom, 'CNPJ');
        $documento['emitente_nome'] = Xml::texto($dom, 'xNome') ?: Xml::texto($dom, 'RazaoSocial');
        $documento['valor_nota_centavos'] = $this->centavos($valor);
        $documento['valor_produtos_centavos'] = $documento['valor_nota_centavos'];
        $descricao = Xml::texto($dom, 'xDescServ') ?: Xml::texto($dom, 'Discriminacao') ?: 'Serviço';
        $documento['itens'][] = $this->item('1', Xml::texto($dom, 'cTribNac'), $descricao, '', '', '', '', '', '', $documento['valor_nota_centavos']);
        return $documento;
    }

    /** @param list<string> $tags */
    private function somarTag(\DOMNode $no, array $tags): int
    {
        $total = 0;
        foreach ($tags as $tag) {
            if ($tag === 'vIBS') {
                $elemento = Xml::elemento($no, 'gIBSCBS') ?? ($no instanceof \DOMElement ? $no : null);
                if ($elemento !== null) {
                    foreach ($elemento->childNodes as $filho) {
                        if ($filho instanceof \DOMElement && $filho->localName === 'vIBS') {
                            $total += $this->centavos(trim($filho->textContent));
                        }
                    }
                }
                continue;
            }
            $total += $this->centavos(Xml::texto($no, $tag));
        }
        return $total;
    }

    private function centavos(string $valor): int
    {
        $valor = trim(str_replace(',', '.', $valor));
        if ($valor === '' || !preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            return 0;
        }
        return Money::fromDecimal($valor)->cents();
    }

    /** @return array<string, mixed> */
    private function item(
        string $numero,
        string $codigo,
        string $descricao,
        string $ncm,
        string $cfop,
        string $cst,
        string $cclass,
        string $redIbs,
        string $redCbs,
        int $valor,
        int $base = 0,
        string $pIbs = '',
        string $pCbs = '',
        int $vIbs = 0,
        int $vCbs = 0,
        string $quantidade = '1',
    ): array {
        return [
            'n_item' => (int) $numero,
            'codigo' => $codigo,
            'descricao' => $descricao,
            'ncm' => preg_replace('/\D/', '', $ncm) ?? '',
            'cfop' => $cfop,
            'cst' => $cst,
            'cclass_trib' => $cclass,
            'reducao_ibs' => $redIbs,
            'reducao_cbs' => $redCbs,
            'quantidade' => $quantidade !== '' ? $quantidade : '1',
            'valor_centavos' => $valor,
            'base_centavos' => $base > 0 ? $base : $valor,
            'aliquota_ibs' => $pIbs,
            'aliquota_cbs' => $pCbs,
            'valor_ibs_centavos' => $vIbs,
            'valor_cbs_centavos' => $vCbs,
        ];
    }
}
