<?php

declare(strict_types=1);

namespace App\Banking\Webhooks;

use App\Core\Money;

final class TransactionWebhookHandler
{
    /**
     * Aceita o formato consolidado dos agregadores: lista de transações com valor assinado
     * ou com amount positivo e type CREDIT/DEBIT.
     *
     * @param array<string, mixed> $payload
     * @return array{conta_externa:string, transacoes:list<array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string}>}
     */
    public function interpretar(array $payload): array
    {
        $conta = (string) ($payload['accountId'] ?? $payload['account_id'] ?? '');
        $lista = $payload['transactions'] ?? $payload['transacoes'] ?? [];
        if (!is_array($lista)) {
            $lista = [];
        }
        $transacoes = [];
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $data = substr((string) ($item['date'] ?? $item['datePosted'] ?? $item['data'] ?? ''), 0, 10);
            $descricao = trim((string) ($item['description'] ?? $item['descricao'] ?? 'Transação Open Finance'));
            $valor = $this->valor($item);
            $id = (string) ($item['id'] ?? $item['transactionId'] ?? '');
            if ($data === '' || $valor === null) {
                continue;
            }
            $transacoes[] = [
                'data' => $data,
                'valor_centavos' => $valor,
                'descricao' => $descricao,
                'documento' => (string) ($item['paymentData']['payer']['documentNumber']['value'] ?? ''),
                'fitid' => $id,
            ];
        }
        return ['conta_externa' => $conta, 'transacoes' => $transacoes];
    }

    /** @param array<string, mixed> $item */
    private function valor(array $item): ?int
    {
        if (!isset($item['amount']) && !isset($item['valor'])) {
            return null;
        }
        $bruto = (string) ($item['amount'] ?? $item['valor']);
        $bruto = str_replace(',', '.', $bruto);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $bruto)) {
            return null;
        }
        $centavos = Money::fromDecimal($bruto)->cents();
        $tipo = strtoupper((string) ($item['type'] ?? $item['creditDebitType'] ?? ''));
        if (in_array($tipo, ['DEBIT', 'DEBITO', 'SAIDA'], true) && $centavos > 0) {
            return -$centavos;
        }
        if (in_array($tipo, ['CREDIT', 'CREDITO', 'ENTRADA'], true) && $centavos < 0) {
            return abs($centavos);
        }
        return $centavos;
    }
}
