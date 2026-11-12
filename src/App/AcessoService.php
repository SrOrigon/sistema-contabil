<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\PorteiroAcessoAgente;
use App\Agents\RegistroDeAgente;
use App\Core\Database;
use App\Core\Format;
use App\Core\ValidationException;

final class AcessoService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, string> $entrada @return array<string, mixed> */
    public function entrar(array $entrada): array
    {
        $leitura = PorteiroAcessoAgente::ler($entrada, ['email', 'senha']);
        if (!$leitura['ok']) {
            throw new ValidationException((string) $leitura['mensagem']);
        }
        $email = $this->identificador((string) $leitura['valores']['email']);
        $usuario = $this->db->one('SELECT * FROM usuarios WHERE email = :email', [':email' => $email]);
        $senha = (string) $leitura['valores']['senha'];
        if ($usuario === null || (int) $usuario['ativo'] !== 1 || !password_verify($senha, (string) $usuario['senha_hash'])) {
            throw new ValidationException('Acesso ou senha não conferem.');
        }
        $this->log((int) $usuario['escritorio_id'], 'autenticar_usuario', ['email' => $email], ['papel' => $usuario['papel']]);
        return $usuario;
    }

    /** @param array<string, string> $entrada */
    public function cadastrarNoEscritorio(int $escritorioId, array $entrada): int
    {
        $leitura = PorteiroAcessoAgente::ler($entrada, ['email', 'senha', 'nome']);
        if (!$leitura['ok']) {
            throw new ValidationException((string) $leitura['mensagem']);
        }
        $papel = mb_strtolower(trim((string) ($entrada['papel'] ?? 'contador')));
        if (!in_array($papel, ['dono', 'contador'], true)) {
            throw new ValidationException('O papel da equipe é dono ou contador.');
        }
        return $this->criar($escritorioId, (string) $leitura['valores']['nome'], (string) $leitura['valores']['email'], (string) $leitura['valores']['senha'], $papel, 0);
    }

    public function criarEscritorio(string $nomeEscritorio, string $cnpj, string $nome, string $email, string $senha): array
    {
        $nomeEscritorio = trim($nomeEscritorio);
        $cnpj = Format::digitos($cnpj);
        if ($nomeEscritorio === '') {
            throw new ValidationException('Informe o nome do escritório.');
        }
        if (!Format::cnpjValido($cnpj)) {
            throw new ValidationException('CNPJ do escritório inválido.');
        }
        $leitura = PorteiroAcessoAgente::ler(['nome' => $nome, 'email' => $email, 'senha' => $senha], ['email', 'senha', 'nome']);
        if (!$leitura['ok']) {
            throw new ValidationException((string) $leitura['mensagem']);
        }
        if (mb_strlen((string) $leitura['valores']['senha']) < 8) {
            throw new ValidationException('A senha precisa ter pelo menos 8 caracteres.');
        }
        return $this->db->transaction(function () use ($nomeEscritorio, $cnpj, $leitura): array {
            $escritorioId = $this->db->insert('escritorios', [
                'nome' => $nomeEscritorio,
                'cnpj' => $cnpj,
                'crc' => '',
                'cor' => '#1f4b3a',
                'logo_path' => null,
                'cidade' => '',
                'telefone' => '',
                'email' => $leitura['valores']['email'],
            ]);
            $this->db->insert('assinaturas', [
                'escritorio_id' => $escritorioId,
                'plano_codigo' => 'essencial',
                'status' => 'ativa',
                'inicio' => date('Y-m-d'),
                'criada_em' => date('Y-m-d H:i:s'),
            ]);
            $usuarioId = $this->criar($escritorioId, (string) $leitura['valores']['nome'], (string) $leitura['valores']['email'], (string) $leitura['valores']['senha'], 'dono', 0);
            $usuario = $this->obter($usuarioId);
            if ($usuario === null) {
                throw new ValidationException('Não foi possível abrir o escritório.');
            }
            return $usuario;
        });
    }

    public function trocarSenha(int $usuarioId, string $atual, string $nova): void
    {
        $usuario = $this->obter($usuarioId);
        if ($usuario === null || !password_verify($atual, (string) $usuario['senha_hash'])) {
            throw new ValidationException('A senha atual não confere.');
        }
        if (mb_strlen($nova) < 8) {
            throw new ValidationException('A senha nova precisa ter pelo menos 8 caracteres.');
        }
        $this->db->run('UPDATE usuarios SET senha_hash = :senha, trocar_senha = 0 WHERE id = :id', [
            ':senha' => password_hash($nova, PASSWORD_DEFAULT),
            ':id' => $usuarioId,
        ]);
    }

    public function obter(int $id): ?array
    {
        $usuario = $this->db->one('SELECT * FROM usuarios WHERE id = :id', [':id' => $id]);
        return $usuario === null || (int) $usuario['ativo'] !== 1 ? null : $usuario;
    }

    public function daSessao(int $id): ?array
    {
        return $id === 0 ? null : $this->obter($id);
    }

    public function senhaInicialPendente(): bool
    {
        $usuario = $this->db->one('SELECT trocar_senha FROM usuarios WHERE email = :email', [':email' => 'dono@escritorio.local']);
        return $usuario !== null && (int) $usuario['trocar_senha'] === 1;
    }

    /** @return list<array<string, mixed>> */
    public function equipe(int $escritorioId): array
    {
        return $this->db->all('SELECT id, nome, email, papel, criado_em FROM usuarios WHERE escritorio_id = :id AND ativo = 1 ORDER BY nome', [':id' => $escritorioId]);
    }

    private function criar(int $escritorioId, string $nome, string $email, string $senha, string $papel, int $trocar): int
    {
        $email = $this->email($email);
        if ($this->db->one('SELECT id FROM usuarios WHERE email = :email', [':email' => $email]) !== null) {
            throw new ValidationException('Já existe um acesso com este e-mail.');
        }
        if (mb_strlen($senha) < 8) {
            throw new ValidationException('A senha precisa ter pelo menos 8 caracteres.');
        }
        $id = $this->db->insert('usuarios', [
            'escritorio_id' => $escritorioId,
            'nome' => trim($nome),
            'email' => $email,
            'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
            'papel' => $papel,
            'ativo' => 1,
            'trocar_senha' => $trocar,
            'criado_em' => date('Y-m-d H:i:s'),
        ]);
        $this->log($escritorioId, 'cadastrar_usuario', ['email' => $email, 'papel' => $papel], ['usuario_id' => $id]);
        return $id;
    }

    private function identificador(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        if ($valor === 'admin') {
            return 'admin';
        }
        return $this->email($valor);
    }

    private function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('E-mail inválido.');
        }
        return $email;
    }

    /** @param array<string, mixed> $entrada @param array<string, mixed> $saida */
    private function log(int $escritorioId, string $acao, array $entrada, array $saida): void
    {
        RegistroDeAgente::gravar($this->db, 'porteiro-acesso', $acao, null, $entrada, $saida, 'deterministico');
    }
}
