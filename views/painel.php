<?php /** @var list<array<string,mixed>> $tarefas */ /** @var list<array<string,mixed>> $documentos */ /** @var list<array<string,mixed>> $contas */ /** @var ?array<string,int> $equacao */ ?>
<?php if ($empresa === null): ?>
  <section class="card vazio">
    <?php if (!empty($temEmpresas)): ?>
      <h2>Escolha a empresa</h2>
      <p>Use a lista ao lado. Com uma empresa só, ela já entra sozinha.</p>
    <?php else: ?>
      <h2>Comece por uma empresa</h2>
      <p>Cadastre o primeiro cliente. As contas nascem junto com o cadastro.</p>
      <a class="btn" href="/empresas">Cadastrar empresa</a>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="grade">
    <a class="card" href="/auditoria"><div class="rotulo">Enviar notas</div><div class="numero"><?= count($documentos) ?></div><p class="sub">Notas já lidas</p></a>
    <a class="card" href="/conferencia"><div class="rotulo">Conferir o mês</div><div class="numero"><?= count($tarefas) ?></div><p class="sub">Pendências</p></a>
    <a class="card" href="/conciliacao"><div class="rotulo">Banco</div><div class="numero"><?= count($contas) ?></div><p class="sub">Contas cadastradas</p></a>
    <a class="card" href="/contabil"><div class="rotulo">As contas fecham</div><div class="numero"><?= \App\Core\Format::money((int) ($equacao['diferenca_centavos'] ?? 0)) ?></div><p class="sub">Diferença, se houver</p></a>
  </section>
  <div class="duas-colunas">
    <section class="card">
      <h2>Para resolver</h2>
      <?php if ($tarefas === []): ?>
        <p class="vazio">Nada pendente. O próximo passo é enviar as notas do mês ou o extrato do banco.</p>
        <p><a class="btn" href="/auditoria">Enviar notas</a></p>
      <?php else: ?>
        <div class="lista">
          <?php foreach ($tarefas as $tarefa): ?>
            <?php
              $destino = match ((string) ($tarefa['tipo'] ?? '')) {
                  'conciliacao' => '/conciliacao',
                  default => (string) ($tarefa['referencia_tipo'] ?? '') === 'documento'
                      ? '/documentos/' . (int) $tarefa['referencia_id']
                      : '/auditoria',
              };
            ?>
            <a class="item-lista" href="<?= e($destino) ?>">
              <div><strong><?= e($tarefa['titulo']) ?></strong><div class="sub"><?= e($tarefa['detalhe']) ?></div></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Dinheiro no banco</h2>
      <?php foreach ($contas as $conta): ?>
        <div class="item-lista"><span><?= e($conta['apelido']) ?></span><strong class="num"><?= \App\Core\Format::money((int) $conta['saldo_centavos']) ?></strong></div>
      <?php endforeach; ?>
      <?php if ($contas === []): ?><p class="vazio">Nenhuma conta ainda. O extrato entra em Banco.</p><?php endif; ?>
      <p><a class="btn pequeno" href="/conferencia">Ver o mês</a> <a class="btn pequeno secundario" href="/relatorios">Abrir o parecer</a></p>
    </section>
  </div>
<?php endif; ?>
