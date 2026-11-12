<?php /** @var array<string,mixed> $escritorio */ /** @var array<string,mixed> $empresa */ /** @var ?array<string,mixed> $simulacao */ /** @var array<string,mixed> $saude */ /** @var bool $emitido */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Parecer — <?= e((string) ($empresa['razao_social'] ?? '')) ?></title>
  <link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body class="folha">
  <main class="conteudo">
    <p class="rotulo"><?= e((string) ($escritorio['nome'] ?? '')) ?><?= $emitido ? '' : ' · ainda não emitido' ?></p>
    <h1><?= e((string) ($empresa['razao_social'] ?? '')) ?></h1>
    <p><?= e(\App\Core\Format::documento((string) ($empresa['cnpj'] ?? ''))) ?></p>
    <?php if (!$emitido): ?><p class="aviso">Este texto ainda não pode ser emitido. Resolva o que está listado abaixo.</p><?php endif; ?>
    <?php if (($saude['bloqueios'] ?? []) !== []): ?>
      <h2>O que impede a emissão</h2>
      <ul><?php foreach ($saude['bloqueios'] as $bloqueio): ?><li><?= e((string) $bloqueio) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="parecer"><?= e((string) ($simulacao['recomendacao'] ?? 'Ainda não há comparação para este cliente.')) ?></p>
    <?php foreach ($simulacao['memoria'] ?? [] as $linha): ?>
      <p><strong><?= e($linha['rotulo']) ?>:</strong> <?= e($linha['valor']) ?> — <?= e($linha['nota']) ?></p>
    <?php endforeach; ?>
    <button class="no-print" onclick="print()">Imprimir</button>
  </main>
</body>
</html>
