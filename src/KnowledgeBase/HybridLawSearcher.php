<?php

declare(strict_types=1);

namespace App\KnowledgeBase;

use App\Core\Database;
use App\Core\Format;

final class HybridLawSearcher
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $consulta, \DateTimeImmutable $competencia, int $limite = 8): array
    {
        $consulta = trim($consulta);
        if ($consulta === '') {
            return [];
        }
        $data = $competencia->format('Y-m-d');
        $chunks = $this->db->all(
            'SELECT * FROM legislacao_chunks
             WHERE vigencia_inicio <= :data
               AND (vigencia_fim IS NULL OR vigencia_fim = \'\' OR vigencia_fim >= :data)',
            [':data' => $data],
        );
        if ($chunks === []) {
            return [];
        }
        $documentos = [];
        foreach ($chunks as $indice => $chunk) {
            $documentos[$indice] = $this->tokens($chunk['titulo'] . ' ' . $chunk['conteudo'] . ' ' . $chunk['diploma_legal'] . ' ' . $chunk['artigo']);
        }
        $consultaTokens = $this->tokens($consulta);
        $n = count($documentos);
        $media = 0;
        foreach ($documentos as $tokens) {
            $media += count($tokens);
        }
        $media = $n > 0 ? $media / $n : 1;
        $df = [];
        foreach ($documentos as $tokens) {
            foreach (array_unique($tokens) as $token) {
                $df[$token] = ($df[$token] ?? 0) + 1;
            }
        }
        $pontos = [];
        $k1 = '1.5';
        $b = '0.75';
        foreach ($documentos as $indice => $tokens) {
            $frequencia = array_count_values($tokens);
            $dl = count($tokens);
            $score = '0';
            foreach ($consultaTokens as $token) {
                $f = $frequencia[$token] ?? 0;
                if ($f === 0) {
                    continue;
                }
                $idf = log(1 + (($n - $df[$token] + 0.5) / ($df[$token] + 0.5)));
                $numerador = bcmul((string) $f, bcadd($k1, '1', 6), 6);
                $denominador = bcadd((string) $f, bcmul($k1, bcadd(bcsub('1', $b, 6), bcmul($b, bcdiv((string) $dl, (string) max($media, 1), 6), 6), 6), 6), 6);
                $score = bcadd($score, bcmul((string) $idf, bcdiv($numerador, $denominador, 6), 6), 6);
            }
            $score = bcadd($score, $this->reforcoExato($consulta, $chunks[$indice]), 6);
            $jaccard = $this->jaccard($consultaTokens, $tokens);
            $pontos[$indice] = bcadd($score, bcmul($jaccard, '0.35', 6), 6);
        }
        uasort($pontos, static fn (string $a, string $b): int => bccomp($b, $a, 6));
        $maior = '0';
        foreach ($pontos as $ponto) {
            if (bccomp($ponto, $maior, 6) > 0) {
                $maior = $ponto;
            }
        }
        $saida = [];
        $tetoNucleo = min(3, $limite);
        foreach ($pontos as $indice => $ponto) {
            if (bccomp($ponto, '0', 6) <= 0) {
                continue;
            }
            $chunk = $chunks[$indice];
            $similaridade = bccomp($maior, '0', 6) > 0 ? bcdiv($ponto, $maior, 4) : '0';
            $saida[] = [
                'id' => (int) $chunk['id'],
                'diploma_legal' => $chunk['diploma_legal'],
                'artigo' => $chunk['artigo'],
                'paragrafo_inciso' => $chunk['paragrafo_inciso'],
                'tipo_tributo' => $chunk['tipo_tributo'],
                'titulo' => $chunk['titulo'],
                'conteudo' => $chunk['conteudo'],
                'vigencia_inicio' => $chunk['vigencia_inicio'],
                'vigencia_fim' => $chunk['vigencia_fim'],
                'similaridade' => $similaridade,
            ];
            if (count($saida) >= $tetoNucleo) {
                break;
            }
        }
        return $this->completarComAcervo($consulta, $data, $saida, $limite);
    }

    /** @param list<array<string, mixed>> $saida @return list<array<string, mixed>> */
    private function completarComAcervo(string $consulta, string $data, array $saida, int $limite): array
    {
        if (count($saida) >= $limite) {
            return $saida;
        }
        try {
            $acervo = (new AcervoCatalog())->buscar($consulta, $data, $limite - count($saida));
        } catch (\Throwable) {
            return $saida;
        }
        foreach ($acervo as $trecho) {
            $saida[] = $trecho;
            if (count($saida) >= $limite) {
                break;
            }
        }
        return $saida;
    }

    /** @return list<string> */
    private function tokens(string $texto): array
    {
        $texto = Format::texto($texto);
        $partes = preg_split('/[^a-z0-9]+/', $texto) ?: [];
        $tokens = [];
        foreach ($partes as $parte) {
            if ($parte === '' || (strlen($parte) < 3 && !ctype_digit($parte))) {
                continue;
            }
            $tokens[] = $parte;
        }
        return $tokens;
    }

    /** @param array<string, mixed> $chunk */
    private function reforcoExato(string $consulta, array $chunk): string
    {
        $consultaNorma = Format::texto($consulta);
        $artigo = Format::texto((string) $chunk['artigo'] . ' ' . $chunk['titulo']);
        $reforco = '0';
        if (preg_match('/art(?:igo)?\.?\s*(\d+)/', $consultaNorma, $numero) && str_contains($artigo, $numero[1])) {
            $reforco = bcadd($reforco, '3', 2);
        }
        if (preg_match('/\b(\d{2,4})\b/', $consultaNorma, $lei) && str_contains(Format::texto((string) $chunk['diploma_legal']), $lei[1])) {
            $reforco = bcadd($reforco, '1.5', 2);
        }
        return $reforco;
    }

    /** @param list<string> $a @param list<string> $b */
    private function jaccard(array $a, array $b): string
    {
        $sa = array_unique($a);
        $sb = array_unique($b);
        if ($sa === [] || $sb === []) {
            return '0';
        }
        $inter = count(array_intersect($sa, $sb));
        $uniao = count(array_unique(array_merge($sa, $sb)));
        return $uniao === 0 ? '0' : bcdiv((string) $inter, (string) $uniao, 6);
    }
}
