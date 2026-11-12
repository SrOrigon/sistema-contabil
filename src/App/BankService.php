<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\ConciliadorExtratoAgente;
use App\Agents\Orchestrators\ReconciliationAgent;
use App\Agents\RegistroDeAgente;
use App\Banking\OpenFinance\PluggyClient;
use App\Banking\StatementImporter;
use App\Banking\Webhooks\TransactionWebhookHandler;
use App\Core\Database;
use App\Core\Format;
use App\Core\ValidationException;

final class BankService
{
    public function __construct(
        private readonly Database $db,
        private readonly LedgerService $razao,
        private readonly SettingsService $config,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function contas(int $empresaId): array
    {
        $contas = $this->db->all('SELECT * FROM contas_bancarias WHERE empresa_id = :empresa ORDER BY apelido', [':empresa' => $empresaId]);
        foreach ($contas as &$conta) {
            $soma = $this->db->one('SELECT COALESCE(SUM(valor_centavos), 0) AS total FROM transacoes_bancarias WHERE conta_id = :id AND status != :ignorada', [':id' => $conta['id'], ':ignorada' => 'ignorado']);
            $conta['saldo_centavos'] = (int) $conta['saldo_inicial_centavos'] + (int) ($soma['total'] ?? 0);
        }
        return $contas;
    }

    /** @param array<string, string> $dados */
    public function criarConta(int $empresaId, array $dados): int
    {
        $apelido = trim($dados['apelido'] ?? '');
        if ($apelido === '') {
            throw new ValidationException('Dê um nome à conta bancária.');
        }
        return $this->db->insert('contas_bancarias', [
            'empresa_id' => $empresaId,
            'banco' => trim($dados['banco'] ?? ''),
            'agencia' => trim($dados['agencia'] ?? ''),
            'conta' => trim($dados['conta'] ?? ''),
            'apelido' => $apelido,
            'provedor' => trim($dados['provedor'] ?? 'manual'),
            'provedor_id' => trim($dados['provedor_id'] ?? ''),
            'saldo_inicial_centavos' => Format::parseMoney($dados['saldo_inicial'] ?? '0'),
        ]);
    }

    public function excluirConta(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $transacoes = $this->db->all('SELECT id FROM transacoes_bancarias WHERE conta_id = :id', [':id' => $id]);
            foreach ($transacoes as $transacao) {
                $this->razao->estornar('conciliacao', (int) $transacao['id']);
                $this->db->run('DELETE FROM conciliacoes WHERE transacao_id = :id', [':id' => $transacao['id']]);
            }
            $this->db->run('DELETE FROM transacoes_bancarias WHERE conta_id = :id', [':id' => $id]);
            $this->db->run('DELETE FROM contas_bancarias WHERE id = :id', [':id' => $id]);
        });
    }

    /** @return array{importadas:int, automaticas:int, fila:int} */
    public function importarArquivo(int $contaId, string $caminho, string $nome): array
    {
        $conta = $this->exigirConta($contaId);
        $transacoes = (new StatementImporter())->importar($caminho, $nome);
        return $this->gravarTransacoes((int) $conta['empresa_id'], $contaId, $transacoes, str_ends_with(strtolower($nome), '.ofx') ? 'ofx' : 'csv');
    }

    /** @param array<string, mixed> $payload */
    public function receberWebhook(array $payload, string $token): array
    {
        $esperado = $this->config->obter('webhook.token');
        if ($esperado !== '' && !hash_equals($esperado, $token)) {
            throw new ValidationException('Token do webhook não confere.');
        }
        $interpretado = (new TransactionWebhookHandler())->interpretar($payload);
        $conta = $this->db->one('SELECT * FROM contas_bancarias WHERE provedor_id = :id', [':id' => $interpretado['conta_externa']]);
        if ($conta === null) {
            throw new ValidationException('Nenhuma conta local está ligada ao accountId ' . $interpretado['conta_externa'] . '.');
        }
        return $this->gravarTransacoes((int) $conta['empresa_id'], (int) $conta['id'], $interpretado['transacoes'], 'webhook');
    }

    /** @return array{importadas:int, automaticas:int, fila:int} */
    public function sincronizarPluggy(int $contaId, string $de, string $ate): array
    {
        $conta = $this->exigirConta($contaId);
        if (($conta['provedor_id'] ?? '') === '') {
            throw new ValidationException('Informe o accountId da Pluggy nesta conta.');
        }
        $cliente = new PluggyClient(
            $this->config->obter('pluggy.base_url') ?: 'https://api.pluggy.ai',
            $this->config->obter('pluggy.client_id') ?: (string) env('PLUGGY_CLIENT_ID', ''),
            $this->config->obter('pluggy.client_secret') ?: (string) env('PLUGGY_CLIENT_SECRET', ''),
        );
        $chave = $cliente->autenticar();
        $lista = $cliente->listarTransacoes($chave, (string) $conta['provedor_id'], $de, $ate);
        $interpretado = (new TransactionWebhookHandler())->interpretar(['accountId' => $conta['provedor_id'], 'transactions' => $lista]);
        return $this->gravarTransacoes((int) $conta['empresa_id'], $contaId, $interpretado['transacoes'], 'pluggy');
    }

    /** @param list<array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string}> $transacoes
     *  @return array{importadas:int, automaticas:int, fila:int}
     */
    private function gravarTransacoes(int $empresaId, int $contaId, array $transacoes, string $origem): array
    {
        $agente = new ReconciliationAgent();
        $documentos = $this->db->all('SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND tipo_operacao IN (\'entrada\', \'saida\')', [':empresa' => $empresaId]);
        $importadas = 0;
        $automaticas = 0;
        $fila = 0;
        $variaveis = 0;
        foreach ($transacoes as $transacao) {
            if ($this->duplicada($contaId, $transacao)) {
                continue;
            }
            $id = $this->db->insert('transacoes_bancarias', [
                'conta_id' => $contaId,
                'empresa_id' => $empresaId,
                'data' => $transacao['data'],
                'valor_centavos' => $transacao['valor_centavos'],
                'descricao' => $transacao['descricao'],
                'documento' => $transacao['documento'],
                'fitid' => $transacao['fitid'],
                'origem' => $origem,
                'status' => 'pendente',
                'conciliacao_id' => null,
                'criado_em' => date('Y-m-d H:i:s'),
            ]);
            $importadas++;
            $sugestao = $agente->sugerir($transacao, $documentos);
            if (($sugestao['modelo'] ?? '') === 'variavel') {
                $variaveis++;
            }
            $aplicada = $this->aplicarSugestao($empresaId, $id, $sugestao, $transacao);
            $automaticas += $aplicada['automaticas'];
            $fila += $aplicada['fila'];
        }
        if ($importadas > 0) {
            $contrato = ConciliadorExtratoAgente::contrato();
            RegistroDeAgente::gravar($this->db, (string) $contrato['id'], (string) $contrato['ferramenta'], $empresaId, [
                'origem' => $origem,
                'lancamentos' => $importadas,
            ], [
                'automaticas' => $automaticas,
                'fila' => $fila,
                'variavel_ilegivel' => $variaveis,
            ], $variaveis > 0 ? 'variavel' : 'deterministico');
        }
        return ['importadas' => $importadas, 'automaticas' => $automaticas, 'fila' => $fila];
    }

    /** @return array{automaticas:int, fila:int} */
    public function reprocessarPendentes(int $empresaId): array
    {
        $agente = new ReconciliationAgent();
        $documentos = $this->db->all('SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND tipo_operacao IN (\'entrada\', \'saida\')', [':empresa' => $empresaId]);
        $pendentes = $this->db->all(
            'SELECT * FROM transacoes_bancarias WHERE empresa_id = :empresa AND status = :status AND conciliacao_id IS NULL',
            [':empresa' => $empresaId, ':status' => 'pendente'],
        );
        $automaticas = 0;
        $fila = 0;
        $variaveis = 0;
        foreach ($pendentes as $transacao) {
            $sugestao = $agente->sugerir($transacao, $documentos);
            if (($sugestao['modelo'] ?? '') === 'variavel') {
                $variaveis++;
            }
            $aplicada = $this->aplicarSugestao($empresaId, (int) $transacao['id'], $sugestao, $transacao);
            $automaticas += $aplicada['automaticas'];
            $fila += $aplicada['fila'];
        }
        if ($pendentes !== []) {
            $contrato = ConciliadorExtratoAgente::contrato();
            RegistroDeAgente::gravar($this->db, (string) $contrato['id'], (string) $contrato['ferramenta'], $empresaId, [
                'origem' => 'pendentes',
                'lancamentos' => count($pendentes),
            ], [
                'automaticas' => $automaticas,
                'fila' => $fila,
                'variavel_ilegivel' => $variaveis,
            ], $variaveis > 0 ? 'variavel' : 'deterministico');
        }
        return ['automaticas' => $automaticas, 'fila' => $fila];
    }

    /**
     * @param array<string, mixed> $sugestao
     * @param array<string, mixed> $transacao
     * @return array{automaticas:int, fila:int}
     */
    private function aplicarSugestao(int $empresaId, int $transacaoId, array $sugestao, array $transacao): array
    {
        if ($sugestao['documento_id'] === null) {
            return ['automaticas' => 0, 'fila' => 0];
        }
        $conciliacao = $this->db->insert('conciliacoes', [
            'empresa_id' => $empresaId,
            'transacao_id' => $transacaoId,
            'documento_id' => $sugestao['documento_id'],
            'pontuacao' => $sugestao['pontuacao'],
            'confianca' => $sugestao['confianca'],
            'motivo' => $sugestao['card'] ?? $sugestao['motivo'],
            'status' => $sugestao['automatica'] ? 'automatica' : 'sugerida',
            'criada_em' => date('Y-m-d H:i:s'),
            'decidida_em' => $sugestao['automatica'] ? date('Y-m-d H:i:s') : null,
        ]);
        if ($sugestao['automatica']) {
            $this->confirmarInterno($transacaoId, $conciliacao, (int) $sugestao['documento_id']);
            return ['automaticas' => 1, 'fila' => 0];
        }
        $this->db->run('UPDATE transacoes_bancarias SET conciliacao_id = :conciliacao WHERE id = :id', [':conciliacao' => $conciliacao, ':id' => $transacaoId]);
        $this->db->insert('tarefas', [
            'empresa_id' => $empresaId,
            'tipo' => 'conciliacao',
            'titulo' => 'Conciliação de ' . Format::money((int) $transacao['valor_centavos']),
            'detalhe' => (string) ($sugestao['card'] ?? $sugestao['motivo']),
            'referencia_tipo' => 'conciliacao',
            'referencia_id' => $conciliacao,
            'status' => 'aberta',
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
        return ['automaticas' => 0, 'fila' => 1];
    }

    public function confirmar(int $conciliacaoId): void
    {
        $conciliacao = $this->db->one('SELECT * FROM conciliacoes WHERE id = :id', [':id' => $conciliacaoId]);
        if ($conciliacao === null || $conciliacao['documento_id'] === null) {
            throw new ValidationException('Sugestão de conciliação não encontrada.');
        }
        $this->confirmarInterno((int) $conciliacao['transacao_id'], $conciliacaoId, (int) $conciliacao['documento_id']);
        $this->db->run('UPDATE tarefas SET status = :status WHERE referencia_tipo = :tipo AND referencia_id = :id', [':status' => 'concluida', ':tipo' => 'conciliacao', ':id' => $conciliacaoId]);
    }

    public function rejeitar(int $conciliacaoId): void
    {
        $this->db->run('UPDATE conciliacoes SET status = :status, decidida_em = :quando WHERE id = :id', [':status' => 'rejeitada', ':quando' => date('Y-m-d H:i:s'), ':id' => $conciliacaoId]);
        $this->db->run('UPDATE tarefas SET status = :status WHERE referencia_tipo = :tipo AND referencia_id = :id', [':status' => 'concluida', ':tipo' => 'conciliacao', ':id' => $conciliacaoId]);
    }

    public function ignorar(int $transacaoId): void
    {
        $this->razao->estornar('conciliacao', $transacaoId);
        $this->db->run('UPDATE transacoes_bancarias SET status = :status WHERE id = :id', [':status' => 'ignorado', ':id' => $transacaoId]);
    }

    public function lancarManual(int $transacaoId, string $codigoConta): void
    {
        $transacao = $this->db->one('SELECT * FROM transacoes_bancarias WHERE id = :id', [':id' => $transacaoId]);
        if ($transacao === null) {
            throw new ValidationException('Transação não encontrada.');
        }
        $this->razao->estornar('conciliacao', $transacaoId);
        $this->razao->postarAvulso((int) $transacao['empresa_id'], $transacao, $codigoConta);
        $this->db->run('UPDATE transacoes_bancarias SET status = :status WHERE id = :id', [':status' => 'conciliado', ':id' => $transacaoId]);
    }

    /** @return list<array<string, mixed>> */
    public function transacoes(int $empresaId): array
    {
        return $this->db->all(
            'SELECT t.*, c.apelido, k.motivo, k.pontuacao, k.status AS status_sugestao, k.id AS sugestao_id, d.numero AS nota
             FROM transacoes_bancarias t
             JOIN contas_bancarias c ON c.id = t.conta_id
             LEFT JOIN conciliacoes k ON k.id = t.conciliacao_id
             LEFT JOIN documentos_fiscais d ON d.id = k.documento_id
             WHERE t.empresa_id = :empresa
             ORDER BY t.data DESC, t.id DESC',
            [':empresa' => $empresaId],
        );
    }

    private function confirmarInterno(int $transacaoId, int $conciliacaoId, int $documentoId): void
    {
        $transacao = $this->db->one('SELECT * FROM transacoes_bancarias WHERE id = :id', [':id' => $transacaoId]);
        $documento = $this->db->one('SELECT * FROM documentos_fiscais WHERE id = :id', [':id' => $documentoId]);
        if ($transacao === null || $documento === null) {
            throw new ValidationException('Não foi possível confirmar a conciliação.');
        }
        if (abs((int) $transacao['valor_centavos']) !== (int) $documento['valor_nota_centavos']) {
            throw new ValidationException('A confirmação exige o mesmo valor do documento. Use o lançamento manual se o pagamento for parcial.');
        }
        $this->razao->estornar('conciliacao', $transacaoId);
        $this->razao->postarConciliacao((int) $transacao['empresa_id'], $transacao, $documento);
        $this->db->run(
            'UPDATE conciliacoes SET status = :status, decidida_em = :quando WHERE id = :id',
            [':status' => 'confirmada', ':quando' => date('Y-m-d H:i:s'), ':id' => $conciliacaoId],
        );
        $this->db->run(
            'UPDATE transacoes_bancarias SET status = :status, conciliacao_id = :conciliacao WHERE id = :id',
            [':status' => 'conciliado', ':conciliacao' => $conciliacaoId, ':id' => $transacaoId],
        );
    }

    /** @param array{data:string,valor_centavos:int,descricao:string,documento:string,fitid:string} $transacao */
    private function duplicada(int $contaId, array $transacao): bool
    {
        if ($transacao['fitid'] !== '') {
            $existe = $this->db->one('SELECT id FROM transacoes_bancarias WHERE conta_id = :conta AND fitid = :fitid', [':conta' => $contaId, ':fitid' => $transacao['fitid']]);
            return $existe !== null;
        }
        $existe = $this->db->one(
            'SELECT id FROM transacoes_bancarias WHERE conta_id = :conta AND data = :data AND valor_centavos = :valor AND descricao = :descricao',
            [':conta' => $contaId, ':data' => $transacao['data'], ':valor' => $transacao['valor_centavos'], ':descricao' => $transacao['descricao']],
        );
        return $existe !== null;
    }

    /** @return array<string, mixed> */
    private function exigirConta(int $contaId): array
    {
        $conta = $this->db->one('SELECT * FROM contas_bancarias WHERE id = :id', [':id' => $contaId]);
        if ($conta === null) {
            throw new ValidationException('Conta bancária não encontrada.');
        }
        return $conta;
    }
}
