<?php

declare(strict_types=1);

use App\Agents\Agentes\AuditorComplianceAgente;
use App\Agents\Agentes\TesoureiroPreditivoAgente;
use App\Agents\Orchestrators\FechamentoAutonomoOrchestrator;
use App\App\AuditService;
use App\App\BankService;
use App\App\CompanyService;
use App\App\FolhaPagamentoEngine;
use App\App\KnowledgeService;
use App\App\LedgerService;
use App\App\SettingsService;
use App\App\StrategyService;
use App\App\TesourariaEstocasticaEngine;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Money;
use App\Fiscal\Engine\IbsCbsCalculator;
use App\Fiscal\Engine\IngestorDocumentosEngine;
use App\Fiscal\Engine\PlanejamentoTributarioAvancado;
use App\Fiscal\Engine\RoteadorArquivosService;
use App\Fiscal\Engine\SimplesNacionalCalculator;
use App\Fiscal\Engine\TeseTributariaEngine;
use App\Fiscal\Engine\TransitionSimulator;
use App\Fiscal\Parsers\NfeXmlStreamParser;
use App\Fiscal\ReformaOficialSeeder;
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

$zipCaminho = tempnam(sys_get_temp_dir(), 'lote');
$zip = new ZipArchive();
$zip->open($zipCaminho, ZipArchive::OVERWRITE);
$zip->addFromString('pasta/fralda-lote.xml', $xml);
$zip->addFromString('pasta/farinha-lote.xml', $compra);
$zip->addFromString('pasta/leia-me.pdf', '%PDF-1.4');
$zip->addFromString('__MACOSX/._fralda-lote.xml', $xml);
$zip->addFromString('pasta/quebrada.xml', '<nota>sem estrutura</nota>');
$zip->close();
$lote = $auditoria->importar($id, $zipCaminho, 'fechamento.zip');
$t->igual(2, count($lote), 'ZIP grava as notas válidas e segue depois do XML quebrado');
$fraldaLote = $auditoria->documento($documentoId);
$t->igual('pasta/fralda-lote.xml', $fraldaLote['arquivo_nome'] ?? '', 'Cada nota guarda o nome de dentro do ZIP');
$resumoLote = $auditoria->resumoLote();
$t->igual(2, $resumoLote['ignorados'], 'PDF e arquivo oculto do ZIP ficam de fora');
$t->igual(1, count($resumoLote['erros']), 'XML sem nota entra no resumo e não interrompe o lote');
@unlink($zipCaminho);

$pastaAcervo = sys_get_temp_dir() . '/acervo-reforma-' . uniqid();
$pasta214 = $pastaAcervo . '/federal/leis-complementares/2025';
$pasta227 = $pastaAcervo . '/federal/leis-complementares/2026';
mkdir($pasta214, 0777, true);
mkdir($pasta227, 0777, true);
file_put_contents($pasta214 . '/lcp-214-teste.md', "Republicação dos dispositivos vetados.\nArt. 26.\n");
file_put_contents($pasta227 . '/lcp-227-teste.md', "Altera a LC 214.\nANEXO I\nNCM 1902.11.00 citado na ementa, sem grade.\n");
$pastaCsv = sys_get_temp_dir() . '/tabelas-reforma-' . uniqid();
mkdir($pastaCsv, 0777, true);
file_put_contents($pastaCsv . '/cclass-trib.csv', "codigo;cst;descricao;reducao_ibs;reducao_cbs;fundamento;vigencia_inicio\n200001;200;Redução de teste;60;60;CSV de teste;2026-01-01\n");
file_put_contents($pastaCsv . '/regras-ncm.csv', "ncm;match_tipo;descricao;reducao;cst;cclass_trib;palavras_incluir;palavras_excluir;fundamento;vigencia_inicio;vigencia_fim;prioridade\n19021100;prefixo;Massa de teste;100;200;;;;LC de teste;2026-01-01;;40\n");
$semeador = new ReformaOficialSeeder($banco);
$varredura214 = $semeador->varrerArquivo($pasta214 . '/lcp-214-teste.md');
$t->igual(0, $varredura214['ncm'], 'Texto de veto da LC 214 não produz NCM');
$t->verdadeiro(str_contains($varredura214['observacao'], 'vetados'), 'Arquivo curto com veto é identificado como republicação');
$relatorio = $semeador->executar($pastaAcervo, $pastaCsv);
$t->verdadeiro(str_contains($relatorio, 'não é inventado') === false, 'Quando o texto cita NCM, o relatório não diz que a lista estava vazia');
$t->verdadeiro(str_contains($relatorio, 'Nada foi gravado a partir da prosa'), 'Citação solta de NCM não vira regra');
$t->verdadeiro(str_contains($relatorio, '1 código'), 'CSV de cClassTrib entra pelo comando');
$codigoTeste = $banco->one('SELECT cst, reducao_ibs FROM cclass_trib WHERE codigo = :codigo', [':codigo' => '200001']);
$t->igual('200', $codigoTeste['cst'] ?? '', 'Código do CSV fica gravado com o CST informado');
$regraTeste = $banco->one('SELECT reducao FROM regras_ncm WHERE ncm = :ncm AND descricao = :descricao', [':ncm' => '19021100', ':descricao' => 'Massa de teste']);
$t->igual('100', $regraTeste['reducao'] ?? '', 'Regra de NCM do CSV fica gravada');
$semeador->executar($pastaAcervo, $pastaCsv);
$repetida = $banco->one('SELECT COUNT(*) AS total FROM regras_ncm WHERE ncm = :ncm AND descricao = :descricao', [':ncm' => '19021100', ':descricao' => 'Massa de teste']);
$t->igual(1, (int) ($repetida['total'] ?? 0), 'Rodar o comando de novo não duplica a regra');
$flag = $banco->one('SELECT valor FROM configuracoes WHERE chave = :chave', [':chave' => 'tabelas.cclass_oficial_completa']);
$t->igual('0', $flag['valor'] ?? '', 'O comando não marca a tabela de cClassTrib como completa');

@unlink($arquivo);
@unlink($ofx);
$catalogo = \App\Agents\AgentCatalog::todos();
$t->igual(8, count($catalogo), 'O catálogo traz um agente por ferramenta');
$t->igual(count(array_column($catalogo, 'ferramenta')), count(array_unique(array_column($catalogo, 'ferramenta'))), 'Nenhuma ferramenta fica com dois agentes');
$tipos = ['codigo_ncm', 'codigo', 'texto', 'data', 'ano', 'dinheiro', 'percentual', 'centavos', 'inteiro', 'regime', 'status', 'decimal', 'escolha'];
foreach ($catalogo as $agente) {
    $t->verdadeiro($agente['variaveis'] !== [], $agente['id'] . ' declara variáveis');
    foreach ($agente['variaveis'] as $variavel) {
        $t->verdadeiro(in_array($variavel['tipo'], $tipos, true), $agente['id'] . ' tipa ' . $variavel['nome']);
    }
    $t->verdadeiro($agente['leis'] !== [], $agente['id'] . ' declara leis');
    $instrucao = \App\Agents\AgentCatalog::instrucao($agente['id']);
    $t->verdadeiro(str_contains($instrucao, $agente['ferramenta']), $agente['id'] . ' leva a própria ferramenta na instrução');
}
$t->verdadeiro(str_contains(\App\Agents\AgentCatalog::trava(), 'calcula imposto por conta própria'), 'A trava impede o agente de calcular sozinho');
$semNcm = \App\Agents\LeitorDeVariaveis::ler(\App\Agents\Agentes\ValidadorRegrasAgente::contrato(), ['descricao' => 'Fralda', 'ncm' => ''], ['ncm', 'descricao']);
$t->igual(false, $semNcm['ok'], 'NCM vazio interrompe o validador');
$t->verdadeiro(str_contains($semNcm['mensagem'], 'ncm'), 'A mensagem cita a variável ncm');
$ncmLido = \App\Agents\LeitorDeVariaveis::ler(\App\Agents\Agentes\ValidadorRegrasAgente::contrato(), [
    'ncm' => '9619.00.10',
    'descricao' => ' Fralda ',
    'cclass_trib' => '',
], ['ncm', 'descricao']);
$t->igual(true, $ncmLido['ok'], 'NCM com pontuação e cClassTrib vazio são lidos');
$t->igual('96190010', $ncmLido['valores']['ncm'], 'O NCM fica em oito dígitos');
$t->igual(false, isset($ncmLido['valores']['cClassTrib']), 'cClassTrib vazio não é inventado');
$ncmCurto = \App\Agents\LeitorDeVariaveis::ler(\App\Agents\Agentes\ValidadorRegrasAgente::contrato(), ['ncm' => '9619', 'descricao' => 'Fralda'], ['ncm', 'descricao']);
$t->igual(false, $ncmCurto['ok'], 'NCM com menos de oito dígitos é ilegível');
$simulacao = \App\Agents\LeitorDeVariaveis::ler(\App\Agents\Agentes\SimuladorRegimeAgente::contrato(), [
    'ano' => '2026',
    'regime' => 'lucro presumido',
    'receita_mes' => '1.234,50',
    'aliquota_ibs' => '17,70%',
    'aliquota_cbs' => '8.80',
]);
$t->igual(true, $simulacao['ok'], 'O simulador lê ano, regime, receita e alíquotas');
$t->igual(2026, $simulacao['valores']['ano'], 'O ano fica inteiro');
$t->igual('presumido', $simulacao['valores']['regime'], 'Lucro presumido vira o regime presumido');
$t->igual('1234.50', $simulacao['valores']['receita_mes'], 'A receita em reais brasileiros vira decimal');
$t->igual('17.70', $simulacao['valores']['aliquota_ibs'], 'A alíquota perde o símbolo de percentual');
$consulta = \App\Agents\Agentes\ConsultorArtigoAgente::ler('O que é a cesta básica?', '26/10/2026');
$t->igual(true, $consulta['ok'], 'O consultor aceita a data brasileira');
$t->igual('2026-10-26', $consulta['valores']['competencia'], 'A competência fica em AAAA-MM-DD');

$aberta = \App\Fiscal\Engine\JanelaOpcaoHibrido::decidir('2026-09-15');
$t->igual('aberta', $aberta['status'], '15/09/2026 a janela do primeiro semestre de 2027 está aberta');
$t->igual('2026-10-30', $aberta['janela_fim'], 'A janela excepcional vai até 30/10/2026');
$t->igual('2027-01-01', $aberta['semestre_inicio'], 'Setembro de 2026 decide janeiro de 2027');
$retratacao = \App\Fiscal\Engine\JanelaOpcaoHibrido::decidir('2026-11-10');
$t->igual('so_desistencia', $retratacao['status'], '10/11/2026 só cabe desistir');
$t->igual('2026-11-30', $retratacao['retratacao_ate'], 'A desistência vai até 30/11/2026');
$depois = \App\Fiscal\Engine\JanelaOpcaoHibrido::decidir('2026-12-01');
$t->igual('futura', $depois['status'], 'Em dezembro de 2026 a próxima janela ainda não abriu');
$t->igual('2027-03-01', $depois['janela_inicio'], 'A janela seguinte é março de 2027');
$marco = \App\Fiscal\Engine\JanelaOpcaoHibrido::decidir('2027-03-15');
$t->igual('aberta', $marco['status'], 'Março de 2027 decide o segundo semestre');
$t->igual('2027-07-01', $marco['semestre_inicio'], 'Março decide julho');
$ordinaria = \App\Fiscal\Engine\JanelaOpcaoHibrido::decidir('2028-09-10');
$t->igual('2028-09-30', $ordinaria['janela_fim'], 'Fora de 2027 a janela termina em setembro');
$t->igual(null, $ordinaria['retratacao_ate'], 'A lei não cria retratação fora do primeiro semestre de 2027');

$conferencia = new \App\App\ConferenciaService($banco, new SettingsService($banco));
$empresaConf = $empresas->obter($id);
$semReceita = $conferencia->montar($empresaConf, ['de' => '2026-10', 'ate' => '2026-10'], '2026-11-10');
$t->igual(null, $semReceita['diagnostico'], 'Sem RBT12 o diagnóstico não roda');
$t->verdadeiro(str_contains((string) $semReceita['mensagem'], 'receita dos últimos 12 meses'), 'A mensagem pede a receita de 12 meses');
$t->igual(true, $semReceita['pode_unificado'], 'Na retratação ainda dá para gravar o unificado');
$t->igual(false, $semReceita['pode_hibrido'], 'Híbrido não grava sem a conta');
$t->verdadeiro($semReceita['contagens']['erro'] >= 1, 'A fralda com redução zero entra como erro');
$t->verdadeiro($semReceita['faixas'] !== [], 'A faixa sai da redução declarada');
$comReceita = $conferencia->montar($empresaConf, [
    'de' => '2026-10', 'ate' => '2026-10', 'rbt12' => '180000,00', 'margem' => '20',
    'horas_adicionais' => '2', 'valor_hora' => '150,00',
], '2026-11-10');
$t->igual(2027, $comReceita['diagnostico']['ano_decisao'] ?? 0, 'A conta usa o ano do semestre em decisão');
$t->verdadeiro(isset($comReceita['diagnostico']['unificado']['caixa_centavos']), 'Unificado e híbrido devolvem centavos');
$t->igual(30000, $comReceita['honorario_centavos'], 'Duas horas a 150 reais são 300 reais');
$t->igual(7, count($comReceita['projecao']), 'A projeção cobre 2027 a 2033');
$meio = $conferencia->montar($empresaConf, ['de' => '2026-10', 'ate' => '2026-10', 'rbt12' => '180000,00', 'horas_adicionais' => '2'], '2026-11-10');
$t->igual(null, $meio['honorario_centavos'], 'Horas sem valor da hora não viram honorário');
$t->verdadeiro(str_contains((string) $meio['mensagem'], 'valor da hora'), 'A mensagem pede o valor da hora');
try {
    $conferencia->registrar($empresaConf, ['de' => '2026-10', 'ate' => '2026-10', 'rbt12' => '180000,00', 'escolha' => 'hibrido'], '2026-11-10');
    $t->verdadeiro(false, 'Híbrido na retratação deveria falhar');
} catch (\App\Core\ValidationException $erroJanela) {
    $t->verdadeiro(str_contains($erroJanela->getMessage(), 'unificado'), 'A retratação só admite o unificado');
}
$conferencia->registrar($empresaConf, ['de' => '2026-10', 'ate' => '2026-10', 'rbt12' => '180000,00', 'escolha' => 'unificado'], '2026-11-10');
$gravada = $banco->one('SELECT escolha, retratacao_ate FROM decisoes_regime WHERE empresa_id = :id ORDER BY id DESC LIMIT 1', [':id' => $id]);
$t->igual('unificado', $gravada['escolha'] ?? '', 'A permanência no DAS fica gravada');
$t->igual('2026-11-30', $gravada['retratacao_ate'] ?? '', 'O registro guarda até quando dá para voltar atrás');

$acesso = new \App\App\AcessoService($banco);
try {
    $acesso->entrar(['email' => 'dono@escritorio.local', 'senha' => 'errada']);
    $t->verdadeiro(false, 'Senha errada deveria falhar');
} catch (\App\Core\ValidationException $erroAcesso) {
    $t->verdadeiro(str_contains($erroAcesso->getMessage(), 'não conferem'), 'Senha errada não entra');
}
$admin = $acesso->entrar(['email' => 'admin', 'senha' => 'admin']);
$t->igual('plataforma', $admin['papel'], 'O acesso admin entra na plataforma');
$dono = $acesso->entrar(['email' => 'dono@escritorio.local', 'senha' => 'primeiro-acesso']);
$t->igual('plataforma', $dono['papel'], 'O primeiro acesso opera a plataforma');
$outro = $banco->insert('escritorios', [
    'nome' => 'Outro Escritório', 'cnpj' => '11222333000181', 'crc' => '', 'cor' => '#1f4b3a',
    'logo_path' => null, 'cidade' => '', 'telefone' => '', 'email' => 'outro@escritorio.local',
]);
$banco->insert('assinaturas', [
    'escritorio_id' => $outro, 'plano_codigo' => 'essencial', 'status' => 'ativa',
    'inicio' => '2026-11-01', 'criada_em' => '2026-11-01 00:00:00',
]);
$banco->run('UPDATE planos SET empresas_por_ciclo = 1 WHERE codigo = :codigo', [':codigo' => 'essencial']);
\App\Core\Tenant::definir((int) $outro);
$saas = new \App\App\AssinaturaService($banco);
$limitado = new \App\App\CompanyService($banco, new \App\App\LedgerService($banco), $saas);
$t->igual([], $limitado->empresas(), 'Outro escritório não vê a carteira da Padaria');
$t->verdadeiro($limitado->salvar(null, [
    'razao_social' => 'Cliente Um', 'cnpj' => '11222333000181', 'regime' => 'simples', 'anexo_simples' => 'I', 'capital_social' => '1000,00',
]) > 0, 'A primeira empresa do plano cabe');
try {
    $limitado->salvar(null, [
        'razao_social' => 'Cliente Dois', 'cnpj' => '34028316000103', 'regime' => 'simples', 'anexo_simples' => 'I', 'capital_social' => '1000,00',
    ]);
    $t->verdadeiro(false, 'A segunda empresa deveria estourar o plano');
} catch (\App\Core\ValidationException $erroPlano) {
    $t->verdadeiro(str_contains($erroPlano->getMessage(), 'Essencial'), 'O teto do plano Essencial barra a empresa');
}
$contaSaas = $saas->apurar((int) $outro);
$t->igual(49000, $contaSaas['preco_centavos'], 'A mensalidade do Essencial sai da tabela');
$faturaDeNovo = $saas->apurar((int) $outro);
$t->igual($contaSaas['fatura']['id'], $faturaDeNovo['fatura']['id'], 'A fatura do mês não duplica');
\App\Core\Tenant::limpar();
$banco->run('UPDATE planos SET empresas_por_ciclo = 15 WHERE codigo = :codigo', [':codigo' => 'essencial']);

// 1. Testes de migração das novas tabelas
$migrador = new Migrator($banco);
$migrador->migrate();
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='regras_preditivas_banco'") !== null, 'Tabela regras_preditivas_banco criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='divergencias_compliance'") !== null, 'Tabela divergencias_compliance criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='cenarios_planejamento'") !== null, 'Tabela cenarios_planejamento criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='colaboradores'") !== null, 'Tabela colaboradores criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='provisoes_trabalhistas'") !== null, 'Tabela provisoes_trabalhistas criada');

// 2. Testes do TesoureiroPreditivoAgente
$tesoureiro = new TesoureiroPreditivoAgente();
$resTesouraria = $tesoureiro->analisar([
    'valor_centavos' => -250000,
    'data' => '2026-11-05',
    'descricao' => 'PAGTO ALUGUEL SALA 402 OUTUBRO',
]);
$t->verdadeiro($resTesouraria['recorrente'], 'Identifica despesa recorrente de aluguel');
$t->verdadeiro($resTesouraria['automatica'], 'Confiança alta aprova conciliação automática');
$t->igual('Aluguel do Imóvel', $resTesouraria['categoria'], 'Categoria correta do aluguel');

$resDesvio = $tesoureiro->analisar(
    ['valor_centavos' => -450000, 'data' => '2026-11-05', 'descricao' => 'PAGTO ALUGUEL SALA 402'],
    [],
    [['valor_centavos' => -250000, 'data' => '2026-09-05'], ['valor_centavos' => -250000, 'data' => '2026-10-05']]
);
$t->verdadeiro($resDesvio['desvio_detectado'], 'Detecta salto atípico no valor do aluguel');
$t->verdadeiro(!$resDesvio['automatica'], 'Desvio anômalo exige aprovação do contador');

// 3. Testes do AuditorComplianceAgente
$compliance = new AuditorComplianceAgente();
$resCompliancePendente = $compliance->auditar([
    'documento_numero' => '1024',
    'valor_documento_centavos' => 500000,
    'tipo_operacao' => 'saida',
    'data_emissao' => '2026-09-15',
], 58);
$t->verdadeiro(!$resCompliancePendente['em_conformidade'], 'Alerta nota emitida há 58 dias sem trânsito bancário');
$t->igual('alta', $resCompliancePendente['severidade'], 'Severidade alta para risco de omissão de receita');

$resComplianceRetencao = $compliance->auditar([
    'documento_numero' => '1025',
    'valor_documento_centavos' => 1000000,
    'tipo_operacao' => 'saida',
    'data_emissao' => '2026-11-01',
    'valor_quitado_centavos' => 953500, // 4,65% CSRF
], 10);
$t->verdadeiro($resComplianceRetencao['em_conformidade'], 'Diferença de 4,65% é aceita como retenção CSRF na fonte');
$t->igual('retencao_fonte_identificada', $resComplianceRetencao['divergencia_detectada'], 'Identifica retenção de tributos');

// 4. Testes do FolhaPagamentoEngine
$folha = new FolhaPagamentoEngine();
$resInss = $folha->calcularInss(300000); // R$ 3.000,00
$t->igual(26927, $resInss['inss_centavos'], 'INSS na faixa 3 de R$ 3.000,00 dá R$ 269,27');
$t->igual(3, $resInss['faixa'], 'Faixa 3 do INSS');
$t->igual(24000, $folha->calcularFgts(300000), 'FGTS 8% de R$ 3.000,00 é R$ 240,00');

$resFolhaCompleta = $folha->processar(300000, 'João Silva');
$t->igual(273073, $resFolhaCompleta['salario_liquido_centavos'], 'Salário líquido de R$ 2.730,73');
$t->verdadeiro(count($resFolhaCompleta['partidas_contabeis']) >= 5, 'Gera partidas contábeis para folha, FGTS e provisões');

// 5. Testes do PlanejamentoTributarioAvancado
$planejamento = new PlanejamentoTributarioAvancado();
$resPlan = $planejamento->simular([
    'receita_mensal_centavos' => 5000000, // R$ 50.000/mês
    'crescimento_percentual' => 20,
    'rbt12_centavos' => 60000000, // R$ 600.000/ano
    'anexo_simples' => 'I',
    'folha_mensal_centavos' => 1000000,
    'compras_insumos_centavos' => 2000000,
    'percentual_vendas_pj' => 60,
]);
$t->igual(6000000, $resPlan['receita_projetada_centavos'], 'Receita projetada com 20% de crescimento é R$ 60.000,00');
$t->verdadeiro(isset($resPlan['cenarios']['simples_unificado']), 'Simula cenário Simples Unificado');
$t->verdadeiro(isset($resPlan['cenarios']['simples_hibrido']), 'Simula cenário Simples Híbrido');
$t->verdadeiro(isset($resPlan['cenarios']['lucro_presumido']), 'Simula cenário Lucro Presumido');
$t->verdadeiro(isset($resPlan['cenario_recomendado']), 'Emite veredito de melhor cenário tributário');

// 6. Testes do TeseTributariaEngine
$teses = new TeseTributariaEngine();
$resTese69 = $teses->analisarItem([
    'descricao' => 'Servidor de Rede Corporativo',
    'valor_centavos' => 1000000, // R$ 10.000,00
    'icms_destacado_centavos' => 180000, // R$ 1.800,00 de ICMS
], 'presumido');
$t->verdadeiro($resTese69['oportunidades_encontradas'] >= 1, 'Localiza tese do Tema 69 STF');
$t->igual('TEMA_69_STF', $resTese69['oportunidades'][0]['tese_codigo'], 'Código da Tese do Século');
$t->igual(6570, $resTese69['oportunidades'][0]['credito_centavos'], 'Crédito de 3,65% s/ R$ 1.800 de ICMS dá R$ 65,70');

$resMonofasico = $teses->analisarItem([
    'descricao' => 'Medicamento Dipirona 500mg',
    'ncm' => '3004.90.99',
    'valor_centavos' => 100000, // R$ 1.000,00
], 'simples');
$t->verdadeiro($resMonofasico['oportunidades_encontradas'] >= 1, 'Localiza produto monofásico farmacêutico');
$t->igual('MONOFASICO_SIMPLES', $resMonofasico['oportunidades'][0]['tese_codigo'], 'Segregação monofásica no Simples');

// 7. Testes do TesourariaEstocasticaEngine
$tesourariaEstocastica = new TesourariaEstocasticaEngine();
$resEstocastica = $tesourariaEstocastica->projetar([
    'saldo_inicial_centavos' => 1000000, // R$ 10.000,00
    'recebimentos_previstos' => [
        ['valor_centavos' => 3000000, 'dias_ate_vencimento' => 15, 'cliente' => 'Cliente A'],
        ['valor_centavos' => 2000000, 'dias_ate_vencimento' => 45, 'cliente' => 'Cliente B'],
    ],
    'pagamentos_compulsorios' => [
        ['valor_centavos' => 1500000, 'dias_ate_vencimento' => 20, 'descricao' => 'DAS e Tributos'],
        ['valor_centavos' => 1000000, 'dias_ate_vencimento' => 25, 'descricao' => 'Folha Salários'],
    ],
    'taxa_inadimplencia_percentual' => 5.0,
]);
$t->verdadeiro(isset($resEstocastica['projecoes_por_horizonte'][30]), 'Gera projeção de liquidez de 30 dias');
$t->verdadeiro(isset($resEstocastica['projecoes_por_horizonte'][60]), 'Gera projeção de liquidez de 60 dias');
$t->verdadeiro(isset($resEstocastica['projecoes_por_horizonte'][90]), 'Gera projeção de liquidez de 90 dias');
$t->igual('liquidez_segura', $resEstocastica['status_geral'], 'Classifica saúde financeira como segura com superávit');

// 8. Testes do FechamentoAutonomoOrchestrator
$migrador->migrate();
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='teses_tributarias_detectadas'") !== null, 'Tabela teses_tributarias_detectadas criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='projecoes_fluxo_caixa'") !== null, 'Tabela projecoes_fluxo_caixa criada');
$t->verdadeiro($banco->one("SELECT name FROM sqlite_master WHERE type='table' AND name='fechamentos_mensais'") !== null, 'Tabela fechamentos_mensais criada');

$orquestradorFechamento = new FechamentoAutonomoOrchestrator($banco, $tesoureiro, $compliance, $folha, $teses);
$resFechamento = $orquestradorFechamento->executarFechamento((int) $id, '2026-10');
$t->verdadeiro(in_array($resFechamento['status'], ['concluido_com_sucesso', 'requer_atencao', 'bloqueado'], true), 'Status válido do fechamento autônomo');
$t->verdadeiro(strlen((string) $resFechamento['resumo_leigo']) > 20, 'Gera resumo leigo em linguagem natural');
$t->verdadeiro(isset($resFechamento['relatorio_tecnico']['competencia']), 'Gera relatório técnico com detalhamento');

// 9. Testes do IngestorDocumentosEngine
$ingestor = new IngestorDocumentosEngine($banco);

// Teste com XML de NF-e
$xmlNfeTeste = '<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">
  <NFe>
    <infNFe Id="NFe35261011222333000181550010000012341000012345" versao="4.00">
      <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-10-15T10:00:00-03:00</dhEmi><mod>55</mod></ide>
      <emit><CNPJ>11222333000181</CNPJ><xNome>Fornecedor Alpha Ltda</xNome></emit>
      <dest><CNPJ>34028316000103</CNPJ><xNome>Padaria Bella Vista</xNome></dest>
      <total>
        <ICMSTot><vProd>5000.00</vProd><vNF>5000.00</vNF><vICMS>900.00</vICMS><vPIS>82.50</vPIS><vCOFINS>380.00</vCOFINS></ICMSTot>
      </total>
      <det nItem="1"><prod><xProd>Farinha de Trigo Especial</xProd></prod></det>
    </infNFe>
  </NFe>
</nfeProc>';

$resIngestaoNfe = $ingestor->processarConteudo($xmlNfeTeste, 'nfe-teste.xml', '34028316000103');
$t->igual('sucesso', $resIngestaoNfe['status'], 'Status sucesso para XML de NF-e');
$t->igual('NFE_XML', $resIngestaoNfe['tipo'], 'Tipo identificado como NFE_XML');
$t->igual('1234', $resIngestaoNfe['numero'], 'Número da nota extraído');
$t->igual('35261011222333000181550010000012341000012345', $resIngestaoNfe['chave'], 'Chave de 44 dígitos extraída');
$t->igual(500000, $resIngestaoNfe['valores']['total_centavos'], 'Valor total de R$ 5.000,00 em centavos');
$t->igual('entrada', $resIngestaoNfe['direcao'], 'Direção identificada como entrada para a Padaria destinatária');
$t->verdadeiro(!$resIngestaoNfe['cnpj_divergente'], 'CNPJ em conformidade com o destinatário');

// Teste com extrato OFX
$ofxTeste = "OFXHEADER:100
<OFX>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <STMTRS>
        <BANKTRANLIST>
          <STMTTRN>
            <TRNTYPE>DEBIT</TRNTYPE>
            <DTPOSTED>20261016120000</DTPOSTED>
            <TRNAMT>-1500.00</TRNAMT>
            <FITID>TX1001</FITID>
            <MEMO>PAGTO FORNECEDOR MOINHO</MEMO>
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>CREDIT</TRNTYPE>
            <DTPOSTED>20261017120000</DTPOSTED>
            <TRNAMT>3200.50</TRNAMT>
            <FITID>TX1002</FITID>
            <MEMO>RECEBIMENTO CLIENTE RESTAURANTE</MEMO>
          </STMTTRN>
        </BANKTRANLIST>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>";

$resIngestaoOfx = $ingestor->processarConteudo($ofxTeste, 'extrato.ofx');
$t->igual('sucesso', $resIngestaoOfx['status'], 'Status sucesso para OFX');
$t->igual('EXTRATO_OFX', $resIngestaoOfx['tipo'], 'Tipo identificado como EXTRATO_OFX');
$t->igual(2, $resIngestaoOfx['quantidade_transacoes'], 'Extraídas 2 transações do OFX');
$t->igual(320050, $resIngestaoOfx['total_entradas_centavos'], 'Total de entradas no OFX');
$t->igual(150000, $resIngestaoOfx['total_saidas_centavos'], 'Total de saídas no OFX');

// Teste com SPED Fiscal
$spedTeste = "|0000|015|0|01102026|31102026|PADARIA BELLA VISTA LTDA|34028316000103||SP|3550308|||A|1|
|C100|0|1|FORN01|55|00|1|1234|35261011222333000181550010000012341000012345|15102026|15102026|5000,00|0|0|0|5000,00|9|0|0|0|900,00|||||82,50|380,00||
|9999|3|";

$resIngestaoSped = $ingestor->processarConteudo($spedTeste, 'sped-outubro.txt', '34028316000103');
$t->igual('sucesso', $resIngestaoSped['status'], 'Status sucesso para SPED Fiscal');
$t->igual('SPED_FISCAL', $resIngestaoSped['tipo'], 'Tipo identificado como SPED_FISCAL');
$t->igual('34028316000103', $resIngestaoSped['cnpj_escriturado'], 'CNPJ do bloco 0000 extraído');
$t->igual(1, $resIngestaoSped['quantidade_notas'], 'Total de notas C100 contabilizadas');

// 10. Testes da Classe de Roteamento de Arquivos (RoteadorArquivosService)
$tempDir = dirname(__DIR__) . '/storage/tmp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}
$tempXmlPath = $tempDir . '/nfe_roteamento_teste.xml';
file_put_contents($tempXmlPath, $xmlNfeTeste);

$roteador = new RoteadorArquivosService($banco);
$resRoteador = $roteador->rotear($tempXmlPath);
$t->igual('sucesso', $resRoteador['status'], 'Roteador processa arquivo de disco');
$t->verdadeiro($resRoteador['empresa_localizada'], 'Roteador localizou a empresa pelo CNPJ');
$t->igual('entrada', $resRoteador['direcao'], 'Direção identificada como entrada');
$t->igual('AuditorComplianceAgente', $resRoteador['agente_destino'], 'Agente de compliance definido como próximo passo');
$t->verdadeiro(strlen((string) $resRoteador['checksum']) === 64, 'Checksum SHA-256 gerado');

if (file_exists($tempXmlPath)) {
    unlink($tempXmlPath);
}

echo $t->passou . " testes passaram";
if ($t->falhou > 0) {
    echo ", " . $t->falhou . " falharam\n";
    exit(1);
}
echo "\n";
