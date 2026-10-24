<section class="duas-colunas">
  <form class="card pilha" method="post" action="/empresas">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h2>Nova empresa</h2>
    <div class="grade-form">
      <label>Razão social<input name="razao_social" required></label>
      <label>Nome fantasia<input name="nome_fantasia"></label>
      <label>CNPJ<input name="cnpj" required placeholder="00.000.000/0000-00"></label>
      <label>Inscrição estadual<input name="ie"></label>
      <label>Inscrição municipal<input name="im"></label>
      <label>CNAE<input name="cnae"></label>
      <label>Regime
        <select name="regime">
          <option value="simples">Simples Nacional</option>
          <option value="mei">MEI</option>
          <option value="presumido">Lucro presumido</option>
          <option value="real">Lucro real</option>
        </select>
      </label>
      <label>Anexo do Simples
        <select name="anexo_simples">
          <?php foreach (['I','II','III','IV','V','AUTO'] as $anexo): ?>
            <option value="<?= $anexo ?>"><?= $anexo === 'AUTO' ? 'Pelo Fator R' : 'Anexo ' . $anexo ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>UF<input name="uf" maxlength="2"></label>
      <label>Município<input name="municipio"></label>
      <label>Capital social<input name="capital_social" value="0,00"></label>
      <label>Início da atividade<input type="date" name="inicio_atividade"></label>
    </div>
    <button type="submit">Abrir empresa</button>
  </form>
  <section class="card">
    <h2>Carteira</h2>
    <?php if ($listaEmpresas === []): ?>
      <p class="vazio">Nenhuma empresa cadastrada.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Empresa</th><th>Regime</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($listaEmpresas as $item): ?>
          <tr>
            <td><?= e($item['razao_social']) ?><div class="sub"><?= \App\Core\Format::documento((string) $item['cnpj']) ?></div></td>
            <td><?= e((string) $item['regime']) ?></td>
            <td><a href="/empresas/<?= e((string) $item['id']) ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</section>
