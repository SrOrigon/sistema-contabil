<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Format;
use App\Core\Money;

/**
 * Simulador de Planejamento Tributário Dinâmico:
 * Compara cenários de crescimento de faturamento e avalia a melhor opção tributária:
 * Simples Nacional Unificado, Simples Híbrido (IBS/CBS por fora), Lucro Presumido e Lucro Real.
 */
final class PlanejamentoTributarioAvancado
{
    public function __construct(
        private readonly SimplesNacionalCalculator $simplesCalc = new SimplesNacionalCalculator(),
        private readonly IbsCbsCalculator $ibsCbsCalc = new IbsCbsCalculator(),
    ) {
    }

    /**
     * @param array{
     *     receita_mensal_centavos: int,
     *     crescimento_percentual?: float|string,
     *     rbt12_centavos: int,
     *     anexo_simples?: string,
     *     folha_mensal_centavos?: int,
     *     compras_insumos_centavos?: int,
     *     percentual_vendas_pj?: float|string,
     *     margem_lucro_percentual?: float|string,
     *     aliquota_cbs?: string,
     *     aliquota_ibs?: string,
     * } $parametros
     * @return array<string, mixed>
     */
    public function simular(array $parametros): array
    {
        $receitaBase = (int) ($parametros['receita_mensal_centavos'] ?? 0);
        $crescimento = (float) ($parametros['crescimento_percentual'] ?? 0.0);
        $fatorCrescimento = 1.0 + ($crescimento / 100.0);
        $receitaProjetada = (int) round($receitaBase * $fatorCrescimento);

        $rbt12Atual = (int) ($parametros['rbt12_centavos'] ?? 0);
        // RBT12 projetada: substitui um mês pelo projetado ou ajusta proporcionalmente
        $rbt12Projetada = (int) round($rbt12Atual * $fatorCrescimento);

        $anexo = (string) ($parametros['anexo_simples'] ?? 'I');
        $folhaMensal = (int) round(((int) ($parametros['folha_mensal_centavos'] ?? 0)) * $fatorCrescimento);
        $compras = (int) round(((int) ($parametros['compras_insumos_centavos'] ?? 0)) * $fatorCrescimento);
        $percentualPj = min(100.0, max(0.0, (float) ($parametros['percentual_vendas_pj'] ?? 50.0)));
        $margemLucro = (float) ($parametros['margem_lucro_percentual'] ?? 20.0);

        $aliquotaCbs = (string) ($parametros['aliquota_cbs'] ?? '8.80');
        $aliquotaIbs = (string) ($parametros['aliquota_ibs'] ?? '17.70');

        $receitaPj = (int) round($receitaProjetada * ($percentualPj / 100.0));

        // 1. Cenário: Simples Nacional Unificado
        $rbt12Reais = Money::rate(Format::currency($rbt12Projetada));
        $receitaReais = Money::rate(Format::currency($receitaProjetada));
        $folhaReais = Money::rate(Format::currency($folhaMensal));

        $calculoSimples = $this->simplesCalc->calcular($anexo, $rbt12Reais, $receitaReais, $folhaReais);
        $tributoSimplesCentavos = (int) $calculoSimples['das_centavos'];
        $aliquotaEfetivaSimples = (float) $calculoSimples['aliquota_efetiva'];

        // 2. Cenário: Simples Híbrido (destaque de IBS/CBS por fora na saída B2B)
        $partilha = $calculoSimples['partilha_centavos'];
        $icmsIssNoDas = (int) ($partilha['ICMS'] ?? 0) + (int) ($partilha['ISS'] ?? 0);
        $dasSemIcmsIss = max(0, $tributoSimplesCentavos - $icmsIssNoDas);

        // Apuração de IBS e CBS sobre vendas PJ com crédito sobre compras
        $debitoIbsCbs = $this->ibsCbsCalc->calcular(Money::rate(Format::currency($receitaPj)), $aliquotaIbs, $aliquotaCbs);
        $creditoIbsCbs = $this->ibsCbsCalc->calcular(Money::rate(Format::currency($compras)), $aliquotaIbs, $aliquotaCbs);

        $ibsLiquido = max(0, $debitoIbsCbs['ibs_centavos'] - $creditoIbsCbs['ibs_centavos']);
        $cbsLiquido = max(0, $debitoIbsCbs['cbs_centavos'] - $creditoIbsCbs['cbs_centavos']);
        $tributoHibridoCentavos = $dasSemIcmsIss + $ibsLiquido + $cbsLiquido;
        $aliquotaEfetivaHibrido = ($receitaProjetada > 0) ? ($tributoHibridoCentavos / $receitaProjetada) * 100 : 0.0;
        $creditoGeradoParaClientes = $debitoIbsCbs['total_centavos'];

        // 3. Cenário: Lucro Presumido
        // Presunção base: 8% para comércio ou 32% para serviços
        $basePresumidaPercentual = ($anexo === 'I' || $anexo === 'II') ? 0.08 : 0.32;
        $basePresumida = (int) round($receitaProjetada * $basePresumidaPercentual);

        // IRPJ: 15% + 10% s/ excedente mensal de R$ 20.000 (2.000.000 centavos)
        $irpjNormal = (int) round($basePresumida * 0.15);
        $irpjAdicional = ($basePresumida > 2000000) ? (int) round(($basePresumida - 2000000) * 0.10) : 0;
        $irpj = $irpjNormal + $irpjAdicional;

        // CSLL: 9% sobre a base (ou 12% serviços)
        $csll = (int) round($basePresumida * 0.09);

        // PIS/COFINS (ou transição IBS/CBS): ~3.65% cumulativo tradicional + ICMS/ISS médio ~5%
        $pisCofinsPresumido = (int) round($receitaProjetada * 0.0365);
        $icmsIssPresumido = (int) round($receitaProjetada * 0.05);

        // CPP patronal sobre a folha no Presumido (20% INSS patronal + ~5.8% terceiros)
        $encargosFolhaPresumido = (int) round($folhaMensal * 0.258);

        $tributoPresumidoCentavos = $irpj + $csll + $pisCofinsPresumido + $icmsIssPresumido + $encargosFolhaPresumido;
        $aliquotaEfetivaPresumido = ($receitaProjetada > 0) ? ($tributoPresumidoCentavos / $receitaProjetada) * 100 : 0.0;

        // 4. Cenário: Lucro Real (estimado com margem de lucro informada)
        $lucroAntesImpostos = (int) round($receitaProjetada * ($margemLucro / 100.0));
        $irpjReal = (int) round(max(0, $lucroAntesImpostos) * 0.15);
        $csllReal = (int) round(max(0, $lucroAntesImpostos) * 0.09);
        $ibsCbsReal = max(0, $debitoIbsCbs['total_centavos'] - $creditoIbsCbs['total_centavos']);
        $tributoRealCentavos = $irpjReal + $csllReal + $ibsCbsReal + $encargosFolhaPresumido;
        $aliquotaEfetivaReal = ($receitaProjetada > 0) ? ($tributoRealCentavos / $receitaProjetada) * 100 : 0.0;

        // Comparação de resultados
        $cenarios = [
            'simples_unificado' => [
                'nome' => 'Simples Nacional (DAS Tradicional)',
                'tributo_centavos' => $tributoSimplesCentavos,
                'aliquota_efetiva' => round($aliquotaEfetivaSimples, 2),
                'credito_gerado_pj_centavos' => 0,
                'vantagem_b2b' => 'Baixa: Não transfere créditos significativos de IBS/CBS aos clientes.',
            ],
            'simples_hibrido' => [
                'nome' => 'Simples Híbrido (Reforma Tributária)',
                'tributo_centavos' => $tributoHibridoCentavos,
                'aliquota_efetiva' => round($aliquotaEfetivaHibrido, 2),
                'credito_gerado_pj_centavos' => $creditoGeradoParaClientes,
                'vantagem_b2b' => 'Alta: Transfere crédito pleno de IBS/CBS para clientes PJ (' . Format::money($creditoGeradoParaClientes) . ').',
            ],
            'lucro_presumido' => [
                'nome' => 'Lucro Presumido',
                'tributo_centavos' => $tributoPresumidoCentavos,
                'aliquota_efetiva' => round($aliquotaEfetivaPresumido, 2),
                'credito_gerado_pj_centavos' => (int) round($receitaPj * 0.0365),
                'vantagem_b2b' => 'Média: Crédito restrito de PIS/COFINS cumulativo.',
            ],
            'lucro_real' => [
                'nome' => 'Lucro Real',
                'tributo_centavos' => $tributoRealCentavos,
                'aliquota_efetiva' => round($aliquotaEfetivaReal, 2),
                'credito_gerado_pj_centavos' => $debitoIbsCbs['total_centavos'],
                'vantagem_b2b' => 'Alta: Crédito pleno não-cumulativo.',
            ],
        ];

        // Determinação do melhor cenário pelo menor desembolso financeiro
        $melhorChave = 'simples_unificado';
        $menorValor = $tributoSimplesCentavos;
        foreach ($cenarios as $chave => $dadosCenario) {
            if ($dadosCenario['tributo_centavos'] < $menorValor && $dadosCenario['tributo_centavos'] > 0) {
                $menorValor = $dadosCenario['tributo_centavos'];
                $melhorChave = $chave;
            }
        }

        $vencedor = $cenarios[$melhorChave];

        $resumoLeigo = "Para uma receita mensal de " . Format::money($receitaProjetada)
            . ($crescimento > 0 ? " (crescimento de {$crescimento}%)" : '')
            . ", a melhor opção tributária é **{$vencedor['nome']}**, com recolhimento mensal estimado em "
            . Format::money($vencedor['tributo_centavos']) . " (alíquota efetiva de {$vencedor['aliquota_efetiva']}%)."
            . " Economia mensal estimada de " . Format::money(abs($tributoSimplesCentavos - $vencedor['tributo_centavos']))
            . " frente ao regime tradicional.";

        return [
            'receita_base_centavos' => $receitaBase,
            'crescimento_percentual' => $crescimento,
            'receita_projetada_centavos' => $receitaProjetada,
            'cenario_recomendado' => $melhorChave,
            'nome_recomendado' => $vencedor['nome'],
            'cenarios' => $cenarios,
            'resumo_leigo' => $resumoLeigo,
        ];
    }
}
