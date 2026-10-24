<?php

declare(strict_types=1);

namespace App\Http;

use App\App\AuditService;
use App\App\BankService;
use App\App\CompanyService;
use App\App\KnowledgeService;
use App\App\LedgerService;
use App\App\ReportService;
use App\App\SettingsService;
use App\App\StrategyService;
use App\Core\Database;
use App\Core\Format;
use App\Core\InvalidXmlException;
use App\Core\ReportBlockedException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Core\View;

final class Kernel
{
    private CompanyService $empresas;
    private AuditService $auditoria;
    private BankService $bancos;
    private LedgerService $razao;
    private KnowledgeService $leis;
    private StrategyService $estrategia;
    private ReportService $relatorios;
    private SettingsService $config;
    private View $view;

    public function __construct(private readonly Database $db)
    {
        $this->config = new SettingsService($db);
        $this->razao = new LedgerService($db);
        $this->empresas = new CompanyService($db, $this->razao);
        $this->auditoria = new AuditService($db, $this->razao, $this->config);
        $this->bancos = new BankService($db, $this->razao, $this->config);
        $this->leis = new KnowledgeService($db, $this->config);
        $this->estrategia = new StrategyService($db, $this->config);
        $this->relatorios = new ReportService($db, $this->estrategia);
        $this->view = new View(ROOT . '/views');
    }

    public function handle(Request $request): void
    {
        try {
            if ($request->method() === 'POST' && $request->path() !== '/webhooks/open-finance') {
                $token = (string) ($_POST['_csrf'] ?? '');
                if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
                    $this->falha(419, 'A sessão expirou. Atualize a página e envie de novo.');
                    return;
                }
            }
            $this->despachar($request);
        } catch (ValidationException | InvalidXmlException $erro) {
            $this->flash('erro', $erro->getMessage());
            $this->voltar();
        } catch (ReportBlockedException $erro) {
            $this->pagina('bloqueio', ['titulo' => 'Parecer bloqueado', 'mensagem' => $erro->getMessage()], 'relatorios');
        } catch (\Throwable $erro) {
            $this->falha(500, $erro->getMessage());
        }
    }

    private function despachar(Request $request): void
    {
        $metodo = $request->method();
        $caminho = $request->path();
        if ($metodo === 'GET' && $caminho === '/') {
            $this->painel();
            return;
        }
        if ($metodo === 'POST' && $caminho === '/empresa-ativa') {
            $_SESSION['empresa_id'] = (int) $request->input('empresa_id');
            $this->redirect($request->input('voltar', '/'));
        }
        if ($metodo === 'GET' && $caminho === '/empresas') {
            $this->pagina('empresas', ['titulo' => 'Empresas'], 'empresas');
            return;
        }
        if ($metodo === 'POST' && $caminho === '/empresas') {
            $id = $this->empresas->salvar(null, $_POST);
            $_SESSION['empresa_id'] = $id;
            $this->flash('ok', 'Empresa criada e plano de contas aberto.');
            $this->redirect('/empresas/' . $id);
        }
        if (preg_match('#^/empresas/(\d+)$#', $caminho, $m)) {
            $this->empresa((int) $m[1], $metodo, $request);
            return;
        }
        if ($metodo === 'POST' && preg_match('#^/pessoas/(\d+)/excluir$#', $caminho, $m)) {
            $this->empresas->excluirPessoa((int) $m[1]);
            $this->flash('ok', 'Pessoa removida.');
            $this->voltar();
        }
        if ($metodo === 'GET' && $caminho === '/auditoria') {
            $empresa = $this->empresaAtual();
            $this->pagina('auditoria', [
                'titulo' => 'Auditoria fiscal',
                'documentos' => $empresa ? $this->auditoria->documentos((int) $empresa['id'], $request->query('status')) : [],
                'filtro' => $request->query('status'),
            ], 'auditoria');
            return;
        }
        if ($metodo === 'POST' && $caminho === '/auditoria/upload') {
            $this->upload();
            return;
        }
        if ($metodo === 'GET' && $caminho === '/auditoria/csv') {
            $empresa = $this->exigirEmpresa();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="auditoria.csv"');
            echo "\xEF\xBB\xBF" . $this->auditoria->csv((int) $empresa['id']);
            return;
        }
        if (preg_match('#^/documentos/(\d+)$#', $caminho, $m) && $metodo === 'GET') {
            $documento = $this->auditoria->documento((int) $m[1]);
            if ($documento === null) {
                $this->falha(404, 'Documento não encontrado.');
                return;
            }
            $this->pagina('documento', ['titulo' => 'Nota ' . $documento['numero'], 'documento' => $documento, 'csts' => $this->db->all('SELECT * FROM cst_ibs_cbs ORDER BY codigo')], 'auditoria');
            return;
        }
        if ($metodo === 'POST' && preg_match('#^/documentos/(\d+)/direcao$#', $caminho, $m)) {
            $this->auditoria->definirDirecao((int) $m[1], $request->input('direcao'));
            $this->flash('ok', 'Direção definida e lançamento gravado no razão.');
            $this->redirect('/documentos/' . $m[1]);
        }
        if ($metodo === 'POST' && preg_match('#^/itens/(\d+)/corrigir$#', $caminho, $m)) {
            $this->auditoria->corrigirItem((int) $m[1], $_POST);
            $this->flash('ok', 'Correção registrada. A nota original não foi alterada.');
            $this->voltar();
        }
        if ($caminho === '/conciliacao') {
            $this->conciliacao($metodo, $request);
            return;
        }
        if ($metodo === 'POST' && $caminho === '/webhooks/open-finance') {
            $resultado = $this->bancos->receberWebhook($request->json(), $request->header('X-Webhook-Token'));
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($metodo === 'POST' && preg_match('#^/conciliacoes/(\d+)/confirmar$#', $caminho, $m)) {
            $this->bancos->confirmar((int) $m[1]);
            $this->flash('ok', 'Conciliação confirmada e lançada no razão.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && preg_match('#^/conciliacoes/(\d+)/rejeitar$#', $caminho, $m)) {
            $this->bancos->rejeitar((int) $m[1]);
            $this->flash('ok', 'Sugestão recusada.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && preg_match('#^/transacoes/(\d+)/ignorar$#', $caminho, $m)) {
            $this->bancos->ignorar((int) $m[1]);
            $this->flash('ok', 'Lançamento ignorado.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && preg_match('#^/transacoes/(\d+)/lancar$#', $caminho, $m)) {
            $this->bancos->lancarManual((int) $m[1], $request->input('conta'));
            $this->flash('ok', 'Lançamento manual gravado no razão.');
            $this->redirect('/conciliacao');
        }
        if ($caminho === '/estrategia') {
            $this->estrategia($metodo, $request);
            return;
        }
        if ($caminho === '/legislacao') {
            $this->legislacao($metodo, $request);
            return;
        }
        if ($caminho === '/contabil') {
            $this->contabil($metodo, $request);
            return;
        }
        if ($caminho === '/agentes' && $metodo === 'GET') {
            $empresa = $this->empresaAtual();
            $this->pagina('agentes', [
                'titulo' => 'Agentes',
                'logs' => $this->leis->logs($empresa ? (int) $empresa['id'] : null),
                'llm' => $this->config->llm()->configurado(),
                'modelo' => $this->config->llm()->modelo(),
            ], 'agentes');
            return;
        }
        if ($caminho === '/relatorios') {
            $this->relatorio($metodo, $request);
            return;
        }
        if ($caminho === '/configuracoes') {
            $this->configuracoes($metodo, $request);
            return;
        }
        if ($metodo === 'GET' && $caminho === '/marca') {
            $this->marca();
            return;
        }
        if ($metodo === 'GET' && $caminho === '/saude') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'php' => PHP_VERSION,
                'banco' => $this->db->driver(),
                'extensoes' => [
                    'bcmath' => extension_loaded('bcmath'),
                    'curl' => extension_loaded('curl'),
                    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                    'zip' => extension_loaded('zip'),
                    'xmlreader' => extension_loaded('xmlreader'),
                ],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($metodo === 'GET' && $caminho === '/configuracoes/modelo-cclass.csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            echo "codigo;cst;descricao;reducao_ibs;reducao_cbs;fundamento;vigencia_inicio;vigencia_fim\n000001;000;Tributação integral;0;0;Tabela oficial;2026-01-01;\n";
            return;
        }
        if ($metodo === 'GET' && $caminho === '/configuracoes/modelo-regras.csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            echo "ncm;match_tipo;descricao;reducao;cst;cclass_trib;palavras_incluir;palavras_excluir;fundamento;vigencia_inicio;vigencia_fim;prioridade\n";
            return;
        }
        $this->falha(404, 'Página não encontrada.');
    }

    private function painel(): void
    {
        $empresa = $this->empresaAtual();
        $dados = ['titulo' => 'Painel', 'tarefas' => [], 'documentos' => [], 'contas' => [], 'equacao' => null, 'temEmpresas' => $this->empresas->empresas() !== []];
        if ($empresa) {
            $dados['tarefas'] = $this->auditoria->tarefas((int) $empresa['id']);
            $dados['documentos'] = array_slice($this->auditoria->documentos((int) $empresa['id']), 0, 6);
            $dados['contas'] = $this->bancos->contas((int) $empresa['id']);
            $dados['equacao'] = (new \App\Agents\Orchestrators\SanityReverseAuditor($this->db))->equacao((int) $empresa['id']);
        }
        $this->pagina('painel', $dados, 'painel');
    }

    private function empresa(int $id, string $metodo, Request $request): void
    {
        if ($metodo === 'POST' && $request->input('_acao') === 'excluir') {
            $this->empresas->excluir($id);
            if ((int) ($_SESSION['empresa_id'] ?? 0) === $id) {
                unset($_SESSION['empresa_id']);
            }
            $this->flash('ok', 'Empresa e seus lançamentos foram excluídos.');
            $this->redirect('/empresas');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'pessoa') {
            $this->empresas->salvarPessoa($id, $_POST);
            $this->flash('ok', 'Pessoa cadastrada.');
            $this->redirect('/empresas/' . $id);
        }
        if ($metodo === 'POST') {
            $this->empresas->salvar($id, $_POST);
            $this->flash('ok', 'Cadastro atualizado. O capital social já lançado não muda por aqui; use o razão.');
            $this->redirect('/empresas/' . $id);
        }
        $empresa = $this->empresas->obter($id);
        if ($empresa === null) {
            $this->falha(404, 'Empresa não encontrada.');
            return;
        }
        $_SESSION['empresa_id'] = $id;
        $this->pagina('empresa', [
            'titulo' => $empresa['razao_social'],
            'cadastro' => $empresa,
            'pessoas' => $this->empresas->pessoas($id),
            'balancete' => $this->razao->balancete($id),
        ], 'empresas');
    }

    private function upload(): void
    {
        $empresa = $this->exigirEmpresa();
        $arquivos = $this->arquivos('arquivos');
        if ($arquivos === []) {
            throw new ValidationException('Selecione ao menos um XML, SPED ou ZIP.');
        }
        $ids = [];
        foreach ($arquivos as $arquivo) {
            $ids = array_merge($ids, $this->auditoria->importar((int) $empresa['id'], $arquivo['tmp'], $arquivo['nome']));
        }
        $this->flash('ok', count($ids) . ' documento(s) auditado(s) e lançado(s) quando a direção estava clara.');
        $this->redirect(count($ids) === 1 ? '/documentos/' . $ids[0] : '/auditoria');
    }

    private function conciliacao(string $metodo, Request $request): void
    {
        $empresa = $this->exigirEmpresa();
        if ($metodo === 'POST' && $request->input('_acao') === 'conta') {
            $this->bancos->criarConta((int) $empresa['id'], $_POST);
            $this->flash('ok', 'Conta bancária aberta.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'excluir-conta') {
            $this->bancos->excluirConta((int) $request->input('conta_id'));
            $this->flash('ok', 'Conta removida.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'importar') {
            $arquivo = $this->arquivos('extrato')[0] ?? null;
            if ($arquivo === null) {
                throw new ValidationException('Envie um OFX ou CSV.');
            }
            $resultado = $this->bancos->importarArquivo((int) $request->input('conta_id'), $arquivo['tmp'], $arquivo['nome']);
            $this->flash('ok', $resultado['importadas'] . ' transações importadas, ' . $resultado['automaticas'] . ' conciliadas sozinhas e ' . $resultado['fila'] . ' na fila.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'cruzar') {
            $resultado = $this->bancos->reprocessarPendentes((int) $empresa['id']);
            $this->flash('ok', $resultado['automaticas'] . ' pendências conciliadas e ' . $resultado['fila'] . ' novas sugestões.');
            $this->redirect('/conciliacao');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'pluggy') {
            $resultado = $this->bancos->sincronizarPluggy((int) $request->input('conta_id'), $request->input('de'), $request->input('ate'));
            $this->flash('ok', 'Pluggy: ' . $resultado['importadas'] . ' transações novas.');
            $this->redirect('/conciliacao');
        }
        $this->pagina('conciliacao', [
            'titulo' => 'Conciliação',
            'contas' => $this->bancos->contas((int) $empresa['id']),
            'transacoes' => $this->bancos->transacoes((int) $empresa['id']),
            'plano' => $this->razao->plano((int) $empresa['id']),
        ], 'conciliacao');
    }

    private function estrategia(string $metodo, Request $request): void
    {
        $empresa = $this->exigirEmpresa();
        $competencia = $request->input('competencia', date('Y-m'));
        if ($metodo === 'POST') {
            $resultado = $this->estrategia->simular($empresa, $_POST);
            $this->pagina('estrategia', [
                'titulo' => 'Estratégia tributária',
                'resultado' => $resultado,
                'coletado' => $resultado['coletado'],
                'competencia' => $resultado['competencia'],
            ], 'estrategia');
            return;
        }
        $this->pagina('estrategia', [
            'titulo' => 'Estratégia tributária',
            'resultado' => $this->estrategia->ultima((int) $empresa['id']),
            'coletado' => $this->estrategia->coletar((int) $empresa['id'], $competencia),
            'competencia' => $competencia,
        ], 'estrategia');
    }

    private function legislacao(string $metodo, Request $request): void
    {
        if ($metodo === 'POST' && $request->input('_acao') === 'reindexar') {
            $total = $this->leis->reindexarArquivos();
            $this->flash('ok', $total . ' trechos reindexados a partir de database/leis.');
            $this->redirect('/legislacao');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'importar') {
            $arquivo = $this->arquivos('markdown')[0] ?? null;
            if ($arquivo === null) {
                throw new ValidationException('Envie um markdown.');
            }
            $conteudo = file_get_contents($arquivo['tmp']);
            $total = $this->leis->importarMarkdown($conteudo === false ? '' : $conteudo, $arquivo['nome']);
            $this->flash('ok', $total . ' trechos importados.');
            $this->redirect('/legislacao');
        }
        $consulta = $request->query('q');
        $competencia = $request->query('competencia', date('Y-m-d'));
        $resposta = null;
        if ($metodo === 'POST') {
            $consulta = $request->input('q');
            $competencia = $request->input('competencia', date('Y-m-d'));
            $empresa = $this->empresaAtual();
            $resposta = $this->leis->responder($consulta, $competencia, $empresa ? (int) $empresa['id'] : null);
        }
        $this->pagina('legislacao', [
            'titulo' => 'Legislação',
            'consulta' => $consulta,
            'competencia' => $competencia,
            'trechos' => $consulta !== '' && $resposta === null ? $this->leis->pesquisar($consulta, $competencia) : ($resposta['trechos'] ?? []),
            'resposta' => $resposta,
            'acervo' => (new \App\KnowledgeBase\AcervoCatalog())->contar(),
            'acervoPasta' => is_dir(\App\KnowledgeBase\AcervoCatalog::pasta()),
        ], 'legislacao');
    }

    private function contabil(string $metodo, Request $request): void
    {
        $empresa = $this->exigirEmpresa();
        if ($metodo === 'POST') {
            $linhas = [];
            $contas = $_POST['conta'] ?? [];
            $tipos = $_POST['tipo'] ?? [];
            $valores = $_POST['valor'] ?? [];
            foreach ($contas as $indice => $codigo) {
                if ($codigo === '' || ($valores[$indice] ?? '') === '') {
                    continue;
                }
                $linhas[] = [
                    'codigo' => (string) $codigo,
                    'tipo' => (string) ($tipos[$indice] ?? 'D'),
                    'centavos' => Format::parseMoney((string) $valores[$indice]),
                ];
            }
            $this->razao->lancar((int) $empresa['id'], $request->input('data'), $request->input('historico'), 'manual', null, $linhas);
            $this->flash('ok', 'Lançamento gravado.');
            $this->redirect('/contabil');
        }
        $auditor = new \App\Agents\Orchestrators\SanityReverseAuditor($this->db);
        $this->pagina('contabil', [
            'titulo' => 'Contabilidade',
            'balancete' => $this->razao->balancete((int) $empresa['id']),
            'lancamentos' => $this->razao->recentes((int) $empresa['id']),
            'plano' => $this->razao->plano((int) $empresa['id']),
            'equacao' => $auditor->equacao((int) $empresa['id']),
        ], 'contabil');
    }

    private function relatorio(string $metodo, Request $request): void
    {
        $empresa = $this->exigirEmpresa();
        if ($request->query('formato') === 'pdf') {
            $pacote = $this->relatorios->parecer((int) $empresa['id'], true);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="parecer.pdf"');
            echo $this->relatorios->pdf($pacote);
            return;
        }
        if ($request->query('formato') === 'html') {
            $pacote = $this->relatorios->parecer((int) $empresa['id'], false);
            $this->view->raw('parecer', $pacote + ['emitido' => $pacote['saude']['aprovado']]);
            return;
        }
        $pacote = $this->relatorios->parecer((int) $empresa['id'], false);
        $this->pagina('relatorios', ['titulo' => 'Relatórios', 'pacote' => $pacote], 'relatorios');
    }

    private function configuracoes(string $metodo, Request $request): void
    {
        if ($metodo === 'POST' && $request->input('_acao') === 'escritorio') {
            $this->config->salvarEscritorio($_POST);
            $this->flash('ok', 'Escritório atualizado.');
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'logo') {
            $this->config->salvarLogo($_FILES['logo'] ?? []);
            $this->flash('ok', 'Marca atualizada.');
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'ia') {
            foreach (['ai.provedor', 'ai.modelo', 'ai.api_key', 'ai.base_url'] as $chave) {
                $campo = str_replace('.', '_', $chave);
                if ($chave === 'ai.api_key' && $request->input($campo) === '') {
                    continue;
                }
                $this->config->definir($chave, $request->input($campo));
            }
            $this->flash('ok', 'Modelo de linguagem atualizado. Sem chave, os agentes seguem só nas ferramentas determinísticas.');
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'pluggy') {
            foreach (['pluggy.client_id', 'pluggy.client_secret', 'pluggy.base_url', 'webhook.token'] as $chave) {
                $campo = str_replace('.', '_', $chave);
                if (str_contains($chave, 'secret') && $request->input($campo) === '') {
                    continue;
                }
                $this->config->definir($chave, $request->input($campo));
            }
            $this->flash('ok', 'Open Finance atualizado.');
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'aliquota') {
            $this->config->salvarAliquota($_POST);
            $this->flash('ok', 'Alíquota de referência gravada.');
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'cclass') {
            $this->importarCclass();
            $this->redirect('/configuracoes');
        }
        if ($metodo === 'POST' && $request->input('_acao') === 'regras') {
            $this->importarRegras();
            $this->redirect('/configuracoes');
        }
        $this->pagina('configuracoes', [
            'titulo' => 'Configurações',
            'escritorio' => $this->empresas->escritorio(),
            'aliquotas' => $this->config->aliquotas(),
            'cclass' => $this->db->all('SELECT * FROM cclass_trib ORDER BY codigo'),
            'regras' => $this->db->all('SELECT * FROM regras_ncm ORDER BY ncm, prioridade DESC'),
            'config' => [
                'provedor' => $this->config->obter('ai.provedor'),
                'modelo' => $this->config->obter('ai.modelo'),
                'tem_chave' => $this->config->obter('ai.api_key') !== '' || (string) env('GEMINI_API_KEY', '') !== '',
                'base_url' => $this->config->obter('ai.base_url'),
                'pluggy_id' => $this->config->obter('pluggy.client_id'),
                'pluggy_url' => $this->config->obter('pluggy.base_url'),
                'tem_pluggy' => $this->config->obter('pluggy.client_secret') !== '',
                'webhook' => $this->config->obter('webhook.token') !== '',
                'tabela_completa' => $this->config->obter('tabelas.cclass_oficial_completa') === '1',
            ],
        ], 'configuracoes');
    }

    private function importarCclass(): void
    {
        $arquivo = $this->arquivos('csv')[0] ?? null;
        if ($arquivo === null) {
            throw new ValidationException('Envie o CSV oficial do cClassTrib.');
        }
        $linhas = $this->csv($arquivo['tmp']);
        $total = 0;
        foreach ($linhas as $linha) {
            if (($linha['codigo'] ?? '') === '') {
                continue;
            }
            $this->db->run('DELETE FROM cclass_trib WHERE codigo = :codigo', [':codigo' => $linha['codigo']]);
            $this->db->insert('cclass_trib', [
                'codigo' => $linha['codigo'],
                'cst' => $linha['cst'] ?? '',
                'descricao' => $linha['descricao'] ?? '',
                'reducao_ibs' => $linha['reducao_ibs'] ?? '0',
                'reducao_cbs' => $linha['reducao_cbs'] ?? '0',
                'fundamento' => $linha['fundamento'] ?? 'Importado do CSV oficial',
                'vigencia_inicio' => $linha['vigencia_inicio'] ?? '2026-01-01',
                'vigencia_fim' => ($linha['vigencia_fim'] ?? '') !== '' ? $linha['vigencia_fim'] : null,
                'fonte' => 'importado',
            ]);
            $total++;
        }
        if (($_POST['oficial_completa'] ?? '') === '1') {
            $this->config->definir('tabelas.cclass_oficial_completa', '1');
        }
        $this->flash('ok', $total . ' códigos de cClassTrib importados.');
    }

    private function importarRegras(): void
    {
        $arquivo = $this->arquivos('csv')[0] ?? null;
        if ($arquivo === null) {
            throw new ValidationException('Envie o CSV de regras de NCM.');
        }
        $total = 0;
        foreach ($this->csv($arquivo['tmp']) as $linha) {
            if (($linha['ncm'] ?? '') === '') {
                continue;
            }
            $this->db->insert('regras_ncm', [
                'ncm' => Format::digitos($linha['ncm']),
                'match_tipo' => ($linha['match_tipo'] ?? 'prefixo') === 'exato' ? 'exato' : 'prefixo',
                'descricao' => $linha['descricao'] ?? '',
                'reducao' => $linha['reducao'] ?? '0',
                'cst' => $linha['cst'] ?? '000',
                'cclass_trib' => ($linha['cclass_trib'] ?? '') !== '' ? $linha['cclass_trib'] : null,
                'palavras_incluir' => json_encode($this->lista($linha['palavras_incluir'] ?? ''), JSON_UNESCAPED_UNICODE),
                'palavras_excluir' => json_encode($this->lista($linha['palavras_excluir'] ?? ''), JSON_UNESCAPED_UNICODE),
                'fundamento' => $linha['fundamento'] ?? 'Regra importada pelo escritório',
                'vigencia_inicio' => $linha['vigencia_inicio'] ?? '2026-01-01',
                'vigencia_fim' => ($linha['vigencia_fim'] ?? '') !== '' ? $linha['vigencia_fim'] : null,
                'prioridade' => (int) ($linha['prioridade'] ?? 50),
            ]);
            $total++;
        }
        $this->flash('ok', $total . ' regras de NCM importadas.');
    }

    /** @return list<array<string, string>> */
    private function csv(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            return [];
        }
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }
        $linhas = preg_split("/\r\n|\n|\r/", trim($conteudo)) ?: [];
        $delimitador = substr_count($linhas[0] ?? '', ';') >= substr_count($linhas[0] ?? '', ',') ? ';' : ',';
        $cabecalho = str_getcsv(array_shift($linhas) ?: '', $delimitador);
        $saida = [];
        foreach ($linhas as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $colunas = str_getcsv($linha, $delimitador);
            $registro = [];
            foreach ($cabecalho as $indice => $nome) {
                $registro[trim((string) $nome)] = trim((string) ($colunas[$indice] ?? ''));
            }
            $saida[] = $registro;
        }
        return $saida;
    }

    /** @return list<string> */
    private function lista(string $valor): array
    {
        if ($valor === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $valor))));
    }

    private function marca(): void
    {
        $escritorio = $this->empresas->escritorio();
        $relativo = (string) ($escritorio['logo_path'] ?? '');
        $caminho = ROOT . '/' . $relativo;
        if ($relativo === '' || !is_file($caminho)) {
            $this->falha(404, 'Sem marca.');
            return;
        }
        $tipos = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
        $extensao = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($tipos[$extensao] ?? 'application/octet-stream'));
        readfile($caminho);
    }

    /** @param array<string, mixed> $dados */
    private function pagina(string $modelo, array $dados, string $pagina): void
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $empresa = $dados['empresa'] ?? $this->empresaAtual();
        if (isset($dados['cadastro'])) {
            $empresa = $this->empresaAtual();
        }
        $this->view->page($modelo, $dados + [
            'csrf' => $_SESSION['csrf'],
            'flash' => $flash,
            'escritorio' => $this->empresas->escritorio(),
            'listaEmpresas' => $this->empresas->empresas(),
            'empresa' => $empresa,
            'pagina' => $pagina,
        ]);
    }

    /** @return ?array<string, mixed> */
    private function empresaAtual(): ?array
    {
        $id = (int) ($_SESSION['empresa_id'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $empresa = $this->empresas->obter($id);
        if ($empresa === null) {
            unset($_SESSION['empresa_id']);
        }
        return $empresa;
    }

    /** @return array<string, mixed> */
    private function exigirEmpresa(): array
    {
        $empresa = $this->empresaAtual();
        if ($empresa === null) {
            throw new ValidationException('Cadastre e selecione uma empresa para usar esta área.');
        }
        return $empresa;
    }

    /** @return list<array{tmp:string,nome:string}> */
    private function arquivos(string $campo): array
    {
        if (!isset($_FILES[$campo])) {
            return [];
        }
        $arquivo = $_FILES[$campo];
        $nomes = is_array($arquivo['name']) ? $arquivo['name'] : [$arquivo['name']];
        $tmps = is_array($arquivo['tmp_name']) ? $arquivo['tmp_name'] : [$arquivo['tmp_name']];
        $erros = is_array($arquivo['error']) ? $arquivo['error'] : [$arquivo['error']];
        $saida = [];
        foreach ($nomes as $indice => $nome) {
            if (($erros[$indice] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $nome === '') {
                continue;
            }
            $saida[] = ['tmp' => (string) $tmps[$indice], 'nome' => (string) $nome];
        }
        return $saida;
    }

    private function flash(string $tipo, string $texto): void
    {
        $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
    }

    private function redirect(string $para): never
    {
        header('Location: ' . $para);
        exit;
    }

    private function voltar(): never
    {
        $origem = (string) ($_SERVER['HTTP_REFERER'] ?? '/');
        $this->redirect($origem !== '' ? $origem : '/');
    }

    private function falha(int $status, string $mensagem): void
    {
        http_response_code($status);
        $this->pagina('erro', ['titulo' => 'Erro', 'mensagem' => $mensagem, 'status' => $status], '');
    }
}
