<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Banking\TransactionMatcher;
use App\Core\Format;

final class ReconciliationAgent
{
    public function __construct(private readonly TransactionMatcher $matcher = new TransactionMatcher())
    {
    }

    /**
     * @param array{valor_centavos:int,data:string,descricao:string} $transacao
     * @param list<array<string, mixed>> $documentos
     * @return array<string, mixed>
     */
    public function sugerir(array $transacao, array $documentos): array
    {
        $resultado = $this->matcher->comparar($transacao, $documentos);
        $resultado['modelo'] = 'deterministico';
        if ($resultado['documento_id'] === null) {
            $resultado['card'] = 'Não encontrei documento com o valor ' . Format::money(abs((int) $transacao['valor_centavos'])) . '.';
            return $resultado;
        }
        $documento = null;
        foreach ($documentos as $candidato) {
            if ((int) $candidato['id'] === $resultado['documento_id']) {
                $documento = $candidato;
                break;
            }
        }
        $nome = $documento === null ? 'o documento' : trim((string) ($documento['numero'] ?: 's/n') . ' de ' . ($documento['emitente_nome'] ?: $documento['destinatario_nome']));
        $resultado['card'] = 'Identifiquei ' . $resultado['pontuacao'] . ' pontos para ligar este lançamento de '
            . Format::money((int) $transacao['valor_centavos']) . ' a ' . $nome . '. ' . $resultado['motivo'];
        return $resultado;
    }
}
