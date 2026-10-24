<?php /** @var list<array<string,mixed>> $tarefas */ /** @var list<array<string,mixed>> $documentos */ /** @var list<array<string,mixed>> $contas */ /** @var ?array<string,int> $equacao */ ?>
<?php if ($empresa === null): ?>
  <section class="card vazio">
    <?php if (!empty($temEmpresas)): ?>
      <h2>Nenhuma empresa está ativa nesta sessão</h2>
      <p>Escolha o CNPJ no seletor ao lado para abrir o plano de contas, a auditoria e a conciliação.</p>
    <?php else: ?>
      <h2>O escritório ainda não tem empresa</h2>
      <p>Cadastre o primeiro CNPJ para abrir o plano de contas, auditar notas e conciliar o banco.</p>
      <a class="btn" href="/empresas">Cadastrar empresa</a>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="grade">
    <article class="card"><div class="rotulo">Tarefas abertas</div><div class="numero"><?= count($tarefas) ?></div></article>
    <article class="card"><div class="rotulo">Documentos recentes</div><div class="numero"><?= count($documentos) ?></div></article>
    <article class="card"><div class="rotulo">Contas bancárias</div><div class="numero"><?= count($contas) ?></div></article>
    <article class="card"><div class="rotulo">Diferença patrimonial</div><div class="numero"><?= \App\Core\Format::money((int) ($equacao['diferenca_centavos'] ?? 0)) ?></div></article>
  </section>
  <div class="duas-colunas" style="margin-top:16px">
    <section class="card">
      <h2>Fila do contador</h2>
      <?php if ($tarefas === []): ?>
        <p class="vazio">Nada pendente. Importe um lote em Auditoria ou um extrato em Conciliação.</p>
      <?php else: ?>
        <div class="lista">
          <?php foreach ($tarefas as $tarefa): ?>
            <div class="item-lista">
              <div><strong><?= e($tarefa['titulo']) ?></strong><div class="sub"><?= e($tarefa['detalhe']) ?></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Caixa e bancos</h2>
      <?php foreach ($contas as $conta): ?>
        <div class="item-lista"><span><?= e($conta['apelido']) ?></span><strong class="num"><?= \App\Core\Format::money((int) $conta['saldo_centavos']) ?></strong></div>
      <?php endforeach; ?>
      <?php if ($contas === []): ?><p class="vazio">Nenhuma conta bancária.</p><?php endif; ?>
      <p><a class="btn pequeno" href="/auditoria">Auditar notas</a> <a class="btn pequeno secundario" href="/conciliacao">Conciliar</a></p>
    </section>
  </div>
<?php endif; ?>
