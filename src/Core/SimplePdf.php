<?php

declare(strict_types=1);

namespace App\Core;

final class SimplePdf
{
    /** @param list<string> $linhas */
    public static function montar(string $titulo, array $linhas): string
    {
        $paginas = [];
        $atual = [];
        $y = 800;
        foreach ($linhas as $linha) {
            $quebradas = self::quebrar(self::win($linha), 92);
            if ($quebradas === []) {
                $quebradas = [''];
            }
            foreach ($quebradas as $pedaço) {
                if ($y < 48) {
                    $paginas[] = $atual;
                    $atual = [];
                    $y = 800;
                }
                $atual[] = [$pedaço, $y];
                $y -= 14;
            }
        }
        $paginas[] = $atual;
        if ($paginas === []) {
            $paginas = [[]];
        }

        $objetos = [];
        $objetos[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $numero = 3;
        $mapa = [];
        foreach ($paginas as $indice => $pagina) {
            $kids[] = $numero . ' 0 R';
            $mapa[$indice] = ['pagina' => $numero, 'conteudo' => $numero + 1];
            $numero += 2;
        }
        $fonte = $numero;
        $objetos[] = '<< /Type /Pages /Count ' . count($paginas) . ' /Kids [' . implode(' ', $kids) . '] >>';
        foreach ($paginas as $indice => $pagina) {
            $conteudoId = $mapa[$indice]['conteudo'];
            $objetos[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents ' . $conteudoId . ' 0 R /Resources << /Font << /F1 ' . $fonte . ' 0 R >> >> >>';
            $stream = "BT\n/F1 10 Tf\n";
            if ($indice === 0) {
                $stream .= "/F1 16 Tf\n48 810 Td\n(" . self::escapar(self::win($titulo)) . ") Tj\n/F1 10 Tf\n";
            }
            $comandos = [];
            foreach ($pagina as [$texto, $posicao]) {
                if ($indice === 0 && $posicao > 790) {
                    continue;
                }
                $comandos[] = '1 0 0 1 48 ' . $posicao . ' Tm (' . self::escapar($texto) . ') Tj';
            }
            $stream .= implode("\n", $comandos) . "\nET";
            $objetos[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objetos[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $indice => $corpo) {
            $offsets[] = strlen($pdf);
            $pdf .= ($indice + 1) . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $inicio = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objetos); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer << /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $inicio . "\n%%EOF";
        return $pdf;
    }

    private static function win(string $texto): string
    {
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
        return $convertido === false ? preg_replace('/[^\x20-\x7E]/', '', $texto) ?? '' : $convertido;
    }

    private static function escapar(string $texto): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
    }

    /** @return list<string> */
    private static function quebrar(string $texto, int $largura): array
    {
        if ($texto === '') {
            return [''];
        }
        $palavras = explode(' ', $texto);
        $linhas = [];
        $atual = '';
        foreach ($palavras as $palavra) {
            $candidato = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if (strlen($candidato) <= $largura) {
                $atual = $candidato;
                continue;
            }
            if ($atual !== '') {
                $linhas[] = $atual;
            }
            $atual = $palavra;
        }
        if ($atual !== '') {
            $linhas[] = $atual;
        }
        return $linhas;
    }
}
