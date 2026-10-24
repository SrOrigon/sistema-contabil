<?php

declare(strict_types=1);

use App\App\AuditService;
use App\App\BankService;
use App\App\CompanyService;
use App\App\KnowledgeService;
use App\App\LedgerService;
use App\App\SettingsService;
use App\App\StrategyService;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Money;
use App\Fiscal\Engine\IbsCbsCalculator;
use App\Fiscal\Engine\SimplesNacionalCalculator;
use App\Fiscal\Engine\TransitionSimulator;
use App\Fiscal\Parsers\NfeXmlStreamParser;
use App\Fiscal\Validators\CclassTribValidator;
use App\Fiscal\Validators\NcmCorrelationValidator;
use App\KnowledgeBase\HybridLawSearcher;

require dirname(__DIR__) . '/src/bootstrap.php';

final class Teste
{
    public int $passou = 0;
    public int $falhou = 0;

    public function igual(mixed $esperado, mixed $obtido, string $mensagem): void
    {
        if ($esperado === $obtido) {
            $this->passou++;
            return;
        }
        $this->falhou++;
        echo "FALHOU: {$mensagem}\n esperado: " . var_export($esperado, true) . "\n obtido: " . var_export($obtido, true) . "\n";
    }

    public function verdadeiro(bool $condicao, string $mensagem): void
    {
        $this->igual(true, $condicao, $mensagem);
    }
}

$t = new Teste();
$simples = new SimplesNacionalCalculator();
$faixa1 = $simples->calcular('I', '180000.00', '10000.00');
$t->igual(40000, $faixa1['das_centavos'], 'DAS da primeira faixa do Anexo I');
$t->igual(1, $faixa1['faixa'], 'Faixa 1');
$t->igual(40000, array_sum($faixa1['partilha_centavos']), 'Partilha soma o DAS');

$faixa6 = $simples->calcular('I', '4000000.00', '10000.00');
$t->igual('9.55', $faixa6['aliquota_efetiva'], 'Alíquota efetiva da sexta faixa');
$t->igual(95500, $faixa6['das_centavos'], 'DAS de R$ 10.000 na sexta faixa');
$t->verdadeiro(in_array('ICMS', $faixa6['por_fora'], true), 'ICMS por fora na última faixa do Anexo I');

$auto = $simples->calcular('AUTO', '1000000.00', '10000.00', '300000.00');
$t->igual('III', $auto['anexo'], 'Fator R de 30% escolhe o Anexo III');
$autoBaixo = $simples->calcular('AUTO', '1000000.00', '10000.00', '200000.00');
$t->igual('V', $autoBaixo['anexo'], 'Fator R de 20% escolhe o Anexo V');

$ibs = new IbsCbsCalculator();
$calculo = $ibs->calcular('100.00', '0.10', '0.90', '60', '60');
$t->igual(4, $calculo['ibs_centavos'], 'IBS de teste com redução de 60%');
$t->igual(36, $calculo['cbs_centavos'], 'CBS de teste com redução de 60%');
$zerado = $ibs->calcular('100.00', '0.10', '0.90', '100', '100');
$t->igual(0, $zerado['total_centavos'], 'Alíquota zero zera o imposto');

$simulacao = (new TransitionSimulator())->simular([
    'ano' => 2027,
    'regime' => 'simples',
    'anexo' => 'I',
    'rbt12' => '180000.00',
    'receita_mes' => '30000.00',
    'receita_pj' => '10410.00',
    'base_credito' => '10000.00',
    'aliquota_cbs' => '8.80',
    'aliquota_ibs' => '0.10',
    'pis_cofins_vigente' => false,
    'fator_icms_iss' => '1',
    'cbs_compensavel' => false,
    'ibs_compensavel' => true,
    'competencia_rotulo' => 'setembro de 2027',
]);
$t->verdadeiro($simulacao['hibrido']['caixa_centavos'] < $simulacao['unificado']['caixa_centavos'], 'Híbrido aproveita crédito e desembolsa menos');
$t->verdadeiro(str_contains($simulacao['recomendacao'], 'setembro de 2027'), 'Parecer cita a competência');

$banco = new Database('sqlite::memory:');
(new Migrator($banco))->migrate();
$busca = new HybridLawSearcher($banco);
$achou = $busca->search('Fator R', new DateTimeImmutable('2026-06-01'));
$t->verdadeiro($achou !== [] && str_contains($achou[0]['conteudo'], '28%'), 'Busca vigente encontra o Fator R');
$teste2026 = $busca->search('alíquota de teste', new DateTimeImmutable('2027-02-01'));
foreach ($teste2026 as $trecho) {
    $t->verdadeiro($trecho['vigencia_fim'] === '' || $trecho['vigencia_fim'] >= '2027-02-01', 'Trecho de 2026 vencido não volta em 2027');
}
$banco->insert('legislacao_chunks', [
    'diploma_legal' => 'Norma revogada',
    'artigo' => 'Art. 1',
    'paragrafo_inciso' => '',
    'tipo_tributo' => 'TESTE',
    'vigencia_inicio' => '2010-01-01',
    'vigencia_fim' => '2012-12-31',
    'titulo' => 'Regra antiga do teste interno',
    'conteudo' => 'palavraunica revogada que não pode aparecer',
    'origem' => 'teste',
]);
$revogada = $busca->search('palavraunica', new DateTimeImmutable('2026-01-01'));
$t->igual([], $revogada, 'Norma fora da vigência não é recuperada');

$amostra = tempnam(sys_get_temp_dir(), 'lei');
file_put_contents($amostra, implode("\n", [
    '---',
    'titulo: "Lei Complementar nº 214 de 16/01/2025"',
    'tipo: "Lei Complementar"',
    'numero: "214"',
    'data: 2025-01-16',
    'ementa: "Institui o IBS e a CBS."',
    'revogada: false',
    'fonte: "https://www.planalto.gov.br/exemplo"',
    '---',
    '# Lei Complementar nº 214',
    '',
    'Texto de ensaio.',
]));
$lida = (new App\KnowledgeBase\AcervoIndexer())->ler($amostra);
$t->igual('Lei Complementar 214/2025', $lida['diploma'] ?? '', 'Acervo lê o cabeçalho da norma');
$t->igual('2025-01-16', $lida['vigencia_inicio'] ?? '', 'Acervo usa a data da norma como início de vigência');
$t->igual('', $lida['vigencia_fim'] ?? 'x', 'Norma em vigor permanece sem data final');
$expressao = (new App\KnowledgeBase\AcervoCatalog())->expressao('LC 214 fator');
$t->verdadeiro(str_contains($expressao, 'complementar') && str_contains($expressao, '214'), 'Consulta LC 214 também procura lei complementar');
@unlink($amostra);

$regras = $banco->all('SELECT * FROM regras_ncm');
$validador = new NcmCorrelationValidator($regras, new CclassTribValidator($banco->all('SELECT * FROM cclass_trib'), false));
$fralda = $validador->validar(['ncm' => '96190000', 'descricao' => 'FRALDA DESCARTAVEL CONFORT', 'cst' => '000', 'cclass_trib' => '000001', 'reducao_ibs' => '0', 'reducao_cbs' => '0'], '2026-10-01');
$t->igual('rejeitado', $fralda['status'], 'Fralda com redução zero é rejeitada');
$t->igual('60', (string) $fralda['reducao_esperada'], 'Redução esperada da fralda é 60%');
$absorvente = $validador->validar(['ncm' => '96190000', 'descricao' => 'ABSORVENTE NOTURNO', 'cst' => '200', 'cclass_trib' => '', 'reducao_ibs' => '100', 'reducao_cbs' => '100'], '2026-10-01');
$t->igual('conforme', $absorvente['status'], 'Absorvente com alíquota zero está conforme');
$ambiguo = $validador->validar(['ncm' => '96190000', 'descricao' => 'PACOTE HIGIENE', 'cst' => '', 'cclass_trib' => '', 'reducao_ibs' => '', 'reducao_cbs' => ''], '2026-10-01');
$t->igual('ambiguo', $ambiguo['status'], 'Descrição sem hipótese fica ambígua');

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">
  <NFe><infNFe Id="NFe35260645849027000191550010000000011000000011">
    <ide><mod>55</mod><serie>1</serie><nNF>11</nNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi><tpNF>1</tpNF></ide>
    <emit><CNPJ>45849027000191</CNPJ><xNome>Padaria Luz Ltda</xNome></emit>
    <dest><CPF>39053344705</CPF><xNome>Maria Souza</xNome></dest>
    <det nItem="1"><prod><cProd>1</cProd><xProd>FRALDA DESCARTAVEL CONFORT</xProd><NCM>96190000</NCM><CFOP>5102</CFOP><qCom>1.0000</qCom><vProd>100.00</vProd></prod>
      <imposto><IBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib><gIBSCBS><vBC>100.00</vBC><pRedAliq>0.00</pRedAliq><gIBSUF><pIBSUF>0.10</pIBSUF><vIBSUF>0.10</vIBSUF></gIBSUF><gCBS><pCBS>0.90</pCBS><vCBS>0.90</vCBS></gCBS></gIBSCBS></IBSCBS></imposto>
    </det>
    <total><ICMSTot><vProd>100.00</vProd><vNF>100.00</vNF></ICMSTot></total>
  </infNFe></NFe>
</nfeProc>
XML;
$arquivo = tempnam(sys_get_temp_dir(), 'nfe');
file_put_contents($arquivo, $xml);
$lido = (new NfeXmlStreamParser())->parseArquivo($arquivo)[0];
$t->igual('11', $lido['numero'], 'Parser lê o número da nota');
$t->igual('96190000', $lido['itens'][0]['ncm'], 'Parser lê o NCM');
$t->igual(10000, $lido['valor_nota_centavos'], 'Parser lê o valor da nota em centavos');
$t->igual(10, $lido['itens'][0]['valor_ibs_centavos'], 'Parser soma o IBS do item');

$empresas = new CompanyService($banco, new LedgerService($banco));
$id = $empresas->salvar(null, [
    'razao_social' => 'Padaria Luz Ltda',
    'cnpj' => '45849027000191',
    'regime' => 'simples',
    'anexo_simples' => 'I',
    'capital_social' => '10000,00',
    'inicio_atividade' => '2026-01-01',
]);
$auditoria = new AuditService($banco, new LedgerService($banco), new SettingsService($banco));
$documentoId = $auditoria->importar($id, $arquivo, 'fralda.xml')[0];
$documento = $auditoria->documento($documentoId);
$t->igual('rejeitado', $documento['status_auditoria'], 'Lote real rejeita a fralda mal classificada');
$t->igual(null, $documento['itens'][0]['cclass_esperado'], 'cClass de tributação integral não vira o código esperado da fralda');
$t->igual('saida', $documento['tipo_operacao'], 'CNPJ emitente vira saída');
$equacao = (new App\Agents\Orchestrators\SanityReverseAuditor($banco))->equacao($id);
$t->igual(0, $equacao['diferenca_centavos'], 'Razão fecha depois da nota e do capital');

$compra = str_replace(
    ['45849027000191', 'Padaria Luz Ltda', '39053344705', 'Maria Souza', 'FRALDA DESCARTAVEL CONFORT', '96190000', '100.00', 'nNF>11', 'tpNF>1', '000001', 'pRedAliq>0.00', 'vIBSUF>0.10', 'vCBS>0.90', 'pIBSUF>0.10', 'pCBS>0.90'],
    ['11222333000181', 'Moinho Serra Ltda', '45849027000191', 'Padaria Luz Ltda', 'FARINHA DE TRIGO ESPECIAL', '11010010', '1540.00', 'nNF>22', 'tpNF>1', '000001', 'pRedAliq>100.00', 'vIBSUF>0.00', 'vCBS>0.00', 'pIBSUF>0.00', 'pCBS>0.00'],
    $xml,
);
$compra = str_replace('<CST>000</CST>', '<CST>200</CST>', $compra);
$compra = str_replace('<cClassTrib>000001</cClassTrib>', '', $compra);
file_put_contents($arquivo, $compra);
$compraId = $auditoria->importar($id, $arquivo, 'farinha.xml')[0];
$notaCompra = $auditoria->documento($compraId);
$t->igual('entrada', $notaCompra['tipo_operacao'], 'Compra em que a empresa é destinatária vira entrada');
$t->igual('conforme', $notaCompra['status_auditoria'], 'Farinha da cesta básica com alíquota zero fica conforme');

$ofx = tempnam(sys_get_temp_dir(), 'ofx');
file_put_contents($ofx, '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261002<TRNAMT>-1540.00<FITID>abc1<MEMO>PAG FARINHA 11222333000181</STMTTRN></BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>');
$bancos = new BankService($banco, new LedgerService($banco), new SettingsService($banco));
$conta = $bancos->criarConta($id, ['apelido' => 'Conta movimento', 'banco' => 'Itaú', 'saldo_inicial' => '0']);
$resultadoBanco = $bancos->importarArquivo($conta, $ofx, 'extrato.ofx');
$t->igual(1, $resultadoBanco['automaticas'], 'Pagamento com valor, data e CNPJ concilia sozinho');
$equacao = (new App\Agents\Orchestrators\SanityReverseAuditor($banco))->equacao($id);
$t->igual(0, $equacao['diferenca_centavos'], 'Razão continua fechado depois da conciliação');

$adiantado = tempnam(sys_get_temp_dir(), 'ofx');
file_put_contents($adiantado, '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261003<TRNAMT>-880.00<FITID>antes-da-nota<MEMO>PAG ACUCAR 11222333000181</STMTTRN></BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>');
$antes = $bancos->importarArquivo($conta, $adiantado, 'adiantado.ofx');
$t->igual(0, $antes['automaticas'], 'Extrato sem nota correspondente permanece pendente');
$acucar = str_replace(
    ['nNF>22', '1540.00', 'FARINHA DE TRIGO ESPECIAL', 'NFe35260611222333000181550010000000011000000011'],
    ['nNF>23', '880.00', 'FARINHA DE TRIGO PACOTE', 'NFe35260611222333000181550010000000023000000023'],
    $compra,
);
file_put_contents($arquivo, $acucar);
$auditoria->importar($id, $arquivo, 'acucar.xml');
$txAdiantada = $banco->one('SELECT status FROM transacoes_bancarias WHERE fitid = :fitid', [':fitid' => 'antes-da-nota']);
$t->igual('conciliado', $txAdiantada['status'] ?? '', 'Nota que chega depois concilia o extrato que já estava pendente');
@unlink($adiantado);

$estrategia = new StrategyService($banco, new SettingsService($banco));
$empresa = $empresas->obter($id);
$simulado = $estrategia->simular($empresa, ['competencia' => '2026-10', 'origem' => 'documentos', 'rbt12' => '180000,00', 'anexo' => 'I', 'folha12' => '0']);
$t->verdadeiro(str_contains($simulado['recomendacao'], 'outubro de 2026'), 'Estratégia usa as notas gravadas');

$consulta = (new KnowledgeService($banco, new SettingsService($banco)))->responder('Como funciona o Fator R?', '2026-10-01', $id);
$t->verdadeiro(str_contains($consulta['resposta'], '28%'), 'Consultor responde com o trecho vigente');

@unlink($arquivo);
@unlink($ofx);
echo $t->passou . " testes passaram";
if ($t->falhou > 0) {
    echo ", " . $t->falhou . " falharam\n";
    exit(1);
}
echo "\n";
