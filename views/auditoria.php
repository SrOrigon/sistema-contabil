<?php /** @var list<array<string,mixed>> $documentos */ /** @var string $filtro */ ?>
<?php if ($empresa === null): ?>
  <section class="card vazio"><p>Selecione uma empresa para auditar notas.</p><a class="btn" href="/empresas">Cadastrar</a></section>
<?php else: ?>
  <form class="card drop" data-drop method="post" action="/auditoria/upload" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h2>Lote de documentos</h2>
    <p>NF-e, NFC-e, CT-e, NFS-e, SPED ou ZIP. O parser lê em fluxo, o validador cruza NCM, CST e cClassTrib, e o razão recebe a nota quando o CNPJ da empresa é emitente ou destinatário.</p>
    <input type="file" name="arquivos[]" multiple accept=".xml,.txt,.sped,.zip" required>
    <button type="submit">Auditar lote</button>
  </form>
  <div class="acoes" style="margin:16px 0">
    <a class="btn secundario pequeno" href="/auditoria">Todos</a>
    <a class="btn secundario pequeno" href="/auditoria?status=rejeitado">Rejeitados</a>
    <a class="btn secundario pequeno" href="/auditoria?status=ambiguo">Ambíguos</a>
    <a class="btn secundario pequeno" href="/auditoria?status=alerta">Alertas</a>
    <a class="btn secundario pequeno" href="/auditoria?status=conforme">Conformes</a>
    <a class="btn secundario pequeno" href="/auditoria/csv">Exportar CSV</a>
  </div>
  <section class="card">
    <table>
      <thead><tr><th>Documento</th><th>Emissão</th><th>Partes</th><th>Valor</th><th>IBS/CBS</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($documentos as $documento): ?>
        <tr>
          <td><a href="/documentos/<?= e((string) $documento['id']) ?>"><?= e($documento['modelo']) ?> <?= e((string) $documento['numero']) ?></a><div class="sub"><?= e((string) $documento['arquivo_nome']) ?></div></td>
          <td><?= \App\Core\Format::data((string) $documento['emissao']) ?><div><?= chip((string) $documento['tipo_operacao']) ?></div></td>
          <td><?= e((string) $documento['emitente_nome']) ?><div class="sub">para <?= e((string) $documento['destinatario_nome']) ?></div></td>
          <td class="num"><?= \App\Core\Format::money((int) $documento['valor_nota_centavos']) ?></td>
          <td class="num"><?= \App\Core\Format::money((int) $documento['valor_ibs_centavos'] + (int) $documento['valor_cbs_centavos']) ?></td>
          <td><?= chip((string) $documento['status_auditoria']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($documentos === []): ?><p class="vazio">Nenhum documento<?= $filtro !== '' ? ' com esse status' : '' ?>. Os XMLs de ensaio ficam na pasta exemplos, fora do sistema, e passam pelo mesmo motor.</p><?php endif; ?>
  </section>
<?php endif; ?>
