<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private PDO $pdo;
    private int $profundidade = 0;

    public function __construct(private readonly string $dsn)
    {
        $this->pdo = new PDO($this->dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<string, mixed> $params */
    public function all(string $sql, array $params = []): array
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($params);
        return $consulta->fetchAll();
    }

    /** @param array<string, mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($params);
        $linha = $consulta->fetch();
        return $linha === false ? null : $linha;
    }

    /** @param array<string, mixed> $params */
    public function run(string $sql, array $params = []): int
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($params);
        return $consulta->rowCount();
    }

    public function statement(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** @param array<string, mixed> $dados */
    public function insert(string $tabela, array $dados): int
    {
        $colunas = array_keys($dados);
        $marcas = array_map(static fn (string $coluna): string => ':' . $coluna, $colunas);
        $sql = 'INSERT INTO ' . $tabela . ' (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', $marcas) . ')';
        $params = [];
        foreach ($dados as $coluna => $valor) {
            $params[':' . $coluna] = $valor;
        }
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->profundidade++;
        if ($this->profundidade === 1) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT nivel' . $this->profundidade);
        }
        try {
            $resultado = $callback($this);
            if ($this->profundidade === 1) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT nivel' . $this->profundidade);
            }
            $this->profundidade--;
            return $resultado;
        } catch (\Throwable $erro) {
            if ($this->profundidade === 1) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT nivel' . $this->profundidade);
            }
            $this->profundidade--;
            throw $erro;
        }
    }
}
