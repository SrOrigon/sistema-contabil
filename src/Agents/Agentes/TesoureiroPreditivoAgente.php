<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Core\Format;

/**
 * Tesouraria autônoma: classifica movimentações bancárias recorrentes,
 * detecta anomalias de valor e sugere partidas dobradas com cálculo de confiança.
 */
class TesoureiroPreditivoAgente
{
    /** @var array<string, array{categoria:string,debito_sugerido:string,credito_sugerido:string}> */
    private const PADROES_PADRAO = [
        'aluguel' => ['categoria' => 'Aluguel do Imóvel', 'debito_sugerido' => 'Despesas com Aluguéis', 'credito_sugerido' => 'Banco Conta Movimento'],
        'locacao' => ['categoria' => 'Aluguel do Imóvel', 'debito_sugerido' => 'Despesas com Aluguéis', 'credito_sugerido' => 'Banco Conta Movimento'],
        'energia' => ['categoria' => 'Energia Elétrica', 'debito_sugerido' => 'Despesas com Energia', 'credito_sugerido' => 'Banco Conta Movimento'],
        'enel' => ['categoria' => 'Energia Elétrica', 'debito_sugerido' => 'Despesas com Energia', 'credito_sugerido' => 'Banco Conta Movimento'],
        'copel' => ['categoria' => 'Energia Elétrica', 'debito_sugerido' => 'Despesas com Energia', 'credito_sugerido' => 'Banco Conta Movimento'],
        'cpfl' => ['categoria' => 'Energia Elétrica', 'debito_sugerido' => 'Despesas com Energia', 'credito_sugerido' => 'Banco Conta Movimento'],
        'sabesp' => ['categoria' => 'Água e Saneamento', 'debito_sugerido' => 'Despesas com Água/Esgoto', 'credito_sugerido' => 'Banco Conta Movimento'],
        'sanepar' => ['categoria' => 'Água e Saneamento', 'debito_sugerido' => 'Despesas com Água/Esgoto', 'credito_sugerido' => 'Banco Conta Movimento'],
        'internet' => ['categoria' => 'Telecomunicação e Internet', 'debito_sugerido' => 'Despesas com Telefonia/Internet', 'credito_sugerido' => 'Banco Conta Movimento'],
        'telefonia' => ['categoria' => 'Telecomunicação e Internet', 'debito_sugerido' => 'Despesas com Telefonia/Internet', 'credito_sugerido' => 'Banco Conta Movimento'],
        'claro' => ['categoria' => 'Telecomunicação e Internet', 'debito_sugerido' => 'Despesas com Telefonia/Internet', 'credito_sugerido' => 'Banco Conta Movimento'],
        'vivo' => ['categoria' => 'Telecomunicação e Internet', 'debito_sugerido' => 'Despesas com Telefonia/Internet', 'credito_sugerido' => 'Banco Conta Movimento'],
        'salario' => ['categoria' => 'Folha de Pagamento', 'debito_sugerido' => 'Salários a Pagar', 'credito_sugerido' => 'Banco Conta Movimento'],
        'folha' => ['categoria' => 'Folha de Pagamento', 'debito_sugerido' => 'Salários a Pagar', 'credito_sugerido' => 'Banco Conta Movimento'],
        'pro-labore' => ['categoria' => 'Pró-Labore', 'debito_sugerido' => 'Pró-Labore a Pagar', 'credito_sugerido' => 'Banco Conta Movimento'],
        'prolabore' => ['categoria' => 'Pró-Labore', 'debito_sugerido' => 'Pró-Labore a Pagar', 'credito_sugerido' => 'Banco Conta Movimento'],
        'inss' => ['categoria' => 'Tributos Trabalhistas', 'debito_sugerido' => 'INSS a Recolher', 'credito_sugerido' => 'Banco Conta Movimento'],
        'fgts' => ['categoria' => 'Encargos Sociais', 'debito_sugerido' => 'FGTS a Recolher', 'credito_sugerido' => 'Banco Conta Movimento'],
        'simples' => ['categoria' => 'Tributos Federais', 'debito_sugerido' => 'Simples Nacional a Recolher', 'credito_sugerido' => 'Banco Conta Movimento'],
        'das' => ['categoria' => 'Tributos Federais', 'debito_sugerido' => 'Simples Nacional a Recolher', 'credito_sugerido' => 'Banco Conta Movimento'],
        'honorarios' => ['categoria' => 'Serviços Contábeis', 'debito_sugerido' => 'Despesas com Serviços Profissionais', 'credito_sugerido' => 'Banco Conta Movimento'],
        'contabilidade' => ['categoria' => 'Serviços Contábeis', 'debito_sugerido' => 'Despesas com Serviços Profissionais', 'credito_sugerido' => 'Banco Conta Movimento'],
    ];

    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'tesoureiro-preditivo',
            'Tesoureiro Preditivo',
            self::class,
            'classificar_tesouraria_preditiva',
            'Classifica lançamentos bancários recorrentes, prevê contas a pagar/receber e alerta desvios ou gastos anômalos com pontuação de confiança.',
            'Não chama o modelo para cálculo numérico. Aplica regras determinísticas e tolerâncias percentuais históricas.',
            [
                ['valor_centavos', true, 'centavos', 'Valor inteiro do lançamento bancário, com sinal (negativo para saída).'],
                ['data', true, 'data', 'Data da efetivação bancária no formato YYYY-MM-DD.'],
                ['descricao', true, 'texto', 'Histórico ou descrição textual do extrato bancário.'],
                ['padrao_recorrente', false, 'texto', 'Termo-chave ou categoria de recorrência esperada.'],
                ['dia_previsto', false, 'inteiro', 'Dia do mês usualmente previsto para o pagamento/recebimento.'],
                ['tolerancia_percentual', false, 'percentual', 'Variação percentual aceitável do valor contra o histórico médio.'],
            ],
            [
                ['Resolução CFC nº 1.330/2011', 'Escrituração contábil em partidas dobradas e tempestividade dos fatos contábeis.'],
                ['NBC TG Estrutura Conceitual', 'Reconhecimento oportuno de despesas e receitas pelo regime de competência.'],
            ],
        );
    }

    /**
     * @param array{valor_centavos:int,data:string,descricao:string} $transacao
     * @param list<array<string, mixed>> $regrasCadastradas
     * @param list<array{valor_centavos:int,data:string}> $historico
     * @return array<string, mixed>
     */
    public function analisar(array $transacao, array $regrasCadastradas = [], array $historico = []): array
    {
        $leitura = LeitorDeVariaveis::ler(self::contrato(), $transacao, ['valor_centavos', 'data', 'descricao']);
        if (!$leitura['ok']) {
            return [
                'recorrente' => false,
                'categoria' => 'Não classificado',
                'pontuacao' => 0,
                'confianca' => 'nenhuma',
                'automatica' => false,
                'desvio_detectado' => false,
                'card' => $leitura['mensagem'],
                'modelo' => 'variavel',
                'agente' => self::contrato()['id'],
            ];
        }

        $dados = array_merge($transacao, $leitura['valores']);
        $valor = (int) $dados['valor_centavos'];
        $descricao = mb_strtolower((string) $dados['descricao'], 'UTF-8');
        $data = (string) $dados['data'];
        $diaTransacao = (int) date('d', (int) strtotime($data));

        $categoria = null;
        $debito = null;
        $credito = null;
        $pontuacao = 50;

        // 1. Prioridade para regras salvas no banco
        foreach ($regrasCadastradas as $regra) {
            $padrao = mb_strtolower((string) ($regra['padrao_historico'] ?? ''), 'UTF-8');
            if ($padrao !== '' && str_contains($descricao, $padrao)) {
                $categoria = (string) ($regra['categoria'] ?? 'Despesa Recorrente');
                $debito = (string) ($regra['conta_debito_nome'] ?? 'Despesas Operacionais');
                $credito = (string) ($regra['conta_credito_nome'] ?? 'Banco Conta Movimento');
                $pontuacao = (int) ($regra['confianca_base'] ?? 92);
                if (isset($regra['dia_estimado']) && (int) $regra['dia_estimado'] > 0) {
                    $diffDias = abs($diaTransacao - (int) $regra['dia_estimado']);
                    if ($diffDias <= 3) {
                        $pontuacao = min(100, $pontuacao + 5);
                    } else {
                        $pontuacao = max(40, $pontuacao - 10);
                    }
                }
                break;
            }
        }

        // 2. Se não achou na regra específica, procura nos padrões universais contábeis
        if ($categoria === null) {
            foreach (self::PADROES_PADRAO as $termo => $meta) {
                if (str_contains($descricao, $termo)) {
                    $categoria = $meta['categoria'];
                    $debito = $meta['debito_sugerido'];
                    $credito = $meta['credito_sugerido'];
                    $pontuacao = 88;
                    break;
                }
            }
        }

        if ($categoria === null) {
            return [
                'recorrente' => false,
                'categoria' => 'Outros / Avulso',
                'debito_sugerido' => null,
                'credito_sugerido' => null,
                'pontuacao' => 20,
                'confianca' => 'baixa',
                'automatica' => false,
                'desvio_detectado' => false,
                'card' => 'Transação avulsa sem padrão recorrente identificado. Sugerida conciliação manual.',
                'modelo' => 'deterministico',
                'agente' => self::contrato()['id'],
            ];
        }

        // 3. Avaliação de histórico para detectar anomalias de valor
        $desvio = false;
        $motivoDesvio = '';
        if (!empty($historico)) {
            $soma = 0;
            $contagem = 0;
            foreach ($historico as $h) {
                $v = abs((int) ($h['valor_centavos'] ?? 0));
                if ($v > 0) {
                    $soma += $v;
                    $contagem++;
                }
            }
            if ($contagem > 0) {
                $media = (int) round($soma / $contagem);
                $diferenca = abs(abs($valor) - $media);
                $percentualVariacao = ($media > 0) ? ($diferenca / $media) * 100 : 0;

                // Variação acima de 25% gera alerta de anomalia e reduz a confiança
                if ($percentualVariacao > 25.0) {
                    $desvio = true;
                    $pontuacao = max(45, $pontuacao - 25);
                    $motivoDesvio = 'Variação de ' . round($percentualVariacao, 1) . '% em relação à média habitual de ' . Format::money($media) . '.';
                } else {
                    $pontuacao = min(98, $pontuacao + 4);
                }
            }
        }

        $confiancaNivel = match (true) {
            $pontuacao >= 85 => 'alta',
            $pontuacao >= 70 => 'media',
            default => 'baixa',
        };

        $automatica = ($pontuacao >= 85 && !$desvio);

        if ($automatica) {
            $card = "Gasto recorrente de {$categoria} classificado com confiança de {$pontuacao}%. Sugestão de partida: [D] {$debito} / [C] {$credito}.";
        } elseif ($desvio) {
            $card = "Atenção: saída de " . Format::money(abs($valor)) . " para '{$transacao['descricao']}' se assemelha a {$categoria}, mas apresentou desvio incomum. {$motivoDesvio} Requer validação do contador.";
        } else {
            $card = "Lançamento identificado como {$categoria} (confiança {$pontuacao}%). Sugerido revisar antes de efetivar no razão.";
        }

        return [
            'recorrente' => true,
            'categoria' => $categoria,
            'debito_sugerido' => $debito,
            'credito_sugerido' => $credito,
            'pontuacao' => $pontuacao,
            'confianca' => $confiancaNivel,
            'automatica' => $automatica,
            'desvio_detectado' => $desvio,
            'card' => $card,
            'modelo' => 'deterministico',
            'agente' => self::contrato()['id'],
        ];
    }
}
