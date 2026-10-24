<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Llm\LlmClient;
use App\Core\Database;
use App\Core\ValidationException;

final class SettingsService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function obter(string $chave, string $padrao = ''): string
    {
        $linha = $this->db->one('SELECT valor FROM configuracoes WHERE chave = :chave', [':chave' => $chave]);
        $valor = $linha['valor'] ?? $padrao;
        return (string) $valor;
    }

    public function definir(string $chave, string $valor): void
    {
        $existe = $this->db->one('SELECT chave FROM configuracoes WHERE chave = :chave', [':chave' => $chave]);
        if ($existe === null) {
            $this->db->insert('configuracoes', ['chave' => $chave, 'valor' => $valor]);
            return;
        }
        $this->db->run('UPDATE configuracoes SET valor = :valor WHERE chave = :chave', [':valor' => $valor, ':chave' => $chave]);
    }

    public function llm(): LlmClient
    {
        $provedor = $this->obter('ai.provedor');
        $chave = $this->obter('ai.api_key');
        if ($chave === '' && env('GEMINI_API_KEY')) {
            $provedor = 'gemini';
            $chave = (string) env('GEMINI_API_KEY');
        }
        if ($chave === '' && env('OPENAI_API_KEY')) {
            $provedor = 'openai';
            $chave = (string) env('OPENAI_API_KEY');
        }
        return new LlmClient($provedor, $this->obter('ai.modelo'), $chave, $this->obter('ai.base_url') ?: (string) env('OPENAI_BASE_URL', ''));
    }

    /** @param array<string, string> $dados */
    public function salvarEscritorio(array $dados): void
    {
        $nome = trim($dados['nome'] ?? '');
        if ($nome === '') {
            throw new ValidationException('O escritório precisa de um nome.');
        }
        $cor = trim($dados['cor'] ?? '#1f4b3a');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            throw new ValidationException('A cor precisa estar no formato #RRGGBB.');
        }
        $this->db->run(
            'UPDATE escritorios SET nome = :nome, cnpj = :cnpj, crc = :crc, cor = :cor, cidade = :cidade, telefone = :telefone, email = :email WHERE id = (SELECT id FROM escritorios ORDER BY id LIMIT 1)',
            [
                ':nome' => $nome,
                ':cnpj' => \App\Core\Format::digitos($dados['cnpj'] ?? ''),
                ':crc' => trim($dados['crc'] ?? ''),
                ':cor' => $cor,
                ':cidade' => trim($dados['cidade'] ?? ''),
                ':telefone' => trim($dados['telefone'] ?? ''),
                ':email' => trim($dados['email'] ?? ''),
            ],
        );
    }

    public function salvarLogo(array $arquivo): void
    {
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException('Não foi possível receber a marca do escritório.');
        }
        $extensao = strtolower(pathinfo((string) $arquivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extensao, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) {
            throw new ValidationException('A marca precisa ser PNG, JPG, SVG ou WebP.');
        }
        $pasta = ROOT . '/storage/branding';
        if (!is_dir($pasta)) {
            mkdir($pasta, 0777, true);
        }
        $destino = $pasta . '/logo.' . $extensao;
        if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
            throw new ValidationException('Não foi possível guardar a marca.');
        }
        $this->db->run('UPDATE escritorios SET logo_path = :caminho WHERE id = (SELECT id FROM escritorios ORDER BY id LIMIT 1)', [':caminho' => 'storage/branding/logo.' . $extensao]);
    }

    /** @return list<array<string, mixed>> */
    public function aliquotas(): array
    {
        return $this->db->all('SELECT * FROM aliquotas_referencia ORDER BY ano');
    }

    public function aliquotaDoAno(int $ano): array
    {
        $linha = $this->db->one('SELECT * FROM aliquotas_referencia WHERE ano = :ano', [':ano' => $ano]);
        if ($linha !== null) {
            return $linha;
        }
        $fallback = $ano < 2025 ? 2025 : 2033;
        return $this->db->one('SELECT * FROM aliquotas_referencia WHERE ano = :ano', [':ano' => $fallback]) ?? [];
    }

    /** @param array<string, string> $dados */
    public function salvarAliquota(array $dados): void
    {
        $ano = (int) ($dados['ano'] ?? 0);
        if ($ano < 2025 || $ano > 2040) {
            throw new ValidationException('Ano de alíquota fora da transição acompanhada.');
        }
        $this->db->run('DELETE FROM aliquotas_referencia WHERE ano = :ano', [':ano' => $ano]);
        $this->db->insert('aliquotas_referencia', [
            'ano' => $ano,
            'cbs' => $this->numero($dados['cbs'] ?? '0'),
            'ibs' => $this->numero($dados['ibs'] ?? '0'),
            'pis_cofins_vigente' => isset($dados['pis_cofins_vigente']) ? 1 : 0,
            'fator_icms_iss' => $this->numero($dados['fator_icms_iss'] ?? '1'),
            'cbs_compensavel' => isset($dados['cbs_compensavel']) ? 1 : 0,
            'ibs_compensavel' => isset($dados['ibs_compensavel']) ? 1 : 0,
            'natureza' => ($dados['natureza'] ?? '') === 'legal' ? 'legal' : 'estimada',
            'observacao' => trim($dados['observacao'] ?? ''),
        ]);
    }

    private function numero(string $valor): string
    {
        $valor = trim(str_replace(',', '.', $valor));
        if (!preg_match('/^\d+(\.\d+)?$/', $valor)) {
            throw new ValidationException('Número de alíquota inválido.');
        }
        return $valor;
    }
}
