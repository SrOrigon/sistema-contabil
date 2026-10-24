<?php /** @var list<array<string,mixed>> $contas */ /** @var list<array<string,mixed>> $transacoes */ /** @var list<array<string,mixed>> $plano */ ?>
<div class="duas-colunas">
  <section class="card pilha">
    <h2>Nova conta</h2>
    <form method="post" class="grade-form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="conta">
      <label>Apelido<input name="apelido" required placeholder="Itaú movimento"></label>
      <label>Banco<input name="banco"></label>
      <label>Agência<input name="agencia"></label>
      <label>Conta<input name="conta"></label>
      <label>Saldo inicial<input name="saldo_inicial" value="0,00"></label>
      <label>AccountId do agregador<input name="provedor_id" placeholder="opcional"></label>
      <button type="submit">Abrir conta</button>
    </form>
    <?php foreach ($contas as $conta): ?>
      <div class="item-lista">
        <div><strong><?= e($conta['apelido']) ?></strong><div class="sub"><?= e((string) $conta['banco']) ?> · saldo <?= \App\Core\Format::money((int) $conta['saldo_centavos']) ?></div></div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="_acao" value="excluir-conta">
          <input type="hidden" name="conta_id" value="<?= e((string) $conta['id']) ?>">
          <button class="secundario pequeno" type="submit">Remover</button>
        </form>
      </div>
    <?php endforeach; ?>
  </section>
  <section class="card pilha">
    <h2>Importar extrato</h2>
    <form method="post" enctype="multipart/form-data" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="importar">
      <label>Conta
        <select name="conta_id"><?php foreach ($contas as $conta): ?><option value="<?= e((string) $conta['id']) ?>"><?= e($conta['apelido']) ?></option><?php endforeach; ?></select>
      </label>
      <label>OFX ou CSV<input type="file" name="extrato" accept=".ofx,.csv,.txt" required></label>
      <button type="submit">Conciliar extrato</button>
    </form>
    <form method="post" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="pluggy">
      <h2>Sincronizar Pluggy</h2>
      <p class="sub">Usa as credenciais de Configurações e o accountId da conta. Sem credencial, a importação OFX/CSV continua completa.</p>
      <label>Conta<select name="conta_id"><?php foreach ($contas as $conta): ?><option value="<?= e((string) $conta['id']) ?>"><?= e($conta['apelido']) ?></option><?php endforeach; ?></select></label>
      <label>De<input type="date" name="de" value="<?= date('Y-m-01') ?>"></label>
      <label>Até<input type="date" name="ate" value="<?= date('Y-m-d') ?>"></label>
      <button class="secundario" type="submit">Buscar transações</button>
    </form>
  </section>
</div>
<section class="card" style="margin-top:16px">
  <div class="item-lista">
    <h2>Movimento</h2>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="cruzar">
      <button class="secundario pequeno" type="submit">Cruzar pendentes com as notas</button>
    </form>
  </div>
  <table>
    <thead><tr><th>Data</th><th>Descrição</th><th>Valor</th><th>Sugestão</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($transacoes as $transacao): ?>
      <tr>
        <td><?= \App\Core\Format::data((string) $transacao['data']) ?><div class="sub"><?= e((string) $transacao['apelido']) ?></div></td>
        <td><?= e((string) $transacao['descricao']) ?></td>
        <td class="num"><?= \App\Core\Format::money((int) $transacao['valor_centavos']) ?></td>
        <td><?= chip((string) $transacao['status']) ?><div class="sub"><?= e((string) ($transacao['motivo'] ?? '')) ?></div></td>
        <td class="acoes">
          <?php if ($transacao['status'] === 'pendente' && !empty($transacao['sugestao_id']) && ($transacao['status_sugestao'] ?? '') === 'sugerida'): ?>
            <form method="post" action="/conciliacoes/<?= e((string) $transacao['sugestao_id']) ?>/confirmar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="pequeno" type="submit">Confirmar</button></form>
            <form method="post" action="/conciliacoes/<?= e((string) $transacao['sugestao_id']) ?>/rejeitar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="pequeno secundario" type="submit">Recusar</button></form>
          <?php endif; ?>
          <?php if ($transacao['status'] === 'pendente'): ?>
            <form method="post" action="/transacoes/<?= e((string) $transacao['id']) ?>/lancar">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <select name="conta"><?php foreach ($plano as $conta): ?><option value="<?= e($conta['codigo']) ?>"><?= e($conta['codigo']) ?> <?= e($conta['nome']) ?></option><?php endforeach; ?></select>
              <button class="pequeno secundario" type="submit">Lançar</button>
            </form>
            <form method="post" action="/transacoes/<?= e((string) $transacao['id']) ?>/ignorar"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="pequeno secundario" type="submit">Ignorar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($transacoes === []): ?><p class="vazio">Nenhuma transação importada.</p><?php endif; ?>
</section>
