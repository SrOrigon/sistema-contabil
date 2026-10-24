<?php

declare(strict_types=1);

namespace App\KnowledgeBase;

final class LawChunker
{
    /** @return list<array<string, string>> */
    public function chunkArquivo(string $caminho): array
    {
        $texto = file_get_contents($caminho);
        if ($texto === false) {
            return [];
        }
        $texto = str_replace("\r\n", "\n", $texto);
        $pedacos = preg_split('/^## /m', $texto) ?: [];
        $chunks = [];
        foreach ($pedacos as $pedaco) {
            $pedaco = trim($pedaco);
            if ($pedaco === '' || !str_contains($pedaco, '@diploma:')) {
                continue;
            }
            $linhas = explode("\n", $pedaco);
            $titulo = trim(array_shift($linhas) ?? '');
            $meta = [
                'diploma_legal' => '',
                'artigo' => '',
                'paragrafo_inciso' => '',
                'tipo_tributo' => '',
                'vigencia_inicio' => '1900-01-01',
                'vigencia_fim' => '',
            ];
            $corpo = [];
            $lendoMeta = true;
            foreach ($linhas as $linha) {
                if ($lendoMeta && str_starts_with($linha, '@')) {
                    [$chave, $valor] = array_pad(explode(':', substr($linha, 1), 2), 2, '');
                    $chave = trim($chave);
                    $valor = trim($valor);
                    $mapa = [
                        'diploma' => 'diploma_legal',
                        'artigo' => 'artigo',
                        'paragrafo' => 'paragrafo_inciso',
                        'tipo' => 'tipo_tributo',
                        'inicio' => 'vigencia_inicio',
                        'fim' => 'vigencia_fim',
                    ];
                    if (isset($mapa[$chave])) {
                        $meta[$mapa[$chave]] = $valor;
                    }
                    continue;
                }
                if ($lendoMeta && trim($linha) === '') {
                    $lendoMeta = false;
                    continue;
                }
                $lendoMeta = false;
                $corpo[] = $linha;
            }
            $conteudo = trim(implode("\n", $corpo));
            if ($meta['diploma_legal'] === '' || $conteudo === '') {
                continue;
            }
            $chunks[] = $meta + [
                'titulo' => $titulo,
                'conteudo' => $conteudo,
                'origem' => 'arquivo',
            ];
        }
        return $chunks;
    }
}
