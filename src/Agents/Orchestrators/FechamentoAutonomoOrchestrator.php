<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Agentes\AuditorComplianceAgente;
use App\Agents\Agentes\AuditorEquacaoAgente;
use App\Agents\Agentes\TesoureiroPreditivoAgente;
use App\App\FolhaPagamentoEngine;
use App\Core\Database;
use App\Core\Format;
use App\Fiscal\Engine\TeseTributariaEngine;

/**
 * Maestro do Fechamento Contábil Autônomo:
 * Orquestra todos os agentes em cadeia sequencial determinística para encerrar o período:
 * 1. Tesouraria Preditiva e Conciliação
 * 2. Auditoria Fiscal e Cross-Compliance
 * 3. Encargos Trabalhistas e Provisões
 * 4. Varredura de Teses e Créditos Tributários
 * 5. Auditoria da Equação Patrimonial (Ativo = Passivo + PL)
 * 6. Emissão de Parecer Executivo e Técnico
 */
final class FechamentoAutonomoOrchestrator
{
    public function __construct(
        private readonly Database $db,
        private readonly TesoureiroPreditivoAgente $tesoureiro = new TesoureiroPreditivoAgente(),
        private readonly AuditorComplianceAgente $compliance = new AuditorComplianceAgente(),
        private readonly FolhaPagamentoEngine $folhaEngine = new FolhaPagamentoEngine(),
        private readonly TeseTributariaEngine $tesesEngine = new TeseTributariaEngine(),
        private readonly ?AuditorEquacaoAgente $auditorEquacao = null,
    ) {
    }

    /**
     * Executa o ciclo de fechamento contábil mensal completo da empresa.
     * @return array<string, mixed>
     */
    public function executarFechamento(int $empresaId, string $competencia): array
    {
        $auditor = $this->auditorEquacao ?? new AuditorEquacaoAgente($this->db);

        $empresa = $this->db->one('SELECT * FROM empresas WHERE id = :id', [':id' => $empresaId]);
        if ($empresa === null) {
            throw new \InvalidArgumentException('Empresa não encontrada: ' . $empresaId);
        }

        $regime = (string) ($empresa['regime'] ?? 'simples');

        // ---------------------------------------------------------
        // ETAPA 1: Conciliação e Tesouraria Preditiva
        // ---------------------------------------------------------
        $transacoes = $this->db->all(
            'SELECT t.* FROM transacoes_bancarias t
             WHERE t.empresa_id = :empresa AND t.data LIKE :competencia',
            [':empresa' => $empresaId, ':competencia' => $competencia . '%']
        );

        $totalTransacoes = count($transacoes);
        $conciliadasAuto = 0;
        $desviosTesouraria = 0;

        foreach ($transacoes as $tr) {
            $analise = $this->tesoureiro->analisar([
                'valor_centavos' => (int) $tr['valor_centavos'],
                'data' => (string) $tr['data'],
                'descricao' => (string) $tr['descricao'],
            ]);
            if (!empty($analise['automatica'])) {
                $conciliadasAuto++;
            }
            if (!empty($analise['desvio_detectado'])) {
                $desviosTesouraria++;
            }
        }

        // ---------------------------------------------------------
        // ETAPA 2: Auditoria Fiscal e Cross-Compliance
        // ---------------------------------------------------------
        $documentos = $this->db->all(
            'SELECT * FROM documentos_fiscais
             WHERE empresa_id = :empresa AND emissao LIKE :competencia',
            [':empresa' => $empresaId, ':competencia' => $competencia . '%']
        );

        $totalDocumentos = count($documentos);
        $divergenciasFiscais = 0;
        $detalhesDivergencias = [];

        foreach ($documentos as $doc) {
            $quitadoCentavos = (int) ($doc['valor_quitado_centavos'] ?? $doc['valor_nota_centavos']);
            $auditoriaDoc = $this->compliance->auditar([
                'documento_numero' => (string) ($doc['numero'] ?? 's/n'),
                'valor_documento_centavos' => (int) $doc['valor_nota_centavos'],
                'tipo_operacao' => (string) $doc['tipo_operacao'],
                'data_emissao' => (string) $doc['emissao'],
                'valor_quitado_centavos' => $quitadoCentavos,
            ]);

            if (empty($auditoriaDoc['em_conformidade'])) {
                $divergenciasFiscais++;
                $detalhesDivergencias[] = $auditoriaDoc['explicacao_leigo'];
            }
        }

        // ---------------------------------------------------------
        // ETAPA 3: Encargos Trabalhistas e Provisões
        // ---------------------------------------------------------
        $colaboradores = $this->db->all(
            'SELECT * FROM colaboradores WHERE empresa_id = :empresa AND status = \'ativo\'',
            [':empresa' => $empresaId]
        );

        $totalSalariosBrutos = 0;
        $totalInss = 0;
        $totalFgts = 0;
        $totalProvisoes = 0;

        foreach ($colaboradores as $colab) {
            $folha = $this->folhaEngine->processar((int) $colab['salario_base_centavos'], (string) $colab['nome']);
            $totalSalariosBrutos += (int) $folha['salario_bruto_centavos'];
            $totalInss += (int) $folha['inss_centavos'];
            $totalFgts += (int) $folha['fgts_centavos'];
            $totalProvisoes += (int) $folha['provisoes']['total_provisoes_centavos'];
        }

        // ---------------------------------------------------------
        // ETAPA 4: Varredura de Teses e Créditos Tributários
        // ---------------------------------------------------------
        $itensNotas = $this->db->all(
            'SELECT i.*, d.numero FROM documento_itens i
             JOIN documentos_fiscais d ON d.id = i.documento_id
             WHERE d.empresa_id = :empresa AND d.emissao LIKE :competencia',
            [':empresa' => $empresaId, ':competencia' => $competencia . '%']
        );

        $laudoTeses = $this->tesesEngine->varrerLote($itensNotas, $regime);
        $totalCreditosRecuperaveis = (int) $laudoTeses['total_creditos_recuperaveis_centavos'];

        // ---------------------------------------------------------
        // ETAPA 5: Auditoria da Equação Patrimonial
        // ---------------------------------------------------------
        $auditoriaEquacao = $auditor->auditar($empresaId, $competencia);
        $equacaoValida = empty($auditoriaEquacao['bloqueios']);

        // Determinação do status do fechamento
        $status = 'concluido_com_sucesso';
        if (!$equacaoValida) {
            $status = 'bloqueado';
        } elseif ($divergenciasFiscais > 0 || $desviosTesouraria > 0) {
            $status = 'requer_atencao';
        }

        // ---------------------------------------------------------
        // ETAPA 6: Consolidação Executiva e Redação dos Relatórios
        // ---------------------------------------------------------
        $resumoLeigo = "Fechamento de {$competencia} para {$empresa['razao_social']}: "
            . ($equacaoValida ? "A equação contábil fechou perfeitamente." : "Atenção: A equação patrimonial apresentou bloqueios.")
            . " Foram processadas {$totalDocumentos} notas fiscais e {$totalTransacoes} transações bancárias."
            . ($totalSalariosBrutos > 0 ? " Encargos de folha e provisões apurados em " . Format::money($totalSalariosBrutos + $totalFgts + $totalProvisoes) . "." : "")
            . ($totalCreditosRecuperaveis > 0 ? " Oportunidade: foram localizados " . Format::money($totalCreditosRecuperaveis) . " em créditos tributários para compensação." : "");

        $relatorioTecnico = [
            'competencia' => $competencia,
            'empresa_id' => $empresaId,
            'status' => $status,
            'equacao_patrimonial_aprovada' => $equacaoValida,
            'bloqueios_equacao' => $auditoriaEquacao['bloqueios'],
            'alertas_equacao' => $auditoriaEquacao['alertas'],
            'transacoes_total' => $totalTransacoes,
            'conciliadas_automaticamente' => $conciliadasAuto,
            'desvios_tesouraria' => $desviosTesouraria,
            'documentos_total' => $totalDocumentos,
            'divergencias_compliance' => $divergenciasFiscais,
            'detalhes_divergencias' => $detalhesDivergencias,
            'folha_colaboradores_ativos' => count($colaboradores),
            'folha_salarios_centavos' => $totalSalariosBrutos,
            'folha_inss_centavos' => $totalInss,
            'folha_fgts_centavos' => $totalFgts,
            'folha_provisoes_centavos' => $totalProvisoes,
            'creditos_tributarios_recuperaveis_centavos' => $totalCreditosRecuperaveis,
            'teses_identificadas' => $laudoTeses['teses_consolidadas'],
        ];

        // Grava o histórico do fechamento no banco de dados
        $this->db->run(
            'INSERT OR REPLACE INTO fechamentos_mensais (
                empresa_id, competencia, status, diferenca_equacao_centavos,
                total_receitas_centavos, total_despesas_centavos, resultado_liquido_centavos,
                divergencias_total, relatorio_leigo, relatorio_tecnico, executado_por, executado_em
            ) VALUES (
                :empresa, :competencia, :status, :dif_equacao,
                :receitas, :despesas, :resultado,
                :divergencias, :leigo, :tecnico, :executor, :data
            )',
            [
                ':empresa' => $empresaId,
                ':competencia' => $competencia,
                ':status' => $status,
                ':dif_equacao' => $equacaoValida ? 0 : 1,
                ':receitas' => 0,
                ':despesas' => $totalSalariosBrutos + $totalFgts + $totalProvisoes,
                ':resultado' => 0,
                ':divergencias' => $divergenciasFiscais + $desviosTesouraria,
                ':leigo' => $resumoLeigo,
                ':tecnico' => json_encode($relatorioTecnico, JSON_UNESCAPED_UNICODE),
                ':executor' => 'FechamentoAutonomoOrchestrator',
                ':data' => date('Y-m-d H:i:s'),
            ]
        );

        return [
            'empresa_id' => $empresaId,
            'competencia' => $competencia,
            'status' => $status,
            'resumo_leigo' => $resumoLeigo,
            'relatorio_tecnico' => $relatorioTecnico,
        ];
    }
}
