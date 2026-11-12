<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Banking\TransactionMatcher;
use App\Core\Format;

/** Uma ferramenta: reconciliar_extrato_bancario. Não chama o modelo. */
class ConciliadorExtratoAgente
{
    public function __construct(private readonly TransactionMatcher $matcher = new TransactionMatcher())
    {
    }

    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'conciliador-extrato',
            'Conciliador de extrato',
            self::class,
            'reconciliar_extrato_bancario',
            'Liga um lançamento do extrato a um documento já existente. Não recalcula o imposto da nota.',
            'Não chama o modelo.',
            [
                ['valor_centavos', true, 'centavos', 'Valor inteiro do lançamento bancário, com sinal.'],
                ['data', true, 'data', 'Data do lançamento.'],
                ['descricao', true, 'texto', 'Histórico do banco.'],
                ['documento_id', false, 'inteiro', 'Documento escolhido pelo casamento. Vazio quando não há par.'],
                ['pontuacao', false, 'inteiro', 'Pontos do casamento. Automático só acima de 95 e sem empate.'],
            ],
            [
                ['documento já auditado', 'O valor da nota é o que o validador gravou. Este agente não aplica alíquota.'],
                ['partida dobrada', 'O lançamento no razão só entra depois do casamento ou do lançamento manual.'],
            ],
        );
    }

    /**
     * @param array{valor_centavos:int,data:string,descricao:string} $transacao
     * @param list<array<string, mixed>> $documentos
     * @return array<string, mixed>
     */
    public function sugerir(array $transacao, array $documentos): array
    {
        $leitura = LeitorDeVariaveis::ler(self::contrato(), $transacao, ['valor_centavos', 'data', 'descricao']);
        if (!$leitura['ok']) {
            return [
                'documento_id' => null,
                'pontuacao' => 0,
                'confianca' => 'nenhuma',
                'motivo' => $leitura['mensagem'],
                'automatica' => false,
                'card' => $leitura['mensagem'],
                'modelo' => 'variavel',
                'agente' => self::contrato()['id'],
            ];
        }
        $transacao = array_merge($transacao, $leitura['valores']);
        $resultado = $this->matcher->comparar($transacao, $documentos);
        $resultado['modelo'] = 'deterministico';
        $resultado['agente'] = self::contrato()['id'];
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
