<?php /** @var array<string,mixed> $pacote */ $simulacao = $pacote['simulacao']; $saude = $pacote['saude']; ?>
<section class="card">
  <p>O parecer executivo usa a última simulação gravada e passa pelo auditor reverso. PDF só sai sem bloqueio. O rascunho em tela pode ser lido mesmo assim, marcado como não emitido.</p>
  <div class="acoes no-print">
    <?php if ($saude['aprovado'] && $simulacao): ?>
      <a class="btn" href="/relatorios?formato=pdf">Baixar PDF</a>
    <?php else: ?>
      <span class="aviso">Emissão bloqueada ou sem simulação. Rode a estratégia e resolva os itens abaixo.</span>
    <?php endif; ?>
    <a class="btn secundario" href="/relatorios?formato=html" target="_blank">Abrir folha do escritório</a>
  </div>
</section>
<?php if ($saude['bloqueios'] !== []): ?>
  <section class="card" style="margin-top:16px"><h2>Bloqueios</h2><ul><?php foreach ($saude['bloqueios'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php if ($saude['alertas'] !== []): ?>
  <section class="card" style="margin-top:16px"><h2>Alertas</h2><ul><?php foreach ($saude['alertas'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php if ($simulacao): ?>
  <section class="card" style="margin-top:16px"><p class="parecer"><?= e((string) $simulacao['recomendacao']) ?></p></section>
<?php endif; ?>
