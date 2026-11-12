<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Format;

/**
 * Motor determinístico de inteligência fiscal e teses tributárias.
 * Varre operações para identificar recuperação de créditos:
 * 1. Tema 69 STF (Exclusão do ICMS da base do PIS/COFINS)
 * 2. Produtos Monofásicos de PIS/COFINS (LC 123/2006 e Lei 10.147/2000)
 * 3. Exclusão do ISS da base do PIS/COFINS (Tema 118 STF)
 * 4. Créditos não apropriados na transição IBS/CBS
 */
final class TeseTributariaEngine
{
    /** @var list<string> Prefixos de NCM monofásicos clássicos (Farmácia, Cosméticos, Autopeças, Bebidas) */
    private const PREFIXOS_MONOFASICOS = [
        // Farmacêuticos e medicamentos
        '3001', '3002', '3003', '3004', '3005', '3006',
        // Cosméticos, perfumaria e toucador
        '3303', '3304', '3305', '3306', '3307', '3401',
        // Autopeças e pneus
        '4011', '4012', '4013', '8708', '8407', '8408', '8409',
        // Bebidas frias e cervejas
        '2201', '2202', '2203',
    ];

    /**
     * Analisa um item de documento fiscal para detectar oportunidades de créditos ou teses.
     * @param array{
     *     codigo?: string|null,
     *     descricao: string,
     *     ncm?: string|null,
     *     valor_centavos: int,
     *     cfop?: string|null,
     *     cst?: string|null,
     *     icms_destacado_centavos?: int|null,
     *     iss_destacado_centavos?: int|null
     * } $item
     * @param string $regime 'simples', 'presumido' ou 'real'
     * @return array<string, mixed>
     */
    public function analisarItem(array $item, string $regime): array
    {
        $regime = strtolower(trim($regime));
        $valorItem = (int) ($item['valor_centavos'] ?? 0);
        $ncm = preg_replace('/\D/', '', (string) ($item['ncm'] ?? ''));
        $icmsDestacado = (int) ($item['icms_destacado_centavos'] ?? 0);
        $issDestacado = (int) ($item['iss_destacado_centavos'] ?? 0);

        $oportunidades = [];

        // 1. Tese do Século (Tema 69 STF) - Exclusão do ICMS da base do PIS/COFINS
        // Aplicável a Lucro Presumido (3,65%) e Lucro Real (9,25%)
        if (in_array($regime, ['presumido', 'real'], true) && $icmsDestacado > 0) {
            $aliquotaPisCofins = ($regime === 'real') ? 0.0925 : 0.0365;
            $creditoCentavos = (int) round($icmsDestacado * $aliquotaPisCofins);

            if ($creditoCentavos > 0) {
                $oportunidades[] = [
                    'tese_codigo' => 'TEMA_69_STF',
                    'titulo' => 'Exclusão do ICMS da Base do PIS/COFINS (Tema 69 STF)',
                    'base_centavos' => $icmsDestacado,
                    'credito_centavos' => $creditoCentavos,
                    'aliquota_aplicada' => number_format($aliquotaPisCofins * 100, 2, '.', '') . '%',
                    'fundamento' => 'STF, RE 574.706 (Tema 69 da Repercussão Geral). O ICMS destacado não compõe a receita bruta para incidência do PIS e da COFINS.',
                    'tipo_compensacao' => 'PER/DCOMP ou abatimento em DCTFWeb',
                ];
            }
        }

        // 2. Exclusão do ISS da base do PIS/COFINS (Tema 118 STF)
        if (in_array($regime, ['presumido', 'real'], true) && $issDestacado > 0) {
            $aliquotaPisCofins = ($regime === 'real') ? 0.0925 : 0.0365;
            $creditoCentavos = (int) round($issDestacado * $aliquotaPisCofins);

            if ($creditoCentavos > 0) {
                $oportunidades[] = [
                    'tese_codigo' => 'TEMA_118_STF',
                    'titulo' => 'Exclusão do ISS da Base do PIS/COFINS (Tema 118 STF)',
                    'base_centavos' => $issDestacado,
                    'credito_centavos' => $creditoCentavos,
                    'aliquota_aplicada' => number_format($aliquotaPisCofins * 100, 2, '.', '') . '%',
                    'fundamento' => 'STF, Tema 118 da Repercussão Geral. O ISS recolhido ao município não integra a base de incidência do PIS e da COFINS.',
                    'tipo_compensacao' => 'PER/DCOMP ou ação de repetição de indébito',
                ];
            }
        }

        // 3. Produtos Monofásicos de PIS/COFINS
        $ehMonofasico = false;
        foreach (self::PREFIXOS_MONOFASICOS as $prefixo) {
            if (str_starts_with($ncm, $prefixo)) {
                $ehMonofasico = true;
                break;
            }
        }

        if ($ehMonofasico && $valorItem > 0) {
            if ($regime === 'simples') {
                // No Simples Nacional, monofásicos têm PIS/COFINS zerado na revenda (art. 18, § 4º-A, I da LC 123/2006)
                // A parcela de PIS/COFINS na alíquota do Anexo I fica em torno de 1,7% a 2,2%
                $creditoCentavos = (int) round($valorItem * 0.018); // Estimativa conservadora de 1,8%
                $oportunidades[] = [
                    'tese_codigo' => 'MONOFASICO_SIMPLES',
                    'titulo' => 'Segregação de Monofásicos no Simples Nacional (LC 123/2006)',
                    'base_centavos' => $valorItem,
                    'credito_centavos' => $creditoCentavos,
                    'aliquota_aplicada' => '1.80% (parcela PIS/COFINS no DAS)',
                    'fundamento' => 'Art. 18, § 4º-A, inciso I da LC nº 123/2006 e Solução de Consulta Cosit nº 225/2014. A revenda de produtos sujeitos à tributação concentrada (monofásica) autoriza segregação no PGDAS-D.',
                    'tipo_compensacao' => 'Retificação do PGDAS e restituição administrativa direta pela Receita Federal',
                ];
            } elseif ($regime === 'presumido') {
                // No Lucro Presumido, a revenda a varejo de monofásicos tem alíquota zero de PIS/COFINS (Lei 10.147/2000)
                $creditoCentavos = (int) round($valorItem * 0.0365);
                $oportunidades[] = [
                    'tese_codigo' => 'MONOFASICO_PRESUMIDO',
                    'titulo' => 'Alíquota Zero de PIS/COFINS em Monofásicos (Lei 10.147/2000)',
                    'base_centavos' => $valorItem,
                    'credito_centavos' => $creditoCentavos,
                    'aliquota_aplicada' => '3.65% (PIS 0.65% + COFINS 3%)',
                    'fundamento' => 'Art. 2º da Lei nº 10.147/2000 e Lei nº 10.865/2004. Desoneração de PIS/COFINS na comercialização varejista.',
                    'tipo_compensacao' => 'PER/DCOMP ou estorno de débito em EFD-Contribuições',
                ];
            }
        }

        return [
            'item_descricao' => $item['descricao'],
            'ncm' => $ncm,
            'valor_centavos' => $valorItem,
            'oportunidades_encontradas' => count($oportunidades),
            'oportunidades' => $oportunidades,
        ];
    }

    /**
     * Varre um conjunto de itens de documentos fiscais e consolida o laudo de recuperação tributária.
     * @param list<array<string, mixed>> $itens
     * @param string $regime
     * @return array<string, mixed>
     */
    public function varrerLote(array $itens, string $regime): array
    {
        $totalCreditosCentavos = 0;
        $totalItensAnalisados = count($itens);
        $itensComOportunidade = 0;
        $porTese = [];

        foreach ($itens as $item) {
            $resultadoItem = $this->analisarItem($item, $regime);
            if (!empty($resultadoItem['oportunidades'])) {
                $itensComOportunidade++;
                foreach ($resultadoItem['oportunidades'] as $op) {
                    $codigo = $op['tese_codigo'];
                    $credito = (int) $op['credito_centavos'];
                    $totalCreditosCentavos += $credito;

                    if (!isset($porTese[$codigo])) {
                        $porTese[$codigo] = [
                            'titulo' => $op['titulo'],
                            'fundamento' => $op['fundamento'],
                            'tipo_compensacao' => $op['tipo_compensacao'],
                            'quantidade_itens' => 0,
                            'base_total_centavos' => 0,
                            'credito_total_centavos' => 0,
                        ];
                    }
                    $porTese[$codigo]['quantidade_itens']++;
                    $porTese[$codigo]['base_total_centavos'] += (int) $op['base_centavos'];
                    $porTese[$codigo]['credito_total_centavos'] += $credito;
                }
            }
        }

        if ($totalCreditosCentavos > 0) {
            $resumoLeigo = "Identificamos " . Format::money($totalCreditosCentavos)
                . " em créditos tributários passíveis de recuperação administrativa para sua empresa."
                . " Foram localizados {$itensComOportunidade} itens com pagamento indevido de tributos.";
        } else {
            $resumoLeigo = "Nenhum crédito tributário pendente de recuperação identificado nas operações analisadas.";
        }

        return [
            'total_itens_analisados' => $totalItensAnalisados,
            'itens_com_oportunidade' => $itensComOportunidade,
            'total_creditos_recuperaveis_centavos' => $totalCreditosCentavos,
            'teses_consolidadas' => array_values($porTese),
            'resumo_leigo' => $resumoLeigo,
        ];
    }
}
