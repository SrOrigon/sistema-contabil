<?php /** @var array<string,mixed> $documento */ /** @var list<array<string,mixed>> $csts */ ?>
<section class="card">
  <div class="item-lista">
    <div>
      <div class="rotulo">Modelo <?= e((string) $documento['modelo']) ?> · série <?= e((string) $documento['serie']) ?></div>
      <strong><?= e((string) $documento['emitente_nome']) ?> → <?= e((string) $documento['destinatario_nome']) ?></strong>
      <div class="sub"><?= \App\Core\Format::data((string) $documento['emissao']) ?> · <?= \App\Core\Format::money((int) $documento['valor_nota_centavos']) ?> · chave <?= e((string) $documento['chave']) ?></div>
    </div>
    <div><?= chip((string) $documento['status_auditoria']) ?> <?= chip((string) $documento['tipo_operacao']) ?></div>
  </div>
  <?php if ($documento['tipo_operacao'] === 'indeterminada'): ?>
    <form method="post" action="/documentos/<?= e((string) $documento['id']) ?>/direcao" class="acoes" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <span>O CNPJ da empresa não aparece na nota. Defina a direção para lançar no razão:</span>
      <button name="direcao" value="entrada" type="submit">É entrada</button>
      <button name="direcao" value="saida" class="secundario" type="submit">É saída</button>
    </form>
  <?php endif; ?>
</section>
<?php foreach ($documento['itens'] as $item): ?>
  <article class="card" style="margin-top:12px">
    <div class="item-lista">
      <div>
        <strong><?= e((string) $item['n_item']) ?>. <?= e((string) $item['descricao']) ?></strong>
        <div class="sub">NCM <?= e((string) $item['ncm']) ?> · CFOP <?= e((string) $item['cfop']) ?> · CST <?= e((string) $item['cst']) ?> · cClassTrib <?= e((string) $item['cclass_trib']) ?></div>
      </div>
      <?= chip((string) $item['status']) ?>
    </div>
    <p><?= e((string) $item['diagnostico']) ?></p>
    <p class="sub"><?= e((string) $item['sugestao']) ?> · motor <?= e((string) $item['modelo_agente']) ?> · confiança <?= e((string) $item['confianca']) ?></p>
    <p>Declarado IBS/CBS <?= \App\Core\Format::money((int) $item['valor_ibs_centavos'] + (int) $item['valor_cbs_centavos']) ?> · esperado pela regra <?= \App\Core\Format::money((int) $item['valor_ibs_esperado_centavos'] + (int) $item['valor_cbs_esperado_centavos']) ?>. Redução esperada: <?= e((string) ($item['reducao_esperada'] ?? '—')) ?>%.</p>
    <form method="post" action="/itens/<?= e((string) $item['id']) ?>/corrigir" class="grade-form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>CST adotado
        <select name="cst">
          <?php foreach ($csts as $cst): ?>
            <option value="<?= e($cst['codigo']) ?>" <?= ($item['cst_esperado'] ?: $item['cst']) === $cst['codigo'] ? 'selected' : '' ?>><?= e($cst['codigo']) ?> — <?= e($cst['descricao']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Redução adotada (%)<input name="reducao" value="<?= e((string) ($item['reducao_esperada'] ?? $item['reducao_ibs'])) ?>"></label>
      <label>cClassTrib compatível com a regra, se já houver na tabela<input name="cclass_trib" value="<?= e((string) ($item['cclass_esperado'] ?? '')) ?>" placeholder="em branco até a tabela oficial"></label>
      <button type="submit">Registrar correção do contador</button>
    </form>
  </article>
<?php endforeach; ?>
