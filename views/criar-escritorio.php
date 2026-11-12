<?php /** @var ?array{tipo:string,texto:string} $flash */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Abrir escritório — Sistema Contábil</title>
  <link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body>
  <main class="auth">
    <form class="card" method="post" action="/criar-escritorio">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="auth-marca">
        <img src="/assets/icon.svg" alt="">
        <strong>Sistema Contábil</strong>
      </div>
      <h1>Abrir escritório</h1>
      <p>O plano inicial é o Essencial, até 15 empresas. A fatura do mês nasce em aberto, no preço do plano.</p>
      <?php if ($flash): ?><p class="aviso"><?= e($flash['texto']) ?></p><?php endif; ?>
      <label>Nome do escritório<input name="escritorio" required></label>
      <label>CNPJ<input name="cnpj" required inputmode="numeric"></label>
      <label>Seu nome<input name="nome" required></label>
      <label>E-mail<input type="email" name="email" required autocomplete="username"></label>
      <label>Senha<input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
      <button type="submit">Criar e entrar</button>
      <p class="sub"><a href="/entrar">Já tenho acesso</a></p>
    </form>
  </main>
</body>
</html>
