<?php /** @var array<string,mixed> $documento */ /** @var list<array<string,mixed>> $csts */ ?>
<section class="card">
  <div class="item-lista">
    <div>
      <div class="rotulo">Nota <?= e((string) $documento['numero']) ?></div>
      <strong><?= e((string) $documento['emitente_nome']) ?> vendeu para <?= e((string) $documento['destinatario_nome']) ?></strong>
      <div class="sub"><?= \App\Core\Format::data((string) $documento['emissao']) ?> · <?= \App\Core\Format::money((int) $documento['valor_nota_centavos']) ?></div>
    </div>
    <div><?= chip((string) $documento['status_auditoria']) ?> <?= chip((string) $documento['tipo_operacao']) ?></div>
  </div>
  <?php if ($documento['tipo_operacao'] === 'indeterminada'): ?>
    <form method="post" action="/documentos/<?= e((string) $documento['id']) ?>/direcao" class="acoes">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <span>Esta nota não mostra se é compra ou venda da empresa. Diga qual é:</span>
      <button name="direcao" value="entrada" type="submit">É compra</button>
      <button name="direcao" value="saida" class="secundario" type="submit">É venda</button>
    </form>
  <?php endif; ?>
</section>
<?php foreach ($documento['itens'] as $item): ?>
  <article class="card">
    <div class="item-lista">
      <div>
        <strong><?= e((string) $item['descricao']) ?></strong>
        <div class="sub">Código do produto <?= e((string) $item['ncm']) ?></div>
      </div>
      <?= chip((string) $item['status']) ?>
    </div>
    <p><?= e((string) $item['diagnostico']) ?></p>
    <?php if ((string) $item['sugestao'] !== ''): ?><p><?= e((string) $item['sugestao']) ?></p><?php endif; ?>
    <p>Na nota: <?= \App\Core\Format::money((int) $item['valor_ibs_centavos'] + (int) $item['valor_cbs_centavos']) ?>. Pela regra: <?= \App\Core\Format::money((int) $item['valor_ibs_esperado_centavos'] + (int) $item['valor_cbs_esperado_centavos']) ?>.</p>
    <form method="post" action="/itens/<?= e((string) $item['id']) ?>/corrigir" class="grade-form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>Situação que vale
        <select name="cst">
          <?php foreach ($csts as $cst): ?>
            <option value="<?= e($cst['codigo']) ?>" <?= ($item['cst_esperado'] ?: $item['cst']) === $cst['codigo'] ? 'selected' : '' ?>><?= e($cst['descricao']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Redução (%)<input name="reducao" value="<?= e((string) ($item['reducao_esperada'] ?? $item['reducao_ibs'])) ?>"></label>
      <label>Código da classificação, se já souber<input name="cclass_trib" value="<?= e((string) ($item['cclass_esperado'] ?? '')) ?>"></label>
      <button type="submit">Guardar esta correção</button>
    </form>
  </article>
<?php endforeach; ?>
