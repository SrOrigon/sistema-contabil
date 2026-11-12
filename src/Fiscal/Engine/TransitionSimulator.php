<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Format;
use App\Core\Money;
use App\Core\SimplesLimitExceededException;

final class TransitionSimulator
{
    public function __construct(
        private readonly SimplesNacionalCalculator $simples = new SimplesNacionalCalculator(),
        private readonly IbsCbsCalculator $ibsCbs = new IbsCbsCalculator(),
    ) {
    }

    /**
     * @param array<string, mixed> $entrada
     * @return array<string, mixed>
     */
    public function simular(array $entrada): array
    {
        $ano = (int) ($entrada['ano'] ?? 0);
        $regime = (string) ($entrada['regime'] ?? 'simples');
        $receita = Money::rate((string) ($entrada['receita_mes'] ?? '0'));
        $receitaPj = Money::rate((string) ($entrada['receita_pj'] ?? '0'));
        $receitaPf = Money::rate((string) ($entrada['receita_pf'] ?? '0'));
        $baseCredito = Money::rate((string) ($entrada['base_credito'] ?? '0'));
        $reducao = (string) ($entrada['reducao_saidas'] ?? '0');
        $aliquotaCbs = (string) ($entrada['aliquota_cbs'] ?? '0');
        $aliquotaIbs = (string) ($entrada['aliquota_ibs'] ?? '0');
        $fatorIcmsIss = Money::rate((string) ($entrada['fator_icms_iss'] ?? '1'));
        $pisVigente = (bool) ($entrada['pis_cofins_vigente'] ?? false);
        $cbsCompensavel = (bool) ($entrada['cbs_compensavel'] ?? false);
        $ibsCompensavel = (bool) ($entrada['ibs_compensavel'] ?? false);

        $debitoInformado = isset($entrada['debito_ibs_centavos']) && $entrada['debito_ibs_centavos'] !== null;
        if ($debitoInformado) {
            $debitoIbs = (int) $entrada['debito_ibs_centavos'];
            $debitoCbs = (int) $entrada['debito_cbs_centavos'];
            $creditoIbs = (int) ($entrada['credito_ibs_centavos'] ?? 0);
            $creditoCbs = (int) ($entrada['credito_cbs_centavos'] ?? 0);
            $debitoPjIbs = (int) ($entrada['debito_pj_ibs_centavos'] ?? 0);
            $debitoPjCbs = (int) ($entrada['debito_pj_cbs_centavos'] ?? 0);
        } else {
            $saida = $this->ibsCbs->calcular($receita, $aliquotaIbs, $aliquotaCbs, $reducao, $reducao);
            $credito = $this->ibsCbs->calcular($baseCredito, $aliquotaIbs, $aliquotaCbs, '0', '0');
            $pj = $this->ibsCbs->calcular($receitaPj, $aliquotaIbs, $aliquotaCbs, $reducao, $reducao);
            $debitoIbs = $saida['ibs_centavos'];
            $debitoCbs = $saida['cbs_centavos'];
            $creditoIbs = $credito['ibs_centavos'];
            $creditoCbs = $credito['cbs_centavos'];
            $debitoPjIbs = $pj['ibs_centavos'];
            $debitoPjCbs = $pj['cbs_centavos'];
        }

        $das = null;
        $dasAjustado = 0;
        $aviso = null;
        $partilha = [];
        if ($regime === 'simples') {
            try {
                $das = $this->simples->calcular(
                    (string) ($entrada['anexo'] ?? 'I'),
                    (string) ($entrada['rbt12'] ?? '0'),
                    $receita,
                    isset($entrada['folha12']) ? (string) $entrada['folha12'] : null,
                );
                $partilha = $das['partilha_centavos'];
                $dasAjustado = $this->ajustarDas($partilha, $pisVigente, $fatorIcmsIss);
            } catch (SimplesLimitExceededException $erro) {
                $aviso = $erro->getMessage();
            }
        } elseif ($regime === 'mei') {
            $dasAjustado = (int) ($entrada['das_mei_centavos'] ?? 0);
            $rbt = Money::rate((string) ($entrada['rbt12'] ?? '0'));
            if (bccomp($rbt, '81000', 2) > 0) {
                $aviso = 'A receita informada supera o teto anual de R$ 81.000,00 do MEI.';
            }
        }

        $unificado = $this->cenario(false, $dasAjustado, $debitoIbs, $debitoCbs, 0, 0, $partilha, $cbsCompensavel, $ibsCompensavel);
        $hibrido = $this->cenario(true, $dasAjustado, $debitoIbs, $debitoCbs, $creditoIbs, $creditoCbs, $partilha, $cbsCompensavel, $ibsCompensavel);
        $percentualPj = bccomp($receita, '0', 2) > 0 ? bcmul(bcdiv($receitaPj, $receita, 8), '100', 2) : '0.00';
        $debitoTotal = $debitoIbs + $debitoCbs;
        $creditoTotal = $creditoIbs + $creditoCbs;
        $abate = $debitoTotal > 0 ? bcmul(bcdiv((string) min($creditoTotal, $debitoTotal), (string) $debitoTotal, 8), '100', 2) : '0.00';
        $split = $debitoPjIbs + $debitoPjCbs;

        return [
            'ano' => $ano,
            'regime' => $regime,
            'elegivel' => $aviso === null || $regime !== 'simples',
            'aviso' => $aviso,
            'das' => $das,
            'das_ajustado_centavos' => $dasAjustado,
            'debito_ibs_centavos' => $debitoIbs,
            'debito_cbs_centavos' => $debitoCbs,
            'credito_ibs_centavos' => $creditoIbs,
            'credito_cbs_centavos' => $creditoCbs,
            'percentual_pj' => $percentualPj,
            'creditos_abatem' => $abate,
            'split_centavos' => $split,
            'unificado' => $unificado,
            'hibrido' => $hibrido,
            'recomendacao' => $this->recomendacao($regime, $unificado, $hibrido, $percentualPj, $abate, $split, $entrada),
            'memoria' => $this->memoria($regime, $das, $dasAjustado, $debitoIbs, $debitoCbs, $creditoIbs, $creditoCbs, $unificado, $hibrido, $split, $percentualPj, (string) ($entrada['natureza_aliquota'] ?? 'legal')),
        ];
    }

    /** @param array<string, int> $partilha */
    private function ajustarDas(array $partilha, bool $pisVigente, string $fatorIcmsIss): int
    {
        $total = array_sum($partilha);
        if (!$pisVigente) {
            $total -= ($partilha['PIS'] ?? 0) + ($partilha['COFINS'] ?? 0);
        }
        foreach (['ICMS', 'ISS'] as $tributo) {
            $original = $partilha[$tributo] ?? 0;
            $mantido = Money::ofCents($original)->percent(bcmul($fatorIcmsIss, '100', 6))->cents();
            $total -= ($original - $mantido);
        }
        return max(0, $total);
    }

    /** @param array<string, int> $partilha
     *  @return array{imposto_centavos:int, compensacao_centavos:int, caixa_centavos:int, saldo_credor_centavos:int}
     */
    private function cenario(bool $aproveitaCredito, int $dasAjustado, int $debitoIbs, int $debitoCbs, int $creditoIbs, int $creditoCbs, array $partilha, bool $cbsCompensavel, bool $ibsCompensavel): array
    {
        $ibsDevido = $aproveitaCredito ? max(0, $debitoIbs - $creditoIbs) : $debitoIbs;
        $cbsDevido = $aproveitaCredito ? max(0, $debitoCbs - $creditoCbs) : $debitoCbs;
        $saldo = ($aproveitaCredito ? max(0, $creditoIbs - $debitoIbs) : 0) + ($aproveitaCredito ? max(0, $creditoCbs - $debitoCbs) : 0);
        $compensacao = 0;
        if ($ibsCompensavel) {
            $teto = ($partilha['ICMS'] ?? 0) + ($partilha['ISS'] ?? 0);
            $compensacao += min($ibsDevido, $teto);
        }
        if ($cbsCompensavel) {
            $teto = ($partilha['PIS'] ?? 0) + ($partilha['COFINS'] ?? 0);
            $compensacao += min($cbsDevido, $teto);
        }
        $caixaTributo = max(0, $ibsDevido + $cbsDevido - $compensacao);
        return [
            'imposto_centavos' => $ibsDevido + $cbsDevido,
            'compensacao_centavos' => $compensacao,
            'caixa_centavos' => $dasAjustado + $caixaTributo,
            'saldo_credor_centavos' => $saldo,
        ];
    }

    /** @param array<string, mixed> $unificado @param array<string, mixed> $hibrido @param array<string, mixed> $entrada */
    private function recomendacao(string $regime, array $unificado, array $hibrido, string $percentualPj, string $abate, int $split, array $entrada): string
    {
        $competencia = (string) ($entrada['competencia_rotulo'] ?? 'desta competência');
        if ($regime === 'mei') {
            return 'O MEI não escolhe entre ficar no Simples ou destacar o imposto novo. A comparação mede o imposto destacado em ' . $competencia . ' e o efeito do DAS-MEI informado. Confirme o enquadramento antes de mudar.';
        }
        if ($regime !== 'simples') {
            $economia = $unificado['caixa_centavos'] - $hibrido['caixa_centavos'];
            return 'Fora do Simples não há essa escolha. O crédito das compras cobre ' . Format::percentual($abate) . ' do imposto novo em ' . $competencia . '. A diferença frente a um cálculo sem crédito é de ' . Format::money($economia) . '.';
        }
        if ($unificado['caixa_centavos'] === 0 && $hibrido['caixa_centavos'] === 0 && ($entrada['aviso'] ?? null)) {
            return (string) $entrada['aviso'];
        }
        $economia = $unificado['caixa_centavos'] - $hibrido['caixa_centavos'];
        $lado = $economia > 0 ? 'híbrido' : ($economia < 0 ? 'unificado' : 'indiferente em caixa');
        $texto = 'Em ' . $competencia . ', ' . Format::percentual($percentualPj) . ' das vendas foram para empresa. O crédito das compras cobre ' . Format::percentual($abate) . ' do imposto novo. ';
        if ($lado === 'híbrido') {
            $texto .= 'Destacar o imposto reduz o desembolso em ' . Format::money($economia) . ' neste mês e deixa crédito para o cliente empresa. ';
        } elseif ($lado === 'unificado') {
            $texto .= 'Ficar no Simples desembolsa ' . Format::money(abs($economia)) . ' a menos, porque o crédito das compras não compensa o imposto destacado. ';
        } else {
            $texto .= 'Os dois caminhos desembolsam o mesmo neste mês. ';
        }
        if (bccomp($percentualPj, '30', 2) >= 0 && $split > 0) {
            $texto .= 'Como boa parte das vendas é para empresa, destacar o imposto ainda deixa crédito para quem compra. A parte retida nessas vendas é de ' . Format::money($split) . ', efeito de prazo, não de imposto a mais.';
        } elseif ($split > 0) {
            $texto .= 'A parte retida nas vendas para empresa é de ' . Format::money($split) . '.';
        }
        return trim($texto);
    }

    /** @param array<string, int>|null $das @param array<string, mixed> $unificado @param array<string, mixed> $hibrido
     *  @return list<array{rotulo:string, valor:string, nota:string}>
     */
    private function memoria(?string $regime, ?array $das, int $dasAjustado, int $debitoIbs, int $debitoCbs, int $creditoIbs, int $creditoCbs, array $unificado, array $hibrido, int $split, string $percentualPj, string $natureza): array
    {
        $linhas = [];
        if ($das !== null) {
            $linhas[] = ['rotulo' => 'DAS clássico do mês', 'valor' => Format::money((int) $das['das_centavos']), 'nota' => 'Anexo ' . $das['anexo'] . ', faixa ' . $das['faixa'] . ', alíquota efetiva ' . Format::percentual((string) $das['aliquota_efetiva'])];
            foreach ($das['partilha_centavos'] as $tributo => $centavos) {
                $linhas[] = ['rotulo' => 'Partilha ' . $tributo, 'valor' => Format::money((int) $centavos), 'nota' => in_array($tributo, $das['por_fora'], true) ? 'Recolhido fora do DAS nesta faixa.' : 'Dentro do DAS.'];
            }
        }
        $linhas[] = ['rotulo' => 'Guia ajustada ao ano', 'valor' => Format::money($dasAjustado), 'nota' => 'Tira o que já não vale neste ano e aplica a redução de ICMS ou ISS.'];
        $linhas[] = ['rotulo' => 'IBS nas vendas', 'valor' => Format::money($debitoIbs), 'nota' => $natureza === 'estimada' ? 'Alíquota estimada. Dá para ajustar em Escritório.' : 'Alíquota de teste prevista em lei.'];
        $linhas[] = ['rotulo' => 'CBS nas vendas', 'valor' => Format::money($debitoCbs), 'nota' => $natureza === 'estimada' ? 'Alíquota estimada. Dá para ajustar em Escritório.' : 'Alíquota de teste prevista em lei.'];
        $linhas[] = ['rotulo' => 'Crédito de IBS nas compras', 'valor' => Format::money($creditoIbs), 'nota' => 'Vale ao destacar o imposto, ou fora do Simples.'];
        $linhas[] = ['rotulo' => 'Crédito de CBS nas compras', 'valor' => Format::money($creditoCbs), 'nota' => 'Vale ao destacar o imposto, ou fora do Simples.'];
        $linhas[] = ['rotulo' => 'Desembolso no Simples', 'valor' => Format::money((int) $unificado['caixa_centavos']), 'nota' => 'Sem crédito das compras.'];
        $linhas[] = ['rotulo' => 'Desembolso destacando o imposto', 'valor' => Format::money((int) $hibrido['caixa_centavos']), 'nota' => 'Imposto por fora, com crédito das compras.'];
        $linhas[] = ['rotulo' => 'Vendas para empresa', 'valor' => Format::percentual($percentualPj), 'nota' => 'Base do crédito que o cliente pode tomar.'];
        $linhas[] = ['rotulo' => 'Parte retida nas vendas a empresa', 'valor' => Format::money($split), 'nota' => 'Parcela do imposto novo retida no pagamento. É prazo, não imposto a mais.'];
        return $linhas;
    }
}
