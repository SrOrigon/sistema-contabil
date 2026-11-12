<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\GestorAssinaturaAgente;
use App\Agents\RegistroDeAgente;
use App\Core\Database;
use App\Core\ValidationException;

final class AssinaturaService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function apurar(int $escritorioId): array
    {
        $assinatura = $this->atual($escritorioId);
        if ($assinatura === null) {
            throw new ValidationException('Este escritório não tem assinatura.');
        }
        $plano = $this->plano((string) $assinatura['plano_codigo']);
        $empresas = (int) ($this->db->one('SELECT COUNT(*) AS total FROM empresas WHERE escritorio_id = :id AND ativa = 1', [':id' => $escritorioId])['total'] ?? 0);
        $limite = (int) $plano['empresas_por_ciclo'];
        $fatura = $this->garantirFatura($escritorioId, $plano);
        $resultado = [
            'plano_codigo' => $plano['codigo'],
            'plano_nome' => $plano['nome'],
            'descricao' => $plano['descricao'],
            'preco_centavos' => (int) $plano['preco_centavos'],
            'empresas' => $empresas,
            'limite' => $limite,
            'pode_abrir' => $empresas < $limite && $assinatura['status'] === 'ativa',
            'status' => (string) $assinatura['status'],
            'fatura' => $fatura,
            'planos' => $this->planos(),
        ];
        RegistroDeAgente::gravar($this->db, 'gestor-assinatura', 'apurar_assinatura', null, [
            'escritorio_id' => $escritorioId,
        ], [
            'plano' => $plano['codigo'],
            'empresas' => $empresas,
            'limite' => $limite,
            'valor_centavos' => (int) $fatura['valor_centavos'],
        ], 'deterministico');
        return $resultado;
    }

    public function exigirVaga(int $escritorioId): void
    {
        $conta = $this->apurar($escritorioId);
        if ($conta['status'] !== 'ativa') {
            throw new ValidationException('A assinatura está suspensa. Regularize a cobrança antes de abrir empresa.');
        }
        if (!$conta['pode_abrir']) {
            throw new ValidationException('O plano ' . $conta['plano_nome'] . ' cobre ' . $conta['limite'] . ' empresas e já há ' . $conta['empresas'] . ' ativas. A troca de plano está em Assinatura.');
        }
    }

    public function trocarPlano(int $escritorioId, string $codigo): void
    {
        $leitura = GestorAssinaturaAgente::ler(['plano' => $codigo]);
        if (!$leitura['ok']) {
            throw new ValidationException((string) $leitura['mensagem']);
        }
        $plano = $this->plano((string) $leitura['valores']['plano']);
        $this->db->run('UPDATE assinaturas SET plano_codigo = :plano WHERE escritorio_id = :id', [
            ':plano' => $plano['codigo'],
            ':id' => $escritorioId,
        ]);
        $competencia = date('Y-m');
        $this->db->run(
            'UPDATE faturas_saas SET plano_codigo = :plano, valor_centavos = :valor WHERE escritorio_id = :id AND competencia = :competencia AND status = :status',
            [
                ':plano' => $plano['codigo'],
                ':valor' => $plano['preco_centavos'],
                ':id' => $escritorioId,
                ':competencia' => $competencia,
                ':status' => 'aberta',
            ],
        );
    }

    public function status(int $escritorioId): string
    {
        return (string) ($this->atual($escritorioId)['status'] ?? 'suspensa');
    }

    /** @return list<array<string, mixed>> */
    public function carteira(): array
    {
        return $this->db->all(
            'SELECT e.id, e.nome, e.cnpj, a.plano_codigo, p.nome AS plano_nome, a.status,
                    (SELECT COUNT(*) FROM empresas em WHERE em.escritorio_id = e.id AND em.ativa = 1) AS empresas,
                    (SELECT valor_centavos FROM faturas_saas f WHERE f.escritorio_id = e.id AND f.status = \'aberta\' ORDER BY id DESC LIMIT 1) AS aberta_centavos
             FROM escritorios e
             LEFT JOIN assinaturas a ON a.escritorio_id = e.id
             LEFT JOIN planos p ON p.codigo = a.plano_codigo
             ORDER BY e.nome',
        );
    }

    /** @return list<array<string, mixed>> */
    public function abertas(): array
    {
        return $this->db->all(
            'SELECT f.*, e.nome AS escritorio FROM faturas_saas f JOIN escritorios e ON e.id = f.escritorio_id WHERE f.status = :status ORDER BY f.vencimento',
            [':status' => 'aberta'],
        );
    }

    /** @return list<array<string, mixed>> */
    public function faturas(int $escritorioId): array
    {
        return $this->db->all('SELECT * FROM faturas_saas WHERE escritorio_id = :id ORDER BY competencia DESC', [':id' => $escritorioId]);
    }

    public function pagar(int $faturaId): void
    {
        $fatura = $this->db->one('SELECT * FROM faturas_saas WHERE id = :id', [':id' => $faturaId]);
        if ($fatura === null || $fatura['status'] !== 'aberta') {
            throw new ValidationException('Só uma fatura aberta pode ser recebida.');
        }
        $this->db->run('UPDATE faturas_saas SET status = :status, paga_em = :quando WHERE id = :id', [
            ':status' => 'paga',
            ':quando' => date('Y-m-d H:i:s'),
            ':id' => $faturaId,
        ]);
    }

    public function definirStatus(int $escritorioId, string $status): void
    {
        if (!in_array($status, ['ativa', 'suspensa'], true)) {
            throw new ValidationException('Status de assinatura inválido.');
        }
        $this->db->run('UPDATE assinaturas SET status = :status WHERE escritorio_id = :id', [
            ':status' => $status,
            ':id' => $escritorioId,
        ]);
    }

    /** @return ?array<string, mixed> */
    public function atual(int $escritorioId): ?array
    {
        return $this->db->one('SELECT * FROM assinaturas WHERE escritorio_id = :id ORDER BY id DESC LIMIT 1', [':id' => $escritorioId]);
    }

    /** @return list<array<string, mixed>> */
    public function planos(): array
    {
        return $this->db->all('SELECT * FROM planos ORDER BY preco_centavos');
    }

    /** @return array<string, mixed> */
    private function plano(string $codigo): array
    {
        $plano = $this->db->one('SELECT * FROM planos WHERE codigo = :codigo', [':codigo' => $codigo]);
        if ($plano === null) {
            throw new ValidationException('Plano desconhecido.');
        }
        return $plano;
    }

    /** @param array<string, mixed> $plano @return array<string, mixed> */
    private function garantirFatura(int $escritorioId, array $plano): array
    {
        $competencia = date('Y-m');
        $existente = $this->db->one(
            'SELECT * FROM faturas_saas WHERE escritorio_id = :id AND competencia = :competencia',
            [':id' => $escritorioId, ':competencia' => $competencia],
        );
        if ($existente !== null) {
            return $existente;
        }
        $id = $this->db->insert('faturas_saas', [
            'escritorio_id' => $escritorioId,
            'competencia' => $competencia,
            'plano_codigo' => $plano['codigo'],
            'valor_centavos' => $plano['preco_centavos'],
            'status' => 'aberta',
            'vencimento' => $competencia . '-10',
            'paga_em' => null,
            'criada_em' => date('Y-m-d H:i:s'),
        ]);
        return $this->db->one('SELECT * FROM faturas_saas WHERE id = :id', [':id' => $id]) ?? [];
    }
}
