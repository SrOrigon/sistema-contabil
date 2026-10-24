<?php /** @var ?array<string,mixed> $resultado */ /** @var array<string,mixed> $coletado */ /** @var string $competencia */ ?>
<form class="card grade-form" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Competência<input type="month" name="competencia" value="<?= e($competencia) ?>"></label>
  <label>Origem dos valores
    <select name="origem">
      <option value="documentos">Notas da competência (<?= (int) ($coletado['documentos'] ?? 0) ?>)</option>
      <option value="manual">Valores digitados abaixo</option>
    </select>
  </label>
  <label>RBT12<input name="rbt12" value="0,00"></label>
  <label>Folha de 12 meses<input name="folha12" value="0,00"></label>
  <label>Receita do mês<input name="receita_mes" value="<?= e(number_format((float) ($coletado['receita_mes'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Receita para PJ<input name="receita_pj" value="<?= e(number_format((float) ($coletado['receita_pj'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Compras do mês<input name="base_credito" value="<?= e(number_format((float) ($coletado['base_credito'] ?? 0), 2, ',', '.')) ?>"></label>
  <label>Redução média nas saídas, se manual (%)<input name="reducao_saidas" value="0"></label>
  <label>Anexo
    <select name="anexo">
      <?php foreach (['I','II','III','IV','V','AUTO'] as $anexo): ?>
        <option value="<?= $anexo ?>" <?= ($empresa['anexo_simples'] ?? '') === $anexo ? 'selected' : '' ?>><?= e($anexo) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if (($empresa['regime'] ?? '') === 'mei'): ?>
    <label>DAS-MEI pago no mês<input name="das_mei" value="0,00"></label>
  <?php endif; ?>
  <button type="submit">Simular unificado e híbrido</button>
</form>
<?php if ($resultado): ?>
  <section class="card" style="margin-top:16px">
    <?php if (!empty($resultado['observacao_aliquota']) && ($resultado['das']['ano'] ?? $resultado['ano'] ?? 0) >= 2027): ?>
      <p class="aviso"><?= e((string) $resultado['observacao_aliquota']) ?></p>
    <?php endif; ?>
    <?php if (!empty($resultado['aviso'])): ?><p class="aviso"><?= e((string) $resultado['aviso']) ?></p><?php endif; ?>
    <p class="parecer"><?= e((string) $resultado['recomendacao']) ?></p>
    <div class="grade">
      <article><div class="rotulo">Caixa unificado</div><div class="numero"><?= \App\Core\Format::money((int) $resultado['unificado']['caixa_centavos']) ?></div></article>
      <article><div class="rotulo">Caixa híbrido</div><div class="numero"><?= \App\Core\Format::money((int) $resultado['hibrido']['caixa_centavos']) ?></div></article>
      <article><div class="rotulo">Vendas a PJ</div><div class="numero"><?= \App\Core\Format::percentual((string) $resultado['percentual_pj']) ?></div></article>
      <article><div class="rotulo">Split estimado</div><div class="numero"><?= \App\Core\Format::money((int) $resultado['split_centavos']) ?></div></article>
    </div>
    <div class="memoria" style="margin-top:16px">
      <?php foreach ($resultado['memoria'] as $linha): ?>
        <div><strong><?= e($linha['rotulo']) ?></strong><span class="num"><?= e($linha['valor']) ?></span><span class="sub"><?= e($linha['nota']) ?></span></div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
