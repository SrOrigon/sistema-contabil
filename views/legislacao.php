<form class="card filtros" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Pergunta<input name="q" value="<?= e((string) $consulta) ?>" required placeholder="Fator R, cesta básica, teste de 2026"></label>
  <label>Competência da operação<input type="date" name="competencia" value="<?= e((string) $competencia) ?>"></label>
  <button type="submit">Consultar com vigência</button>
</form>
<?php if (($acervo ?? 0) > 0): ?>
  <p class="sub"><?= number_format((int) $acervo, 0, ',', '.') ?> normas do acervo local entram na consulta, junto com o núcleo do escritório. Norma revogada só responde na data em que foi publicada.</p>
<?php elseif (!empty($acervoPasta)): ?>
  <p class="sub">Os arquivos já estão em database/acervo/leis. Rode <code>bin\console indexar-leis</code> com o PHP do projeto para a consulta passar a usá-los.</p>
<?php endif; ?>
<div class="acoes" style="margin:12px 0">
  <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="_acao" value="reindexar"><button class="secundario pequeno" type="submit">Reindexar database/leis</button></form>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="_acao" value="importar"><input type="file" name="markdown" accept=".md,.txt" required><button class="pequeno" type="submit">Importar markdown</button></form>
</div>
<?php if (!empty($resposta)): ?>
  <article class="card parecer"><?= nl2br(e((string) $resposta['resposta'])) ?><p class="sub">Fonte: <?= e((string) $resposta['modelo']) ?></p></article>
<?php endif; ?>
<section class="pilha" style="margin-top:16px">
  <?php foreach ($trechos as $trecho): ?>
    <article class="card">
      <div class="rotulo"><?= e((string) $trecho['diploma_legal']) ?> · <?= e((string) $trecho['artigo']) ?> · vigência <?= \App\Core\Format::data((string) $trecho['vigencia_inicio']) ?></div>
      <h2><?= e((string) $trecho['titulo']) ?></h2>
      <p><?= nl2br(e((string) $trecho['conteudo'])) ?></p>
    </article>
  <?php endforeach; ?>
</section>
