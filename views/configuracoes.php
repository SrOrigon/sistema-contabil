<?php /** @var array<string,mixed> $escritorio */ /** @var list<array<string,mixed>> $aliquotas */ /** @var list<array<string,mixed>> $cclass */ /** @var list<array<string,mixed>> $regras */ /** @var array<string,mixed> $config */ ?>
<div class="duas-colunas">
  <form class="card pilha" method="post">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="escritorio">
    <h2>Escritório</h2>
    <label>Nome<input name="nome" value="<?= e((string) $escritorio['nome']) ?>" required></label>
    <label>CNPJ<input name="cnpj" value="<?= e((string) $escritorio['cnpj']) ?>"></label>
    <label>CRC<input name="crc" value="<?= e((string) $escritorio['crc']) ?>"></label>
    <label>Cidade<input name="cidade" value="<?= e((string) $escritorio['cidade']) ?>"></label>
    <label>Telefone<input name="telefone" value="<?= e((string) $escritorio['telefone']) ?>"></label>
    <label>E-mail<input name="email" value="<?= e((string) $escritorio['email']) ?>"></label>
    <label>Cor da barra<input name="cor" value="<?= e((string) $escritorio['cor']) ?>"></label>
    <button type="submit">Salvar escritório</button>
  </form>
  <form class="card pilha" method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="logo">
    <h2>Marca</h2>
    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp" required>
    <button type="submit">Enviar marca</button>
    <h2>Modelo de linguagem</h2>
  </form>
  <form class="card pilha" method="post">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="ia">
    <label>Provedor
      <select name="ai_provedor">
        <option value="" <?= $config['provedor'] === '' ? 'selected' : '' ?>>Somente motor determinístico</option>
        <option value="gemini" <?= $config['provedor'] === 'gemini' ? 'selected' : '' ?>>Gemini</option>
        <option value="openai" <?= $config['provedor'] === 'openai' ? 'selected' : '' ?>>Compatível com OpenAI</option>
      </select>
    </label>
    <label>Modelo<input name="ai_modelo" value="<?= e((string) $config['modelo']) ?>"></label>
    <label>Chave <?= $config['tem_chave'] ? '(já guardada, deixe em branco para manter)' : '' ?><input name="ai_api_key" type="password" autocomplete="off"></label>
    <label>URL base, se não for a oficial<input name="ai_base_url" value="<?= e((string) $config['base_url']) ?>"></label>
    <button type="submit">Salvar modelo</button>
  </form>
  <form class="card pilha" method="post">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="pluggy">
    <h2>Open Finance</h2>
    <p class="sub">O webhook local é <code>/webhooks/open-finance</code>. Sem token, ele aceita chamada local para os testes. Com token, exige o cabeçalho X-Webhook-Token.</p>
    <label>Client ID<input name="pluggy_client_id" value="<?= e((string) $config['pluggy_id']) ?>"></label>
    <label>Client secret <?= $config['tem_pluggy'] ? '(guardado)' : '' ?><input type="password" name="pluggy_client_secret" autocomplete="off"></label>
    <label>URL<input name="pluggy_base_url" value="<?= e((string) $config['pluggy_url']) ?>"></label>
    <label>Token do webhook <?= $config['webhook'] ? '(guardado, preencha só para trocar)' : '' ?><input name="webhook_token"></label>
    <button type="submit">Salvar Open Finance</button>
  </form>
</div>
<section class="card" style="margin-top:16px">
  <h2>Alíquotas de referência</h2>
  <p class="sub">2026 é alíquota legal de teste. De 2027 em diante o número é estimativa até o Senado fixar. Edite o ano aqui; o motor usa a linha gravada.</p>
  <table>
    <thead><tr><th>Ano</th><th>CBS</th><th>IBS</th><th>Natureza</th><th>Observação</th></tr></thead>
    <tbody>
    <?php foreach ($aliquotas as $linha): ?>
      <tr>
        <td><?= e((string) $linha['ano']) ?></td>
        <td><?= e((string) $linha['cbs']) ?>%</td>
        <td><?= e((string) $linha['ibs']) ?>%</td>
        <td><?= e((string) $linha['natureza']) ?></td>
        <td><?= e((string) $linha['observacao']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" class="grade-form" style="margin-top:12px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="aliquota">
    <label>Ano<input name="ano" required></label>
    <label>CBS %<input name="cbs" required></label>
    <label>IBS %<input name="ibs" required></label>
    <label>Fator ICMS/ISS<input name="fator_icms_iss" value="1"></label>
    <label>Observação<input name="observacao"></label>
    <label>Natureza<select name="natureza"><option value="estimada">Estimada</option><option value="legal">Legal</option></select></label>
    <label><input type="checkbox" name="pis_cofins_vigente" value="1"> PIS/Cofins vigente</label>
    <label><input type="checkbox" name="cbs_compensavel" value="1"> CBS compensável</label>
    <label><input type="checkbox" name="ibs_compensavel" value="1"> IBS compensável</label>
    <button type="submit">Gravar ano</button>
  </form>
</section>
<div class="duas-colunas" style="margin-top:16px">
  <section class="card">
    <h2>cClassTrib</h2>
    <p class="sub"><?= count($cclass) ?> código(s). <?= $config['tabela_completa'] ? 'Tabela marcada como oficial completa: código ausente bloqueia.' : 'Tabela ainda parcial: código ausente gera alerta.' ?> <a href="/configuracoes/modelo-cclass.csv">Baixar modelo</a></p>
    <form method="post" enctype="multipart/form-data" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="cclass">
      <input type="file" name="csv" accept=".csv,.txt" required>
      <label><input type="checkbox" name="oficial_completa" value="1"> Esta importação completa a tabela oficial</label>
      <button type="submit">Importar cClassTrib</button>
    </form>
  </section>
  <section class="card">
    <h2>Regras de NCM</h2>
    <p class="sub"><?= count($regras) ?> regras. Palavras no CSV separam por barra vertical. <a href="/configuracoes/modelo-regras.csv">Baixar modelo</a></p>
    <form method="post" enctype="multipart/form-data" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="regras">
      <input type="file" name="csv" accept=".csv,.txt" required>
      <button type="submit">Importar regras</button>
    </form>
  </section>
</div>
