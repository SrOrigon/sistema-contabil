<?php /** @var ?array{tipo:string,texto:string} $flash */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar — Sistema Contábil</title>
  <link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body>
  <main class="auth">
    <form class="card" method="post" action="/entrar">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="auth-marca">
        <img src="/assets/icon.svg" alt="">
        <strong>Sistema Contábil</strong>
      </div>
      <h1>Entrar</h1>
      <p>A carteira, a equipe e a cobrança ficam no escritório de quem entra.</p>
      <?php if ($flash): ?><p class="aviso"><?= e($flash['texto']) ?></p><?php endif; ?>
      <p class="aviso">Demonstração: admin / admin</p>
      <label>Acesso<input type="text" name="email" required autocomplete="username" value="admin"></label>
      <label>Senha<input type="password" name="senha" required autocomplete="current-password" value="admin"></label>
      <button type="submit">Entrar</button>
      <p class="sub"><a href="/criar-escritorio">Abrir um escritório novo</a></p>
    </form>
  </main>
</body>
</html>
