<?php /** @var ?array<string,mixed> $resultado */ /** @var array<string,mixed> $coletado */ /** @var string $competencia */ ?>
<form class="card grade-form" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Mês<input type="month" name="competencia" value="<?= e($competencia) ?>"></label>
  <label>De onde vêm os números
    <select name="origem">
      <option value="documentos">Das notas já enviadas (<?= (int) ($coletado['documentos'] ?? 0) ?>)</option>
      <option value="manual">Vou digitar</option>
    </select>
  </label>
  <label>Receita dos últimos 12 meses<input name="rbt12" value="0,00"></label>
  <label>Folha dos últimos 12 meses<input name="folha12" value="0,00"></label>
  <label>Receita do mês<input name="receita_mes" value="<?= e(number_format((float) ($coletado['receita_mes'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Quanto foi vendido para empresa<input name="receita_pj" value="<?= e(number_format((float) ($coletado['receita_pj'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Compras do mês<input name="base_credito" value="<?= e(number_format((float) ($coletado['base_credito'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Desconto médio nas vendas, se for digitar (%)<input name="reducao_saidas" value="0"></label>
  <label>Anexo
    <select name="anexo">
      <?php foreach (['I','II','III','IV','V','AUTO'] as $anexo): ?>
        <option value="<?= $anexo ?>" <?= ($empresa['anexo_simples'] ?? '') === $anexo ? 'selected' : '' ?>><?= $anexo === 'AUTO' ? 'Pela folha' : 'Anexo ' . e($anexo) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if (($empresa['regime'] ?? '') === 'mei'): ?>
    <label>DAS-MEI pago no mês<input name="das_mei" value="0,00"></label>
  <?php endif; ?>
  <button type="submit">Comparar</button>
</form>
<?php if ($resultado): ?>
  <section class="card">
    <?php if (!empty($resultado['observacao_aliquota']) && ($resultado['das']['ano'] ?? $resultado['ano'] ?? 0) >= 2027): ?>
      <p class="aviso"><?= e((string) $resultado['observacao_aliquota']) ?></p>
    <?php endif; ?>
    <?php if (!empty($resultado['aviso'])): ?><p class="aviso"><?= e((string) $resultado['aviso']) ?></p><?php endif; ?>
    <p class="parecer"><?= e((string) $resultado['recomendacao']) ?></p>
    <p class="sub">Conta feita pelo sistema<?php if (!empty($resultado['variaveis_usadas'])): ?>, com o que foi informado<?php endif; ?>.</p>
    <?php if (isset($resultado['unificado']['caixa_centavos'], $resultado['hibrido']['caixa_centavos'])): ?>
    <div class="grade">
      <article class="medida"><div class="rotulo">Ficar no Simples</div><div class="numero"><?= \App\Core\Format::money((int) $resultado['unificado']['caixa_centavos']) ?></div></article>
      <article class="medida"><div class="rotulo">Destacar o imposto</div><div class="numero"><?= \App\Core\Format::money((int) $resultado['hibrido']['caixa_centavos']) ?></div></article>
      <article class="medida"><div class="rotulo">Vendas para empresa</div><div class="numero"><?= \App\Core\Format::percentual((string) ($resultado['percentual_pj'] ?? '0')) ?></div></article>
      <article class="medida"><div class="rotulo">Retido nessas vendas</div><div class="numero"><?= \App\Core\Format::money((int) ($resultado['split_centavos'] ?? 0)) ?></div></article>
    </div>
    <div class="memoria">
      <?php foreach ($resultado['memoria'] ?? [] as $linha): ?>
        <div><strong><?= e($linha['rotulo']) ?></strong><span class="num"><?= e($linha['valor']) ?></span><span class="sub"><?= e($linha['nota']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
