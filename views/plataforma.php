<?php /** @var list<array<string, mixed>> $carteira */ ?>
<section class="card">
  <p>Receber a fatura ou suspender o escritório vale na hora. O escritório suspenso continua entrando e encontra a cobrança em Assinatura.</p>
</section>
<section class="card">
  <h2>Faturas abertas</h2>
  <?php foreach ($abertas as $fatura): ?>
    <form method="post" action="/plataforma/faturas/<?= (int) $fatura['id'] ?>/pagar" class="item-lista">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <span><?= e((string) $fatura['escritorio']) ?> · <?= e(\App\Core\Format::competencia((string) $fatura['competencia'])) ?> · <?= \App\Core\Format::money((int) $fatura['valor_centavos']) ?></span>
      <button class="pequeno" type="submit">Receber</button>
    </form>
  <?php endforeach; ?>
  <?php if ($abertas === []): ?><p class="vazio">Nenhuma fatura aberta.</p><?php endif; ?>
</section>
<?php foreach ($carteira as $escritorio): ?>
  <article class="card">
    <h2><?= e((string) $escritorio['nome']) ?></h2>
    <p><?= e((string) ($escritorio['plano_nome'] ?? 'Sem plano')) ?> · <?= e(\App\Core\Format::rotulo((string) ($escritorio['status'] ?? ''))) ?> · <?= (int) $escritorio['empresas'] ?> <?= (int) $escritorio['empresas'] === 1 ? 'empresa' : 'empresas' ?></p>
    <p class="sub"><?= $escritorio['aberta_centavos'] === null ? 'Nada em aberto' : 'Em aberto: ' . \App\Core\Format::money((int) $escritorio['aberta_centavos']) ?></p>
    <div class="acoes">
      <?php if (($escritorio['status'] ?? '') === 'ativa'): ?>
        <form method="post" action="/plataforma/escritorios/<?= (int) $escritorio['id'] ?>/suspender">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="perigo pequeno" type="submit">Suspender</button>
        </form>
      <?php else: ?>
        <form method="post" action="/plataforma/escritorios/<?= (int) $escritorio['id'] ?>/reativar">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button class="pequeno" type="submit">Reativar</button>
        </form>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
