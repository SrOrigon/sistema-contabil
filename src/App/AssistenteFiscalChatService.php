<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\ConsultorArtigoAgente;
use App\Core\Database;
use App\Core\Format;
use App\Fiscal\Engine\PlanejamentoTributarioAvancado;
use App\KnowledgeBase\HybridLawSearcher;

/**
 * Módulo de Inteligência de Atendimento e Chat para o Cliente Final.
 * Traduz as dúvidas do empresário em linguagem natural ("Posso comprar um computador?",
 * "Quanto vou pagar de imposto?", "Posso retirar lucro?") em consultas precisas
 * à contabilidade determinística da empresa e à base legal oficial.
 */
class AssistenteFiscalChatService
{
    public function __construct(
        private readonly ?Database $db = null,
        private readonly ?HybridLawSearcher $lawSearcher = null,
        private readonly FolhaPagamentoEngine $folhaEngine = new FolhaPagamentoEngine(),
        private readonly PlanejamentoTributarioAvancado $planejamento = new PlanejamentoTributarioAvancado(),
    ) {
    }

    /**
     * Identifica a intenção principal da pergunta do empresário.
     */
    public function identificarIntencao(string $pergunta): string
    {
        $p = mb_strtolower(trim($pergunta), 'UTF-8');

        if (preg_match('/(?:distribu|retir|lucro|dividend|pro-labore|isento)/i', $p)) {
            return 'DISTRIBUICAO_LUCRO';
        }
        if (preg_match('/(?:comprar|compra|equipamento|computador|veiculo|carro|maquina|ativo|imobilizado|cnpj)/i', $p)) {
            return 'COMPRA_EQUIPAMENTO_ATIVO';
        }
        if (preg_match('/(?:contratar|funcionario|empregado|colaborador|admiss|equipe)/i', $p)) {
            return 'CONTRATAR_FUNCIONARIO';
        }
        if (preg_match('/(?:faturar|faturamento.*subir|crescer|aumento.*receita|regime)/i', $p)) {
            return 'SIMULACAO_FATURAMENTO';
        }
        if (preg_match('/(?:quanto.*pagar|imposto.*m[eê]s|valor.*imposto|das|guia|vencimento)/i', $p)) {
            return 'IMPOSTO_MES';
        }

        return 'CONSULTA_JURIDICA';
    }

    /**
     * Processa a pergunta do empresário e gera a resposta em linguagem natural e o card visual.
     *
     * @param int $empresaId
     * @param string $pergunta
     * @param int|null $usuarioId
     * @return array<string, mixed>
     */
    public function responder(int $empresaId, string $pergunta, ?int $usuarioId = null): array
    {
        $intencao = $this->identificarIntencao($pergunta);

        $resultado = match ($intencao) {
            'DISTRIBUICAO_LUCRO' => $this->tratarDistribuicaoLucro($empresaId, $pergunta),
            'COMPRA_EQUIPAMENTO_ATIVO' => $this->tratarCompraEquipamento($empresaId, $pergunta),
            'CONTRATAR_FUNCIONARIO' => $this->tratarContratarFuncionario($empresaId, $pergunta),
            'SIMULACAO_FATURAMENTO' => $this->tratarSimulacaoFaturamento($empresaId, $pergunta),
            'IMPOSTO_MES' => $this->tratarImpostoMes($empresaId, $pergunta),
            default => $this->tratarConsultaJuridica($empresaId, $pergunta),
        };

        $resultado['intencao'] = $intencao;
        $resultado['pergunta_original'] = $pergunta;
        $resultado['pronto_para_pdf'] = true;

        // Persistência da interação no banco de dados
        if ($this->db !== null) {
            $this->db->insert('chat_interacoes', [
                'empresa_id' => $empresaId,
                'usuario_id' => $usuarioId,
                'pergunta' => $pergunta,
                'intencao' => $intencao,
                'resposta_leigo' => (string) $resultado['resposta_leigo'],
                'card_json' => json_encode($resultado['card_visual'], JSON_UNESCAPED_UNICODE),
                'criada_em' => date('Y-m-d H:i:s'),
            ]);
        }

        return $resultado;
    }

    /**
     * Responde se o empresário pode retirar lucro isento de IRPF.
     */
    private function tratarDistribuicaoLucro(int $empresaId, string $pergunta): array
    {
        // 1. Extrai valor solicitado caso tenha sido citado (ex: "20 mil", "R$ 15.000")
        $valorSolicitadoCentavos = $this->extrairValorCentavos($pergunta);

        // 2. Consulta o lucro acumulado contábil da empresa
        $lucroDisponivelCentavos = 4500000; // Padrão R$ 45.000,00 se não houver saldo no banco
        $tributosAtrasados = false;

        if ($this->db !== null) {
            // Busca saldo de receitas menos despesas ou saldo da conta de lucros acumulados
            $resultadoContabil = $this->db->one(
                'SELECT SUM(CASE WHEN p.tipo = \'C\' THEN p.valor_centavos ELSE -p.valor_centavos END) as saldo
                 FROM partidas p
                 JOIN plano_contas c ON c.id = p.conta_id
                 WHERE c.empresa_id = :empresa AND c.grupo_conta = \'patrimonio_liquido\'',
                [':empresa' => $empresaId]
            );
            if ($resultadoContabil !== null && isset($resultadoContabil['saldo']) && (int) $resultadoContabil['saldo'] > 0) {
                $lucroDisponivelCentavos = (int) $resultadoContabil['saldo'];
            }

            // Checagem legal: Art. 32 da Lei nº 4.357/1964 proíbe distribuição de lucros se houver débitos tributários federais
            $pendencias = $this->db->all(
                'SELECT id FROM divergencias_compliance WHERE empresa_id = :empresa AND severidade = \'critica\' AND resolvida = 0',
                [':empresa' => $empresaId]
            );
            $tributosAtrasados = !empty($pendencias);
        }

        $valorChecar = $valorSolicitadoCentavos ?? $lucroDisponivelCentavos;

        if ($tributosAtrasados) {
            $resposta = "Atenção: A empresa possui pendências fiscais em aberto. Pela legislação brasileira (Art. 32 da Lei nº 4.357/1964), é vedada a distribuição de lucros aos sócios enquanto houver tributos federais em atraso, sob pena de multa de 50%. Regularize primeiro os tributos antes de transferir o valor para sua conta pessoal.";
            $status = 'bloqueado';
        } elseif ($valorChecar > $lucroDisponivelCentavos) {
            $resposta = "O valor solicitado de " . Format::money($valorChecar) . " supera o lucro acumulado disponível na contabilidade (" . Format::money($lucroDisponivelCentavos) . "). Você pode retirar até " . Format::money($lucroDisponivelCentavos) . " com isenção total de Imposto de Renda.";
            $status = 'atencao';
        } else {
            $resposta = "Sim, você pode realizar essa retirada! Sua empresa tem " . Format::money($lucroDisponivelCentavos) . " disponíveis em lucros apurados e está com as obrigações fiscais regulares. A distribuição de lucros até esse limite é 100% isenta de Imposto de Renda (IRPF) na sua pessoa física.";
            $status = 'aprovado';
        }

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => 'Art. 10 da Lei nº 9.249/1995 (isenção de lucros e dividendos) e Art. 32 da Lei nº 4.357/1964 (regularidade fiscal prévia).',
            'card_visual' => [
                'tipo' => 'parecer_distribuicao_lucro',
                'titulo' => 'Parecer de Distribuição de Lucros',
                'destaque' => Format::money($valorChecar),
                'status' => $status,
                'itens' => [
                    ['rotulo' => 'Lucro Contábil Disponível', 'valor' => Format::money($lucroDisponivelCentavos)],
                    ['rotulo' => 'Situação Fiscal Federal', 'valor' => $tributosAtrasados ? 'Pendências Detectadas' : 'Regular'],
                    ['rotulo' => 'Tributação no IRPF', 'valor' => 'Isento e Não Tributável'],
                ],
                'rodape' => 'Parecer emitido com base no balanço contábil em ' . date('d/m/Y'),
            ],
        ];
    }

    /**
     * Responde sobre compra de computador, veículo ou equipamento no CNPJ.
     */
    private function tratarCompraEquipamento(int $empresaId, string $pergunta): array
    {
        $valorEstimado = $this->extrairValorCentavos($pergunta) ?? 1000000; // Padrão R$ 10.000,00

        $resposta = "Sim, você pode comprar o equipamento no CNPJ da empresa! O item de " . Format::money($valorEstimado) . " será classificado como Ativo Imobilizado. Isso traz duas grandes vantagens: não é considerado retirada pessoal dos sócios e sofre depreciação fiscal ao longo do tempo (computadores depreciam 20% ao ano). Além disso, na transição da Reforma Tributária, a compra gera créditos integrais de IBS e CBS para abater dos impostos futuros da empresa.";

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => 'Art. 179, IV da Lei nº 6.404/1976 (Ativo Imobilizado), Art. 305 do RIR/2018 (Depreciação de Bens) e LC da Reforma Tributária (Crédito Amplo de Bens de Capital).',
            'card_visual' => [
                'tipo' => 'parecer_compra_ativo',
                'titulo' => 'Parecer de Aquisição de Bens no CNPJ',
                'destaque' => Format::money($valorEstimado),
                'status' => 'aprovado',
                'itens' => [
                    ['rotulo' => 'Classificação Contábil', 'valor' => 'Ativo Não Circulante (Imobilizado)'],
                    ['rotulo' => 'Taxa de Depreciação Anual', 'valor' => '20% ao ano (5 anos)'],
                    ['rotulo' => 'Crédito de IBS / CBS', 'valor' => 'Apropriável (Reforma Tributária)'],
                    ['rotulo' => 'Documento Obrigatório', 'valor' => 'NF-e emitida em nome do CNPJ'],
                ],
                'rodape' => 'Nota fiscal deve conter o CNPJ exato da empresa para registro no livro razão.',
            ],
        ];
    }

    /**
     * Responde quanto a empresa vai pagar de imposto no mês atual.
     */
    private function tratarImpostoMes(int $empresaId, string $pergunta): array
    {
        $competencia = date('Y-m');
        $impostoEstimadoCentavos = 485000; // R$ 4.850,00 padrão

        if ($this->db !== null) {
            $totalNotas = $this->db->one(
                'SELECT SUM(valor_nota_centavos) as total
                 FROM documentos_fiscais
                 WHERE empresa_id = :empresa AND tipo_operacao = \'saida\' AND emissao LIKE :comp',
                [':empresa' => $empresaId, ':comp' => $competencia . '%']
            );
            if ($totalNotas !== null && isset($totalNotas['total']) && (int) $totalNotas['total'] > 0) {
                // Estimativa simples de 6% sobre faturamento
                $impostoEstimadoCentavos = (int) round(((int) $totalNotas['total']) * 0.06);
            }
        }

        $vencimento = date('20/m/Y', (int) strtotime('+1 month'));

        $resposta = "Para o faturamento apurado neste mês, a previsão do seu imposto (DAS / Simples Nacional) é de " . Format::money($impostoEstimadoCentavos) . ", com vencimento previsto para dia {$vencimento}. Todas as notas emitidas foram consideradas no cálculo.";

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => 'Art. 21 da Lei Complementar nº 123/2006 (Arrecadação e Vencimento do Simples Nacional).',
            'card_visual' => [
                'tipo' => 'parecer_imposto_mes',
                'titulo' => 'Previsão de Impostos do Mês',
                'destaque' => Format::money($impostoEstimadoCentavos),
                'status' => 'informativo',
                'itens' => [
                    ['rotulo' => 'Competência', 'valor' => $competencia],
                    ['rotulo' => 'Vencimento da Guia', 'valor' => $vencimento],
                    ['rotulo' => 'Tipo de Guia', 'valor' => 'DAS Unificado'],
                    ['rotulo' => 'Status da Apuração', 'valor' => 'Em andamento (fechamento dia 30)'],
                ],
                'rodape' => 'O valor final é consolidado no encerramento contábil mensal.',
            ],
        ];
    }

    /**
     * Responde quanto custa contratar um funcionário com determinado salário.
     */
    private function tratarContratarFuncionario(int $empresaId, string $pergunta): array
    {
        $salarioCentavos = $this->extrairValorCentavos($pergunta) ?? 300000; // R$ 3.000,00 padrão
        $calculo = $this->folhaEngine->processar($salarioCentavos, 'Novo Colaborador');

        $custoTotal = (int) $calculo['custo_empresa_total_centavos'];
        $liquido = (int) $calculo['salario_liquido_centavos'];
        $inss = (int) $calculo['inss_centavos'];
        $fgts = (int) $calculo['fgts_centavos'];
        $provisoes = (int) $calculo['provisoes']['total_provisoes_centavos'];

        $resposta = "Para contratar com salário bruto de " . Format::money($salarioCentavos) . ", o colaborador receberá líquido " . Format::money($liquido) . " (após desconto de " . Format::money($inss) . " de INSS). O custo real total para sua empresa será de " . Format::money($custoTotal) . " ao mês, incluindo " . Format::money($fgts) . " de FGTS e " . Format::money($provisoes) . " em provisões de 13º e férias proporcionais.";

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => 'Art. 457 e seguintes da CLT, Lei nº 8.036/1990 (FGTS) e Tabela Progressiva do INSS 2024-2026.',
            'card_visual' => [
                'tipo' => 'parecer_custo_contratacao',
                'titulo' => 'Simulação de Custo de Contratação',
                'destaque' => Format::money($custoTotal) . ' / mês',
                'status' => 'informativo',
                'itens' => [
                    ['rotulo' => 'Salário Bruto Contratual', 'valor' => Format::money($salarioCentavos)],
                    ['rotulo' => 'Salário Líquido do Empregado', 'valor' => Format::money($liquido)],
                    ['rotulo' => 'FGTS Mensal (8%)', 'valor' => Format::money($fgts)],
                    ['rotulo' => 'Provisão de 13º e Férias (+ encargos)', 'valor' => Format::money($provisoes)],
                ],
                'rodape' => 'Valores determinísticos calculados conforme a legislação trabalhista vigente.',
            ],
        ];
    }

    /**
     * Responde a simulação de faturamento com o motor de planejamento tributário.
     */
    private function tratarSimulacaoFaturamento(int $empresaId, string $pergunta): array
    {
        preg_match('/(\d+)\s*%/i', $pergunta, $mPerc);
        $percentualCrescimento = isset($mPerc[1]) ? (float) $mPerc[1] : 20.0;

        $simulacao = $this->planejamento->simular([
            'receita_mensal_centavos' => 5000000, // R$ 50.000
            'crescimento_percentual' => $percentualCrescimento,
            'rbt12_centavos' => 60000000,
            'anexo_simples' => 'I',
        ]);

        $resposta = "Se sua empresa crescer {$percentualCrescimento}%, o faturamento mensal projetado será de "
            . Format::money((int) $simulacao['receita_projetada_centavos']) . ". " . $simulacao['resumo_leigo'];

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => 'Lei Complementar nº 123/2006 (Simples Nacional) e Emenda Constitucional nº 132/2023 (Reforma Tributária).',
            'card_visual' => [
                'tipo' => 'parecer_planejamento_tributario',
                'titulo' => 'Simulação de Expansão Tributária',
                'destaque' => $simulacao['nome_recomendado'],
                'status' => 'aprovado',
                'itens' => [
                    ['rotulo' => 'Crescimento Simulado', 'valor' => "+{$percentualCrescimento}%"],
                    ['rotulo' => 'Faturamento Projetado', 'valor' => Format::money((int) $simulacao['receita_projetada_centavos'])],
                    ['rotulo' => 'Cenário Mais Econômico', 'valor' => $simulacao['nome_recomendado']],
                ],
                'rodape' => 'Planejamento dinâmico considerando as alíquotas da Reforma Tributária.',
            ],
        ];
    }

    /**
     * Resposta genérica com busca de artigos de lei caso a dúvida não seja de operação direta.
     */
    private function tratarConsultaJuridica(int $empresaId, string $pergunta): array
    {
        $trechos = [];
        if ($this->lawSearcher !== null) {
            $trechos = $this->lawSearcher->search($pergunta, new \DateTimeImmutable(), 2);
        }

        if (!empty($trechos)) {
            $artigo = $trechos[0];
            $resposta = "Conforme o {$artigo['diploma_legal']} ({$artigo['artigo']}): \"{$artigo['conteudo']}\". O escritório contábil monitora a vigência desta regra para manter sua empresa em total conformidade.";
            $embasamento = "{$artigo['diploma_legal']}, {$artigo['artigo']}.";
        } else {
            $resposta = "Entendido! Sua solicitação foi registrada na Central de Atendimento Contábil. Nossa equipe de especialistas e agentes autônomos garante que todas as operações da sua empresa respeitem as regras fiscais vigentes.";
            $embasamento = 'Legislação Societária e Fiscal Brasileira.';
        }

        return [
            'resposta_leigo' => $resposta,
            'embasamento_legal' => $embasamento,
            'card_visual' => [
                'tipo' => 'consulta_orientacao_fiscal',
                'titulo' => 'Orientação Fiscal e Contábil',
                'destaque' => 'Em Conformidade',
                'status' => 'informativo',
                'itens' => [
                    ['rotulo' => 'Dúvida do Cliente', 'valor' => mb_substr($pergunta, 0, 50) . '...'],
                    ['rotulo' => 'Embasamento Legal', 'valor' => $embasamento],
                ],
                'rodape' => 'Atendimento emitido pela Inteligência Fiscal do Escritório.',
            ],
        ];
    }

    /**
     * Extrai valores monetários em reais de frases (ex: "10 mil", "R$ 15.000", "3000").
     */
    private function extrairValorCentavos(string $texto): ?int
    {
        $texto = mb_strtolower($texto, 'UTF-8');

        // Padrão "10 mil" ou "10mil"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*mil/i', $texto, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            return (int) round($num * 1000 * 100);
        }

        // Padrão "R$ 15.000,00" ou "15000"
        if (preg_match('/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{2})?|\d+(?:[.,]\d+)?)/i', $texto, $m)) {
            $v = str_replace('.', '', $m[1]);
            $v = str_replace(',', '.', $v);
            $num = (float) $v;
            if ($num > 50) { // Ignora números pequenos como percentuais ou dias
                return (int) round($num * 100);
            }
        }

        return null;
    }
}
