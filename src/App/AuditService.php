<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Orchestrators\DocumentAuditAgent;
use App\Core\Database;
use App\Core\Format;
use App\Core\InvalidXmlException;
use App\Core\Money;
use App\Core\NcmNotFoundException;
use App\Core\ValidationException;
use App\Fiscal\Engine\IbsCbsCalculator;
use App\Fiscal\Parsers\NfeXmlStreamParser;
use App\Fiscal\Parsers\SpedFiscalParser;
use App\Fiscal\Validators\CclassTribValidator;
use App\Fiscal\Validators\NcmCorrelationValidator;

final class AuditService
{
    public function __construct(
        private readonly Database $db,
        private readonly LedgerService $razao,
        private readonly SettingsService $config,
    ) {
    }

    /** @return list<int> */
    public function importar(int $empresaId, string $caminho, string $nome): array
    {
        $empresa = $this->db->one('SELECT * FROM empresas WHERE id = :id', [':id' => $empresaId]);
        if ($empresa === null) {
            throw new ValidationException('Escolha a empresa antes de importar.');
        }
        $documentos = $this->ler($caminho, $nome);
        $ids = [];
        foreach ($documentos as $documento) {
            $ids[] = $this->gravar($empresa, $documento, $nome, $caminho);
        }
        (new BankService($this->db, $this->razao, $this->config))->reprocessarPendentes($empresaId);
        $this->db->insert('jobs', [
            'tipo' => 'auditoria',
            'payload' => json_encode(['empresa_id' => $empresaId, 'arquivo' => $nome, 'documentos' => $ids], JSON_UNESCAPED_UNICODE),
            'status' => 'concluido',
            'erro' => null,
            'criado_em' => date('Y-m-d H:i:s'),
            'processado_em' => date('Y-m-d H:i:s'),
        ]);
        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function ler(string $caminho, string $nome): array
    {
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if ($extensao === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($caminho) !== true) {
                throw new InvalidXmlException('O ZIP não pôde ser aberto.');
            }
            $saida = [];
            $pasta = ROOT . '/storage/tmp';
            if (!is_dir($pasta)) {
                mkdir($pasta, 0777, true);
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $interno = (string) $zip->getNameIndex($i);
                if (str_ends_with($interno, '/')) {
                    continue;
                }
                $destino = $pasta . '/' . uniqid('lote_', true);
                file_put_contents($destino, $zip->getFromIndex($i));
                try {
                    $saida = array_merge($saida, $this->ler($destino, basename($interno)));
                } finally {
                    @unlink($destino);
                }
            }
            $zip->close();
            if ($saida === []) {
                throw new InvalidXmlException('O ZIP não contém XML nem SPED.');
            }
            return $saida;
        }
        $inicio = ltrim((string) file_get_contents($caminho, false, null, 0, 300));
        if (str_starts_with($inicio, '|')) {
            return (new SpedFiscalParser())->parseArquivo($caminho);
        }
        if (str_starts_with($inicio, '<')) {
            return (new NfeXmlStreamParser())->parseArquivo($caminho);
        }
        throw new InvalidXmlException('Formato não reconhecido. Envie XML de NF-e, NFC-e, CT-e, NFS-e, SPED ou ZIP desses arquivos.');
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $documento */
    private function gravar(array $empresa, array $documento, string $nome, string $caminho): int
    {
        return $this->db->transaction(function () use ($empresa, $documento, $nome, $caminho): int {
            $documento = $this->completarSped($empresa, $documento);
            $direcao = $this->direcao($empresa, $documento);
            $existente = $this->localizar($empresa['id'], $documento);
            $campos = [
                'empresa_id' => $empresa['id'],
                'modelo' => (string) $documento['modelo'],
                'chave' => (string) $documento['chave'],
                'serie' => (string) $documento['serie'],
                'numero' => (string) $documento['numero'],
                'emissao' => (string) $documento['emissao'],
                'tipo_operacao' => $direcao,
                'emitente_doc' => Format::digitos((string) $documento['emitente_doc']),
                'emitente_nome' => (string) $documento['emitente_nome'],
                'destinatario_doc' => Format::digitos((string) $documento['destinatario_doc']),
                'destinatario_nome' => (string) $documento['destinatario_nome'],
                'valor_produtos_centavos' => (int) $documento['valor_produtos_centavos'],
                'valor_nota_centavos' => (int) $documento['valor_nota_centavos'],
                'valor_ibs_centavos' => (int) $documento['valor_ibs_centavos'],
                'valor_cbs_centavos' => (int) $documento['valor_cbs_centavos'],
                'status_auditoria' => 'pendente',
                'arquivo_nome' => $nome,
                'xml_path' => null,
            ];
            if ($existente !== null) {
                $this->razao->estornar('documento', (int) $existente['id']);
                $this->db->run('DELETE FROM documento_alertas WHERE documento_id = :id', [':id' => $existente['id']]);
                $this->db->run('DELETE FROM documento_itens WHERE documento_id = :id', [':id' => $existente['id']]);
                $this->db->run('DELETE FROM tarefas WHERE referencia_tipo = :tipo AND referencia_id = :id', [':tipo' => 'documento', ':id' => $existente['id']]);
                $id = (int) $existente['id'];
                $sets = [];
                $params = [':id' => $id];
                foreach ($campos as $coluna => $valor) {
                    $sets[] = $coluna . ' = :' . $coluna;
                    $params[':' . $coluna] = $valor;
                }
                $this->db->run('UPDATE documentos_fiscais SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            } else {
                $campos['criado_em'] = date('Y-m-d H:i:s');
                $id = $this->db->insert('documentos_fiscais', $campos);
            }
            $cnpjEmpresa = Format::digitos((string) $empresa['cnpj']);
            if (Format::digitos((string) $documento['emitente_doc']) !== $cnpjEmpresa) {
                $this->guardarPessoa($empresa['id'], (string) $documento['emitente_doc'], (string) $documento['emitente_nome'], 'fornecedor');
            }
            if (Format::digitos((string) $documento['destinatario_doc']) !== $cnpjEmpresa) {
                $this->guardarPessoa($empresa['id'], (string) $documento['destinatario_doc'], (string) $documento['destinatario_nome'], 'cliente');
            }
            $status = $this->auditarItens($empresa, $id, $documento, $direcao);
            $this->db->run('UPDATE documentos_fiscais SET status_auditoria = :status WHERE id = :id', [':status' => $status, ':id' => $id]);
            $gravado = $this->db->one('SELECT * FROM documentos_fiscais WHERE id = :id', [':id' => $id]);
            if ($gravado !== null && $direcao !== 'indeterminada') {
                $this->razao->postarDocumento((int) $empresa['id'], $gravado);
            }
            if ($direcao === 'indeterminada') {
                $this->tarefa((int) $empresa['id'], 'Direção da nota ' . $documento['numero'], 'O CNPJ da empresa não é o emitente nem o destinatário. Defina se a nota é entrada ou saída para lançar no razão.', 'documento', $id);
            }
            if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'zip') {
                $this->copiarArquivo($caminho, (int) $empresa['id'], $id);
            }
            return $id;
        });
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $documento @return array<string, mixed> */
    private function completarSped(array $empresa, array $documento): array
    {
        if (($documento['especie'] ?? '') !== 'sped') {
            return $documento;
        }
        if (($documento['tipo_nf'] ?? '1') === '0') {
            $documento['destinatario_doc'] = $empresa['cnpj'];
            $documento['destinatario_nome'] = $empresa['razao_social'];
        } else {
            $documento['emitente_doc'] = $empresa['cnpj'];
            $documento['emitente_nome'] = $empresa['razao_social'];
        }
        return $documento;
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $documento */
    private function direcao(array $empresa, array $documento): string
    {
        $cnpj = Format::digitos((string) $empresa['cnpj']);
        $emit = Format::digitos((string) $documento['emitente_doc']);
        $dest = Format::digitos((string) $documento['destinatario_doc']);
        if ($cnpj !== '' && $cnpj === $emit) {
            return 'saida';
        }
        if ($cnpj !== '' && $cnpj === $dest) {
            return 'entrada';
        }
        return 'indeterminada';
    }

    /** @param array<string, mixed> $documento */
    private function localizar(int $empresaId, array $documento): ?array
    {
        if (($documento['chave'] ?? '') !== '') {
            $porChave = $this->db->one(
                'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND chave = :chave',
                [':empresa' => $empresaId, ':chave' => $documento['chave']],
            );
            if ($porChave !== null) {
                return $porChave;
            }
        }
        return $this->db->one(
            'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND modelo = :modelo AND numero = :numero AND serie = :serie AND emitente_doc = :emitente',
            [
                ':empresa' => $empresaId,
                ':modelo' => $documento['modelo'],
                ':numero' => $documento['numero'],
                ':serie' => $documento['serie'],
                ':emitente' => Format::digitos((string) $documento['emitente_doc']),
            ],
        );
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $documento */
    private function auditarItens(array $empresa, int $documentoId, array $documento, string $direcao): string
    {
        $competencia = (string) ($documento['emissao'] ?: date('Y-m-d'));
        $ano = (int) substr($competencia, 0, 4);
        $aliquota = $this->config->aliquotaDoAno($ano);
        $validador = new NcmCorrelationValidator(
            $this->db->all('SELECT * FROM regras_ncm'),
            new CclassTribValidator(
                $this->db->all('SELECT * FROM cclass_trib'),
                $this->config->obter('tabelas.cclass_oficial_completa') === '1',
            ),
        );
        $agente = new DocumentAuditAgent();
        $llm = $this->config->llm();
        $feedback = $this->db->all('SELECT * FROM agent_feedback WHERE empresa_id = :empresa', [':empresa' => $empresa['id']]);
        $calculadora = new IbsCbsCalculator();
        $exigeNcm = !in_array($documento['modelo'], ['nfse', '57'], true);
        $pior = 'conforme';
        foreach ($documento['itens'] as $item) {
            $classificacao = $this->classificar($validador, $item, $competencia, $exigeNcm);
            $hipoteses = $classificacao['hipoteses'] ?? [];
            $sugestao = $agente->sugerir($item, $classificacao, $feedback, $hipoteses, $llm);
            $esperado = $this->esperado($calculadora, $item, $classificacao, $aliquota);
            $classificacao = $this->cruzarValor($item, $classificacao, $esperado, $competencia, (string) $documento['modelo']);
            $itemId = $this->db->insert('documento_itens', [
                'documento_id' => $documentoId,
                'n_item' => (int) $item['n_item'],
                'codigo' => (string) $item['codigo'],
                'descricao' => (string) $item['descricao'],
                'ncm' => (string) $item['ncm'],
                'cfop' => (string) $item['cfop'],
                'cst' => (string) $item['cst'],
                'cclass_trib' => (string) $item['cclass_trib'],
                'reducao_ibs' => (string) $item['reducao_ibs'],
                'reducao_cbs' => (string) $item['reducao_cbs'],
                'quantidade' => (string) $item['quantidade'],
                'valor_centavos' => (int) $item['valor_centavos'],
                'base_centavos' => (int) $item['base_centavos'],
                'aliquota_ibs' => (string) $item['aliquota_ibs'],
                'aliquota_cbs' => (string) $item['aliquota_cbs'],
                'valor_ibs_centavos' => (int) $item['valor_ibs_centavos'],
                'valor_cbs_centavos' => (int) $item['valor_cbs_centavos'],
                'valor_ibs_esperado_centavos' => $esperado['ibs'],
                'valor_cbs_esperado_centavos' => $esperado['cbs'],
                'cst_esperado' => $classificacao['cst_esperado'],
                'cclass_esperado' => $classificacao['cclass_esperado'],
                'reducao_esperada' => $classificacao['reducao_esperada'],
                'status' => $classificacao['status'],
                'diagnostico' => $classificacao['diagnostico'],
                'fundamento' => $classificacao['fundamento'],
                'sugestao' => $sugestao['sugestao'],
                'confianca' => $sugestao['confianca'],
                'modelo_agente' => $sugestao['modelo'],
                'corrigido_por_contador' => 0,
            ]);
            foreach ($classificacao['alertas'] as $alerta) {
                $this->db->insert('documento_alertas', [
                    'documento_id' => $documentoId,
                    'item_id' => $itemId,
                    'severidade' => $alerta['severidade'],
                    'codigo' => $alerta['codigo'],
                    'mensagem' => $alerta['mensagem'],
                ]);
            }
            if (in_array($classificacao['status'], ['rejeitado', 'ambiguo', 'alerta'], true)) {
                $this->tarefa((int) $empresa['id'], 'Item ' . $item['n_item'] . ' da nota ' . $documento['numero'], (string) $classificacao['diagnostico'], 'item', $itemId);
            }
            $pior = $this->pior($pior, (string) $classificacao['status']);
        }
        $this->db->insert('agent_logs', [
            'empresa_id' => $empresa['id'],
            'agente' => 'auditor-de-documentos',
            'acao' => 'validar_regras_tributarias_item',
            'entrada' => json_encode(['documento' => $documento['numero'], 'itens' => count($documento['itens'])], JSON_UNESCAPED_UNICODE),
            'saida' => json_encode(['status' => $pior], JSON_UNESCAPED_UNICODE),
            'ferramentas' => 'validar_regras_tributarias_item',
            'modelo' => $llm->configurado() ? $llm->modelo() : 'deterministico',
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        return $pior;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function classificar(NcmCorrelationValidator $validador, array $item, string $competencia, bool $exigeNcm): array
    {
        if (!$exigeNcm && Format::digitos((string) $item['ncm']) === '') {
            return [
                'status' => 'alerta',
                'cst_esperado' => null,
                'cclass_esperado' => null,
                'reducao_esperada' => null,
                'fundamento' => 'Documento de serviço ou transporte, sem NCM de mercadoria.',
                'diagnostico' => 'Não há NCM para cruzar. Revise o código de serviço e o grupo IBS/CBS.',
                'alertas' => [['severidade' => 'alerta', 'codigo' => 'SERVICO_SEM_NCM', 'mensagem' => 'Documento sem NCM de mercadoria.']],
                'hipoteses' => [],
            ];
        }
        try {
            return $validador->validar($item, $competencia);
        } catch (NcmNotFoundException $erro) {
            return [
                'status' => 'rejeitado',
                'cst_esperado' => null,
                'cclass_esperado' => null,
                'reducao_esperada' => null,
                'fundamento' => '',
                'diagnostico' => $erro->getMessage(),
                'alertas' => [['severidade' => 'bloqueio', 'codigo' => 'NCM_AUSENTE', 'mensagem' => $erro->getMessage()]],
                'hipoteses' => [],
            ];
        }
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $classificacao @param array<string, mixed> $aliquota @return array{ibs:int,cbs:int} */
    private function esperado(IbsCbsCalculator $calculadora, array $item, array $classificacao, array $aliquota): array
    {
        if ($classificacao['reducao_esperada'] === null || $aliquota === []) {
            return ['ibs' => (int) $item['valor_ibs_centavos'], 'cbs' => (int) $item['valor_cbs_centavos']];
        }
        $base = Money::ofCents((int) $item['base_centavos'])->toDecimal();
        $calculo = $calculadora->calcular($base, (string) $aliquota['ibs'], (string) $aliquota['cbs'], (string) $classificacao['reducao_esperada'], (string) $classificacao['reducao_esperada']);
        return ['ibs' => $calculo['ibs_centavos'], 'cbs' => $calculo['cbs_centavos']];
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $classificacao @param array{ibs:int,cbs:int} $esperado @return array<string, mixed> */
    private function cruzarValor(array $item, array $classificacao, array $esperado, string $competencia, string $modelo): array
    {
        $declarado = (int) $item['valor_ibs_centavos'] + (int) $item['valor_cbs_centavos'];
        $calculado = $esperado['ibs'] + $esperado['cbs'];
        $temGrupo = $item['cst'] !== '' || $item['cclass_trib'] !== '' || $declarado > 0 || $item['aliquota_ibs'] !== '' || $item['aliquota_cbs'] !== '';
        if (!$temGrupo && $competencia >= '2026-01-01' && in_array($modelo, ['55', '65'], true)) {
            $classificacao['alertas'][] = ['severidade' => 'alerta', 'codigo' => 'GRUPO_AUSENTE', 'mensagem' => 'Nota emitida a partir de 2026 sem grupo IBS/CBS.'];
            $classificacao['status'] = $this->pior((string) $classificacao['status'], 'alerta');
            $classificacao['diagnostico'] = 'Grupo IBS/CBS ausente em documento de ' . $competencia . '.';
        }
        if ($temGrupo && $classificacao['reducao_esperada'] !== null && abs($declarado - $calculado) > 1 && ($item['aliquota_ibs'] === '' && $item['aliquota_cbs'] === '')) {
            $classificacao['alertas'][] = [
                'severidade' => 'bloqueio',
                'codigo' => 'VALOR_DIVERGENTE',
                'mensagem' => 'O IBS/CBS declarado é ' . Format::money($declarado) . ' e o cálculo da regra esperada, com a alíquota de referência do ano, é ' . Format::money($calculado) . '.',
            ];
            $classificacao['status'] = 'rejeitado';
            $classificacao['diagnostico'] = 'Valor de IBS/CBS divergente do cálculo determinístico.';
        }
        if ($item['aliquota_ibs'] !== '' || $item['aliquota_cbs'] !== '') {
            $base = Money::ofCents((int) $item['base_centavos'])->toDecimal();
            $peloDocumento = (new IbsCbsCalculator())->calcular(
                $base,
                $item['aliquota_ibs'] !== '' ? (string) $item['aliquota_ibs'] : '0',
                $item['aliquota_cbs'] !== '' ? (string) $item['aliquota_cbs'] : '0',
                '0',
                '0',
            );
            if (abs(($peloDocumento['ibs_centavos'] + $peloDocumento['cbs_centavos']) - $declarado) > 1) {
                $classificacao['alertas'][] = ['severidade' => 'bloqueio', 'codigo' => 'CALCULO_XML', 'mensagem' => 'O valor de IBS/CBS do XML não fecha com a base e as alíquotas declaradas no próprio item.'];
                $classificacao['status'] = 'rejeitado';
                $classificacao['diagnostico'] = 'A própria nota não fecha base × alíquota.';
            }
        }
        return $classificacao;
    }

    /** @param array<string, string> $dados */
    public function corrigirItem(int $itemId, array $dados): void
    {
        $item = $this->db->one('SELECT i.*, d.empresa_id, d.numero FROM documento_itens i JOIN documentos_fiscais d ON d.id = i.documento_id WHERE i.id = :id', [':id' => $itemId]);
        if ($item === null) {
            throw new ValidationException('Item não encontrado.');
        }
        $reducao = trim(str_replace(',', '.', $dados['reducao'] ?? ''));
        $cst = trim($dados['cst'] ?? '');
        $cclass = trim($dados['cclass_trib'] ?? '');
        if ($reducao === '' || !preg_match('/^\d+(\.\d+)?$/', $reducao) || $cst === '') {
            throw new ValidationException('Informe o CST e a redução adotados pelo escritório.');
        }
        if ($cclass !== '') {
            $linha = $this->db->one('SELECT * FROM cclass_trib WHERE codigo = :codigo', [':codigo' => $cclass]);
            if ($linha === null) {
                throw new ValidationException('O cClassTrib ' . $cclass . ' não está na tabela carregada. Deixe o campo em branco até importar o código oficial.');
            }
            if ((string) $linha['cst'] !== $cst || bccomp((string) $linha['reducao_ibs'], $reducao, 2) !== 0 || bccomp((string) $linha['reducao_cbs'], $reducao, 2) !== 0) {
                throw new ValidationException('O cClassTrib ' . $cclass . ' não combina com o CST ' . $cst . ' e a redução de ' . $reducao . '% adotados.');
            }
        }
        $this->db->run(
            'UPDATE documento_itens SET cst_esperado = :cst, reducao_esperada = :reducao, cclass_esperado = :cclass, status = :status, corrigido_por_contador = 1, sugestao = :sugestao, diagnostico = :diagnostico WHERE id = :id',
            [
                ':cst' => $cst,
                ':reducao' => $reducao,
                ':cclass' => $cclass,
                ':status' => 'corrigido',
                ':sugestao' => 'Classificação adotada pelo contador. A nota original permanece intacta.',
                ':diagnostico' => 'Corrigido pelo escritório para CST ' . $cst . ' e redução de ' . $reducao . '%.',
                ':id' => $itemId,
            ],
        );
        $this->db->insert('agent_feedback', [
            'empresa_id' => $item['empresa_id'],
            'item_id' => $itemId,
            'ncm' => $item['ncm'],
            'descricao_produto' => $item['descricao'],
            'cst' => $cst,
            'cclass_trib' => $cclass,
            'reducao' => $reducao,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        $this->db->run('UPDATE tarefas SET status = :status WHERE referencia_tipo = :tipo AND referencia_id = :id', [':status' => 'concluida', ':tipo' => 'item', ':id' => $itemId]);
        $this->atualizarStatusDocumento((int) $item['documento_id']);
    }

    public function definirDirecao(int $documentoId, string $direcao): void
    {
        if (!in_array($direcao, ['entrada', 'saida'], true)) {
            throw new ValidationException('A direção precisa ser entrada ou saída.');
        }
        $documento = $this->db->one('SELECT * FROM documentos_fiscais WHERE id = :id', [':id' => $documentoId]);
        if ($documento === null) {
            throw new ValidationException('Documento não encontrado.');
        }
        $this->razao->estornar('documento', $documentoId);
        $this->db->run('UPDATE documentos_fiscais SET tipo_operacao = :direcao WHERE id = :id', [':direcao' => $direcao, ':id' => $documentoId]);
        $documento['tipo_operacao'] = $direcao;
        $this->razao->postarDocumento((int) $documento['empresa_id'], $documento);
        $this->db->run('UPDATE tarefas SET status = :status WHERE referencia_tipo = :tipo AND referencia_id = :id', [':status' => 'concluida', ':tipo' => 'documento', ':id' => $documentoId]);
    }

    /** @return list<array<string, mixed>> */
    public function documentos(int $empresaId, string $status = ''): array
    {
        $sql = 'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa';
        $params = [':empresa' => $empresaId];
        if ($status !== '') {
            $sql .= ' AND status_auditoria = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY emissao DESC, id DESC';
        return $this->db->all($sql, $params);
    }

    public function documento(int $id): ?array
    {
        $documento = $this->db->one('SELECT * FROM documentos_fiscais WHERE id = :id', [':id' => $id]);
        if ($documento === null) {
            return null;
        }
        $documento['itens'] = $this->db->all('SELECT * FROM documento_itens WHERE documento_id = :id ORDER BY n_item', [':id' => $id]);
        $documento['alertas'] = $this->db->all('SELECT * FROM documento_alertas WHERE documento_id = :id', [':id' => $id]);
        return $documento;
    }

    /** @return list<array<string, mixed>> */
    public function tarefas(int $empresaId): array
    {
        return $this->db->all('SELECT * FROM tarefas WHERE empresa_id = :empresa AND status = :status ORDER BY id DESC', [':empresa' => $empresaId, ':status' => 'aberta']);
    }

    public function csv(int $empresaId): string
    {
        $linhas = ["nota;item;descricao;ncm;cst;cclass;reducao_declarada;reducao_esperada;status;diagnostico"];
        $itens = $this->db->all(
            'SELECT d.numero, i.* FROM documento_itens i JOIN documentos_fiscais d ON d.id = i.documento_id WHERE d.empresa_id = :empresa ORDER BY d.id, i.n_item',
            [':empresa' => $empresaId],
        );
        foreach ($itens as $item) {
            $linhas[] = implode(';', [
                $item['numero'], $item['n_item'], '"' . str_replace('"', '""', (string) $item['descricao']) . '"',
                $item['ncm'], $item['cst'], $item['cclass_trib'], $item['reducao_ibs'], $item['reducao_esperada'], $item['status'],
                '"' . str_replace('"', '""', (string) $item['diagnostico']) . '"',
            ]);
        }
        return implode("\n", $linhas);
    }

    private function atualizarStatusDocumento(int $documentoId): void
    {
        $itens = $this->db->all('SELECT status FROM documento_itens WHERE documento_id = :id', [':id' => $documentoId]);
        $pior = 'conforme';
        foreach ($itens as $item) {
            $pior = $this->pior($pior, (string) $item['status']);
        }
        $this->db->run('UPDATE documentos_fiscais SET status_auditoria = :status WHERE id = :id', [':status' => $pior, ':id' => $documentoId]);
    }

    private function pior(string $atual, string $novo): string
    {
        $ordem = ['conforme' => 0, 'corrigido' => 1, 'alerta' => 2, 'ambiguo' => 3, 'rejeitado' => 4];
        return ($ordem[$novo] ?? 0) > ($ordem[$atual] ?? 0) ? $novo : $atual;
    }

    private function tarefa(int $empresaId, string $titulo, string $detalhe, string $tipo, int $id): void
    {
        $this->db->insert('tarefas', [
            'empresa_id' => $empresaId,
            'tipo' => 'auditoria',
            'titulo' => $titulo,
            'detalhe' => $detalhe,
            'referencia_tipo' => $tipo,
            'referencia_id' => $id,
            'status' => 'aberta',
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
    }

    private function guardarPessoa(int $empresaId, string $documento, string $nome, string $tipo): void
    {
        $documento = Format::digitos($documento);
        if ($documento === '' || $nome === '') {
            return;
        }
        $existe = $this->db->one('SELECT id FROM pessoas WHERE empresa_id = :empresa AND documento = :documento', [':empresa' => $empresaId, ':documento' => $documento]);
        if ($existe !== null) {
            return;
        }
        $this->db->insert('pessoas', [
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'documento' => $documento,
            'nome' => $nome,
            'ie' => '',
            'email' => '',
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
    }

    private function copiarArquivo(string $caminho, int $empresaId, int $documentoId): void
    {
        if (!is_file($caminho)) {
            return;
        }
        $pasta = ROOT . '/storage/xml/' . $empresaId;
        if (!is_dir($pasta)) {
            mkdir($pasta, 0777, true);
        }
        $destino = $pasta . '/' . $documentoId . '.xml';
        copy($caminho, $destino);
        $this->db->run('UPDATE documentos_fiscais SET xml_path = :caminho WHERE id = :id', [':caminho' => 'storage/xml/' . $empresaId . '/' . $documentoId . '.xml', ':id' => $documentoId]);
    }
}
