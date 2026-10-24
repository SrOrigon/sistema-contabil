<?php

declare(strict_types=1);

namespace App\App;

use App\Core\Database;
use App\Core\ValidationException;

final class LedgerService
{
    /** @var list<array{0:string,1:string,2:string,3:string}> */
    private const PLANO = [
        ['1.1.01.001', 'Caixa', 'ativo', 'circulante'],
        ['1.1.01.002', 'Bancos', 'ativo', 'circulante'],
        ['1.1.02.001', 'Clientes', 'ativo', 'circulante'],
        ['1.1.03.001', 'Estoques', 'ativo', 'circulante'],
        ['1.1.04.001', 'IBS a recuperar', 'ativo', 'circulante'],
        ['1.1.04.002', 'CBS a recuperar', 'ativo', 'circulante'],
        ['2.1.01.001', 'Fornecedores', 'passivo', 'circulante'],
        ['2.1.02.001', 'IBS a recolher', 'passivo', 'circulante'],
        ['2.1.02.002', 'CBS a recolher', 'passivo', 'circulante'],
        ['2.1.02.003', 'Simples Nacional a recolher', 'passivo', 'circulante'],
        ['2.3.01.001', 'Capital social', 'patrimonio_liquido', 'patrimonio'],
        ['2.3.01.002', 'Lucros acumulados', 'patrimonio_liquido', 'patrimonio'],
        ['3.1.01.001', 'Receita de vendas', 'receita', 'resultado'],
        ['3.1.01.002', 'Receita de serviços', 'receita', 'resultado'],
        ['4.1.01.001', 'Custos', 'custo', 'resultado'],
        ['4.2.01.001', 'Despesas administrativas', 'despesa', 'resultado'],
        ['4.2.01.002', 'Tributos sobre vendas', 'despesa', 'resultado'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function garantirPlano(int $empresaId): void
    {
        foreach (self::PLANO as [$codigo, $nome, $natureza, $grupo]) {
            $existe = $this->db->one(
                'SELECT id FROM plano_contas WHERE empresa_id = :empresa AND codigo = :codigo',
                [':empresa' => $empresaId, ':codigo' => $codigo],
            );
            if ($existe === null) {
                $this->db->insert('plano_contas', [
                    'empresa_id' => $empresaId,
                    'codigo' => $codigo,
                    'nome' => $nome,
                    'natureza' => $natureza,
                    'grupo_conta' => $grupo,
                    'aceita_lancamento' => 1,
                ]);
            }
        }
    }

    public function abertura(int $empresaId, int $capital, string $data): void
    {
        if ($capital <= 0) {
            return;
        }
        $this->lancar($empresaId, $data, 'Integralização do capital social', 'abertura', $empresaId, [
            ['codigo' => '1.1.01.002', 'tipo' => 'D', 'centavos' => $capital],
            ['codigo' => '2.3.01.001', 'tipo' => 'C', 'centavos' => $capital],
        ]);
    }

    /** @param array<string, mixed> $documento */
    public function postarDocumento(int $empresaId, array $documento): ?int
    {
        $direcao = (string) $documento['tipo_operacao'];
        if (!in_array($direcao, ['entrada', 'saida'], true)) {
            return null;
        }
        $total = (int) $documento['valor_nota_centavos'];
        $ibs = (int) $documento['valor_ibs_centavos'];
        $cbs = (int) $documento['valor_cbs_centavos'];
        if ($total <= 0 || $ibs + $cbs > $total) {
            return null;
        }
        $liquido = $total - $ibs - $cbs;
        $nota = (string) ($documento['numero'] ?: $documento['id']);
        if ($direcao === 'saida') {
            $receita = ($documento['modelo'] ?? '') === 'nfse' ? '3.1.01.002' : '3.1.01.001';
            $linhas = [
                ['codigo' => '1.1.02.001', 'tipo' => 'D', 'centavos' => $total],
                ['codigo' => $receita, 'tipo' => 'C', 'centavos' => $liquido],
            ];
            $this->somarImposto($linhas, '2.1.02.001', 'C', $ibs);
            $this->somarImposto($linhas, '2.1.02.002', 'C', $cbs);
            return $this->lancar($empresaId, (string) $documento['emissao'], 'Venda da nota ' . $nota, 'documento', (int) $documento['id'], $linhas);
        }
        $debito = in_array($documento['modelo'] ?? '', ['nfse', '57'], true) ? '4.2.01.001' : '1.1.03.001';
        if (($documento['modelo'] ?? '') === '57') {
            $debito = '4.1.01.001';
        }
        $linhas = [
            ['codigo' => $debito, 'tipo' => 'D', 'centavos' => $liquido],
            ['codigo' => '2.1.01.001', 'tipo' => 'C', 'centavos' => $total],
        ];
        $this->somarImposto($linhas, '1.1.04.001', 'D', $ibs);
        $this->somarImposto($linhas, '1.1.04.002', 'D', $cbs);
        return $this->lancar($empresaId, (string) $documento['emissao'], 'Compra da nota ' . $nota, 'documento', (int) $documento['id'], $linhas);
    }

    /** @param array<string, mixed> $transacao @param array<string, mixed> $documento */
    public function postarConciliacao(int $empresaId, array $transacao, array $documento): int
    {
        $valor = abs((int) $transacao['valor_centavos']);
        $data = (string) $transacao['data'];
        if ((int) $transacao['valor_centavos'] > 0) {
            return $this->lancar($empresaId, $data, 'Recebimento da nota ' . $documento['numero'], 'conciliacao', (int) $transacao['id'], [
                ['codigo' => '1.1.01.002', 'tipo' => 'D', 'centavos' => $valor],
                ['codigo' => '1.1.02.001', 'tipo' => 'C', 'centavos' => $valor],
            ]);
        }
        return $this->lancar($empresaId, $data, 'Pagamento da nota ' . $documento['numero'], 'conciliacao', (int) $transacao['id'], [
            ['codigo' => '2.1.01.001', 'tipo' => 'D', 'centavos' => $valor],
            ['codigo' => '1.1.01.002', 'tipo' => 'C', 'centavos' => $valor],
        ]);
    }

    public function postarAvulso(int $empresaId, array $transacao, string $codigoContrapartida): int
    {
        $valor = abs((int) $transacao['valor_centavos']);
        if ((int) $transacao['valor_centavos'] > 0) {
            $linhas = [
                ['codigo' => '1.1.01.002', 'tipo' => 'D', 'centavos' => $valor],
                ['codigo' => $codigoContrapartida, 'tipo' => 'C', 'centavos' => $valor],
            ];
        } else {
            $linhas = [
                ['codigo' => $codigoContrapartida, 'tipo' => 'D', 'centavos' => $valor],
                ['codigo' => '1.1.01.002', 'tipo' => 'C', 'centavos' => $valor],
            ];
        }
        return $this->lancar($empresaId, (string) $transacao['data'], 'Conciliação manual: ' . $transacao['descricao'], 'conciliacao', (int) $transacao['id'], $linhas);
    }

    public function estornar(string $origem, int $origemId): void
    {
        $lancamentos = $this->db->all(
            'SELECT id FROM lancamentos WHERE origem = :origem AND origem_id = :id',
            [':origem' => $origem, ':id' => $origemId],
        );
        foreach ($lancamentos as $lancamento) {
            $this->db->run('DELETE FROM partidas WHERE lancamento_id = :id', [':id' => $lancamento['id']]);
            $this->db->run('DELETE FROM lancamentos WHERE id = :id', [':id' => $lancamento['id']]);
        }
    }

    /**
     * @param list<array{codigo:string,tipo:string,centavos:int}> $linhas
     */
    public function lancar(int $empresaId, string $data, string $historico, string $origem, ?int $origemId, array $linhas): int
    {
        $this->garantirPlano($empresaId);
        $debitos = 0;
        $creditos = 0;
        foreach ($linhas as $linha) {
            if ($linha['centavos'] <= 0) {
                throw new ValidationException('Partida sem valor no lançamento "' . $historico . '".');
            }
            if ($linha['tipo'] === 'D') {
                $debitos += $linha['centavos'];
            } elseif ($linha['tipo'] === 'C') {
                $creditos += $linha['centavos'];
            } else {
                throw new ValidationException('Tipo de partida inválido.');
            }
        }
        if ($debitos !== $creditos) {
            throw new ValidationException('O lançamento "' . $historico . '" não fecha. Débitos e créditos diferem.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $data = date('Y-m-d');
        }
        return $this->db->transaction(function () use ($empresaId, $data, $historico, $origem, $origemId, $linhas): int {
            $id = $this->db->insert('lancamentos', [
                'empresa_id' => $empresaId,
                'data' => $data,
                'historico' => $historico,
                'origem' => $origem,
                'origem_id' => $origemId,
                'criado_em' => date('Y-m-d H:i:s'),
            ]);
            foreach ($linhas as $linha) {
                $this->db->insert('partidas', [
                    'lancamento_id' => $id,
                    'conta_id' => $this->contaId($empresaId, $linha['codigo']),
                    'tipo' => $linha['tipo'],
                    'valor_centavos' => $linha['centavos'],
                ]);
            }
            return $id;
        });
    }

    /** @return list<array<string, mixed>> */
    public function balancete(int $empresaId): array
    {
        return $this->db->all(
            'SELECT c.codigo, c.nome, c.natureza,
                    COALESCE(SUM(CASE WHEN p.tipo = \'D\' THEN p.valor_centavos ELSE 0 END), 0) AS debitos,
                    COALESCE(SUM(CASE WHEN p.tipo = \'C\' THEN p.valor_centavos ELSE 0 END), 0) AS creditos
             FROM plano_contas c
             LEFT JOIN partidas p ON p.conta_id = c.id
             WHERE c.empresa_id = :empresa
             GROUP BY c.id
             ORDER BY c.codigo',
            [':empresa' => $empresaId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentes(int $empresaId, int $limite = 30): array
    {
        return $this->db->all(
            'SELECT * FROM lancamentos WHERE empresa_id = :empresa ORDER BY data DESC, id DESC LIMIT ' . $limite,
            [':empresa' => $empresaId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function plano(int $empresaId): array
    {
        $this->garantirPlano($empresaId);
        return $this->db->all('SELECT * FROM plano_contas WHERE empresa_id = :empresa ORDER BY codigo', [':empresa' => $empresaId]);
    }

    private function contaId(int $empresaId, string $codigo): int
    {
        $conta = $this->db->one(
            'SELECT id FROM plano_contas WHERE empresa_id = :empresa AND codigo = :codigo',
            [':empresa' => $empresaId, ':codigo' => $codigo],
        );
        if ($conta === null) {
            throw new ValidationException('Conta ' . $codigo . ' não existe no plano desta empresa.');
        }
        return (int) $conta['id'];
    }

    /** @param list<array{codigo:string,tipo:string,centavos:int}> $linhas */
    private function somarImposto(array &$linhas, string $codigo, string $tipo, int $centavos): void
    {
        if ($centavos > 0) {
            $linhas[] = ['codigo' => $codigo, 'tipo' => $tipo, 'centavos' => $centavos];
        }
    }
}
