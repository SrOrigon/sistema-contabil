<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Core\Database;
use App\Core\Format;

final class SanityReverseAuditor
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{aprovado:bool, bloqueios:list<string>, alertas:list<string>} */
    public function auditar(int $empresaId, ?string $competencia = null): array
    {
        $bloqueios = [];
        $alertas = [];
        $desbalanceados = $this->db->all(
            'SELECT l.id, l.historico,
                    SUM(CASE WHEN p.tipo = \'D\' THEN p.valor_centavos ELSE 0 END) AS debitos,
                    SUM(CASE WHEN p.tipo = \'C\' THEN p.valor_centavos ELSE 0 END) AS creditos
             FROM lancamentos l
             JOIN partidas p ON p.lancamento_id = l.id
             WHERE l.empresa_id = :empresa
             GROUP BY l.id
             HAVING SUM(CASE WHEN p.tipo = \'D\' THEN p.valor_centavos ELSE 0 END)
                  <> SUM(CASE WHEN p.tipo = \'C\' THEN p.valor_centavos ELSE 0 END)',
            [':empresa' => $empresaId],
        );
        foreach ($desbalanceados as $lancamento) {
            $bloqueios[] = 'Lançamento ' . $lancamento['id'] . ' (' . $lancamento['historico'] . ') não fecha débito e crédito.';
        }
        $equacao = $this->equacao($empresaId);
        if ($equacao['diferenca_centavos'] !== 0) {
            $bloqueios[] = 'A equação patrimonial não fecha. Diferença de ' . Format::money($equacao['diferenca_centavos']) . '.';
        }
        $filtro = '';
        $params = [':empresa' => $empresaId];
        if ($competencia !== null && $competencia !== '') {
            $filtro = ' AND d.emissao LIKE :competencia';
            $params[':competencia'] = $competencia . '%';
        }
        $itens = $this->db->all(
            'SELECT i.*, d.numero FROM documento_itens i
             JOIN documentos_fiscais d ON d.id = i.documento_id
             WHERE d.empresa_id = :empresa' . $filtro,
            $params,
        );
        foreach ($itens as $item) {
            $imposto = (int) $item['valor_ibs_centavos'] + (int) $item['valor_cbs_centavos'];
            if ($imposto > (int) $item['valor_centavos'] + 1) {
                $bloqueios[] = 'O IBS/CBS do item ' . $item['n_item'] . ' da nota ' . $item['numero'] . ' supera o valor do item.';
            }
            if (in_array($item['status'], ['rejeitado', 'ambiguo'], true)) {
                $bloqueios[] = 'Item ' . $item['n_item'] . ' da nota ' . $item['numero'] . ' está ' . $item['status'] . ': ' . $item['diagnostico'];
            } elseif ($item['status'] === 'alerta') {
                $alertas[] = 'Item ' . $item['n_item'] . ' da nota ' . $item['numero'] . ': ' . $item['diagnostico'];
            }
        }
        $indeterminados = $this->db->all(
            'SELECT numero FROM documentos_fiscais WHERE empresa_id = :empresa AND tipo_operacao = \'indeterminada\'' . ($competencia ? ' AND emissao LIKE :competencia' : ''),
            $params,
        );
        foreach ($indeterminados as $documento) {
            $bloqueios[] = 'A nota ' . $documento['numero'] . ' não teve a direção definida. O razão não a recebeu.';
        }
        return [
            'aprovado' => $bloqueios === [],
            'bloqueios' => $bloqueios,
            'alertas' => $alertas,
            'equacao' => $equacao,
        ];
    }

    /** @return array{ativo_centavos:int, passivo_centavos:int, pl_centavos:int, resultado_centavos:int, diferenca_centavos:int} */
    public function equacao(int $empresaId): array
    {
        $contas = $this->db->all(
            'SELECT c.natureza,
                    COALESCE(SUM(CASE WHEN p.tipo = \'D\' THEN p.valor_centavos ELSE 0 END), 0) AS debitos,
                    COALESCE(SUM(CASE WHEN p.tipo = \'C\' THEN p.valor_centavos ELSE 0 END), 0) AS creditos
             FROM plano_contas c
             LEFT JOIN partidas p ON p.conta_id = c.id
             LEFT JOIN lancamentos l ON l.id = p.lancamento_id
             WHERE c.empresa_id = :empresa
             GROUP BY c.id',
            [':empresa' => $empresaId],
        );
        $devedor = 0;
        $credor = 0;
        $ativo = 0;
        $passivo = 0;
        $pl = 0;
        $resultado = 0;
        foreach ($contas as $conta) {
            $saldoDevedor = (int) $conta['debitos'] - (int) $conta['creditos'];
            $saldoCredor = (int) $conta['creditos'] - (int) $conta['debitos'];
            if (in_array($conta['natureza'], ['ativo', 'despesa', 'custo'], true)) {
                $devedor += $saldoDevedor;
            } else {
                $credor += $saldoCredor;
            }
            if ($conta['natureza'] === 'ativo') {
                $ativo += $saldoDevedor;
            } elseif ($conta['natureza'] === 'passivo') {
                $passivo += $saldoCredor;
            } elseif ($conta['natureza'] === 'patrimonio_liquido') {
                $pl += $saldoCredor;
            } elseif ($conta['natureza'] === 'receita') {
                $resultado += $saldoCredor;
            } else {
                $resultado -= $saldoDevedor;
            }
        }
        return [
            'ativo_centavos' => $ativo,
            'passivo_centavos' => $passivo,
            'pl_centavos' => $pl,
            'resultado_centavos' => $resultado,
            'diferenca_centavos' => $devedor - $credor,
        ];
    }
}
