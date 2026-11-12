<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\ConferencistaMensalAgente;
use App\Agents\Agentes\SimuladorRegimeAgente;
use App\Agents\RegistroDeAgente;
use App\Core\Database;
use App\Core\Format;
use App\Core\Money;
use App\Core\ValidationException;
use App\Fiscal\Engine\IbsCbsCalculator;
use App\Fiscal\Engine\JanelaOpcaoHibrido;

/** Do lote de notas até a decisão do semestre. Nenhum percentual ausente vira zero. */
final class ConferenciaService
{
    public function __construct(private readonly Database $db, private readonly SettingsService $config)
    {
    }

    /** @param array<string, mixed> $empresa @param array<string, string> $formulario @return array<string, mixed> */
    public function montar(array $empresa, array $formulario, string $hoje): array
    {
        $padrao = date('Y-m', strtotime($hoje . ' -1 month'));
        $de = $this->mes((string) ($formulario['de'] ?? ''), $padrao);
        $ate = $this->mes((string) ($formulario['ate'] ?? ''), $de);
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        $formulario['de'] = $de;
        $formulario['ate'] = $ate;
        $janela = JanelaOpcaoHibrido::decidir($hoje);
        $leitura = ConferencistaMensalAgente::ler($formulario);
        $lote = $this->lote((int) $empresa['id'], $de, $ate);
        $conta = [
            'de' => $de,
            'ate' => $ate,
            'hoje' => $hoje,
            'janela' => $janela,
            'leitura' => $leitura,
            'perfil' => $lote['perfil'],
            'faixas' => $lote['faixas'],
            'avisos' => $lote['avisos'],
            'itens' => $lote['itens'],
            'contagens' => $lote['contagens'],
            'meses' => $lote['meses'],
            'decisao' => $this->decisao((int) $empresa['id'], (string) $janela['semestre_inicio']),
            'diagnostico' => null,
            'projecao' => [],
            'honorario_centavos' => null,
            'mensagem' => $leitura['ok'] ? '' : (string) $leitura['mensagem'],
            'variaveis_usadas' => $leitura['usadas'],
            'variaveis_ausentes' => $leitura['ausentes'],
            'formulario' => [
                'rbt12' => trim((string) ($formulario['rbt12'] ?? '')),
                'folha12' => trim((string) ($formulario['folha12'] ?? '')),
                'margem' => trim((string) ($formulario['margem'] ?? '')),
                'horas_adicionais' => trim((string) ($formulario['horas_adicionais'] ?? '')),
                'valor_hora' => trim((string) ($formulario['valor_hora'] ?? '')),
            ],
            'pode_hibrido' => false,
            'pode_unificado' => false,
        ];
        if ($lote['meses'] === 0) {
            $conta['mensagem'] = trim($conta['mensagem'] . ' Não há nota neste período. A conferência espera o XML antes de comparar regimes.');
        }
        $conta['honorario_centavos'] = $this->honorario($leitura, $conta);
        if (!$leitura['ok'] || $lote['meses'] === 0) {
            $this->registrarLog($empresa, $conta, 'variavel');
            return $this->permissao($empresa, $conta);
        }
        $bloqueio = $this->bloqueioDoSimples($empresa, $leitura);
        if ($bloqueio !== null) {
            $conta['mensagem'] = trim($bloqueio . ' ' . $conta['mensagem']);
            $this->registrarLog($empresa, $conta, 'variavel');
            return $this->permissao($empresa, $conta);
        }
        $ano = (int) substr((string) $janela['semestre_inicio'], 0, 4);
        $aliquota = $this->config->aliquotaDoAno($ano);
        $impostos = $this->impostos($lote['snapshots'], $aliquota);
        $rotulo = $this->rotuloPeriodo($de, $ate, (int) $lote['meses'], (string) $janela['rotulo_semestre']);
        $entrada = $this->entrada($empresa, $leitura, $lote, $impostos, $aliquota, $ano, $rotulo);
        $simulado = (new SimuladorRegimeAgente())->simular($entrada);
        if (($simulado['modelo'] ?? '') === 'variavel' || !isset($simulado['unificado'], $simulado['hibrido'])) {
            $conta['mensagem'] = (string) ($simulado['recomendacao'] ?? 'O simulador não devolveu a conta.');
            $conta['variaveis_usadas'] = $simulado['variaveis_usadas'] ?? $conta['variaveis_usadas'];
            $conta['variaveis_ausentes'] = $simulado['variaveis_ausentes'] ?? $conta['variaveis_ausentes'];
            $this->registrarLog($empresa, $conta, 'variavel');
            return $this->permissao($empresa, $conta);
        }
        $conta['diagnostico'] = $this->diagnostico($simulado, $lote, $leitura, $aliquota, $ano);
        $conta['projecao'] = $this->projecao($empresa, $leitura, $lote, $ano);
        $this->registrarLog($empresa, $conta, 'deterministico');
        return $this->permissao($empresa, $conta);
    }

    /** @param array<string, mixed> $empresa @param array<string, string> $formulario */
    public function registrar(array $empresa, array $formulario, string $hoje): void
    {
        $leitura = ConferencistaMensalAgente::ler($formulario);
        $escolha = $leitura['valores']['escolha'] ?? null;
        if (!is_string($escolha)) {
            throw new ValidationException($leitura['ok'] ? 'Informe unificado ou híbrido.' : (string) $leitura['mensagem']);
        }
        if ((string) $empresa['regime'] !== 'simples') {
            throw new ValidationException('A opção do art. 13, § 9º, da LC 123/2006 é do optante pelo Simples Nacional.');
        }
        $janela = JanelaOpcaoHibrido::decidir($hoje);
        if ($janela['status'] === 'so_desistencia' && $escolha !== 'unificado') {
            throw new ValidationException('A janela fechou em ' . Format::data((string) $janela['janela_fim']) . '. Até ' . Format::data((string) $janela['retratacao_ate']) . ' só cabe desistir e permanecer no unificado.');
        }
        if (!in_array($janela['status'], ['aberta', 'so_desistencia'], true)) {
            $quando = $janela['proxima_janela_inicio'] !== '' ? $janela['proxima_janela_inicio'] : $janela['janela_inicio'];
            throw new ValidationException('Não há janela aberta. A próxima começa em ' . Format::data((string) $quando) . '.');
        }
        $conta = $this->montar($empresa, $formulario, $hoje);
        if ($escolha === 'hibrido' && $conta['diagnostico'] === null) {
            throw new ValidationException($conta['mensagem'] !== '' ? (string) $conta['mensagem'] : 'A opção pelo regime regular só é gravada com a conta do semestre feita.');
        }
        $this->db->insert('decisoes_regime', [
            'empresa_id' => $empresa['id'],
            'semestre_inicio' => $janela['semestre_inicio'],
            'semestre_fim' => $janela['semestre_fim'],
            'escolha' => $escolha,
            'janela_inicio' => $janela['janela_inicio'],
            'janela_fim' => $janela['janela_fim'],
            'retratacao_ate' => $janela['retratacao_ate'],
            'fundamento' => $janela['fundamento'],
            'fatores' => json_encode($conta['diagnostico']['fatores'] ?? [], JSON_UNESCAPED_UNICODE),
            'registrada_em' => date('Y-m-d H:i:s'),
        ]);
        $contrato = ConferencistaMensalAgente::contrato();
        RegistroDeAgente::gravar($this->db, (string) $contrato['id'], 'registrar_opcao_semestre', (int) $empresa['id'], [
            'escolha' => $escolha,
            'semestre' => $janela['rotulo_semestre'],
            'variaveis_usadas' => $conta['variaveis_usadas'],
        ], [
            'fundamento' => $janela['fundamento'],
        ], 'deterministico');
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $conta @return array<string, mixed> */
    private function permissao(array $empresa, array $conta): array
    {
        $simples = (string) $empresa['regime'] === 'simples';
        $status = (string) $conta['janela']['status'];
        $conta['pode_unificado'] = $simples && in_array($status, ['aberta', 'so_desistencia'], true);
        $conta['pode_hibrido'] = $simples && $status === 'aberta' && $conta['diagnostico'] !== null;
        return $conta;
    }

    /** @param array<string, mixed> $leitura @return ?string */
    private function bloqueioDoSimples(array $empresa, array $leitura): ?string
    {
        if ((string) $empresa['regime'] !== 'simples') {
            return null;
        }
        $rbt = $leitura['valores']['rbt12'] ?? null;
        if (!is_string($rbt) || bccomp($rbt, '0', 2) <= 0) {
            return 'Falta a receita dos últimos 12 meses. Ela escolhe a faixa do DAS. O sistema não multiplica o mês por doze.';
        }
        if (strtoupper((string) $empresa['anexo_simples']) === 'AUTO' && !isset($leitura['valores']['folha12'])) {
            return 'Falta a folha dos últimos 12 meses. Com o anexo automático, ela decide se o serviço fica no anexo III ou no V.';
        }
        return null;
    }

    /** @param array<string, mixed> $leitura @param array<string, mixed> $conta */
    private function honorario(array $leitura, array &$conta): ?int
    {
        $horas = $leitura['valores']['horas_adicionais'] ?? null;
        $hora = $leitura['valores']['valor_hora'] ?? null;
        $temHoras = is_string($horas);
        $temValor = is_string($hora);
        if ($temHoras xor $temValor) {
            $extra = 'O honorário pede as horas e o valor da hora juntos. Um dos dois sozinho não vira preço.';
            $conta['mensagem'] = trim($conta['mensagem'] . ' ' . $extra);
            return null;
        }
        if (!$temHoras || !$temValor) {
            return null;
        }
        $produto = Money::roundHalfUp(bcmul($horas, $hora, 8), 2);
        return Money::fromDecimal($produto)->cents();
    }

    /** @return array{perfil:array<string,int|string>,faixas:list<array<string,mixed>>,avisos:list<string>,itens:list<array<string,mixed>>,contagens:array<string,int>,meses:int,snapshots:list<array<string,mixed>>} */
    private function lote(int $empresaId, string $de, string $ate): array
    {
        $linhas = $this->db->all(
            'SELECT i.*, d.tipo_operacao, d.destinatario_doc, d.modelo, d.numero, d.emissao, d.valor_nota_centavos, d.status_auditoria
             FROM documento_itens i
             JOIN documentos_fiscais d ON d.id = i.documento_id
             WHERE d.empresa_id = :empresa AND substr(d.emissao, 1, 7) >= :de AND substr(d.emissao, 1, 7) <= :ate
             ORDER BY d.emissao, d.numero, i.n_item',
            [':empresa' => $empresaId, ':de' => $de, ':ate' => $ate],
        );
        $documentos = $this->db->all(
            'SELECT * FROM documentos_fiscais WHERE empresa_id = :empresa AND substr(emissao, 1, 7) >= :de AND substr(emissao, 1, 7) <= :ate',
            [':empresa' => $empresaId, ':de' => $de, ':ate' => $ate],
        );
        $perfil = ['faturamento' => 0, 'pj' => 0, 'consumidor' => 0, 'compras' => 0, 'sem_documento' => 0];
        $meses = [];
        $indeterminadas = 0;
        foreach ($documentos as $documento) {
            $meses[substr((string) $documento['emissao'], 0, 7)] = true;
            $valor = (int) $documento['valor_nota_centavos'];
            if ($documento['tipo_operacao'] === 'indeterminada') {
                $indeterminadas++;
                continue;
            }
            if ($documento['tipo_operacao'] === 'entrada') {
                $perfil['compras'] += $valor;
                continue;
            }
            if ($documento['tipo_operacao'] !== 'saida') {
                continue;
            }
            $perfil['faturamento'] += $valor;
            $doc = Format::digitos((string) $documento['destinatario_doc']);
            if ((string) $documento['modelo'] === '65' || strlen($doc) === 11) {
                $perfil['consumidor'] += $valor;
            } elseif (strlen($doc) === 14) {
                $perfil['pj'] += $valor;
            } else {
                $perfil['sem_documento'] += $valor;
            }
        }
        $contagens = ['saida' => 0, 'erro' => 0, 'conferir' => 0, 'conforme' => 0];
        $faixas = [];
        $problemas = [];
        $snapshots = [];
        $semDestaque = 0;
        $entradaSemCodigo = 0;
        $pesoIbs = '0';
        $pesoCbs = '0';
        $basePeso = 0;
        foreach ($linhas as $linha) {
            if ($linha['tipo_operacao'] === 'saida') {
                $contagens['saida']++;
                $status = (string) $linha['status'];
                if ($status === 'conforme' || $status === 'corrigido') {
                    $contagens['conforme']++;
                } elseif ($status === 'ambiguo') {
                    $contagens['conferir']++;
                } else {
                    $contagens['erro']++;
                }
                if (!in_array($status, ['conforme', 'corrigido'], true) && count($problemas) < 12) {
                    $problemas[] = $linha;
                }
            }
            $reducao = $this->reducao($linha);
            if ($linha['tipo_operacao'] === 'entrada' && trim((string) $linha['cclass_trib']) === '') {
                $entradaSemCodigo++;
            }
            if ($linha['tipo_operacao'] === 'saida' && $reducao['origem'] === 'sem_destaque') {
                $semDestaque++;
            }
            if ($linha['tipo_operacao'] === 'saida') {
                $chave = $reducao['origem'] === 'sem_destaque' ? 'sem' : bcadd($reducao['ibs'], '0', 2) . '|' . bcadd($reducao['cbs'], '0', 2);
                if (!isset($faixas[$chave])) {
                    $faixas[$chave] = [
                        'rotulo' => $reducao['origem'] === 'sem_destaque' ? 'Sem destaque de IBS/CBS' : $this->rotuloFaixa($reducao['ibs'], $reducao['cbs']),
                        'itens' => 0,
                        'valor_centavos' => 0,
                        'exemplos' => [],
                        'ordem' => $reducao['origem'] === 'sem_destaque' ? 999.0 : (float) $reducao['ibs'],
                    ];
                }
                $faixas[$chave]['itens']++;
                $faixas[$chave]['valor_centavos'] += (int) $linha['valor_centavos'];
                if (count($faixas[$chave]['exemplos']) < 3 && !in_array($linha['descricao'], $faixas[$chave]['exemplos'], true)) {
                    $faixas[$chave]['exemplos'][] = (string) $linha['descricao'];
                }
                $basePeso += (int) $linha['valor_centavos'];
                $pesoIbs = bcadd($pesoIbs, bcmul((string) $linha['valor_centavos'], $reducao['ibs'], 4), 4);
                $pesoCbs = bcadd($pesoCbs, bcmul((string) $linha['valor_centavos'], $reducao['cbs'], 4), 4);
            }
            if (in_array($linha['tipo_operacao'], ['saida', 'entrada'], true)) {
                $base = (int) $linha['base_centavos'] > 0 ? (int) $linha['base_centavos'] : (int) $linha['valor_centavos'];
                $doc = Format::digitos((string) $linha['destinatario_doc']);
                $snapshots[] = [
                    'base' => Money::ofCents($base)->toDecimal(),
                    'ibs' => $reducao['ibs'],
                    'cbs' => $reducao['cbs'],
                    'lado' => (string) $linha['tipo_operacao'],
                    'pj' => $linha['tipo_operacao'] === 'saida' && strlen($doc) === 14 && (string) $linha['modelo'] !== '65',
                ];
            }
        }
        $avisos = [];
        if ($semDestaque > 0) {
            $avisos[] = $semDestaque . ($semDestaque === 1 ? ' venda sem o imposto novo destacado.' : ' vendas sem o imposto novo destacado.') . ' Quem comprou precisa desse destaque para tomar crédito.';
        }
        if ($entradaSemCodigo > 0) {
            $avisos[] = $entradaSemCodigo . ($entradaSemCodigo === 1 ? ' compra sem a classificação do produto.' : ' compras sem a classificação do produto.') . ' O crédito dessa entrada fica em aberto.';
        }
        if ($indeterminadas > 0) {
            $avisos[] = $indeterminadas . ($indeterminadas === 1 ? ' nota em que a empresa não aparece.' : ' notas em que a empresa não aparece.') . ' Confira se o arquivo é deste cliente.';
        }
        uasort($faixas, static fn (array $a, array $b): int => $a['ordem'] <=> $b['ordem']);
        $listaFaixas = [];
        foreach ($faixas as $faixa) {
            $faixa['percentual'] = $perfil['faturamento'] > 0
                ? bcmul(bcdiv((string) $faixa['valor_centavos'], (string) $perfil['faturamento'], 8), '100', 2)
                : '0.00';
            $listaFaixas[] = $faixa;
        }
        $perfil['percentual_pj'] = $perfil['faturamento'] > 0
            ? bcmul(bcdiv((string) $perfil['pj'], (string) $perfil['faturamento'], 8), '100', 2)
            : '0.00';
        $perfil['reducao_ibs'] = $basePeso > 0 ? bcdiv($pesoIbs, (string) $basePeso, 2) : null;
        $perfil['reducao_cbs'] = $basePeso > 0 ? bcdiv($pesoCbs, (string) $basePeso, 2) : null;
        return [
            'perfil' => $perfil,
            'faixas' => $listaFaixas,
            'avisos' => $avisos,
            'itens' => $problemas,
            'contagens' => $contagens,
            'meses' => count($meses),
            'snapshots' => $snapshots,
        ];
    }

    /** @param array<string, mixed> $linha @return array{ibs:string,cbs:string,origem:string} */
    private function reducao(array $linha): array
    {
        $ibs = $this->numeroOuNull($linha['reducao_ibs'] ?? null);
        $cbs = $this->numeroOuNull($linha['reducao_cbs'] ?? null);
        if ($ibs !== null || $cbs !== null) {
            $ibs ??= $cbs ?? '0';
            $cbs ??= $ibs;
            return ['ibs' => $ibs, 'cbs' => $cbs, 'origem' => 'declarada'];
        }
        $temCodigo = trim((string) ($linha['cclass_trib'] ?? '')) !== '';
        $temDestaque = (int) $linha['valor_ibs_centavos'] > 0 || (int) $linha['valor_cbs_centavos'] > 0;
        if (!$temCodigo && !$temDestaque) {
            return ['ibs' => '0', 'cbs' => '0', 'origem' => 'sem_destaque'];
        }
        $esperada = $this->numeroOuNull($linha['reducao_esperada'] ?? null);
        if ($esperada !== null) {
            return ['ibs' => $esperada, 'cbs' => $esperada, 'origem' => 'tabela'];
        }
        return ['ibs' => '0', 'cbs' => '0', 'origem' => 'declarada'];
    }

    private function numeroOuNull(mixed $valor): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        $texto = str_replace(',', '.', $texto);
        if (preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            return null;
        }
        return Money::rate($texto);
    }

    private function rotuloFaixa(string $ibs, string $cbs): string
    {
        $ib = bcadd($ibs, '0', 2);
        $cb = bcadd($cbs, '0', 2);
        if (bccomp($ib, '0', 2) === 0 && bccomp($cb, '0', 2) === 0) {
            return 'Alíquota cheia, sem redução';
        }
        if (bccomp($ib, '100', 2) === 0 && bccomp($cb, '100', 2) === 0) {
            return 'Alíquota zero';
        }
        if (bccomp($ib, $cb, 2) === 0) {
            return 'Redução de ' . Format::percentual($ib);
        }
        return 'IBS ' . Format::percentual($ib) . ' e CBS ' . Format::percentual($cb);
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @param array<string, mixed> $aliquota
     * @return array<string, int>
     */
    private function impostos(array $snapshots, array $aliquota): array
    {
        $calc = new IbsCbsCalculator();
        $totais = ['debito_ibs' => 0, 'debito_cbs' => 0, 'credito_ibs' => 0, 'credito_cbs' => 0, 'pj_ibs' => 0, 'pj_cbs' => 0];
        foreach ($snapshots as $item) {
            $valor = $calc->calcular((string) $item['base'], (string) $aliquota['ibs'], (string) $aliquota['cbs'], (string) $item['ibs'], (string) $item['cbs']);
            if ($item['lado'] === 'entrada') {
                $totais['credito_ibs'] += $valor['ibs_centavos'];
                $totais['credito_cbs'] += $valor['cbs_centavos'];
                continue;
            }
            $totais['debito_ibs'] += $valor['ibs_centavos'];
            $totais['debito_cbs'] += $valor['cbs_centavos'];
            if ($item['pj'] === true) {
                $totais['pj_ibs'] += $valor['ibs_centavos'];
                $totais['pj_cbs'] += $valor['cbs_centavos'];
            }
        }
        return $totais;
    }

    /**
     * @param array<string, mixed> $empresa
     * @param array<string, mixed> $leitura
     * @param array<string, mixed> $lote
     * @param array<string, int> $impostos
     * @param array<string, mixed> $aliquota
     * @return array<string, mixed>
     */
    private function entrada(array $empresa, array $leitura, array $lote, array $impostos, array $aliquota, int $ano, string $rotulo): array
    {
        $meses = max(1, (int) $lote['meses']);
        $perfil = $lote['perfil'];
        $entrada = [
            'ano' => $ano,
            'regime' => $empresa['regime'],
            'anexo' => $empresa['anexo_simples'],
            'receita_mes' => Money::ofCents(intdiv((int) $perfil['faturamento'], $meses))->toDecimal(),
            'receita_pj' => Money::ofCents(intdiv((int) $perfil['pj'], $meses))->toDecimal(),
            'receita_pf' => Money::ofCents(intdiv((int) $perfil['consumidor'], $meses))->toDecimal(),
            'base_credito' => Money::ofCents(intdiv((int) $perfil['compras'], $meses))->toDecimal(),
            'aliquota_cbs' => (string) $aliquota['cbs'],
            'aliquota_ibs' => (string) $aliquota['ibs'],
            'pis_cofins_vigente' => (int) $aliquota['pis_cofins_vigente'] === 1,
            'fator_icms_iss' => (string) $aliquota['fator_icms_iss'],
            'cbs_compensavel' => (int) $aliquota['cbs_compensavel'] === 1,
            'ibs_compensavel' => (int) $aliquota['ibs_compensavel'] === 1,
            'natureza_aliquota' => (string) $aliquota['natureza'],
            'competencia_rotulo' => $rotulo,
            'debito_ibs_centavos' => intdiv($impostos['debito_ibs'], $meses),
            'debito_cbs_centavos' => intdiv($impostos['debito_cbs'], $meses),
            'credito_ibs_centavos' => intdiv($impostos['credito_ibs'], $meses),
            'credito_cbs_centavos' => intdiv($impostos['credito_cbs'], $meses),
            'debito_pj_ibs_centavos' => intdiv($impostos['pj_ibs'], $meses),
            'debito_pj_cbs_centavos' => intdiv($impostos['pj_cbs'], $meses),
        ];
        if (isset($leitura['valores']['rbt12'])) {
            $entrada['rbt12'] = $leitura['valores']['rbt12'];
        }
        if (isset($leitura['valores']['folha12'])) {
            $entrada['folha12'] = $leitura['valores']['folha12'];
        }
        return $entrada;
    }

    /**
     * @param array<string, mixed> $simulado
     * @param array<string, mixed> $lote
     * @param array<string, mixed> $leitura
     * @param array<string, mixed> $aliquota
     * @return array<string, mixed>
     */
    private function diagnostico(array $simulado, array $lote, array $leitura, array $aliquota, int $ano): array
    {
        $unificado = (int) $simulado['unificado']['caixa_centavos'];
        $hibrido = (int) $simulado['hibrido']['caixa_centavos'];
        $delta = $hibrido - $unificado;
        $percentual = $unificado === 0 ? null : bcmul(bcdiv((string) $delta, (string) abs($unificado), 8), '100', 2);
        $impacto = 'indefinido';
        if ($percentual !== null) {
            $absoluto = ltrim($percentual, '-');
            $impacto = bccomp($absoluto, '5', 2) < 0 ? 'baixo' : (bccomp($absoluto, '15', 2) < 0 ? 'moderado' : 'alto');
        }
        $perfil = $lote['perfil'];
        $fatores = [];
        if ($percentual !== null) {
            if ($delta > 0) {
                $comparacao = 'Destacar o imposto novo custa ' . Format::percentual($percentual) . ' a mais do que ficar no Simples.';
                $ladoCaixa = bccomp($percentual, '5', 2) < 0 ? 'híbrido' : 'unificado';
            } elseif ($delta < 0) {
                $comparacao = 'Destacar o imposto novo fica ' . Format::percentual(ltrim($percentual, '-')) . ' mais barato do que ficar no Simples.';
                $ladoCaixa = 'híbrido';
            } else {
                $comparacao = 'Os dois caminhos custam o mesmo.';
                $ladoCaixa = 'híbrido';
            }
            $fatores[] = [
                'lado' => $ladoCaixa,
                'texto' => $comparacao . ' Até 5% a mais, o escritório lê como diferença pequena. Isso não é limite da lei.',
            ];
        }
        $pj = (string) $perfil['percentual_pj'];
        $fatores[] = [
            'lado' => bccomp($pj, '30', 2) >= 0 ? 'híbrido' : 'unificado',
            'texto' => Format::percentual($pj) . ' das vendas foram para empresa, que pode aproveitar crédito. Abaixo de 30%, esse ganho é pequeno.',
        ];
        $abate = (string) ($simulado['creditos_abatem'] ?? '0');
        $fatores[] = [
            'lado' => bccomp($abate, '20', 2) >= 0 ? 'híbrido' : 'unificado',
            'texto' => 'O crédito das compras cobre ' . Format::percentual($abate) . ' do imposto novo, com a alíquota de ' . $ano . '.',
        ];
        if (is_string($perfil['reducao_ibs'])) {
            $diferente = is_string($perfil['reducao_cbs']) && bccomp($perfil['reducao_ibs'], $perfil['reducao_cbs'], 2) !== 0;
            $texto = 'A redução média de IBS sobre as saídas é ' . Format::percentual($perfil['reducao_ibs']) . '.';
            if ($diferente) {
                $texto .= ' A de CBS é ' . Format::percentual((string) $perfil['reducao_cbs']) . '. As duas ficaram separadas.';
            }
            $fatores[] = ['lado' => bccomp($perfil['reducao_ibs'], '30', 2) >= 0 ? 'híbrido' : 'unificado', 'texto' => $texto];
        }
        $porFora = $simulado['das']['por_fora'] ?? [];
        if (is_array($porFora) && $porFora !== []) {
            $fatores[] = [
                'lado' => 'unificado',
                'texto' => 'Na faixa ' . (string) ($simulado['das']['faixa'] ?? '') . ' do anexo ' . (string) ($simulado['das']['anexo'] ?? '') . ', ' . implode(' e ', $porFora) . ' já sai do DAS.',
            ];
        }
        $repasse = null;
        $margem = $leitura['valores']['margem'] ?? null;
        if (is_string($margem)) {
            if (bccomp($margem, '0', 2) <= 0 || bccomp($margem, '100', 2) >= 0) {
                $fatores[] = ['lado' => 'unificado', 'texto' => 'A margem precisa ficar entre 0 e 100 para ajustar o preço. O número informado ficou de fora.'];
            } elseif ($delta > 0) {
                $fator = bcsub('1', bcdiv($margem, '100', 8), 8);
                $reais = bcdiv(Money::ofCents($delta)->toDecimal(), $fator, 8);
                $repasse = Money::fromDecimal(Money::roundHalfUp($reais, 2))->cents();
            } else {
                $repasse = 0;
            }
        }
        $simulado['delta_centavos'] = $delta;
        $simulado['delta_percentual'] = $percentual;
        $simulado['impacto'] = $impacto;
        $simulado['fatores'] = $fatores;
        $simulado['repasse_centavos'] = $repasse;
        $simulado['ano_decisao'] = $ano;
        $simulado['natureza'] = (string) $aliquota['natureza'];
        $simulado['observacao_aliquota'] = (string) $aliquota['observacao'];
        return $simulado;
    }

    /**
     * @param array<string, mixed> $empresa
     * @param array<string, mixed> $leitura
     * @param array<string, mixed> $lote
     * @return list<array<string, mixed>>
     */
    private function projecao(array $empresa, array $leitura, array $lote, int $anoDecisao): array
    {
        $linhas = [];
        $agente = new SimuladorRegimeAgente();
        for ($ano = 2027; $ano <= 2033; $ano++) {
            $aliquota = $this->config->aliquotaDoAno($ano);
            $impostos = $this->impostos($lote['snapshots'], $aliquota);
            $simulado = $agente->simular($this->entrada($empresa, $leitura, $lote, $impostos, $aliquota, $ano, (string) $ano));
            if (!isset($simulado['unificado']['caixa_centavos'], $simulado['hibrido']['caixa_centavos'])) {
                continue;
            }
            $linhas[] = [
                'ano' => $ano,
                'ibs' => (string) $aliquota['ibs'],
                'cbs' => (string) $aliquota['cbs'],
                'natureza' => (string) $aliquota['natureza'],
                'unificado' => (int) $simulado['unificado']['caixa_centavos'],
                'hibrido' => (int) $simulado['hibrido']['caixa_centavos'],
                'decisao' => $ano === $anoDecisao,
            ];
        }
        return $linhas;
    }

    /** @return ?array<string, mixed> */
    private function decisao(int $empresaId, string $semestreInicio): ?array
    {
        return $this->db->one(
            'SELECT * FROM decisoes_regime WHERE empresa_id = :empresa AND semestre_inicio = :semestre ORDER BY id DESC LIMIT 1',
            [':empresa' => $empresaId, ':semestre' => $semestreInicio],
        );
    }

    /** @param array<string, mixed> $empresa @param array<string, mixed> $conta */
    private function registrarLog(array $empresa, array $conta, string $modelo): void
    {
        $contrato = ConferencistaMensalAgente::contrato();
        RegistroDeAgente::gravar($this->db, (string) $contrato['id'], (string) $contrato['ferramenta'], (int) $empresa['id'], [
            'de' => $conta['de'],
            'ate' => $conta['ate'],
            'variaveis_usadas' => $conta['variaveis_usadas'],
            'variaveis_ausentes' => $conta['variaveis_ausentes'],
        ], [
            'mensagem' => $conta['mensagem'],
            'itens_saida' => $conta['contagens']['saida'] ?? 0,
            'semestre' => $conta['janela']['rotulo_semestre'] ?? '',
        ], $modelo);
    }

    private function rotuloPeriodo(string $de, string $ate, int $meses, string $semestre): string
    {
        $nomes = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];
        $inicio = ($nomes[(int) substr($de, 5, 2)] ?? $de) . ' de ' . substr($de, 0, 4);
        $fim = ($nomes[(int) substr($ate, 5, 2)] ?? $ate) . ' de ' . substr($ate, 0, 4);
        $quando = $de === $ate ? $inicio : $inicio . ' a ' . $fim;
        $contagem = $meses === 1 ? '1 mês conferido' : $meses . ' meses conferidos';
        return $quando . ', na média de ' . $contagem . ', com as alíquotas de ' . $semestre;
    }

    private function mes(string $valor, string $padrao): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $valor, $encontrado) === 1 && checkdate((int) $encontrado[2], 1, (int) $encontrado[1])) {
            return $encontrado[1] . '-' . $encontrado[2];
        }
        return $padrao;
    }
}
