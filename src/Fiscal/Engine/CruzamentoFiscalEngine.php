<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Agents\Agentes\AuditorComplianceAgente;
use App\Core\Database;
use App\Core\Format;

/**
 * Motor de Cruzamento Fiscal e Cross-Audit:
 * Cruza em tempo real notas fiscais (XMLs), movimentação financeira (extratos OFX)
 * e escriturações (SPED Fiscal), detectando inconsistências críticas, fraudes,
 * divergências de alíquotas e notas canceladas para alimentar os agentes de auditoria.
 */
class CruzamentoFiscalEngine
{
    public function __construct(
        private readonly ?Database $db = null,
        private readonly AuditorComplianceAgente $complianceAgente = new AuditorComplianceAgente(),
    ) {
    }

    /**
     * Executa o cruzamento completo para uma empresa e competência.
     *
     * @param int $empresaId
     * @param string $competencia Formato YYYY-MM
     * @param list<array<string, mixed>> $documentosFiscal Lote opcional de documentos (se vazio, busca no banco)
     * @param list<array<string, mixed>> $transacoesBanco Lote opcional de transações bancárias
     * @param list<array<string, mixed>> $spedDocumentos Lote opcional de notas do SPED C100
     * @return array<string, mixed>
     */
    public function cruzar(
        int $empresaId,
        string $competencia,
        array $documentosFiscal = [],
        array $transacoesBanco = [],
        array $spedDocumentos = []
    ): array {
        $empresa = null;
        if ($this->db !== null) {
            $empresa = $this->db->one('SELECT * FROM empresas WHERE id = :id', [':id' => $empresaId]);
        }
        $regime = strtolower((string) ($empresa['regime'] ?? 'simples'));
        $cnpjEmpresa = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));

        // 1. Carrega dados do banco caso não tenham sido passados diretamente
        if (empty($documentosFiscal) && $this->db !== null) {
            $documentosFiscal = $this->db->all(
                'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND emissao LIKE :comp',
                [':empresa' => $empresaId, ':comp' => $competencia . '%']
            );
        }

        if (empty($transacoesBanco) && $this->db !== null) {
            $transacoesBanco = $this->db->all(
                'SELECT * FROM transacoes_bancarias WHERE empresa_id = :empresa AND data LIKE :comp',
                [':empresa' => $empresaId, ':comp' => $competencia . '%']
            );
        }

        $divergencias = [];
        $chavesVistas = [];

        // Mapeia transações bancárias por valor absoluto para busca ágil
        $bancoPorValor = [];
        foreach ($transacoesBanco as $tr) {
            $vlr = abs((int) ($tr['valor_centavos'] ?? 0));
            $bancoPorValor[$vlr][] = $tr;
        }

        // Mapeia notas do SPED por chave e número
        $spedPorChave = [];
        $spedPorNumero = [];
        foreach ($spedDocumentos as $sp) {
            $chaveSp = (string) ($sp['chave'] ?? '');
            $numSp = (string) ($sp['numero'] ?? '');
            if ($chaveSp !== '') {
                $spedPorChave[$chaveSp] = $sp;
            }
            if ($numSp !== '') {
                $spedPorNumero[$numSp] = $sp;
            }
        }

        // -------------------------------------------------------------
        // DIMENSÃO 1 & 2: Cruzamento Fiscal vs Banco e Regras de Negócio
        // -------------------------------------------------------------
        foreach ($documentosFiscal as $doc) {
            $numero = (string) ($doc['numero'] ?? 's/n');
            $chave = (string) ($doc['chave'] ?? '');
            $valorDoc = (int) ($doc['valor_nota_centavos'] ?? 0);
            $tipoOp = (string) ($doc['tipo_operacao'] ?? 'saida');
            $statusAuditoria = (string) ($doc['status_auditoria'] ?? 'conforme');

            // A. Verificação de Duplicidade de Chave
            if ($chave !== '') {
                if (isset($chavesVistas[$chave])) {
                    $divergencias[] = [
                        'tipo' => 'chave_duplicada',
                        'severidade' => 'critica',
                        'documento_numero' => $numero,
                        'chave' => $chave,
                        'valor_divergente_centavos' => $valorDoc,
                        'descricao_simples' => "Nota fiscal nº {$numero} possui chave de acesso duplicada no sistema.",
                        'detalhe_tecnico' => "Chave de 44 dígitos [{$chave}] encontrada em mais de um registro na mesma competência.",
                        'acao_recomendada' => 'Excluir o documento em duplicidade do razão contábil.',
                    ];
                }
                $chavesVistas[$chave] = true;
            }

            // B. Verificação de Notas Canceladas / Denegadas
            if (in_array(strtolower($statusAuditoria), ['cancelada', 'denegada', 'inutilizada'], true)) {
                $divergencias[] = [
                    'tipo' => 'nota_cancelada_com_lancamento',
                    'severidade' => 'critica',
                    'documento_numero' => $numero,
                    'chave' => $chave,
                    'valor_divergente_centavos' => $valorDoc,
                    'descricao_simples' => "A nota fiscal nº {$numero} foi {$statusAuditoria} na SEFAZ e não pode gerar débito/crédito.",
                    'detalhe_tecnico' => "Documento fiscal com status [{$statusAuditoria}] presente na base de apuração da competência {$competencia}.",
                    'acao_recomendada' => 'Estornar os lançamentos contábeis e fiscais vinculados a este documento.',
                ];
                continue;
            }

            // C. Cruzamento de Liquidação Financeira no Extrato Bancário
            $quitadoCentavos = (int) ($doc['valor_quitado_centavos'] ?? 0);
            if ($quitadoCentavos === 0 && isset($bancoPorValor[$valorDoc])) {
                $quitadoCentavos = $valorDoc; // Encontrou par exato no extrato
            }

            $auditoriaDoc = $this->complianceAgente->auditar([
                'documento_numero' => $numero,
                'valor_documento_centavos' => $valorDoc,
                'tipo_operacao' => $tipoOp,
                'data_emissao' => (string) ($doc['emissao'] ?? date('Y-m-d')),
                'valor_quitado_centavos' => $quitadoCentavos > 0 ? $quitadoCentavos : null,
            ], 50);

            if (empty($auditoriaDoc['em_conformidade'])) {
                $divergencias[] = [
                    'tipo' => (string) ($auditoriaDoc['divergencia_detectada'] ?? 'divergencia_financeira'),
                    'severidade' => (string) ($auditoriaDoc['severidade'] ?? 'alta'),
                    'documento_numero' => $numero,
                    'chave' => $chave,
                    'valor_divergente_centavos' => (int) ($auditoriaDoc['diferenca_centavos'] ?? $valorDoc),
                    'descricao_simples' => (string) $auditoriaDoc['explicacao_leigo'],
                    'detalhe_tecnico' => (string) $auditoriaDoc['detalhe_tecnico'],
                    'acao_recomendada' => (string) $auditoriaDoc['acao_recomendada'],
                ];
            }

            // D. Validação de Alíquotas e Tributos em Relação ao Regime
            $valorIcms = (int) ($doc['valor_ibs_centavos'] ?? 0) + (int) ($doc['valor_cbs_centavos'] ?? 0);
            if ($regime === 'simples' && $tipoOp === 'saida') {
                // Alerta se empresa do Simples destacou imposto com alíquota fora do padrão
                $vIcms = (int) ($doc['valor_icms_centavos'] ?? 0);
                if ($vIcms > 0 && ($vIcms / max(1, $valorDoc)) > 0.12) {
                    $divergencias[] = [
                        'tipo' => 'aliquota_icms_incompativel_simples',
                        'severidade' => 'alta',
                        'documento_numero' => $numero,
                        'chave' => $chave,
                        'valor_divergente_centavos' => $vIcms,
                        'descricao_simples' => "A nota de saída nº {$numero} destaca ICMS de " . Format::money($vIcms) . ", o que é atípico para o Simples Nacional.",
                        'detalhe_tecnico' => "Destaque de ICMS em documento de saída superior à alíquota de transferência do Anexo do Simples.",
                        'acao_recomendada' => 'Verificar se a nota exige carta de correção ou estorno de destaque indevido.',
                    ];
                }
            }

            // ---------------------------------------------------------
            // DIMENSÃO 3: Cruzamento NF-e vs SPED Fiscal
            // ---------------------------------------------------------
            if (!empty($spedDocumentos)) {
                $achouSped = false;
                $spedItem = null;

                if ($chave !== '' && isset($spedPorChave[$chave])) {
                    $achouSped = true;
                    $spedItem = $spedPorChave[$chave];
                } elseif ($numero !== '' && isset($spedPorNumero[$numero])) {
                    $achouSped = true;
                    $spedItem = $spedPorNumero[$numero];
                }

                if (!$achouSped) {
                    $divergencias[] = [
                        'tipo' => 'omissao_sped_fiscal',
                        'severidade' => 'alta',
                        'documento_numero' => $numero,
                        'chave' => $chave,
                        'valor_divergente_centavos' => $valorDoc,
                        'descricao_simples' => "A nota fiscal nº {$numero} (" . Format::money($valorDoc) . ") não consta no SPED Fiscal gerado.",
                        'detalhe_tecnico' => "Documento fiscal presente na base de XMLs mas ausente nos registros C100 da EFD da competência {$competencia}.",
                        'acao_recomendada' => 'Incluir a escrituração da nota no SPED antes de transmitir à Receita.',
                    ];
                } else {
                    $valorSped = (int) ($spedItem['valor_produtos_centavos'] ?? $spedItem['valor_total_centavos'] ?? 0);
                    if ($valorSped > 0 && abs($valorSped - $valorDoc) > 100) {
                        $diff = abs($valorSped - $valorDoc);
                        $divergencias[] = [
                            'tipo' => 'divergencia_valor_nfe_sped',
                            'severidade' => 'alta',
                            'documento_numero' => $numero,
                            'chave' => $chave,
                            'valor_divergente_centavos' => $diff,
                            'descricao_simples' => "O valor da nota nº {$numero} no XML (" . Format::money($valorDoc) . ") difere do valor no SPED (" . Format::money($valorSped) . ").",
                            'detalhe_tecnico' => "Divergência de " . Format::money($diff) . " entre o XML da NF-e e o registro C100 do SPED Fiscal.",
                            'acao_recomendada' => 'Retificar o registro C100 no SPED para coincidir com o XML da SEFAZ.',
                        ];
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // PERSISTÊNCIA DAS DIVERGÊNCIAS NO BANCO DE DADOS
        // -------------------------------------------------------------
        if ($this->db !== null && !empty($divergencias)) {
            foreach ($divergencias as $div) {
                $this->db->insert('divergencias_compliance', [
                    'empresa_id' => $empresaId,
                    'competencia' => $competencia,
                    'tipo' => (string) $div['tipo'],
                    'severidade' => (string) $div['severidade'],
                    'documento_id' => null,
                    'transacao_id' => null,
                    'valor_divergente_centavos' => (int) $div['valor_divergente_centavos'],
                    'descricao_simples' => (string) $div['descricao_simples'],
                    'detalhe_tecnico' => (string) $div['detalhe_tecnico'],
                    'resolvida' => 0,
                    'detectada_em' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $bloqueiosCriticos = 0;
        foreach ($divergencias as $div) {
            if ($div['severidade'] === 'critica' || $div['severidade'] === 'alta') {
                $bloqueiosCriticos++;
            }
        }

        $totalDocs = count($documentosFiscal);
        $totalTrans = count($transacoesBanco);
        $totalDiv = count($divergencias);

        if ($totalDiv === 0) {
            $resumoLeigo = "Auditoria de Cruzamento concluída: 100% de conformidade entre notas fiscais, extrato bancário e SPED. Nenhuma inconsistência encontrada.";
        } else {
            $resumoLeigo = "Identificamos {$totalDiv} inconsistências no cruzamento fiscal (sendo {$bloqueiosCriticos} de alta gravidade). "
                . "Recomendamos revisar as notas apontadas antes da transmissão de obrigações acessórias.";
        }

        return [
            'empresa_id' => $empresaId,
            'competencia' => $competencia,
            'total_documentos_auditados' => $totalDocs,
            'total_transacoes_auditadas' => $totalTrans,
            'total_divergencias' => $totalDiv,
            'bloqueios_criticos' => $bloqueiosCriticos,
            'em_conformidade' => ($totalDiv === 0),
            'divergencias' => $divergencias,
            'resumo_leigo' => $resumoLeigo,
        ];
    }
}
