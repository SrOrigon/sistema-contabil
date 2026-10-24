<?php
/** @var string $conteudo */
/** @var string $titulo */
/** @var string $pagina */
/** @var array<string, mixed> $escritorio */
/** @var list<array<string, mixed>> $listaEmpresas */
/** @var ?array<string, mixed> $empresa */
/** @var ?array{tipo:string,texto:string} $flash */
$cor = (string) ($escritorio['cor'] ?? '#1f4b3a');
$itens = [
    'painel' => ['/', 'Painel'],
    'empresas' => ['/empresas', 'Empresas'],
    'auditoria' => ['/auditoria', 'Auditoria'],
    'conciliacao' => ['/conciliacao', 'Conciliação'],
    'estrategia' => ['/estrategia', 'Estratégia'],
    'legislacao' => ['/legislacao', 'Legislação'],
    'contabil' => ['/contabil', 'Contabilidade'],
    'agentes' => ['/agentes', 'Agentes'],
    'relatorios' => ['/relatorios', 'Relatórios'],
    'configuracoes' => ['/configuracoes', 'Configurações'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> — Sistema Contábil</title>
  <link rel="icon" href="/assets/icon.svg">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="stylesheet" href="/assets/app.css">
  <style>:root { --sidebar: <?= e($cor) ?>; }</style>
</head>
<body>
  <div class="shell">
    <aside class="sidebar">
      <div class="marca">
        <?php if (!empty($escritorio['logo_path'])): ?>
          <img src="/marca" alt="">
        <?php else: ?>
          <img src="/assets/icon.svg" alt="">
        <?php endif; ?>
        <div>
          <strong><?= e($escritorio['nome'] ?? 'Escritório') ?></strong>
          <span>Sistema Contábil</span>
        </div>
      </div>
      <form class="empresa-ativa" method="post" action="/empresa-ativa">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
        <label>Empresa ativa</label>
        <select name="empresa_id" onchange="this.form.submit()">
          <option value="0">Nenhuma selecionada</option>
          <?php foreach ($listaEmpresas as $item): ?>
            <option value="<?= e((string) $item['id']) ?>" <?= ($empresa['id'] ?? 0) == $item['id'] ? 'selected' : '' ?>><?= e($item['razao_social']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <nav>
        <?php foreach ($itens as $chave => [$href, $nome]): ?>
          <a class="<?= $pagina === $chave ? 'ativa' : '' ?>" href="<?= e($href) ?>"><?= e($nome) ?></a>
        <?php endforeach; ?>
      </nav>
      <footer>Cálculo no motor. A IA só sugere quando há chave configurada.</footer>
    </aside>
    <main class="conteudo">
      <?php if ($flash): ?>
        <div class="flash <?= e($flash['tipo']) ?>"><?= e($flash['texto']) ?></div>
      <?php endif; ?>
      <header class="topo">
        <div>
          <h1><?= e($titulo) ?></h1>
        </div>
      </header>
      <?= $conteudo ?>
    </main>
  </div>
  <script src="/assets/app.js"></script>
</body>
</html>
