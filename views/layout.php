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
    'painel' => ['/', 'Início'],
    'empresas' => ['/empresas', 'Empresas'],
    'auditoria' => ['/auditoria', 'Notas'],
    'conferencia' => ['/conferencia', 'Mês'],
    'conciliacao' => ['/conciliacao', 'Banco'],
    'estrategia' => ['/estrategia', 'Comparar'],
    'legislacao' => ['/legislacao', 'Perguntar'],
    'contabil' => ['/contabil', 'Livros'],
    'relatorios' => ['/relatorios', 'Parecer'],
    'agentes' => ['/agentes', 'Sistema'],
    'assinatura' => ['/assinatura', 'Plano'],
    'configuracoes' => ['/configuracoes', 'Escritório'],
];
if (($usuario['papel'] ?? '') === 'plataforma') {
    $itens['plataforma'] = ['/plataforma', 'Clientes'];
}
$chamadas = [
    'painel' => 'O que esta empresa precisa de você hoje.',
    'empresas' => 'Cadastre o cliente. O sistema abre as contas sozinho.',
    'auditoria' => 'Envie as notas. O sistema aponta o que está errado.',
    'conferencia' => 'Veja o mês e decida se o cliente fica no Simples ou destaca o imposto novo.',
    'conciliacao' => 'Envie o extrato. O sistema sugere o par com a nota. Você confirma.',
    'estrategia' => 'Os dois caminhos lado a lado, com o número pronto.',
    'legislacao' => 'Pergunte em português. A resposta usa a regra daquela data.',
    'contabil' => 'O saldo das contas. Um lançamento manual entra aqui.',
    'agentes' => 'Quem faz o trabalho, e o que já foi feito.',
    'assinatura' => 'Seu plano, a mensalidade e as pessoas da equipe.',
    'relatorios' => 'O texto para levar à reunião com o cliente.',
    'configuracoes' => 'Nome, contato e a marca que aparece no parecer.',
    'plataforma' => 'Os escritórios que usam o sistema e o que ainda não pagaram.',
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
  <link rel="stylesheet" href="/assets/app.css?v=7">
  <style>:root { --sidebar: <?= e($cor) ?>; }</style>
</head>
<body>
  <a class="pular" href="#conteudo">Ir para o conteúdo</a>
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
      <button type="button" class="menu-btn" data-menu aria-expanded="false" aria-controls="menu">Mostrar menu</button>
      <form class="empresa-ativa" method="post" action="/empresa-ativa">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
        <label for="empresa-ativa">Empresa em uso</label>
        <select id="empresa-ativa" name="empresa_id" data-empresa-ativa>
          <option value="0">Nenhuma selecionada</option>
          <?php foreach ($listaEmpresas as $item): ?>
            <option value="<?= e((string) $item['id']) ?>" <?= ($empresa['id'] ?? 0) == $item['id'] ? 'selected' : '' ?>><?= e($item['razao_social']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="pequeno" type="submit">Usar esta empresa</button>
      </form>
      <nav id="menu" aria-label="Seções">
        <?php foreach ($itens as $chave => [$href, $nome]): ?>
          <a class="<?= $pagina === $chave ? 'ativa' : '' ?>" href="<?= e($href) ?>" <?= $pagina === $chave ? 'aria-current="page"' : '' ?>><?= e($nome) ?></a>
        <?php endforeach; ?>
      </nav>
      <footer>
        <div class="quem">
          <strong><?= e((string) ($usuario['nome'] ?? '')) ?></strong>
          <span><?= e(\App\Core\Format::rotulo((string) ($usuario['papel'] ?? ''))) ?></span>
        </div>
        <form method="post" action="/sair">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="secundario pequeno" type="submit">Sair</button>
        </form>
      </footer>
    </aside>
    <main class="conteudo" id="conteudo">
      <?php if ($flash): ?>
        <div class="flash <?= e($flash['tipo']) ?>" role="status"><?= e($flash['texto']) ?></div>
      <?php endif; ?>
      <header class="topo">
        <div>
          <h1><?= e($titulo) ?></h1>
          <?php if (isset($chamadas[$pagina])): ?><p class="chamada"><?= e($chamadas[$pagina]) ?></p><?php endif; ?>
        </div>
      </header>
      <?= $conteudo ?>
    </main>
  </div>
  <script src="/assets/app.js?v=7"></script>
</body>
</html>
