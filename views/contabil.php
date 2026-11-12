<?php /** @var list<array<string,mixed>> $balancete */ /** @var list<array<string,mixed>> $lancamentos */ /** @var list<array<string,mixed>> $plano */ /** @var array<string,int> $equacao */ ?>
<section class="grade">
  <article class="card"><div class="rotulo">Ativo</div><div class="numero"><?= \App\Core\Format::money((int) $equacao['ativo_centavos']) ?></div></article>
  <article class="card"><div class="rotulo">Passivo</div><div class="numero"><?= \App\Core\Format::money((int) $equacao['passivo_centavos']) ?></div></article>
  <article class="card"><div class="rotulo">Patrimônio</div><div class="numero"><?= \App\Core\Format::money((int) $equacao['pl_centavos']) ?></div></article>
  <article class="card"><div class="rotulo">Resultado</div><div class="numero"><?= \App\Core\Format::money((int) $equacao['resultado_centavos']) ?></div></article>
</section>
<p class="sub">O sistema confere se o ativo empata com o restante. Diferença: <?= \App\Core\Format::money((int) $equacao['diferenca_centavos']) ?>.</p>
<div class="duas-colunas">
  <section class="card">
    <h2>Novo lançamento</h2>
    <form method="post" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>Data<input type="date" name="data" value="<?= date('Y-m-d') ?>" required></label>
      <label>Histórico<input name="historico" required></label>
      <div id="partidas" class="pilha">
        <div class="acoes">
          <select name="conta[]"><?php foreach ($plano as $conta): ?><option value="<?= e($conta['codigo']) ?>"><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></option><?php endforeach; ?></select>
          <select name="tipo[]"><option value="D">Débito</option><option value="C">Crédito</option></select>
          <input name="valor[]" placeholder="0,00" required>
        </div>
        <div class="acoes">
          <select name="conta[]"><?php foreach ($plano as $conta): ?><option value="<?= e($conta['codigo']) ?>"><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></option><?php endforeach; ?></select>
          <select name="tipo[]"><option value="C">Crédito</option><option value="D">Débito</option></select>
          <input name="valor[]" placeholder="0,00" required>
        </div>
      </div>
      <template id="modelo-partida">
        <div class="acoes">
          <select name="conta[]"><?php foreach ($plano as $conta): ?><option value="<?= e($conta['codigo']) ?>"><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></option><?php endforeach; ?></select>
          <select name="tipo[]"><option value="D">Débito</option><option value="C">Crédito</option></select>
          <input name="valor[]" placeholder="0,00">
        </div>
      </template>
      <div class="acoes">
        <button type="button" class="secundario" data-adicionar-partida>Mais uma partida</button>
        <button type="submit">Lançar</button>
      </div>
    </form>
  </section>
  <section class="card">
    <h2>Últimos lançamentos</h2>
    <?php foreach ($lancamentos as $lancamento): ?>
      <div class="item-lista"><span><?= \App\Core\Format::data((string) $lancamento['data']) ?> · <?= e((string) $lancamento['historico']) ?></span></div>
    <?php endforeach; ?>
  </section>
</div>
<section class="card">
  <h2>Balancete</h2>
  <table>
    <thead><tr><th>Conta</th><th class="num">Débitos</th><th class="num">Créditos</th><th class="num">Saldo</th></tr></thead>
    <tbody>
    <?php foreach ($balancete as $conta): ?>
      <?php $saldo = in_array($conta['natureza'], ['ativo', 'despesa', 'custo'], true) ? (int) $conta['debitos'] - (int) $conta['creditos'] : (int) $conta['creditos'] - (int) $conta['debitos']; ?>
      <tr>
        <td><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></td>
        <td class="num"><?= \App\Core\Format::money((int) $conta['debitos']) ?></td>
        <td class="num"><?= \App\Core\Format::money((int) $conta['creditos']) ?></td>
        <td class="num"><?= \App\Core\Format::money($saldo) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
