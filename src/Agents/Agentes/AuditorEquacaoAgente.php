<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Core\Database;
use App\Core\Format;

/** Uma checagem: a equação e os itens. Não chama o modelo. */
class AuditorEquacaoAgente
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'auditor-equacao',
            'Auditor da equação',
            self::class,
            'auditar_equacao_e_itens',
            'Bloqueia o parecer quando o débito não fecha com o crédito, a equação patrimonial quebra ou o IBS/CBS passa do valor do item.',
            'Não chama o modelo.',
            [
                ['debitos', true, 'centavos', 'Soma das partidas de débito do lançamento, em centavos.'],
                ['creditos', true, 'centavos', 'Soma das partidas de crédito do mesmo lançamento.'],
                ['ativo_centavos', true, 'centavos', 'Saldo devedor das contas de ativo.'],
                ['passivo_centavos', true, 'centavos', 'Saldo credor das contas de passivo.'],
                ['pl_centavos', true, 'centavos', 'Saldo credor do patrimônio líquido.'],
                ['resultado_centavos', true, 'centavos', 'Receitas menos despesas e custos.'],
                ['valor_ibs_centavos', true, 'centavos', 'IBS do item gravado pelo validador.'],
                ['valor_cbs_centavos', true, 'centavos', 'CBS do item gravado pelo validador.'],
                ['valor_centavos', true, 'centavos', 'Valor do item. O imposto não pode superá-lo.'],
                ['status', true, 'status', 'conforme, alerta, ambíguo ou rejeitado. Ambíguo e rejeitado bloqueiam.'],
            ],
            [
                ['equação patrimonial', 'Ativo deve igualar passivo, patrimônio líquido e resultado.'],
                ['LC 214/2025', 'IBS e CBS nascem da base do item. Imposto maior que o item bloqueia o parecer.'],
            ],
        );
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
            $leitura = LeitorDeVariaveis::ler(self::contrato(), $lancamento, ['debitos', 'creditos']);
            if (!$leitura['ok']) {
                $bloqueios[] = 'Lançamento ' . $lancamento['id'] . ' (' . $lancamento['historico'] . '). ' . $leitura['mensagem'];
                continue;
            }
            $bloqueios[] = 'Lançamento ' . $lancamento['id'] . ' (' . $lancamento['historico'] . ') não fecha débito '
                . Format::money((int) $leitura['valores']['debitos']) . ' e crédito ' . Format::money((int) $leitura['valores']['creditos']) . '.';
        }
        $equacao = $this->equacao($empresaId);
        $leituraEquacao = LeitorDeVariaveis::ler(self::contrato(), $equacao, ['ativo_centavos', 'passivo_centavos', 'pl_centavos', 'resultado_centavos']);
        if (!$leituraEquacao['ok']) {
            $bloqueios[] = $leituraEquacao['mensagem'];
        } elseif ($equacao['diferenca_centavos'] !== 0) {
            $valores = $leituraEquacao['valores'];
            $bloqueios[] = 'A equação patrimonial não fecha. Ativo ' . Format::money((int) $valores['ativo_centavos'])
                . ', passivo ' . Format::money((int) $valores['passivo_centavos'])
                . ', patrimônio líquido ' . Format::money((int) $valores['pl_centavos'])
                . ', resultado ' . Format::money((int) $valores['resultado_centavos'])
                . '. Diferença de ' . Format::money($equacao['diferenca_centavos']) . '.';
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
            $leituraItem = LeitorDeVariaveis::ler(self::contrato(), $item, ['valor_ibs_centavos', 'valor_cbs_centavos', 'valor_centavos', 'status']);
            if (!$leituraItem['ok']) {
                $bloqueios[] = 'Item ' . $item['n_item'] . ' da nota ' . $item['numero'] . '. ' . $leituraItem['mensagem'];
                continue;
            }
            $valoresItem = $leituraItem['valores'];
            $imposto = (int) $valoresItem['valor_ibs_centavos'] + (int) $valoresItem['valor_cbs_centavos'];
            if ($imposto > (int) $valoresItem['valor_centavos'] + 1) {
                $bloqueios[] = 'O IBS/CBS do item ' . $item['n_item'] . ' da nota ' . $item['numero'] . ' supera o valor do item.';
            }
            if (in_array($valoresItem['status'], ['rejeitado', 'ambiguo'], true)) {
                $bloqueios[] = 'Item ' . $item['n_item'] . ' da nota ' . $item['numero'] . ' está ' . $valoresItem['status'] . ': ' . $item['diagnostico'];
            } elseif ($valoresItem['status'] === 'alerta') {
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
