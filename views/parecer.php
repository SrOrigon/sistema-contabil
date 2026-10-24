<?php /** @var array<string,mixed> $escritorio */ /** @var array<string,mixed> $empresa */ /** @var ?array<string,mixed> $simulacao */ /** @var array<string,mixed> $saude */ /** @var bool $emitido */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Parecer — <?= e((string) ($empresa['razao_social'] ?? '')) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body style="background:white">
  <main class="conteudo">
    <p class="rotulo"><?= e((string) ($escritorio['nome'] ?? '')) ?> <?= $emitido ? '' : '· rascunho não emitido' ?></p>
    <h1><?= e((string) ($empresa['razao_social'] ?? '')) ?></h1>
    <p><?= e(\App\Core\Format::documento((string) ($empresa['cnpj'] ?? ''))) ?></p>
    <?php if (!$emitido): ?><p class="aviso">Este rascunho não foi emitido. O auditor reverso ainda aponta bloqueio.</p><?php endif; ?>
    <p class="parecer"><?= e((string) ($simulacao['recomendacao'] ?? 'Sem simulação.')) ?></p>
    <?php foreach ($simulacao['memoria'] ?? [] as $linha): ?>
      <p><strong><?= e($linha['rotulo']) ?>:</strong> <?= e($linha['valor']) ?> — <?= e($linha['nota']) ?></p>
    <?php endforeach; ?>
    <button class="no-print" onclick="print()">Imprimir</button>
  </main>
</body>
</html>
