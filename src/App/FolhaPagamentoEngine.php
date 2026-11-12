<?php

declare(strict_types=1);

namespace App\App;

use App\Core\Format;

/**
 * Motor determinístico de cálculo de encargos trabalhistas e provisões contábeis.
 * Calcula INSS progressivo, FGTS, provisões mensais de 13º e férias (1/12 avos)
 * e gera as partidas dobradas para o razonete contábil.
 */
final class FolhaPagamentoEngine
{
    // Faixas INSS oficiais vigentes (valores em centavos)
    private const FAIXA1_TETO = 141200;
    private const FAIXA2_TETO = 266668;
    private const FAIXA3_TETO = 400003;
    private const FAIXA4_TETO = 778602;

    private const FAIXA1_ALIQUOTA = 0.075;
    private const FAIXA2_ALIQUOTA = 0.090;
    private const FAIXA3_ALIQUOTA = 0.120;
    private const FAIXA4_ALIQUOTA = 0.140;

    /**
     * Calcula o INSS do empregado pela tabela progressiva oficial.
     * @return array{inss_centavos:int,aliquota_efetiva:string,faixa:int}
     */
    public function calcularInss(int $salarioBrutoCentavos): array
    {
        if ($salarioBrutoCentavos <= 0) {
            return ['inss_centavos' => 0, 'aliquota_efetiva' => '0.00', 'faixa' => 1];
        }

        $base = min($salarioBrutoCentavos, self::FAIXA4_TETO);
        $totalInss = 0.0;
        $faixa = 1;

        // Faixa 1
        $baseFaixa1 = min($base, self::FAIXA1_TETO);
        $totalInss += $baseFaixa1 * self::FAIXA1_ALIQUOTA;

        // Faixa 2
        if ($base > self::FAIXA1_TETO) {
            $faixa = 2;
            $baseFaixa2 = min($base, self::FAIXA2_TETO) - self::FAIXA1_TETO;
            $totalInss += $baseFaixa2 * self::FAIXA2_ALIQUOTA;
        }

        // Faixa 3
        if ($base > self::FAIXA2_TETO) {
            $faixa = 3;
            $baseFaixa3 = min($base, self::FAIXA3_TETO) - self::FAIXA2_TETO;
            $totalInss += $baseFaixa3 * self::FAIXA3_ALIQUOTA;
        }

        // Faixa 4
        if ($base > self::FAIXA3_TETO) {
            $faixa = 4;
            $baseFaixa4 = $base - self::FAIXA3_TETO;
            $totalInss += $baseFaixa4 * self::FAIXA4_ALIQUOTA;
        }

        $inssCentavos = (int) round($totalInss);
        $aliquotaEfetiva = number_format(($inssCentavos / $salarioBrutoCentavos) * 100, 2, '.', '');

        return [
            'inss_centavos' => $inssCentavos,
            'aliquota_efetiva' => $aliquotaEfetiva,
            'faixa' => $faixa,
        ];
    }

    /**
     * Calcula o FGTS mensal (8% padrão CLT).
     */
    public function calcularFgts(int $salarioBrutoCentavos): int
    {
        if ($salarioBrutoCentavos <= 0) {
            return 0;
        }
        return (int) round($salarioBrutoCentavos * 0.08);
    }

    /**
     * Calcula as provisões mensais de 13º Salário e Férias (+ 1/3) com seus respectivos encargos de FGTS.
     * @return array{decimo_terceiro_centavos:int,fgts_decimo_centavos:int,ferias_centavos:int,fgts_ferias_centavos:int,total_provisoes_centavos:int}
     */
    public function calcularProvisoesMensais(int $salarioBrutoCentavos): array
    {
        if ($salarioBrutoCentavos <= 0) {
            return [
                'decimo_terceiro_centavos' => 0,
                'fgts_decimo_centavos' => 0,
                'ferias_centavos' => 0,
                'fgts_ferias_centavos' => 0,
                'total_provisoes_centavos' => 0,
            ];
        }

        // 1/12 avos do 13º salário
        $decimoTerceiro = (int) round($salarioBrutoCentavos / 12);
        $fgtsDecimo = (int) round($decimoTerceiro * 0.08);

        // 1/12 avos de férias + 1/3 constitucional: (Salário * 4/3) / 12 = Salário / 9
        $feriasComTerco = (int) round(($salarioBrutoCentavos * (4.0 / 3.0)) / 12);
        $fgtsFerias = (int) round($feriasComTerco * 0.08);

        $total = $decimoTerceiro + $fgtsDecimo + $feriasComTerco + $fgtsFerias;

        return [
            'decimo_terceiro_centavos' => $decimoTerceiro,
            'fgts_decimo_centavos' => $fgtsDecimo,
            'ferias_centavos' => $feriasComTerco,
            'fgts_ferias_centavos' => $fgtsFerias,
            'total_provisoes_centavos' => $total,
        ];
    }

    /**
     * Gera o demonstrativo consolidado da folha individual com as partidas contábeis para o razão.
     * @return array<string, mixed>
     */
    public function processar(int $salarioBrutoCentavos, string $nome = 'Colaborador'): array
    {
        $inss = $this->calcularInss($salarioBrutoCentavos);
        $fgts = $this->calcularFgts($salarioBrutoCentavos);
        $provisoes = $this->calcularProvisoesMensais($salarioBrutoCentavos);

        $liquido = max(0, $salarioBrutoCentavos - $inss['inss_centavos']);
        $custoEmpresaTotal = $salarioBrutoCentavos + $fgts + $provisoes['total_provisoes_centavos'];

        // Partidas dobradas para a escrituração contábil
        $partidas = [
            // Salário bruto e líquido
            [
                'tipo' => 'folha_salario',
                'debito' => 'Despesas com Salários e Ordenados',
                'credito' => 'Salários a Pagar',
                'valor_centavos' => $liquido,
                'historico' => "Vlr. líq. salários ref. mês - {$nome}",
            ],
            // Retenção do INSS
            [
                'tipo' => 'retencao_inss',
                'debito' => 'Despesas com Salários e Ordenados',
                'credito' => 'INSS a Recolher',
                'valor_centavos' => $inss['inss_centavos'],
                'historico' => "INSS retido em folha - {$nome}",
            ],
            // FGTS do mês
            [
                'tipo' => 'fgts_mensal',
                'debito' => 'Despesas com FGTS',
                'credito' => 'FGTS a Recolher',
                'valor_centavos' => $fgts,
                'historico' => "FGTS s/ folha - {$nome}",
            ],
            // Provisão de 13º Salário
            [
                'tipo' => 'provisao_13',
                'debito' => 'Despesa com Provisão de 13º Salário',
                'credito' => 'Provisão de 13º Salário a Pagar',
                'valor_centavos' => $provisoes['decimo_terceiro_centavos'] + $provisoes['fgts_decimo_centavos'],
                'historico' => "Provisão 1/12 13º salário e encargos - {$nome}",
            ],
            // Provisão de Férias
            [
                'tipo' => 'provisao_ferias',
                'debito' => 'Despesa com Provisão de Férias',
                'credito' => 'Provisão de Férias a Pagar',
                'valor_centavos' => $provisoes['ferias_centavos'] + $provisoes['fgts_ferias_centavos'],
                'historico' => "Provisão 1/12 férias com 1/3 e encargos - {$nome}",
            ],
        ];

        $resumoLeigo = "Para {$nome} com salário bruto de " . Format::money($salarioBrutoCentavos)
            . ", o valor líquido a pagar é " . Format::money($liquido)
            . " (INSS de " . Format::money($inss['inss_centavos']) . " com alíquota efetiva de {$inss['aliquota_efetiva']}%)."
            . " O custo total da empresa no mês é " . Format::money($custoEmpresaTotal)
            . ", sendo " . Format::money($fgts) . " de FGTS e " . Format::money($provisoes['total_provisoes_centavos'])
            . " guardados em provisões de 13º e férias.";

        return [
            'colaborador' => $nome,
            'salario_bruto_centavos' => $salarioBrutoCentavos,
            'inss_centavos' => $inss['inss_centavos'],
            'inss_aliquota_efetiva' => $inss['aliquota_efetiva'],
            'salario_liquido_centavos' => $liquido,
            'fgts_centavos' => $fgts,
            'provisoes' => $provisoes,
            'custo_empresa_total_centavos' => $custoEmpresaTotal,
            'partidas_contabeis' => $partidas,
            'resumo_leigo' => $resumoLeigo,
        ];
    }
}
