<section class="card">
  <p>O orquestrador registra cada ferramenta chamada. Sem chave de modelo, a sugestão sai do motor determinístico. Com chave, o modelo só escolhe uma hipótese já listada ou redige a partir dos trechos vigentes. Ele não calcula imposto.</p>
  <p><?= $llm ? 'Modelo configurado: ' . e($modelo) : 'Nenhuma chave configurada. O escritório opera inteiro no motor.' ?></p>
</section>
<section class="pilha" style="margin-top:16px">
  <?php foreach ($logs as $log): ?>
    <article class="card">
      <div class="rotulo"><?= e((string) $log['criado_em']) ?> · <?= e((string) $log['agente']) ?> · <?= e((string) $log['modelo']) ?></div>
      <h2><?= e((string) $log['acao']) ?></h2>
      <p><?= e((string) $log['saida']) ?></p>
    </article>
  <?php endforeach; ?>
  <?php if ($logs === []): ?><p class="vazio">Ainda não há decisões registradas.</p><?php endif; ?>
</section>
