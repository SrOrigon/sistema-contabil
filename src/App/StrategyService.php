<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Orchestrators\TaxStrategyAgent;
use App\Core\Database;
use App\Core\Format;
use App\Core\Money;

final class StrategyService
{
    public function __construct(private readonly Database $db, private readonly SettingsService $config)
    {
    }

    /** @return array<string, mixed> */
    public function coletar(int $empresaId, string $competencia): array
    {
        $documentos = $this->db->all(
            'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND emissao LIKE :competencia AND tipo_operacao IN (\'entrada\', \'saida\')',
            [':empresa' => $empresaId, ':competencia' => $competencia . '%'],
        );
        $receita = 0;
        $receitaPj = 0;
        $receitaPf = 0;
        $compras = 0;
        $debitoIbs = 0;
        $debitoCbs = 0;
        $creditoIbs = 0;
        $creditoCbs = 0;
        $debitoPjIbs = 0;
        $debitoPjCbs = 0;
        foreach ($documentos as $documento) {
            $itens = $this->db->all('SELECT * FROM documento_itens WHERE documento_id = :id', [':id' => $documento['id']]);
            $ibs = 0;
            $cbs = 0;
            foreach ($itens as $item) {
                $ibs += (int) ($item['valor_ibs_esperado_centavos'] ?: $item['valor_ibs_centavos']);
                $cbs += (int) ($item['valor_cbs_esperado_centavos'] ?: $item['valor_cbs_centavos']);
            }
            if ($documento['tipo_operacao'] === 'saida') {
                $receita += (int) $documento['valor_nota_centavos'];
                $debitoIbs += $ibs;
                $debitoCbs += $cbs;
                if (strlen((string) $documento['destinatario_doc']) === 14) {
                    $receitaPj += (int) $documento['valor_nota_centavos'];
                    $debitoPjIbs += $ibs;
                    $debitoPjCbs += $cbs;
                } elseif (strlen((string) $documento['destinatario_doc']) === 11) {
                    $receitaPf += (int) $documento['valor_nota_centavos'];
                }
            } else {
                $compras += (int) $documento['valor_nota_centavos'];
                $creditoIbs += $ibs;
                $creditoCbs += $cbs;
            }
        }
        return [
            'receita_mes' => Money::ofCents($receita)->toDecimal(),
            'receita_pj' => Money::ofCents($receitaPj)->toDecimal(),
            'receita_pf' => Money::ofCents($receitaPf)->toDecimal(),
            'base_credito' => Money::ofCents($compras)->toDecimal(),
            'debito_ibs_centavos' => $debitoIbs,
            'debito_cbs_centavos' => $debitoCbs,
            'credito_ibs_centavos' => $creditoIbs,
            'credito_cbs_centavos' => $creditoCbs,
            'debito_pj_ibs_centavos' => $debitoPjIbs,
            'debito_pj_cbs_centavos' => $debitoPjCbs,
            'documentos' => count($documentos),
        ];
    }

    /** @param array<string, string> $formulario @return array<string, mixed> */
    public function simular(array $empresa, array $formulario): array
    {
        $competencia = $formulario['competencia'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            $competencia = date('Y-m');
        }
        $coletado = $this->coletar((int) $empresa['id'], $competencia);
        $ano = (int) substr($competencia, 0, 4);
        $aliquota = $this->config->aliquotaDoAno($ano);
        $usaDocumentos = ($formulario['origem'] ?? 'documentos') === 'documentos' && $coletado['documentos'] > 0;
        $entrada = [
            'ano' => $ano,
            'regime' => $empresa['regime'],
            'anexo' => $formulario['anexo'] ?? $empresa['anexo_simples'],
            'rbt12' => $this->decimal($formulario['rbt12'] ?? '0'),
            'receita_mes' => $usaDocumentos ? $coletado['receita_mes'] : $this->decimal($formulario['receita_mes'] ?? '0'),
            'folha12' => $this->decimal($formulario['folha12'] ?? '0'),
            'receita_pj' => $usaDocumentos ? $coletado['receita_pj'] : $this->decimal($formulario['receita_pj'] ?? '0'),
            'receita_pf' => $usaDocumentos ? $coletado['receita_pf'] : '0',
            'base_credito' => $usaDocumentos ? $coletado['base_credito'] : $this->decimal($formulario['base_credito'] ?? '0'),
            'reducao_saidas' => $this->decimal($formulario['reducao_saidas'] ?? '0'),
            'aliquota_cbs' => (string) $aliquota['cbs'],
            'aliquota_ibs' => (string) $aliquota['ibs'],
            'pis_cofins_vigente' => (int) $aliquota['pis_cofins_vigente'] === 1,
            'fator_icms_iss' => (string) $aliquota['fator_icms_iss'],
            'cbs_compensavel' => (int) $aliquota['cbs_compensavel'] === 1,
            'ibs_compensavel' => (int) $aliquota['ibs_compensavel'] === 1,
            'natureza_aliquota' => (string) $aliquota['natureza'],
            'competencia_rotulo' => $this->rotuloCompetencia($competencia),
            'das_mei_centavos' => Format::parseMoney($formulario['das_mei'] ?? '0'),
        ];
        if ($usaDocumentos) {
            $entrada['debito_ibs_centavos'] = $coletado['debito_ibs_centavos'];
            $entrada['debito_cbs_centavos'] = $coletado['debito_cbs_centavos'];
            $entrada['credito_ibs_centavos'] = $coletado['credito_ibs_centavos'];
            $entrada['credito_cbs_centavos'] = $coletado['credito_cbs_centavos'];
            $entrada['debito_pj_ibs_centavos'] = $coletado['debito_pj_ibs_centavos'];
            $entrada['debito_pj_cbs_centavos'] = $coletado['debito_pj_cbs_centavos'];
        }
        $resultado = (new TaxStrategyAgent())->simular($entrada);
        $resultado['competencia'] = $competencia;
        $resultado['origem'] = $usaDocumentos ? 'documentos' : 'manual';
        $resultado['coletado'] = $coletado;
        $resultado['observacao_aliquota'] = (string) $aliquota['observacao'];
        $this->db->insert('simulacoes', [
            'empresa_id' => $empresa['id'],
            'competencia' => $competencia,
            'memoria' => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'recomendacao' => (string) $resultado['recomendacao'],
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
        $this->db->insert('agent_logs', [
            'empresa_id' => $empresa['id'],
            'agente' => 'estrategista-tributario',
            'acao' => 'simular_carga_hibrido_vs_unificado',
            'entrada' => json_encode(['competencia' => $competencia, 'origem' => $resultado['origem']], JSON_UNESCAPED_UNICODE),
            'saida' => json_encode(['recomendacao' => $resultado['recomendacao']], JSON_UNESCAPED_UNICODE),
            'ferramentas' => 'simular_carga_hibrido_vs_unificado',
            'modelo' => 'deterministico',
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        return $resultado;
    }

    public function ultima(int $empresaId): ?array
    {
        $linha = $this->db->one('SELECT * FROM simulacoes WHERE empresa_id = :empresa ORDER BY id DESC LIMIT 1', [':empresa' => $empresaId]);
        if ($linha === null) {
            return null;
        }
        $memoria = json_decode((string) $linha['memoria'], true);
        return is_array($memoria) ? $memoria : null;
    }

    private function decimal(string $valor): string
    {
        return Money::ofCents(Format::parseMoney($valor))->toDecimal();
    }

    private function rotuloCompetencia(string $competencia): string
    {
        $meses = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];
        $mes = (int) substr($competencia, 5, 2);
        return ($meses[$mes] ?? $competencia) . ' de ' . substr($competencia, 0, 4);
    }
}
