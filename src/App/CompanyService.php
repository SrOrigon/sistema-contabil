<?php

declare(strict_types=1);

namespace App\App;

use App\Core\Database;
use App\Core\Format;
use App\Core\ValidationException;

final class CompanyService
{
    public function __construct(private readonly Database $db, private readonly LedgerService $razao)
    {
    }

    public function escritorio(): array
    {
        return $this->db->one('SELECT * FROM escritorios ORDER BY id LIMIT 1') ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function empresas(): array
    {
        return $this->db->all('SELECT * FROM empresas ORDER BY razao_social');
    }

    public function obter(int $id): ?array
    {
        return $this->db->one('SELECT * FROM empresas WHERE id = :id', [':id' => $id]);
    }

    /** @param array<string, string> $dados */
    public function salvar(?int $id, array $dados): int
    {
        $cnpj = Format::digitos($dados['cnpj'] ?? '');
        if (!Format::cnpjValido($cnpj)) {
            throw new ValidationException('CNPJ inválido.');
        }
        $razao = trim($dados['razao_social'] ?? '');
        if ($razao === '') {
            throw new ValidationException('Informe a razão social.');
        }
        $regime = $dados['regime'] ?? 'simples';
        if (!in_array($regime, ['simples', 'presumido', 'real', 'mei'], true)) {
            throw new ValidationException('Regime tributário inválido.');
        }
        $anexo = strtoupper(trim($dados['anexo_simples'] ?? 'I'));
        if (!in_array($anexo, ['I', 'II', 'III', 'IV', 'V', 'AUTO'], true)) {
            throw new ValidationException('Anexo do Simples inválido.');
        }
        $capital = Format::parseMoney($dados['capital_social'] ?? '0');
        $registro = [
            'escritorio_id' => (int) $this->escritorio()['id'],
            'razao_social' => $razao,
            'nome_fantasia' => trim($dados['nome_fantasia'] ?? ''),
            'cnpj' => $cnpj,
            'ie' => trim($dados['ie'] ?? ''),
            'im' => trim($dados['im'] ?? ''),
            'cnae' => trim($dados['cnae'] ?? ''),
            'regime' => $regime,
            'anexo_simples' => $anexo,
            'uf' => strtoupper(substr(trim($dados['uf'] ?? ''), 0, 2)),
            'municipio' => trim($dados['municipio'] ?? ''),
            'capital_social_centavos' => max(0, $capital),
            'inicio_atividade' => $this->data($dados['inicio_atividade'] ?? ''),
            'ativa' => 1,
        ];
        if ($id === null) {
            $registro['criada_em'] = date('Y-m-d H:i:s');
            $novo = $this->db->insert('empresas', $registro);
            $this->razao->garantirPlano($novo);
            $this->razao->abertura($novo, $registro['capital_social_centavos'], $registro['inicio_atividade'] ?: date('Y-m-d'));
            return $novo;
        }
        $sets = [];
        $params = [':id' => $id];
        foreach ($registro as $coluna => $valor) {
            if ($coluna === 'escritorio_id' || $coluna === 'capital_social_centavos') {
                continue;
            }
            $sets[] = $coluna . ' = :' . $coluna;
            $params[':' . $coluna] = $valor;
        }
        $this->db->run('UPDATE empresas SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        $this->razao->garantirPlano($id);
        return $id;
    }

    public function excluir(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $documentos = $this->db->all('SELECT id FROM documentos_fiscais WHERE empresa_id = :id', [':id' => $id]);
            foreach ($documentos as $documento) {
                $this->db->run('DELETE FROM documento_alertas WHERE documento_id = :id', [':id' => $documento['id']]);
                $this->db->run('DELETE FROM documento_itens WHERE documento_id = :id', [':id' => $documento['id']]);
            }
            foreach (['conciliacoes', 'transacoes_bancarias', 'contas_bancarias', 'documentos_fiscais', 'tarefas', 'agent_feedback', 'simulacoes', 'pessoas', 'agent_logs'] as $tabela) {
                $this->db->run('DELETE FROM ' . $tabela . ' WHERE empresa_id = :id', [':id' => $id]);
            }
            $lancamentos = $this->db->all('SELECT id FROM lancamentos WHERE empresa_id = :id', [':id' => $id]);
            foreach ($lancamentos as $lancamento) {
                $this->db->run('DELETE FROM partidas WHERE lancamento_id = :id', [':id' => $lancamento['id']]);
            }
            $this->db->run('DELETE FROM lancamentos WHERE empresa_id = :id', [':id' => $id]);
            $this->db->run('DELETE FROM plano_contas WHERE empresa_id = :id', [':id' => $id]);
            $this->db->run('DELETE FROM empresas WHERE id = :id', [':id' => $id]);
        });
    }

    /** @param array<string, string> $dados */
    public function salvarPessoa(int $empresaId, array $dados): void
    {
        $documento = Format::digitos($dados['documento'] ?? '');
        $valido = strlen($documento) === 14 ? Format::cnpjValido($documento) : Format::cpfValido($documento);
        if (!$valido) {
            throw new ValidationException('CPF ou CNPJ da pessoa inválido.');
        }
        $nome = trim($dados['nome'] ?? '');
        if ($nome === '') {
            throw new ValidationException('Informe o nome da pessoa.');
        }
        $tipo = $dados['tipo'] ?? 'ambos';
        if (!in_array($tipo, ['cliente', 'fornecedor', 'ambos'], true)) {
            $tipo = 'ambos';
        }
        $this->db->insert('pessoas', [
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'documento' => $documento,
            'nome' => $nome,
            'ie' => trim($dados['ie'] ?? ''),
            'email' => trim($dados['email'] ?? ''),
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
    }

    public function excluirPessoa(int $id): void
    {
        $this->db->run('DELETE FROM pessoas WHERE id = :id', [':id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function pessoas(int $empresaId): array
    {
        return $this->db->all('SELECT * FROM pessoas WHERE empresa_id = :id ORDER BY nome', [':id' => $empresaId]);
    }

    private function data(string $valor): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $valor;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return '';
    }
}
