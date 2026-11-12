<?php
/** @var array<string, mixed> $conta */
$janela = $conta['janela'];
$perfil = $conta['perfil'];
$diagnostico = $conta['diagnostico'];
$impacto = [
    'baixo' => 'Baixo impacto',
    'moderado' => 'Impacto moderado',
    'alto' => 'Alto impacto',
    'indefinido' => 'Impacto em aberto',
];
$statusJanela = [
    'aberta' => 'A janela está aberta até ' . \App\Core\Format::data((string) $janela['janela_fim']) . '. A escolha vale de ' . \App\Core\Format::data((string) $janela['semestre_inicio']) . ' a ' . \App\Core\Format::data((string) $janela['semestre_fim']) . '.',
    'so_desistencia' => 'A janela fechou em ' . \App\Core\Format::data((string) $janela['janela_fim']) . '. Dá para voltar atrás até ' . \App\Core\Format::data((string) $janela['retratacao_ate']) . ', e só para permanecer no Simples.',
    'futura' => 'A janela deste semestre abre em ' . \App\Core\Format::data((string) $janela['janela_inicio']) . '.',
    'fechada' => 'Esta janela já fechou.',
];
?>
<form class="card grade-form" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <p>O sistema lê as notas do período. Preencha só o que ele não consegue ver na nota. Campo vazio fica de fora.</p>
  <label>Do mês<input type="month" name="de" value="<?= e((string) $conta['de']) ?>"></label>
  <label>Até o mês<input type="month" name="ate" value="<?= e((string) $conta['ate']) ?>"></label>
  <label>Receita dos últimos 12 meses<input name="rbt12" value="<?= e((string) ($conta['formulario']['rbt12'] ?? '')) ?>" placeholder="se souber"></label>
  <label>Folha dos últimos 12 meses<input name="folha12" value="<?= e((string) ($conta['formulario']['folha12'] ?? '')) ?>" placeholder="só se o anexo depende disso"></label>
  <label>Margem que o cliente quer manter (%)<input name="margem" value="<?= e((string) ($conta['formulario']['margem'] ?? '')) ?>" placeholder="opcional"></label>
  <label>Horas a mais neste cliente<input name="horas_adicionais" value="<?= e((string) ($conta['formulario']['horas_adicionais'] ?? '')) ?>" placeholder="opcional"></label>
  <label>Valor da sua hora<input name="valor_hora" value="<?= e((string) ($conta['formulario']['valor_hora'] ?? '')) ?>" placeholder="opcional"></label>
  <button type="submit">Conferir</button>
</form>

<section class="card">
  <div class="rotulo">Janela da opção · <?= e((string) $janela['rotulo_semestre']) ?></div>
  <p><?= e($statusJanela[(string) $janela['status']] ?? '') ?></p>
  <p class="sub"><?= e((string) $janela['fundamento']) ?></p>
  <?php if ($janela['proxima_rotulo'] !== ''): ?>
    <p class="sub">Próxima janela: <?= e(\App\Core\Format::data((string) $janela['proxima_janela_inicio'])) ?>, para <?= e((string) $janela['proxima_rotulo']) ?>.</p>
  <?php endif; ?>
  <?php if ($conta['decisao']): ?>
    <p>Última escolha gravada neste semestre: <strong><?= e(\App\Core\Format::rotulo((string) $conta['decisao']['escolha'])) ?></strong>, em <?= e(\App\Core\Format::data(substr((string) $conta['decisao']['registrada_em'], 0, 10))) ?>.</p>
  <?php endif; ?>
</section>

<?php if ($conta['mensagem'] !== ''): ?>
  <p class="aviso"><?= e((string) $conta['mensagem']) ?></p>
<?php endif; ?>

<section class="grade">
  <article class="card"><div class="rotulo">Vendas</div><div class="numero"><?= (int) $conta['contagens']['saida'] ?></div></article>
  <article class="card"><div class="rotulo">Com erro</div><div class="numero"><?= (int) $conta['contagens']['erro'] ?></div></article>
  <article class="card"><div class="rotulo">Para conferir</div><div class="numero"><?= (int) $conta['contagens']['conferir'] ?></div></article>
  <article class="card"><div class="rotulo">Certas</div><div class="numero"><?= (int) $conta['contagens']['conforme'] ?></div></article>
</section>

<?php foreach ($conta['avisos'] as $aviso): ?>
  <p class="aviso"><?= e((string) $aviso) ?></p>
<?php endforeach; ?>

<section class="card">
  <h2>Perfil do faturamento</h2>
  <p class="sub"><?= (int) $conta['meses'] === 1 ? '1 mês com nota' : (int) $conta['meses'] . ' meses com nota' ?>. A conta usa a média do mês e a alíquota do semestre que está em decisão.</p>
  <div class="grade">
    <article class="medida"><div class="rotulo">Faturamento de saída</div><div class="numero"><?= \App\Core\Format::money((int) $perfil['faturamento']) ?></div></article>
    <article class="medida"><div class="rotulo">Pessoa jurídica</div><div class="numero"><?= \App\Core\Format::percentual((string) $perfil['percentual_pj']) ?></div></article>
    <article class="medida"><div class="rotulo">Consumidor final</div><div class="numero"><?= \App\Core\Format::money((int) $perfil['consumidor']) ?></div></article>
    <article class="medida"><div class="rotulo">Compras</div><div class="numero"><?= \App\Core\Format::money((int) $perfil['compras']) ?></div></article>
  </div>
</section>

<?php if ($conta['faixas'] !== []): ?>
<section class="card">
  <h2>Composição pela redução declarada</h2>
  <p class="sub">Cada faixa é o desconto que a própria nota destacou. Produtos diferentes não se misturam só porque o código se parece.</p>
  <?php foreach ($conta['faixas'] as $faixa): ?>
    <div class="faixa">
      <strong><?= e((string) $faixa['rotulo']) ?></strong>
      <span class="sub"><?= (int) $faixa['itens'] ?> <?= (int) $faixa['itens'] === 1 ? 'item' : 'itens' ?> · <?= \App\Core\Format::money((int) $faixa['valor_centavos']) ?> · <?= e(\App\Core\Format::percentual((string) $faixa['percentual'])) ?></span>
      <div class="faixa-trilho">
        <div class="faixa-valor" style="width:<?= e((string) min(100, (float) $faixa['percentual'])) ?>%"></div>
      </div>
      <p class="sub"><?= e(implode(' · ', $faixa['exemplos'])) ?></p>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($conta['itens'] !== []): ?>
<section class="card">
  <h2>O que a emissão errou</h2>
  <?php foreach ($conta['itens'] as $item): ?>
    <article class="item-erro">
      <div><?= chip((string) $item['status']) ?> <strong><?= e((string) $item['descricao']) ?></strong></div>
      <p><?= e((string) ($item['diagnostico'] ?? '')) ?></p>
      <p class="sub">Produto <?= e((string) $item['ncm']) ?>. Redução do IBS <?= e((string) ($item['reducao_ibs'] !== '' ? $item['reducao_ibs'] . '%' : 'não veio na nota')) ?>. Redução da CBS <?= e((string) ($item['reducao_cbs'] !== '' ? $item['reducao_cbs'] . '%' : 'não veio na nota')) ?>.</p>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (is_array($diagnostico)): ?>
<section class="card">
  <div class="tarja tarja-<?= e((string) $diagnostico['impacto']) ?>"><?= e($impacto[(string) $diagnostico['impacto']] ?? '') ?></div>
  <?php if (($diagnostico['natureza'] ?? '') !== 'legal'): ?>
    <p class="aviso"><?= e((string) $diagnostico['observacao_aliquota']) ?></p>
  <?php endif; ?>
  <p class="parecer"><?= e((string) $diagnostico['recomendacao']) ?></p>
  <div class="grade">
    <article class="medida"><div class="rotulo">Ficar no Simples</div><div class="numero"><?= \App\Core\Format::money((int) $diagnostico['unificado']['caixa_centavos']) ?></div></article>
    <article class="medida"><div class="rotulo">Destacar o imposto</div><div class="numero"><?= \App\Core\Format::money((int) $diagnostico['hibrido']['caixa_centavos']) ?></div></article>
    <article class="medida"><div class="rotulo">Diferença no mês</div><div class="numero"><?= \App\Core\Format::money((int) $diagnostico['delta_centavos']) ?></div></article>
    <article class="medida"><div class="rotulo">Retido nas vendas a empresa</div><div class="numero"><?= \App\Core\Format::money((int) ($diagnostico['split_centavos'] ?? 0)) ?></div></article>
  </div>
  <h2>Por que chegou nisso</h2>
  <ul>
    <?php foreach ($diagnostico['fatores'] as $fator): ?>
      <li><?= e((string) $fator['texto']) ?></li>
    <?php endforeach; ?>
  </ul>
  <h2>Preço e honorário</h2>
  <?php if ($diagnostico['repasse_centavos'] === null): ?>
    <p>Sem a margem, o sistema não calcula o aumento de preço. A diferença acima é o que o preço precisa comportar.</p>
  <?php elseif ((int) $diagnostico['repasse_centavos'] === 0): ?>
    <p>Destacar o imposto não aumenta o desembolso. Não há preço a repassar.</p>
  <?php else: ?>
    <p>Para manter a margem informada, o preço do mês precisa subir <?= \App\Core\Format::money((int) $diagnostico['repasse_centavos']) ?>.</p>
  <?php endif; ?>
  <?php if ($conta['honorario_centavos'] === null): ?>
    <p class="sub">Para calcular o honorário, preencha as horas e o valor da hora.</p>
  <?php else: ?>
    <p>Honorário deste cliente, com as horas e o valor lidos: <?= \App\Core\Format::money((int) $conta['honorario_centavos']) ?>.</p>
  <?php endif; ?>
  <?php
    $nomeVariavel = static fn (string $nome): string => [
        'rbt12' => 'receita de 12 meses',
        'folha12' => 'folha de 12 meses',
        'horas_adicionais' => 'horas a mais',
        'valor_hora' => 'valor da hora',
        'competencia' => 'mês',
        'margem' => 'margem',
        'escolha' => 'escolha',
    ][$nome] ?? $nome;
  ?>
  <p class="sub">Usou: <?= e(implode(', ', array_map($nomeVariavel, $conta['variaveis_usadas']))) ?><?php if ($conta['variaveis_ausentes'] !== []): ?>. Ficou de fora: <?= e(implode(', ', array_map($nomeVariavel, $conta['variaveis_ausentes']))) ?><?php endif; ?>.</p>
</section>
<?php endif; ?>

<?php if ($conta['projecao'] !== []): ?>
<section class="card">
  <h2>Projeção 2027 a 2033</h2>
  <p class="sub">O mesmo perfil de um mês, repetido em cada ano. A receita não cresce. Ano marcado como estimativa usa a referência do escritório.</p>
  <div class="memoria">
    <?php foreach ($conta['projecao'] as $linha): ?>
      <div>
        <strong><?= (int) $linha['ano'] ?><?= $linha['decisao'] ? ' · semestre em decisão' : '' ?></strong>
        <span class="num">Destacando <?= \App\Core\Format::money((int) $linha['hibrido']) ?></span>
        <span class="sub">No Simples <?= \App\Core\Format::money((int) $linha['unificado']) ?> · IBS <?= e((string) $linha['ibs']) ?>% · CBS <?= e((string) $linha['cbs']) ?>% · <?= e(\App\Core\Format::rotulo((string) $linha['natureza'])) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($conta['pode_unificado'] || $conta['pode_hibrido']): ?>
<form class="card" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="_acao" value="decidir">
  <input type="hidden" name="de" value="<?= e((string) $conta['de']) ?>">
  <input type="hidden" name="ate" value="<?= e((string) $conta['ate']) ?>">
  <input type="hidden" name="rbt12" value="<?= e((string) ($conta['formulario']['rbt12'] ?? '')) ?>">
  <input type="hidden" name="folha12" value="<?= e((string) ($conta['formulario']['folha12'] ?? '')) ?>">
  <input type="hidden" name="margem" value="<?= e((string) ($conta['formulario']['margem'] ?? '')) ?>">
  <input type="hidden" name="horas_adicionais" value="<?= e((string) ($conta['formulario']['horas_adicionais'] ?? '')) ?>">
  <input type="hidden" name="valor_hora" value="<?= e((string) ($conta['formulario']['valor_hora'] ?? '')) ?>">
  <h2>Guardar a decisão</h2>
  <p>A escolha fica registrada com o prazo em que ainda dá para voltar atrás.</p>
  <div class="acoes">
    <?php if ($conta['pode_unificado']): ?><button name="escolha" value="unificado" type="submit">Ficar no Simples</button><?php endif; ?>
    <?php if ($conta['pode_hibrido']): ?><button name="escolha" value="hibrido" type="submit">Destacar o imposto novo</button><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<section class="card">
  <h2>Na reunião com o cliente</h2>
  <div class="grade">
    <article>
      <h3>1. O que a nota errou</h3>
      <p>Mostre os avisos primeiro. Erro corrigido no mês ainda é ajuste. Erro achado no fim do semestre já pesa para quem comprou.</p>
    </article>
    <article>
      <h3>2. Para quem ele vende</h3>
      <p>Venda para empresa puxa o imposto destacado, porque o cliente toma crédito. Venda para consumidor puxa ficar no Simples.</p>
    </article>
    <article>
      <h3>3. O preço do serviço</h3>
      <p>A recomendação e o prazo vêm antes do honorário. O valor é o das horas que você informou.</p>
    </article>
  </div>
</section>
