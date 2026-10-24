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
            return 'O MEI não exerce a opção híbrida do Simples Nacional ordinário. A comparação abaixo serve para medir o IBS e a CBS destacados em ' . $competencia . ' e o efeito de caixa do DAS-MEI informado. Confirme o enquadramento antes de qualquer migração.';
        }
        if ($regime !== 'simples') {
            $economia = $unificado['caixa_centavos'] - $hibrido['caixa_centavos'];
            return 'Fora do Simples Nacional não há opção entre guia unificada e regime híbrido. O regime normal já é não cumulativo: os créditos de entrada abatem ' . Format::percentual($abate) . ' do IBS/CBS devido em ' . $competencia . '. A diferença de caixa frente a um cálculo sem crédito é de ' . Format::money($economia) . '.';
        }
        if ($unificado['caixa_centavos'] === 0 && $hibrido['caixa_centavos'] === 0 && ($entrada['aviso'] ?? null)) {
            return (string) $entrada['aviso'];
        }
        $economia = $unificado['caixa_centavos'] - $hibrido['caixa_centavos'];
        $lado = $economia > 0 ? 'híbrido' : ($economia < 0 ? 'unificado' : 'indiferente em caixa');
        $texto = 'Em ' . $competencia . ', ' . Format::percentual($percentualPj) . ' da receita foi para pessoa jurídica. Os créditos de compras abatem ' . Format::percentual($abate) . ' do IBS e da CBS devidos. ';
        if ($lado === 'híbrido') {
            $texto .= 'O regime híbrido reduz o desembolso em ' . Format::money($economia) . ' nesta competência e transfere crédito aos clientes pessoa jurídica. ';
        } elseif ($lado === 'unificado') {
            $texto .= 'O regime unificado desembolsa ' . Format::money(abs($economia)) . ' a menos, porque o crédito de entrada não compensa a saída da CBS/IBS da guia. ';
        } else {
            $texto .= 'O desembolso de caixa dos dois regimes é igual nesta competência. ';
        }
        if (bccomp($percentualPj, '30', 2) >= 0 && $split > 0) {
            $texto .= 'Como a carteira pessoa jurídica é relevante, o híbrido ainda permite que os adquirentes aproveitem crédito. A retenção estimada do split payment nas vendas a pessoa jurídica é de ' . Format::money($split) . ', efeito de prazo e não de tributo novo.';
        } elseif ($split > 0) {
            $texto .= 'A retenção estimada do split payment nas vendas a pessoa jurídica é de ' . Format::money($split) . '.';
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
        $linhas[] = ['rotulo' => 'DAS ajustado à transição', 'valor' => Format::money($dasAjustado), 'nota' => 'Remove tributos já extintos no ano e aplica o fator de redução de ICMS/ISS.'];
        $linhas[] = ['rotulo' => 'Débito de IBS nas saídas', 'valor' => Format::money($debitoIbs), 'nota' => $natureza === 'estimada' ? 'Alíquota de referência estimada, editável em Configurações.' : 'Alíquota legal de teste.'];
        $linhas[] = ['rotulo' => 'Débito de CBS nas saídas', 'valor' => Format::money($debitoCbs), 'nota' => $natureza === 'estimada' ? 'Alíquota de referência estimada, editável em Configurações.' : 'Alíquota legal de teste.'];
        $linhas[] = ['rotulo' => 'Crédito de IBS nas entradas', 'valor' => Format::money($creditoIbs), 'nota' => 'Aproveitado somente no híbrido ou no regime normal.'];
        $linhas[] = ['rotulo' => 'Crédito de CBS nas entradas', 'valor' => Format::money($creditoCbs), 'nota' => 'Aproveitado somente no híbrido ou no regime normal.'];
        $linhas[] = ['rotulo' => 'Caixa no regime unificado', 'valor' => Format::money((int) $unificado['caixa_centavos']), 'nota' => 'Sem crédito de entrada. Compensação de teste quando a lei do ano permitir.'];
        $linhas[] = ['rotulo' => 'Caixa no regime híbrido', 'valor' => Format::money((int) $hibrido['caixa_centavos']), 'nota' => 'IBS/CBS por fora, não cumulativo, com saldo credor preservado.'];
        $linhas[] = ['rotulo' => 'Vendas a pessoa jurídica', 'valor' => Format::percentual($percentualPj), 'nota' => 'Base do crédito transferido ao adquirente no híbrido.'];
        $linhas[] = ['rotulo' => 'Retenção estimada do split payment', 'valor' => Format::money($split), 'nota' => 'Parcela do IBS/CBS das vendas a pessoa jurídica retida na liquidação.'];
        return $linhas;
    }
}
