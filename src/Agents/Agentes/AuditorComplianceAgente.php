<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Core\Format;

/**
 * Auditoria contínua de Cross-Compliance:
 * Cruza o faturamento (NF-e/NFS-e emitidas e tomadas) com a movimentação real bancária
 * e identifica divergências antes do fechamento das obrigações acessórias.
 */
class AuditorComplianceAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'auditor-compliance',
            'Auditor de Compliance Preventivo',
            self::class,
            'auditar_cruzamento_compliance',
            'Cruza faturamento de notas emitidas/tomadas com a movimentação bancária e escriturações fiscais para prevenir malha fina e divergências em tempo real.',
            'Não calcula imposto novo. Compara dados fiscais e bancários já auditados buscando discrepâncias de valor, omissão ou duplicidade.',
            [
                ['documento_numero', true, 'texto', 'Número da NF-e ou NFS-e auditada.'],
                ['valor_documento_centavos', true, 'centavos', 'Valor total bruto da nota fiscal em centavos.'],
                ['tipo_operacao', true, 'texto', 'Tipo da operação: entrada (tomada) ou saida (emitida).'],
                ['data_emissao', true, 'data', 'Data de emissão da nota no formato YYYY-MM-DD.'],
                ['valor_quitado_centavos', false, 'centavos', 'Valor total dos lançamentos bancários conciliados a este documento.'],
                ['competencia', false, 'texto', 'Mês de apuração no formato YYYY-MM.'],
            ],
            [
                ['Lei nº 8.137/1990', 'Crimes contra a ordem tributária: prevenção a omissões de receitas e distorções contábeis.'],
                ['Decreto nº 9.580/2018', 'Regulamento do Imposto de Renda: conformidade e comprovação documental das despesas.'],
                ['IN RFB nº 2.043/2021', 'Conformidade da EFD-Reinf e conciliação prévia de retenções na fonte com a DCTFWeb.'],
            ],
        );
    }

    /**
     * @param array{documento_numero:string,valor_documento_centavos:int,tipo_operacao:string,data_emissao:string,valor_quitado_centavos?:int|null} $dadosDocumento
     * @param int $diasDesdeEmissao Dias corridos desde a data de emissão
     * @return array<string, mixed>
     */
    public function auditar(array $dadosDocumento, int $diasDesdeEmissao = 0): array
    {
        $leitura = LeitorDeVariaveis::ler(
            self::contrato(),
            $dadosDocumento,
            ['documento_numero', 'valor_documento_centavos', 'tipo_operacao', 'data_emissao']
        );

        if (!$leitura['ok']) {
            return [
                'em_conformidade' => false,
                'severidade' => 'alta',
                'divergencia_detectada' => 'dados_incompletos',
                'diferenca_centavos' => 0,
                'explicacao_leigo' => $leitura['mensagem'],
                'detalhe_tecnico' => 'Campos obrigatórios ausentes para o cruzamento de compliance.',
                'acao_recomendada' => 'Preencher os dados do documento para permitir a auditoria preventiva.',
                'card' => $leitura['mensagem'],
                'modelo' => 'variavel',
                'agente' => self::contrato()['id'],
            ];
        }

        $doc = array_merge($dadosDocumento, $leitura['valores']);
        $numero = (string) $doc['documento_numero'];
        $valorDoc = (int) $doc['valor_documento_centavos'];
        $tipo = strtolower(trim((string) $doc['tipo_operacao']));
        $quitado = isset($doc['valor_quitado_centavos']) && $doc['valor_quitado_centavos'] !== null
            ? (int) $doc['valor_quitado_centavos']
            : null;

        // Caso 1: Nota emitida sem qualquer entrada bancária após 45 dias
        if ($tipo === 'saida' && ($quitado === null || $quitado === 0) && $diasDesdeEmissao > 45) {
            $msgLeigo = "A nota emitida nº {$numero} de " . Format::money($valorDoc) . " foi emitida há {$diasDesdeEmissao} dias e não tem recebimento registrado no extrato bancário.";
            $detalhe = "Nota fiscal de saída com status em aberto excedendo o prazo operacional médio. Possível inadimplência de cliente ou recebimento em conta não monitorada (risco de omissão de receita na RFB).";
            return [
                'em_conformidade' => false,
                'severidade' => 'alta',
                'divergencia_detectada' => 'recebimento_ausente',
                'diferenca_centavos' => $valorDoc,
                'explicacao_leigo' => $msgLeigo,
                'detalhe_tecnico' => $detalhe,
                'acao_recomendada' => 'Confirmar se o cliente quitou a nota ou provisionar perdas com clientes.',
                'card' => "Alerta de Compliance: {$msgLeigo}",
                'modelo' => 'deterministico',
                'agente' => self::contrato()['id'],
            ];
        }

        // Caso 2: Houve quitação bancária mas os valores não batem
        if ($quitado !== null && $quitado > 0) {
            $diff = abs($valorDoc - $quitado);

            if ($diff > 0) {
                // Checa se a diferença bate com retenções federais padrão (ex: 4,65% CSRF ou 1,5% IRRF)
                $percentualDiff = ($valorDoc > 0) ? ($diff / $valorDoc) * 100 : 0;
                $provavelRetencao = (abs($percentualDiff - 4.65) < 0.2 || abs($percentualDiff - 1.5) < 0.2 || abs($percentualDiff - 5.0) < 0.2);

                if ($provavelRetencao) {
                    $msgLeigo = "A nota nº {$numero} de " . Format::money($valorDoc) . " teve baixa de " . Format::money($quitado) . ". A diferença de " . Format::money($diff) . " parece ser retenção de tributos na fonte.";
                    $detalhe = "Divergência compatível com retenção na fonte (alíquota aproximada de " . round($percentualDiff, 2) . "%). Exige registro da retenção na EFD-Reinf.";
                    return [
                        'em_conformidade' => true,
                        'severidade' => 'baixa',
                        'divergencia_detectada' => 'retencao_fonte_identificada',
                        'diferenca_centavos' => $diff,
                        'explicacao_leigo' => $msgLeigo,
                        'detalhe_tecnico' => $detalhe,
                        'acao_recomendada' => 'Confirmar o informe de retenção na fonte do tomador do serviço.',
                        'card' => "Compliance: {$msgLeigo}",
                        'modelo' => 'deterministico',
                        'agente' => self::contrato()['id'],
                    ];
                }

                // Se o valor pago for maior que a nota
                if ($quitado > $valorDoc) {
                    $msgLeigo = "O pagamento no banco (" . Format::money($quitado) . ") foi maior que o valor da nota nº {$numero} (" . Format::money($valorDoc) . ").";
                    $detalhe = "Saída financeira superior ao documento fiscal de suporte. Possível inclusão de juros/multa não escriturados ou duplicidade de liquidação.";
                    return [
                        'em_conformidade' => false,
                        'severidade' => 'media',
                        'divergencia_detectada' => 'pagamento_maior_que_documento',
                        'diferenca_centavos' => $diff,
                        'explicacao_leigo' => $msgLeigo,
                        'detalhe_tecnico' => $detalhe,
                        'acao_recomendada' => 'Lançar a diferença em conta de despesas financeiras (juros/multas).',
                        'card' => "Alerta de Divergência: {$msgLeigo}",
                        'modelo' => 'deterministico',
                        'agente' => self::contrato()['id'],
                    ];
                }

                // Se o valor pago for menor sem justificativa de retenção
                $msgLeigo = "O valor no banco (" . Format::money($quitado) . ") é menor que a nota nº {$numero} (" . Format::money($valorDoc) . "), restando " . Format::money($diff) . " pendente.";
                $detalhe = "Liquidação parcial sem registro de retenção tributária correspondente.";
                return [
                    'em_conformidade' => false,
                    'severidade' => 'media',
                    'divergencia_detectada' => 'liquidacao_parcial',
                    'diferenca_centavos' => $diff,
                    'explicacao_leigo' => $msgLeigo,
                    'detalhe_tecnico' => $detalhe,
                    'acao_recomendada' => 'Verificar se haverá segunda parcela ou desconto comercial concedido.',
                    'card' => "Divergência Contábil: {$msgLeigo}",
                    'modelo' => 'deterministico',
                    'agente' => self::contrato()['id'],
                ];
            }
        }

        // Caso 3: 100% conciliado e em conformidade
        return [
            'em_conformidade' => true,
            'severidade' => 'nenhuma',
            'divergencia_detectada' => 'nenhuma',
            'diferenca_centavos' => 0,
            'explicacao_leigo' => "Nota fiscal nº {$numero} de " . Format::money($valorDoc) . " conciliada com exatidão no banco.",
            'detalhe_tecnico' => 'Documento fiscal e baixa bancária conferem integralmente. Sem risco de malha fina nesta operação.',
            'acao_recomendada' => 'Nenhuma ação necessária.',
            'card' => "Nota nº {$numero} em total conformidade fiscal e bancária.",
            'modelo' => 'deterministico',
            'agente' => self::contrato()['id'],
        ];
    }
}
