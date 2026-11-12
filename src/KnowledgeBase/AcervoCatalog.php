<?php

declare(strict_types=1);

namespace App\KnowledgeBase;

use App\Core\Format;

final class AcervoCatalog
{
    public static function pasta(): string
    {
        return ROOT . '/database/acervo/leis';
    }

    public static function banco(): string
    {
        return ROOT . '/storage/legislacao.sqlite';
    }

    public function contar(): int
    {
        $pdo = $this->conectar();
        if ($pdo === null) {
            return 0;
        }
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM normas')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    public function buscar(string $consulta, string $data, int $limite): array
    {
        $pdo = $this->conectar();
        $expressao = $this->expressao($consulta);
        if ($pdo === null || $expressao === '' || $limite < 1) {
            return [];
        }
        $sql = 'SELECT n.diploma, n.titulo, n.artigo, n.tipo, n.vigencia_inicio, n.vigencia_fim, n.ementa, n.fonte,
                       snippet(normas_fts, 3, "", "", " … ", 48) AS trecho
                FROM normas_fts
                JOIN normas n ON n.id = normas_fts.rowid
                WHERE normas_fts MATCH :consulta
                  AND n.vigencia_inicio <= :data
                  AND (n.vigencia_fim = "" OR n.vigencia_fim >= :data)
                ORDER BY bm25(normas_fts)
                LIMIT ' . $limite;
        try {
            $consultaSql = $pdo->prepare($sql);
            $consultaSql->execute([':consulta' => $expressao, ':data' => $data]);
            $linhas = $consultaSql->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        $saida = [];
        foreach ($linhas as $linha) {
            $corpo = trim((string) $linha['ementa']);
            $trecho = trim((string) $linha['trecho']);
            if ($trecho !== '' && !str_contains($corpo, $trecho)) {
                $corpo = trim($corpo . "\n" . $trecho);
            }
            if ((string) $linha['fonte'] !== '') {
                $corpo = trim($corpo . "\nFonte: " . $linha['fonte']);
            }
            $saida[] = [
                'id' => 0,
                'diploma_legal' => (string) $linha['diploma'],
                'artigo' => (string) $linha['artigo'],
                'paragrafo_inciso' => '',
                'tipo_tributo' => (string) $linha['tipo'],
                'titulo' => (string) $linha['titulo'],
                'conteudo' => $corpo,
                'vigencia_inicio' => (string) $linha['vigencia_inicio'],
                'vigencia_fim' => (string) $linha['vigencia_fim'],
                'similaridade' => '1',
            ];
        }
        return $saida;
    }

    /** @return list<array{caminho: string, corpo: string}> */
    public function textosComCaminho(string $trecho): array
    {
        $pdo = $this->conectar();
        if ($pdo === null || $trecho === '') {
            return [];
        }
        try {
            $consulta = $pdo->prepare(
                'SELECT n.caminho, f.corpo FROM normas n JOIN normas_fts f ON f.rowid = n.id WHERE n.caminho LIKE :trecho ORDER BY n.caminho'
            );
            $consulta->execute([':trecho' => $trecho]);
            $linhas = $consulta->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        $saida = [];
        foreach ($linhas as $linha) {
            $saida[] = [
                'caminho' => (string) $linha['caminho'],
                'corpo' => (string) $linha['corpo'],
            ];
        }
        return $saida;
    }

    public function conectar(bool $criar = false): ?\PDO
    {
        $caminho = self::banco();
        if (!is_file($caminho) && !$criar) {
            return null;
        }
        $pasta = dirname($caminho);
        if (!is_dir($pasta)) {
            mkdir($pasta, 0777, true);
        }
        $pdo = new \PDO('sqlite:' . $caminho, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    }

    public function expressao(string $consulta): string
    {
        $texto = Format::texto($consulta);
        $partes = preg_split('/[^a-z0-9]+/', $texto) ?: [];
        $grupos = [];
        $vistos = [];
        foreach ($partes as $parte) {
            if ($parte === '' || isset($vistos[$parte]) || $this->ignorar($parte)) {
                continue;
            }
            $vistos[$parte] = true;
            $alternativas = $this->sinonimos($parte);
            $termos = [];
            foreach ($alternativas as $alternativa) {
                $termos[] = '"' . str_replace('"', '', $alternativa) . '"';
            }
            $grupos[] = count($termos) === 1 ? $termos[0] : '(' . implode(' OR ', $termos) . ')';
        }
        return implode(' AND ', $grupos);
    }

    private function ignorar(string $token): bool
    {
        if (ctype_digit($token)) {
            return strlen($token) < 2;
        }
        if (strlen($token) >= 3) {
            return in_array($token, ['que', 'para', 'com', 'sem', 'por', 'como', 'das', 'dos', 'nas', 'nos', 'uma', 'nao', 'sua', 'seu', 'este', 'esta', 'essa', 'isso', 'sobre', 'entre', 'quando', 'onde', 'qual', 'mais', 'muito', 'pela', 'pelo', 'pelas', 'pelos'], true);
        }
        return !in_array($token, ['lc', 'cf', 'ec', 'rf', 'ir', 'cs', 'in', 'mg', 'sp', 'rj', 'rs', 'pr', 'sc', 'ba', 'pe', 'ce', 'go', 'df'], true);
    }

    /** @return list<string> */
    private function sinonimos(string $token): array
    {
        return match ($token) {
            'lc' => ['lc', 'complementar'],
            'cf' => ['cf', 'constituicao'],
            'ec' => ['ec', 'emenda'],
            'ctn' => ['ctn', 'tributario'],
            'rfb' => ['rfb', 'receita'],
            default => [$token],
        };
    }
}
