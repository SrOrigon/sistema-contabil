<?php

declare(strict_types=1);

namespace App\Banking;

use App\Core\Format;
use App\Core\InvalidXmlException;
use App\Core\Money;

final class StatementImporter
{
    /** @return list<array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string}> */
    public function importar(string $caminho, string $nome): array
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false || trim($conteudo) === '') {
            throw new InvalidXmlException('Extrato vazio.');
        }
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if ($extensao === 'ofx' || str_contains(strtoupper($conteudo), '<STMTTRN>')) {
            return $this->ofx($conteudo);
        }
        return $this->csv($conteudo);
    }

    /** @return list<array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string}> */
    private function ofx(string $conteudo): array
    {
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $conteudo, $blocos);
        $transacoes = [];
        foreach ($blocos[1] as $bloco) {
            $valor = $this->tag($bloco, 'TRNAMT');
            $data = $this->data($this->tag($bloco, 'DTPOSTED'));
            if ($valor === '' || $data === '') {
                continue;
            }
            $descricao = trim($this->tag($bloco, 'MEMO') . ' ' . $this->tag($bloco, 'NAME'));
            $transacoes[] = [
                'data' => $data,
                'valor_centavos' => Money::fromDecimal(str_replace(',', '.', $valor))->cents(),
                'descricao' => $descricao !== '' ? $descricao : 'Lançamento OFX',
                'documento' => $this->tag($bloco, 'CHECKNUM'),
                'fitid' => $this->tag($bloco, 'FITID'),
            ];
        }
        if ($transacoes === []) {
            throw new InvalidXmlException('Nenhuma transação STMTTRN encontrada no OFX.');
        }
        return $transacoes;
    }

    /** @return list<array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string}> */
    private function csv(string $conteudo): array
    {
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }
        $linhas = preg_split("/\r\n|\n|\r/", trim($conteudo)) ?: [];
        $delimitador = substr_count($linhas[0], ';') >= substr_count($linhas[0], ',') ? ';' : ',';
        $cabecalho = str_getcsv(array_shift($linhas) ?: '', $delimitador);
        $mapa = $this->mapa($cabecalho);
        $transacoes = [];
        foreach ($linhas as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $colunas = str_getcsv($linha, $delimitador);
            $data = $this->data($colunas[$mapa['data']] ?? '');
            $bruto = $colunas[$mapa['valor']] ?? '';
            if ($data === '' || trim($bruto) === '') {
                continue;
            }
            $transacoes[] = [
                'data' => $data,
                'valor_centavos' => Format::parseMoney($bruto),
                'descricao' => trim($colunas[$mapa['descricao']] ?? 'Lançamento CSV'),
                'documento' => trim($colunas[$mapa['documento']] ?? ''),
                'fitid' => trim($colunas[$mapa['fitid']] ?? ''),
            ];
        }
        if ($transacoes === []) {
            throw new InvalidXmlException('O CSV não tem linhas com data e valor reconhecíveis.');
        }
        return $transacoes;
    }

    /** @param list<string|null> $cabecalho @return array{data:int,valor:int,descricao:int,documento:int,fitid:int} */
    private function mapa(array $cabecalho): array
    {
        $indices = ['data' => 0, 'valor' => 1, 'descricao' => 2, 'documento' => -1, 'fitid' => -1];
        foreach ($cabecalho as $indice => $nome) {
            $normal = Format::texto((string) $nome);
            if (in_array($normal, ['data', 'date', 'dtposted'], true)) {
                $indices['data'] = (int) $indice;
            } elseif (in_array($normal, ['valor', 'amount', 'trnamt'], true)) {
                $indices['valor'] = (int) $indice;
            } elseif (in_array($normal, ['descricao', 'historico', 'memo', 'name'], true)) {
                $indices['descricao'] = (int) $indice;
            } elseif (in_array($normal, ['documento', 'checknum', 'numero'], true)) {
                $indices['documento'] = (int) $indice;
            } elseif ($normal === 'fitid') {
                $indices['fitid'] = (int) $indice;
            }
        }
        return $indices;
    }

    private function tag(string $bloco, string $tag): string
    {
        if (!preg_match('/<' . $tag . '>([^<\r\n]+)/i', $bloco, $encontrado)) {
            return '';
        }
        return trim($encontrado[1]);
    }

    private function data(string $valor): string
    {
        $valor = trim($valor);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $valor, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $digitos = Format::digitos($valor);
        if (strlen($digitos) >= 8) {
            return substr($digitos, 0, 4) . '-' . substr($digitos, 4, 2) . '-' . substr($digitos, 6, 2);
        }
        return '';
    }
}
