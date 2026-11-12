<?php

declare(strict_types=1);

namespace App\App;

use App\Core\Format;

/**
 * Motor preditivo e estocástico de tesouraria e fluxo de caixa.
 * Projeta curvas de saldo para 30, 60 e 90 dias com cenários de estresse,
 * detecta gargalos de liquidez para pagamento de tributos e folha,
 * e emite recomendações preventivas para a saúde financeira da empresa.
 */
final class TesourariaEstocasticaEngine
{
    /**
     * @param array{
     *     saldo_inicial_centavos: int,
     *     recebimentos_previstos: list<array{valor_centavos:int,dias_ate_vencimento:int,cliente?:string}>,
     *     pagamentos_compulsorios: list<array{valor_centavos:int,dias_ate_vencimento:int,descricao:string,categoria?:string}>,
     *     taxa_inadimplencia_percentual?: float|string,
     * } $dados
     * @return array<string, mixed>
     */
    public function projetar(array $dados): array
    {
        $saldoInicial = (int) ($dados['saldo_inicial_centavos'] ?? 0);
        $taxaInadimplencia = max(0.0, min(50.0, (float) ($dados['taxa_inadimplencia_percentual'] ?? 6.5)));
        $fatorRecebimentoProvavel = 1.0 - ($taxaInadimplencia / 100.0);
        $fatorRecebimentoEstresse = max(0.4, 1.0 - (($taxaInadimplencia * 1.6) / 100.0));

        $recebimentos = $dados['recebimentos_previstos'] ?? [];
        $pagamentos = $dados['pagamentos_compulsorios'] ?? [];

        $horizontes = [30, 60, 90];
        $projecoes = [];
        $gargalosDetectados = [];

        foreach ($horizontes as $dias) {
            $totalEntradasNominal = 0;
            $totalSaidas = 0;
            $saidasDetalhadas = [];

            foreach ($recebimentos as $rec) {
                $prazo = (int) ($rec['dias_ate_vencimento'] ?? 0);
                if ($prazo <= $dias) {
                    $totalEntradasNominal += (int) ($rec['valor_centavos'] ?? 0);
                }
            }

            foreach ($pagamentos as $pag) {
                $prazo = (int) ($pag['dias_ate_vencimento'] ?? 0);
                if ($prazo <= $dias) {
                    $vlr = (int) ($pag['valor_centavos'] ?? 0);
                    $totalSaidas += $vlr;
                    $saidasDetalhadas[] = $pag;
                }
            }

            // Cenário Provável
            $entradasProvaveis = (int) round($totalEntradasNominal * $fatorRecebimentoProvavel);
            $saldoProvavel = $saldoInicial + $entradasProvaveis - $totalSaidas;

            // Cenário Estresse (Stress Test)
            $entradasEstresse = (int) round($totalEntradasNominal * $fatorRecebimentoEstresse);
            // No teste de estresse, contingências adicionais de 5% sobre despesas
            $saidasEstresse = (int) round($totalSaidas * 1.05);
            $saldoEstresse = $saldoInicial + $entradasEstresse - $saidasEstresse;

            // Cenário Otimista
            $saldoOtimista = $saldoInicial + $totalEntradasNominal - $totalSaidas;

            // Avaliação de Gargalo
            $statusLiquidez = 'liquidez_segura';
            if ($saldoProvavel < 0) {
                $statusLiquidez = 'risco_critico';
                $gargalosDetectados[] = [
                    'horizonte_dias' => $dias,
                    'deficit_provavel_centavos' => abs($saldoProvavel),
                    'mensagem' => "Alerta crítico em {$dias} dias: previsão de déficit de caixa de " . Format::money(abs($saldoProvavel)) . ".",
                ];
            } elseif ($saldoEstresse < 0) {
                $statusLiquidez = 'alerta_moderado';
                $gargalosDetectados[] = [
                    'horizonte_dias' => $dias,
                    'deficit_estresse_centavos' => abs($saldoEstresse),
                    'mensagem' => "Alerta preventivo em {$dias} dias: em cenário de estresse, o caixa pode ter déficit de " . Format::money(abs($saldoEstresse)) . ".",
                ];
            }

            $projecoes[$dias] = [
                'horizonte_dias' => $dias,
                'entradas_nominais_centavos' => $totalEntradasNominal,
                'entradas_provaveis_centavos' => $entradasProvaveis,
                'saidas_centavos' => $totalSaidas,
                'saldo_otimista_centavos' => $saldoOtimista,
                'saldo_provavel_centavos' => $saldoProvavel,
                'saldo_estresse_centavos' => $saldoEstresse,
                'status_liquidez' => $statusLiquidez,
            ];
        }

        // Resumo Executivo em linguagem humana para o empresário
        $proj30 = $projecoes[30];
        $recomendacoes = [];

        if ($proj30['saldo_provavel_centavos'] < 0) {
            $recomendacoes[] = 'Antecipar recebíveis de notas fiscais com clientes com baixo índice de inadimplência.';
            $recomendacoes[] = 'Negociar com fornecedores prorrogação de pagamentos para não comprometer o DAS e a folha.';
            $resumoLeigo = "Atenção: Nos próximos 30 dias, há previsão de déficit de "
                . Format::money(abs($proj30['saldo_provavel_centavos']))
                . " considerando as saídas de " . Format::money($proj30['saidas_centavos'])
                . " frente às entradas prováveis de " . Format::money($proj30['entradas_provaveis_centavos']) . ".";
        } elseif ($proj30['saldo_estresse_centavos'] < 0) {
            $recomendacoes[] = 'Manter reserva de contingência e monitorar de perto as cobranças em aberto.';
            $resumoLeigo = "O caixa nos próximos 30 dias é positivo (" . Format::money($proj30['saldo_provavel_centavos'])
                . "), porém em caso de atraso de clientes o saldo pode ficar negativo em "
                . Format::money(abs($proj30['saldo_estresse_centavos'])) . ".";
        } else {
            $recomendacoes[] = 'Caixa robusto. Recomendado aplicar o excedente em liquidez diária ou amortizar juros.';
            $resumoLeigo = "Fluxo de caixa saudável para os próximos 30 dias, com saldo positivo estimado em "
                . Format::money($proj30['saldo_provavel_centavos']) . ".";
        }

        return [
            'saldo_inicial_centavos' => $saldoInicial,
            'taxa_inadimplencia_aplicada' => $taxaInadimplencia . '%',
            'projecoes_por_horizonte' => $projecoes,
            'gargalos' => $gargalosDetectados,
            'status_geral' => $proj30['status_liquidez'],
            'resumo_leigo' => $resumoLeigo,
            'recomendacoes' => $recomendacoes,
        ];
    }
}
