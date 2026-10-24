<?php

declare(strict_types=1);

namespace App\Core;

use App\KnowledgeBase\LawChunker;

final class Migrator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function migrate(): void
    {
        foreach ($this->tabelas() as $sql) {
            $this->db->statement($sql);
        }
        $this->semear();
    }

    /** @return list<string> */
    private function tabelas(): array
    {
        $id = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        return [
            "CREATE TABLE IF NOT EXISTS escritorios (
                id $id, nome TEXT NOT NULL, cnpj TEXT, crc TEXT,
                cor TEXT NOT NULL DEFAULT '#1f4b3a', logo_path TEXT,
                cidade TEXT, telefone TEXT, email TEXT
            )",
            "CREATE TABLE IF NOT EXISTS empresas (
                id $id, escritorio_id INTEGER NOT NULL, razao_social TEXT NOT NULL,
                nome_fantasia TEXT, cnpj TEXT NOT NULL, ie TEXT, im TEXT, cnae TEXT,
                regime TEXT NOT NULL, anexo_simples TEXT, uf TEXT, municipio TEXT,
                capital_social_centavos INTEGER NOT NULL DEFAULT 0, inicio_atividade TEXT,
                ativa INTEGER NOT NULL DEFAULT 1, criada_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS pessoas (
                id $id, empresa_id INTEGER NOT NULL, tipo TEXT NOT NULL,
                documento TEXT NOT NULL, nome TEXT NOT NULL, ie TEXT, email TEXT, criada_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS plano_contas (
                id $id, empresa_id INTEGER NOT NULL, codigo TEXT NOT NULL, nome TEXT NOT NULL,
                natureza TEXT NOT NULL, grupo_conta TEXT NOT NULL, aceita_lancamento INTEGER NOT NULL DEFAULT 1,
                UNIQUE (empresa_id, codigo)
            )",
            "CREATE TABLE IF NOT EXISTS lancamentos (
                id $id, empresa_id INTEGER NOT NULL, data TEXT NOT NULL, historico TEXT NOT NULL,
                origem TEXT NOT NULL, origem_id INTEGER, criado_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS partidas (
                id $id, lancamento_id INTEGER NOT NULL, conta_id INTEGER NOT NULL,
                tipo TEXT NOT NULL, valor_centavos INTEGER NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS documentos_fiscais (
                id $id, empresa_id INTEGER NOT NULL, modelo TEXT NOT NULL, chave TEXT,
                serie TEXT, numero TEXT, emissao TEXT, tipo_operacao TEXT NOT NULL,
                emitente_doc TEXT, emitente_nome TEXT, destinatario_doc TEXT, destinatario_nome TEXT,
                valor_produtos_centavos INTEGER NOT NULL DEFAULT 0, valor_nota_centavos INTEGER NOT NULL DEFAULT 0,
                valor_ibs_centavos INTEGER NOT NULL DEFAULT 0, valor_cbs_centavos INTEGER NOT NULL DEFAULT 0,
                status_auditoria TEXT NOT NULL, arquivo_nome TEXT, xml_path TEXT, criado_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS documento_itens (
                id $id, documento_id INTEGER NOT NULL, n_item INTEGER NOT NULL, codigo TEXT,
                descricao TEXT NOT NULL, ncm TEXT, cfop TEXT, cst TEXT, cclass_trib TEXT,
                reducao_ibs TEXT, reducao_cbs TEXT, quantidade TEXT, valor_centavos INTEGER NOT NULL,
                base_centavos INTEGER NOT NULL DEFAULT 0, aliquota_ibs TEXT, aliquota_cbs TEXT,
                valor_ibs_centavos INTEGER NOT NULL DEFAULT 0, valor_cbs_centavos INTEGER NOT NULL DEFAULT 0,
                valor_ibs_esperado_centavos INTEGER NOT NULL DEFAULT 0, valor_cbs_esperado_centavos INTEGER NOT NULL DEFAULT 0,
                cst_esperado TEXT, cclass_esperado TEXT, reducao_esperada TEXT, status TEXT NOT NULL,
                diagnostico TEXT, fundamento TEXT, sugestao TEXT, confianca INTEGER, modelo_agente TEXT,
                corrigido_por_contador INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS documento_alertas (
                id $id, documento_id INTEGER NOT NULL, item_id INTEGER,
                severidade TEXT NOT NULL, codigo TEXT NOT NULL, mensagem TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS regras_ncm (
                id $id, ncm TEXT NOT NULL, match_tipo TEXT NOT NULL, descricao TEXT NOT NULL,
                reducao TEXT NOT NULL, cst TEXT NOT NULL, cclass_trib TEXT,
                palavras_incluir TEXT, palavras_excluir TEXT, fundamento TEXT NOT NULL,
                vigencia_inicio TEXT NOT NULL, vigencia_fim TEXT, prioridade INTEGER NOT NULL DEFAULT 100
            )",
            "CREATE TABLE IF NOT EXISTS cclass_trib (
                id $id, codigo TEXT NOT NULL UNIQUE, cst TEXT NOT NULL, descricao TEXT NOT NULL,
                reducao_ibs TEXT NOT NULL, reducao_cbs TEXT NOT NULL, fundamento TEXT,
                vigencia_inicio TEXT NOT NULL, vigencia_fim TEXT, fonte TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS cst_ibs_cbs (
                codigo TEXT PRIMARY KEY, descricao TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS legislacao_chunks (
                id $id, diploma_legal TEXT NOT NULL, artigo TEXT, paragrafo_inciso TEXT,
                tipo_tributo TEXT, vigencia_inicio TEXT NOT NULL, vigencia_fim TEXT,
                titulo TEXT NOT NULL, conteudo TEXT NOT NULL, origem TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS aliquotas_referencia (
                ano INTEGER PRIMARY KEY, cbs TEXT NOT NULL, ibs TEXT NOT NULL,
                pis_cofins_vigente INTEGER NOT NULL, fator_icms_iss TEXT NOT NULL,
                cbs_compensavel INTEGER NOT NULL, ibs_compensavel INTEGER NOT NULL,
                natureza TEXT NOT NULL, observacao TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS contas_bancarias (
                id $id, empresa_id INTEGER NOT NULL, banco TEXT NOT NULL, agencia TEXT, conta TEXT,
                apelido TEXT NOT NULL, provedor TEXT, provedor_id TEXT, saldo_inicial_centavos INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS transacoes_bancarias (
                id $id, conta_id INTEGER NOT NULL, empresa_id INTEGER NOT NULL, data TEXT NOT NULL,
                valor_centavos INTEGER NOT NULL, descricao TEXT NOT NULL, documento TEXT, fitid TEXT,
                origem TEXT NOT NULL, status TEXT NOT NULL, conciliacao_id INTEGER, criado_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS conciliacoes (
                id $id, empresa_id INTEGER NOT NULL, transacao_id INTEGER NOT NULL, documento_id INTEGER,
                pontuacao INTEGER NOT NULL, confianca TEXT NOT NULL, motivo TEXT NOT NULL,
                status TEXT NOT NULL, criada_em TEXT NOT NULL, decidida_em TEXT
            )",
            "CREATE TABLE IF NOT EXISTS tarefas (
                id $id, empresa_id INTEGER NOT NULL, tipo TEXT NOT NULL, titulo TEXT NOT NULL,
                detalhe TEXT NOT NULL, referencia_tipo TEXT, referencia_id INTEGER,
                status TEXT NOT NULL, criada_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS agent_logs (
                id $id, empresa_id INTEGER, agente TEXT NOT NULL, acao TEXT NOT NULL,
                entrada TEXT NOT NULL, saida TEXT NOT NULL, ferramentas TEXT, modelo TEXT, criado_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS agent_feedback (
                id $id, empresa_id INTEGER NOT NULL, item_id INTEGER, ncm TEXT, descricao_produto TEXT,
                cst TEXT, cclass_trib TEXT, reducao TEXT, criado_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS simulacoes (
                id $id, empresa_id INTEGER NOT NULL, competencia TEXT NOT NULL,
                memoria TEXT NOT NULL, recomendacao TEXT NOT NULL, criada_em TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS configuracoes (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS jobs (
                id $id, tipo TEXT NOT NULL, payload TEXT NOT NULL, status TEXT NOT NULL,
                erro TEXT, criado_em TEXT NOT NULL, processado_em TEXT
            )",
            'CREATE INDEX IF NOT EXISTS idx_documentos_empresa ON documentos_fiscais (empresa_id, emissao)',
            'CREATE INDEX IF NOT EXISTS idx_transacoes_conta ON transacoes_bancarias (conta_id, data)',
            'CREATE INDEX IF NOT EXISTS idx_legislacao_vigencia ON legislacao_chunks (vigencia_inicio, vigencia_fim)',
        ];
    }

    private function semear(): void
    {
        if ($this->db->one('SELECT id FROM escritorios LIMIT 1') === null) {
            $this->db->insert('escritorios', [
                'nome' => 'Escritório',
                'cnpj' => '',
                'crc' => '',
                'cor' => '#1f4b3a',
                'logo_path' => null,
                'cidade' => '',
                'telefone' => '',
                'email' => '',
            ]);
        }
        $this->semearConfiguracao('tabelas.cclass_oficial_completa', '0');
        $this->semearConfiguracao('ai.provedor', '');
        $this->semearConfiguracao('ai.modelo', 'gemini-2.5-flash');
        $this->semearConfiguracao('ai.api_key', '');
        $this->semearConfiguracao('ai.base_url', '');
        $this->semearConfiguracao('pluggy.client_id', '');
        $this->semearConfiguracao('pluggy.client_secret', '');
        $this->semearConfiguracao('pluggy.base_url', 'https://api.pluggy.ai');
        $this->semearConfiguracao('webhook.token', '');
        if ($this->db->one('SELECT codigo FROM cst_ibs_cbs LIMIT 1') === null) {
            foreach ($this->csts() as $codigo => $descricao) {
                $this->db->insert('cst_ibs_cbs', ['codigo' => $codigo, 'descricao' => $descricao]);
            }
        }
        if ($this->db->one('SELECT id FROM cclass_trib LIMIT 1') === null) {
            $this->db->insert('cclass_trib', [
                'codigo' => '000001',
                'cst' => '000',
                'descricao' => 'Situações tributadas integralmente pelo IBS e pela CBS',
                'reducao_ibs' => '0',
                'reducao_cbs' => '0',
                'fundamento' => 'Código de tributação integral de uso consolidado nas notas técnicas da reforma. Complete a tabela importando o CSV oficial do cClassTrib.',
                'vigencia_inicio' => '2026-01-01',
                'vigencia_fim' => null,
                'fonte' => 'nucleo',
            ]);
        }
        if ($this->db->one('SELECT ano FROM aliquotas_referencia LIMIT 1') === null) {
            foreach ($this->aliquotas() as $linha) {
                $this->db->insert('aliquotas_referencia', $linha);
            }
        }
        if ($this->db->one('SELECT id FROM regras_ncm LIMIT 1') === null) {
            foreach ($this->regras() as $regra) {
                $this->db->insert('regras_ncm', $regra);
            }
        }
        if ($this->db->one('SELECT id FROM legislacao_chunks LIMIT 1') === null) {
            $this->importarLeis();
        }
    }

    public function importarLeis(): int
    {
        $this->db->run('DELETE FROM legislacao_chunks WHERE origem = :origem', [':origem' => 'arquivo']);
        $chunker = new LawChunker();
        $total = 0;
        foreach (glob(ROOT . '/database/leis/*.md') ?: [] as $arquivo) {
            foreach ($chunker->chunkArquivo($arquivo) as $chunk) {
                $this->db->insert('legislacao_chunks', $chunk);
                $total++;
            }
        }
        return $total;
    }

    private function semearConfiguracao(string $chave, string $valor): void
    {
        if ($this->db->one('SELECT chave FROM configuracoes WHERE chave = :chave', [':chave' => $chave]) === null) {
            $this->db->insert('configuracoes', ['chave' => $chave, 'valor' => $valor]);
        }
    }

    /** @return array<string, string> */
    private function csts(): array
    {
        return [
            '000' => 'Tributação integral',
            '010' => 'Tributação com alíquotas uniformes',
            '011' => 'Tributação com alíquotas uniformes reduzidas',
            '200' => 'Alíquota reduzida',
            '220' => 'Alíquota fixa',
            '400' => 'Isenção',
            '410' => 'Imunidade e não incidência',
            '510' => 'Diferimento',
            '550' => 'Suspensão',
            '620' => 'Tributação monofásica',
            '800' => 'Transferência de crédito',
            '810' => 'Ajuste de IBS e CBS',
            '820' => 'Tributação em documento de regime específico',
            '830' => 'Exclusão da base de cálculo',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function aliquotas(): array
    {
        return [
            $this->ano(2025, '0', '0', 1, '1.00', 0, 0, 'legal', 'Antes da fase de teste. IBS e CBS ainda não compõem a operação corrente.'),
            $this->ano(2026, '0.90', '0.10', 1, '1.00', 1, 1, 'legal', 'Alíquotas de teste da LC 214/2025. A CBS de 0,9% é compensável com PIS/Cofins e o IBS de 0,1% com ICMS ou ISS.'),
            $this->ano(2027, '8.80', '0.10', 0, '1.00', 0, 1, 'estimada', 'PIS e Cofins extintos. A CBS usa a alíquota de referência estimada, à espera da resolução do Senado. O IBS segue em 0,1%.'),
            $this->ano(2028, '8.80', '0.10', 0, '1.00', 0, 1, 'estimada', 'O IBS permanece na alíquota de teste de 0,1%.'),
            $this->ano(2029, '8.80', '1.77', 0, '0.90', 0, 0, 'estimada', 'IBS a 10% da referência estimada de 17,7%. ICMS e ISS reduzidos em 10%.'),
            $this->ano(2030, '8.80', '3.54', 0, '0.80', 0, 0, 'estimada', 'IBS a 20% da referência estimada. ICMS e ISS reduzidos em 20%.'),
            $this->ano(2031, '8.80', '5.31', 0, '0.70', 0, 0, 'estimada', 'IBS a 30% da referência estimada. ICMS e ISS reduzidos em 30%.'),
            $this->ano(2032, '8.80', '7.08', 0, '0.60', 0, 0, 'estimada', 'IBS a 40% da referência estimada. ICMS e ISS reduzidos em 40%.'),
            $this->ano(2033, '8.80', '17.70', 0, '0.00', 0, 0, 'estimada', 'IBS e CBS plenos na referência estimada. ICMS e ISS extintos. Ajuste os percentuais quando o Senado fixar a alíquota.'),
        ];
    }

    /** @return array<string, mixed> */
    private function ano(int $ano, string $cbs, string $ibs, int $pis, string $fator, int $cbsComp, int $ibsComp, string $natureza, string $obs): array
    {
        return [
            'ano' => $ano, 'cbs' => $cbs, 'ibs' => $ibs, 'pis_cofins_vigente' => $pis,
            'fator_icms_iss' => $fator, 'cbs_compensavel' => $cbsComp, 'ibs_compensavel' => $ibsComp,
            'natureza' => $natureza, 'observacao' => $obs,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function regras(): array
    {
        $cesta = [
            ['100620', 'Arroz descascado'],
            ['100630', 'Arroz semibranqueado ou polido'],
            ['100640', 'Arroz quebrado'],
            ['090121', 'Café torrado, não descafeinado'],
            ['090122', 'Café torrado, descafeinado'],
            ['040110', 'Leite com teor de gordura até 1%'],
            ['040120', 'Leite com teor de gordura entre 1% e 6%'],
            ['040140', 'Leite com teor de gordura acima de 6%'],
            ['071333', 'Feijão comum'],
            ['170114', 'Açúcar de cana'],
            ['170199', 'Outros açúcares de cana ou beterraba'],
            ['150790', 'Óleo de soja refinado'],
            ['110100', 'Farinha de trigo'],
            ['190211', 'Massas alimentícias com ovos, não cozidas'],
            ['190219', 'Massas alimentícias sem ovos, não cozidas'],
        ];
        $regras = [];
        foreach ($cesta as [$ncm, $descricao]) {
            $regras[] = $this->regra($ncm, 'prefixo', $descricao, '100', '200', null, [], [], 'LC 214/2025, cesta básica nacional de alimentos, alíquota zero. A vigência pode ser ajustada nesta tabela se a redação oficial do item tiver data diferente.', '2026-01-01', 40);
        }
        $regras[] = $this->regra('96190000', 'exato', 'Fraldas e cueiros', '60', '200', null, ['fralda', 'geriatr', 'infantil', 'bebe', 'cueiro'], ['absorvente', 'tampao', 'menstrual'], 'LC 214/2025, redução de 60% para fraldas. A descrição separa este item dos produtos de higiene menstrual.', '2026-01-01', 100);
        $regras[] = $this->regra('96190000', 'exato', 'Absorventes e tampões higiênicos', '100', '200', null, ['absorvente', 'tampao', 'menstrual'], ['fralda', 'geriatr', 'infantil', 'cueiro'], 'LC 214/2025, alíquota zero para produtos de higiene menstrual.', '2026-01-01', 100);
        return $regras;
    }

    /** @param list<string> $incluir @param list<string> $excluir @return array<string, mixed> */
    private function regra(string $ncm, string $tipo, string $descricao, string $reducao, string $cst, ?string $cclass, array $incluir, array $excluir, string $fundamento, string $inicio, int $prioridade): array
    {
        return [
            'ncm' => $ncm,
            'match_tipo' => $tipo,
            'descricao' => $descricao,
            'reducao' => $reducao,
            'cst' => $cst,
            'cclass_trib' => $cclass,
            'palavras_incluir' => json_encode($incluir, JSON_UNESCAPED_UNICODE),
            'palavras_excluir' => json_encode($excluir, JSON_UNESCAPED_UNICODE),
            'fundamento' => $fundamento,
            'vigencia_inicio' => $inicio,
            'vigencia_fim' => null,
            'prioridade' => $prioridade,
        ];
    }
}
