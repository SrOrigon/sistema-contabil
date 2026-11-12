<?php
/** @var array<string, mixed> $conta */
/** @var list<array<string, mixed>> $faturas */
/** @var list<array<string, mixed>> $equipe */
/** @var bool $podeGerir */
?>
<section class="card">
  <div class="rotulo"><?= e((string) $conta['plano_nome']) ?> · <?= e(\App\Core\Format::rotulo((string) $conta['status'])) ?></div>
  <p><?= e((string) $conta['descricao']) ?></p>
  <div class="grade">
    <article class="medida"><div class="rotulo">Empresas ativas</div><div class="numero"><?= (int) $conta['empresas'] ?> / <?= (int) $conta['limite'] ?></div></article>
    <article class="medida"><div class="rotulo">Mensalidade</div><div class="numero"><?= \App\Core\Format::money((int) $conta['preco_centavos']) ?></div></article>
    <article class="medida"><div class="rotulo">Fatura <?= e(\App\Core\Format::competencia((string) ($conta['fatura']['competencia'] ?? ''))) ?></div><div class="numero"><?= e(\App\Core\Format::rotulo((string) ($conta['fatura']['status'] ?? ''))) ?></div></article>
  </div>
  <p class="sub">Se trocar o plano agora, a fatura deste mês acompanha o preço novo.</p>
</section>

<?php if ($podeGerir): ?>
<form class="card" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="_acao" value="plano">
  <h2>Plano</h2>
  <label>Escolha
    <select name="plano">
      <?php foreach ($conta['planos'] as $plano): ?>
        <option value="<?= e((string) $plano['codigo']) ?>" <?= $plano['codigo'] === $conta['plano_codigo'] ? 'selected' : '' ?>><?= e((string) $plano['nome']) ?> · <?= \App\Core\Format::money((int) $plano['preco_centavos']) ?> · até <?= (int) $plano['empresas_por_ciclo'] ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Trocar plano</button>
</form>
<?php endif; ?>

<section class="card">
  <h2>Faturas</h2>
  <?php if ($faturas === []): ?><p class="vazio">Nenhuma fatura neste escritório.</p><?php endif; ?>
  <div class="lista">
  <?php foreach ($faturas as $fatura): ?>
    <div class="item-lista">
      <span><?= e(\App\Core\Format::competencia((string) $fatura['competencia'])) ?> · vence <?= e(\App\Core\Format::data((string) $fatura['vencimento'])) ?></span>
      <strong><?= \App\Core\Format::money((int) $fatura['valor_centavos']) ?> · <?= e(\App\Core\Format::rotulo((string) $fatura['status'])) ?></strong>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2>Equipe</h2>
  <?php if ($equipe === []): ?><p class="vazio">Ninguém além de você neste escritório.</p><?php endif; ?>
  <ul class="lista-limpa">
    <?php foreach ($equipe as $pessoa): ?>
      <li><strong><?= e((string) $pessoa['nome']) ?></strong> · <?= e((string) $pessoa['email']) ?> · <?= e(\App\Core\Format::rotulo((string) $pessoa['papel'])) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($podeGerir): ?>
  <form class="grade-form" method="post">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="equipe">
    <label>Nome<input name="nome" required></label>
    <label>E-mail<input type="email" name="email" required></label>
    <label>Senha<input type="password" name="senha" required minlength="8"></label>
    <label>Papel
      <select name="papel">
        <option value="contador">Contador</option>
        <option value="dono">Dono</option>
      </select>
    </label>
    <button type="submit">Incluir na equipe</button>
  </form>
  <?php endif; ?>
</section>

<form class="card grade-form" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="_acao" value="senha">
  <h2>Senha</h2>
  <label>Senha atual<input type="password" name="senha_atual" required></label>
  <label>Senha nova<input type="password" name="senha_nova" required minlength="8"></label>
  <button type="submit">Trocar senha</button>
</form>
