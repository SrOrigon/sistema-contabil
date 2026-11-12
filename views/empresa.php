<?php /** @var array<string,mixed> $cadastro */ /** @var list<array<string,mixed>> $pessoas */ /** @var list<array<string,mixed>> $balancete */ ?>
<form class="card pilha" method="post">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <div class="grade-form">
    <label>Razão social<input name="razao_social" value="<?= e($cadastro['razao_social']) ?>" required></label>
    <label>Nome fantasia<input name="nome_fantasia" value="<?= e((string) $cadastro['nome_fantasia']) ?>"></label>
    <label>CNPJ<input name="cnpj" value="<?= e(\App\Core\Format::documento((string) $cadastro['cnpj'])) ?>" required></label>
    <label>Inscrição estadual<input name="ie" value="<?= e((string) $cadastro['ie']) ?>"></label>
    <label>Inscrição municipal<input name="im" value="<?= e((string) $cadastro['im']) ?>"></label>
    <label>Atividade principal<input name="cnae" value="<?= e((string) $cadastro['cnae']) ?>"></label>
    <label>Regime
      <select name="regime">
        <?php foreach (['simples' => 'Simples Nacional', 'mei' => 'MEI', 'presumido' => 'Lucro presumido', 'real' => 'Lucro real'] as $valor => $rotulo): ?>
          <option value="<?= e($valor) ?>" <?= $cadastro['regime'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Anexo
      <select name="anexo_simples">
        <?php foreach (['I','II','III','IV','V','AUTO'] as $anexo): ?>
          <option value="<?= $anexo ?>" <?= $cadastro['anexo_simples'] === $anexo ? 'selected' : '' ?>><?= $anexo === 'AUTO' ? 'Pela folha' : 'Anexo ' . e($anexo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>UF<input name="uf" value="<?= e((string) $cadastro['uf']) ?>"></label>
    <label>Município<input name="municipio" value="<?= e((string) $cadastro['municipio']) ?>"></label>
    <label>Início<input type="date" name="inicio_atividade" value="<?= e((string) $cadastro['inicio_atividade']) ?>"></label>
  </div>
  <p class="sub">Capital registrado na abertura: <?= \App\Core\Format::money((int) $cadastro['capital_social_centavos']) ?>. Mudança de capital entra como lançamento em Livros.</p>
  <div class="acoes">
    <button type="submit">Salvar cadastro</button>
  </div>
</form>
<div class="duas-colunas">
  <section class="card">
    <h2>Clientes e fornecedores</h2>
    <form method="post" class="grade-form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="pessoa">
      <label>Nome<input name="nome" required></label>
      <label>CPF ou CNPJ<input name="documento" required></label>
      <label>Tipo
        <select name="tipo"><option value="cliente">Cliente</option><option value="fornecedor">Fornecedor</option><option value="ambos">Ambos</option></select>
      </label>
      <label>Inscrição estadual<input name="ie"></label>
      <label>E-mail<input name="email"></label>
      <button type="submit">Adicionar</button>
    </form>
    <table>
      <tbody>
      <?php foreach ($pessoas as $pessoa): ?>
        <tr>
          <td><?= e($pessoa['nome']) ?><div class="sub"><?= \App\Core\Format::documento((string) $pessoa['documento']) ?> · <?= e(\App\Core\Format::rotulo((string) $pessoa['tipo'])) ?></div></td>
          <td>
            <form method="post" action="/pessoas/<?= e((string) $pessoa['id']) ?>/excluir">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <button class="secundario pequeno" type="submit">Remover</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <section class="card">
    <h2>Saldos</h2>
    <table>
      <tbody>
      <?php foreach ($balancete as $conta): ?>
        <?php $saldo = in_array($conta['natureza'], ['ativo', 'despesa', 'custo'], true) ? (int) $conta['debitos'] - (int) $conta['creditos'] : (int) $conta['creditos'] - (int) $conta['debitos']; ?>
        <?php if ($saldo === 0) continue; ?>
        <tr><td><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></td><td class="num"><?= \App\Core\Format::money($saldo) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" onsubmit="return confirm('Apagar esta empresa e todos os lançamentos dela?');">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="excluir">
      <button class="perigo" type="submit">Excluir empresa</button>
    </form>
  </section>
</div>
