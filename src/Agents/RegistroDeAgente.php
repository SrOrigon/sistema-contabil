<?php

declare(strict_types=1);

namespace App\Agents;

use App\Core\Database;

/** Grava a decisão de um agente para a tela Agentes. */
final class RegistroDeAgente
{
    /** @param array<string, mixed> $entrada @param array<string, mixed> $saida */
    public static function gravar(Database $db, string $agente, string $ferramenta, ?int $empresaId, array $entrada, array $saida, string $modelo): void
    {
        $db->insert('agent_logs', [
            'empresa_id' => $empresaId,
            'agente' => $agente,
            'acao' => $ferramenta,
            'entrada' => json_encode($entrada, JSON_UNESCAPED_UNICODE),
            'saida' => json_encode($saida, JSON_UNESCAPED_UNICODE),
            'ferramentas' => $ferramenta,
            'modelo' => $modelo,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
    }
}
