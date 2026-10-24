<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Llm\LlmClient;
use App\Agents\ToolRegistry;
use App\Core\Database;

final class Orchestrator
{
    public function __construct(
        private readonly Database $db,
        private readonly ToolRegistry $ferramentas,
        private readonly ?LlmClient $llm,
    ) {
    }

    /** @param array<string, mixed> $entrada @return array<string, mixed> */
    public function executar(string $agente, string $ferramenta, array $entrada, ?int $empresaId = null): array
    {
        $saida = $this->ferramentas->executar($ferramenta, $entrada);
        $this->db->insert('agent_logs', [
            'empresa_id' => $empresaId,
            'agente' => $agente,
            'acao' => $ferramenta,
            'entrada' => json_encode($entrada, JSON_UNESCAPED_UNICODE),
            'saida' => json_encode($saida, JSON_UNESCAPED_UNICODE),
            'ferramentas' => $ferramenta,
            'modelo' => $this->llm?->configurado() ? $this->llm->modelo() : 'deterministico',
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        return $saida;
    }
}
