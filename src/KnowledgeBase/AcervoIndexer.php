<?php

declare(strict_types=1);

namespace App\KnowledgeBase;

final class AcervoIndexer
{
    public function indexar(): int
    {
        $pasta = AcervoCatalog::pasta();
        if (!is_dir($pasta)) {
            throw new \RuntimeException('A pasta database/acervo/leis ainda não existe.');
        }
        $catalogo = new AcervoCatalog();
        $pdo = $catalogo->conectar(true);
        if ($pdo === null) {
            throw new \RuntimeException('Não foi possível abrir o índice da legislação.');
        }
        $this->schema($pdo);
        $vistos = [];
        foreach ($pdo->query('SELECT caminho FROM normas') as $linha) {
            $vistos[(string) $linha['caminho']] = true;
        }
        $norma = $pdo->prepare(
            'INSERT INTO normas (caminho, diploma, titulo, artigo, tipo, uf, vigencia_inicio, vigencia_fim, ementa, fonte)
             VALUES (:caminho, :diploma, :titulo, :artigo, :tipo, :uf, :inicio, :fim, :ementa, :fonte)'
        );
        $fts = $pdo->prepare('INSERT INTO normas_fts (rowid, titulo, diploma, ementa, corpo) VALUES (:id, :titulo, :diploma, :ementa, :corpo)');
        $novas = 0;
        $lote = 0;
        $pdo->beginTransaction();
        foreach ($this->arquivos($pasta) as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || !$arquivo->isFile()) {
                continue;
            }
            if (!str_ends_with(strtolower($arquivo->getFilename()), '.md')) {
                continue;
            }
            $absoluto = $arquivo->getPathname();
            if ($this->foraDoAcervo($absoluto)) {
                continue;
            }
            $relativo = str_replace('\\', '/', substr($absoluto, strlen($pasta) + 1));
            if (isset($vistos[$relativo])) {
                continue;
            }
            $lido = $this->ler($absoluto);
            if ($lido === null) {
                continue;
            }
            $norma->execute([
                ':caminho' => $relativo,
                ':diploma' => $lido['diploma'],
                ':titulo' => $lido['titulo'],
                ':artigo' => $lido['artigo'],
                ':tipo' => $lido['tipo'],
                ':uf' => $lido['uf'],
                ':inicio' => $lido['vigencia_inicio'],
                ':fim' => $lido['vigencia_fim'],
                ':ementa' => $lido['ementa'],
                ':fonte' => $lido['fonte'],
            ]);
            $fts->execute([
                ':id' => (int) $pdo->lastInsertId(),
                ':titulo' => $lido['titulo'],
                ':diploma' => $lido['diploma'],
                ':ementa' => $lido['ementa'],
                ':corpo' => $lido['corpo'],
            ]);
            $novas++;
            $lote++;
            if ($lote >= 400) {
                $pdo->commit();
                $pdo->beginTransaction();
                $lote = 0;
                echo $novas . PHP_EOL;
            }
        }
        $pdo->commit();
        return $novas;
    }

    /** @return ?array{diploma:string,titulo:string,artigo:string,tipo:string,uf:string,vigencia_inicio:string,vigencia_fim:string,ementa:string,fonte:string,corpo:string} */
    public function ler(string $caminho): ?array
    {
        $texto = file_get_contents($caminho);
        if ($texto === false) {
            return null;
        }
        $texto = str_replace("\r\n", "\n", $texto);
        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        }
        $meta = [];
        $corpo = trim($texto);
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $texto, $partes)) {
            $meta = $this->cabecalho($partes[1]);
            $corpo = trim($partes[2]);
        } elseif (strtolower(basename($caminho)) === 'constituicao.md') {
            $meta = [
                'titulo' => 'Constituição da República Federativa do Brasil de 1988',
                'tipo' => 'Constituição',
                'data' => '1988-10-05',
                'ementa' => 'Texto constitucional vigente.',
                'revogada' => 'false',
            ];
        } else {
            return null;
        }
        if ($corpo === '' || ($meta['titulo'] ?? '') === '') {
            return null;
        }
        if (strlen($corpo) > 1500000) {
            $corpo = substr($corpo, 0, 1500000) . "\n[Texto truncado na indexação. O arquivo completo permanece em database/acervo/leis.]";
        }
        $data = $this->data((string) ($meta['data'] ?? ''));
        $ano = (string) ($meta['ano'] ?? '');
        if ($ano === '' && $data !== '') {
            $ano = substr($data, 0, 4);
        }
        $tipo = trim((string) ($meta['tipo'] ?? 'Norma'));
        $numero = trim((string) ($meta['numero'] ?? ''));
        $uf = strtoupper(trim((string) ($meta['uf'] ?? '')));
        $diploma = $tipo;
        if ($numero !== '') {
            $diploma .= ' ' . $numero;
        }
        if ($ano !== '') {
            $diploma .= '/' . $ano;
        }
        if ($uf !== '') {
            $diploma .= ' (' . $uf . ')';
        }
        $revogada = in_array(strtolower((string) ($meta['revogada'] ?? 'false')), ['true', 'yes', 'sim', '1'], true);
        $ementa = trim((string) ($meta['ementa'] ?? ''));
        if ($ementa === '') {
            $ementa = trim((string) $meta['titulo']);
        }
        return [
            'diploma' => $diploma,
            'titulo' => trim((string) $meta['titulo']),
            'artigo' => $numero !== '' ? 'nº ' . $numero : 'texto integral',
            'tipo' => $tipo,
            'uf' => $uf,
            'vigencia_inicio' => $data !== '' ? $data : '1900-01-01',
            'vigencia_fim' => $revogada ? ($data !== '' ? $data : '1900-01-01') : '',
            'ementa' => $ementa,
            'fonte' => trim((string) ($meta['fonte'] ?? '')),
            'corpo' => $corpo,
        ];
    }

    private function schema(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS normas (
                id INTEGER PRIMARY KEY,
                caminho TEXT NOT NULL UNIQUE,
                diploma TEXT NOT NULL,
                titulo TEXT NOT NULL,
                artigo TEXT NOT NULL,
                tipo TEXT NOT NULL,
                uf TEXT NOT NULL,
                vigencia_inicio TEXT NOT NULL,
                vigencia_fim TEXT NOT NULL,
                ementa TEXT NOT NULL,
                fonte TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE VIRTUAL TABLE IF NOT EXISTS normas_fts USING fts5(
                titulo, diploma, ementa, corpo,
                tokenize = "unicode61 remove_diacritics 2"
            )'
        );
    }

    /** @return array<string, string> */
    private function cabecalho(string $bloco): array
    {
        $meta = [];
        foreach (explode("\n", $bloco) as $linha) {
            if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $linha, $partes)) {
                continue;
            }
            $valor = trim($partes[2]);
            if (strlen($valor) >= 2 && (($valor[0] === '"' && str_ends_with($valor, '"')) || ($valor[0] === "'" && str_ends_with($valor, "'")))) {
                $valor = substr($valor, 1, -1);
            }
            $meta[$partes[1]] = $valor;
        }
        return $meta;
    }

    private function data(string $valor): string
    {
        $valor = trim($valor, "\"' ");
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : '';
    }

    /** @return \Generator<int, \SplFileInfo> */
    private function arquivos(string $pasta): \Generator
    {
        $prioridade = [
            'constituicao.md',
            'federal/leis-complementares',
            'federal/leis-ordinarias',
            'federal/emendas-constitucionais',
            'federal/medidas-provisorias',
            'federal/leis-delegadas',
            'federal/leis-constitucionais',
            'reguladores',
            'federal',
            'jurisprudencia',
            'tratados',
            'municipais',
            'estaduais',
        ];
        $vistos = [];
        foreach ($prioridade as $nome) {
            $caminho = $pasta . DIRECTORY_SEPARATOR . $nome;
            if (is_file($caminho)) {
                $vistos[$caminho] = true;
                yield new \SplFileInfo($caminho);
                continue;
            }
            if (!is_dir($caminho)) {
                continue;
            }
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($caminho, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $arquivo) {
                if ($arquivo instanceof \SplFileInfo) {
                    $vistos[$arquivo->getPathname()] = true;
                    yield $arquivo;
                }
            }
        }
        $resto = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        foreach ($resto as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && !isset($vistos[$arquivo->getPathname()])) {
                yield $arquivo;
            }
        }
    }

    private function foraDoAcervo(string $caminho): bool
    {
        $normal = str_replace('\\', '/', $caminho);
        foreach (['/.git/', '/scripts/', '/fonte/'] as $trecho) {
            if (str_contains($normal, $trecho)) {
                return true;
            }
        }
        foreach (explode('/', $normal) as $parte) {
            if (str_starts_with($parte, '_')) {
                return true;
            }
        }
        return false;
    }
}
