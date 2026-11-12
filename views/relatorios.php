<?php /** @var array<string,mixed> $pacote */ $simulacao = $pacote['simulacao']; $saude = $pacote['saude']; ?>
<section class="card">
  <p>Este texto sai da última comparação. O PDF só é gerado quando as contas fecham. A leitura na tela continua disponível.</p>
  <div class="acoes no-print">
    <?php if ($saude['aprovado'] && $simulacao): ?>
      <a class="btn" href="/relatorios?formato=pdf">Baixar PDF</a>
    <?php else: ?>
      <span class="aviso">Ainda não há comparação, ou as contas não fecharam. Compare os regimes e resolva os itens abaixo.</span>
    <?php endif; ?>
    <a class="btn secundario" href="/relatorios?formato=html" target="_blank">Abrir folha do escritório</a>
  </div>
</section>
<?php if ($saude['bloqueios'] !== []): ?>
  <section class="card" ><h2>O que impede o PDF</h2><ul><?php foreach ($saude['bloqueios'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php if ($saude['alertas'] !== []): ?>
  <section class="card" ><h2>Alertas</h2><ul><?php foreach ($saude['alertas'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php if ($simulacao): ?>
  <section class="card" ><p class="parecer"><?= e((string) $simulacao['recomendacao']) ?></p></section>
<?php endif; ?>
