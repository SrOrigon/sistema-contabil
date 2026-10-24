<?php

declare(strict_types=1);

namespace App\Agents\Tools;

use App\Agents\Contracts\ToolInterface;
use App\Banking\TransactionMatcher;
use App\Core\Database;

final class MatchBankStatementTool implements ToolInterface
{
    public function __construct(private readonly Database $db, private readonly TransactionMatcher $matcher = new TransactionMatcher())
    {
    }

    public function name(): string
    {
        return 'reconciliar_extrato_bancario';
    }

    public function description(): string
    {
        return 'Pontua o casamento entre uma transação bancária e os documentos em aberto. Acima de 95 e sem empate, a conciliação pode ser automática.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'empresa_id' => ['type' => 'NUMBER'],
                'transacao_id' => ['type' => 'NUMBER'],
            ],
            'required' => ['empresa_id', 'transacao_id'],
        ];
    }

    public function execute(array $argumentos): array
    {
        $transacao = $this->db->one(
            'SELECT * FROM transacoes_bancarias WHERE id = :id AND empresa_id = :empresa',
            [':id' => (int) $argumentos['transacao_id'], ':empresa' => (int) $argumentos['empresa_id']],
        );
        if ($transacao === null) {
            return ['encontrada' => false, 'motivo' => 'Transação inexistente.'];
        }
        $documentos = $this->db->all(
            'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND tipo_operacao IN (\'entrada\', \'saida\')',
            [':empresa' => (int) $argumentos['empresa_id']],
        );
        return ['encontrada' => true] + $this->matcher->comparar($transacao, $documentos);
    }
}
