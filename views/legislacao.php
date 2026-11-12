<form class="card filtros" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Pergunta<input name="q" value="<?= e((string) $consulta) ?>" required placeholder="Ex.: quando o Simples pode destacar o imposto novo"></label>
  <label>Data do fato<input type="date" name="competencia" value="<?= e((string) $competencia) ?>"></label>
  <button type="submit">Perguntar</button>
</form>
<p class="sub">A resposta usa a regra que valia na data informada. Regra já revogada não volta depois do prazo.</p>
<details class="tecnico">
  <summary>Atualizar ou incluir uma norma</summary>
  <div class="acoes">
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="_acao" value="reindexar"><button class="secundario pequeno" type="submit">Reler as normas</button></form>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="_acao" value="importar"><input type="file" name="markdown" accept=".md,.txt" required><button class="pequeno" type="submit">Incluir arquivo</button></form>
  </div>
</details>
<?php if (!empty($resposta)): ?>
  <article class="card parecer"><?= nl2br(e((string) $resposta['resposta'])) ?><p class="sub">Consultor de artigo de lei · <?= e((string) $resposta['modelo']) ?></p></article>
<?php endif; ?>
<section class="pilha">
  <?php foreach ($trechos as $trecho): ?>
    <article class="card">
      <div class="rotulo"><?= e((string) $trecho['diploma_legal']) ?> · <?= e((string) $trecho['artigo']) ?> · vigência <?= \App\Core\Format::data((string) $trecho['vigencia_inicio']) ?></div>
      <h2><?= e((string) $trecho['titulo']) ?></h2>
      <p><?= nl2br(e((string) $trecho['conteudo'])) ?></p>
    </article>
  <?php endforeach; ?>
</section>
