<?php /** @var list<array<string,mixed>> $documentos */ /** @var string $filtro */ ?>
<?php if ($empresa === null): ?>
  <section class="card vazio"><p>Cadastre a empresa antes de enviar as notas.</p><a class="btn" href="/empresas">Cadastrar empresa</a></section>
<?php else: ?>
  <form class="card drop" data-drop method="post" action="/auditoria/upload" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h2>Enviar as notas do mês</h2>
    <p>Solte aqui os arquivos da pasta do mês, ou um zip com todos eles. Uma nota com defeito não atrapalha as outras. O sistema lê, confere e lança quando o CNPJ da empresa está na nota.</p>
    <input type="file" name="arquivos[]" multiple accept=".xml,.txt,.sped,.zip" required>
    <button type="submit">Enviar e conferir</button>
  </form>
  <div class="acoes">
    <a class="btn secundario pequeno" href="/auditoria">Todas</a>
    <a class="btn secundario pequeno" href="/auditoria?status=rejeitado">Com erro</a>
    <a class="btn secundario pequeno" href="/auditoria?status=ambiguo">Em dúvida</a>
    <a class="btn secundario pequeno" href="/auditoria?status=alerta">Para olhar</a>
    <a class="btn secundario pequeno" href="/auditoria?status=conforme">Certas</a>
    <a class="btn secundario pequeno" href="/auditoria/csv">Baixar planilha</a>
  </div>
  <section class="card">
    <table>
      <thead><tr><th>Nota</th><th>Data</th><th>Quem</th><th>Valor</th><th>Imposto novo</th><th>Situação</th></tr></thead>
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
    <?php if ($documentos === []): ?><p class="vazio">Nenhuma nota<?= $filtro !== '' ? ' neste filtro' : ' desta empresa' ?>.</p><?php endif; ?>
  </section>
<?php endif; ?>
