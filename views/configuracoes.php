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
    <label>Cor do menu<input type="color" name="cor" value="<?= e((string) $escritorio['cor']) ?>"></label>
    <button type="submit">Salvar escritório</button>
  </form>
  <form class="card pilha" method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="logo">
    <h2>Marca</h2>
    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp" required>
    <button type="submit">Enviar marca</button>
  </form>
  <details class="tecnico">
    <summary>Ajustes técnicos</summary>
  <form class="card pilha" method="post">
    <h2>Modelo de linguagem</h2>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="ia">
    <label>Provedor
      <select name="ai_provedor">
        <option value="" <?= $config['provedor'] === '' ? 'selected' : '' ?>>Sem modelo de linguagem</option>
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
    <h2>Conexão com o banco</h2>
    <p class="sub">Preencha só se o escritório for buscar o extrato direto no banco. O envio do arquivo continua valendo sem isso.</p>
    <label>Client ID<input name="pluggy_client_id" value="<?= e((string) $config['pluggy_id']) ?>"></label>
    <label>Client secret <?= $config['tem_pluggy'] ? '(guardado)' : '' ?><input type="password" name="pluggy_client_secret" autocomplete="off"></label>
    <label>URL<input name="pluggy_base_url" value="<?= e((string) $config['pluggy_url']) ?>"></label>
    <label>Token do webhook <?= $config['webhook'] ? '(guardado, preencha só para trocar)' : '' ?><input name="webhook_token"></label>
    <button type="submit">Salvar conexão</button>
  </form>
  </details>
</div>
<details class="tecnico">
<summary>Tabelas usadas no cálculo</summary>
<section class="card">
  <h2>Alíquotas de cada ano</h2>
  <p class="sub">2026 usa a alíquota de teste prevista em lei. De 2027 em diante o número é estimativa, até sair o valor oficial. O que você gravar aqui é o que o cálculo usa.</p>
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
  <form method="post" class="grade-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="_acao" value="aliquota">
    <label>Ano<input name="ano" required></label>
    <label>CBS %<input name="cbs" required></label>
    <label>IBS %<input name="ibs" required></label>
    <label>Redução de ICMS e ISS<input name="fator_icms_iss" value="1"></label>
    <label>Observação<input name="observacao"></label>
    <label>Natureza<select name="natureza"><option value="estimada">Estimada</option><option value="legal">Legal</option></select></label>
    <label><input type="checkbox" name="pis_cofins_vigente" value="1"> PIS/Cofins vigente</label>
    <label><input type="checkbox" name="cbs_compensavel" value="1"> CBS compensável</label>
    <label><input type="checkbox" name="ibs_compensavel" value="1"> IBS compensável</label>
    <button type="submit">Gravar ano</button>
  </form>
</section>
<div class="duas-colunas">
  <section class="card">
    <h2>Classificação dos produtos</h2>
    <p class="sub"><?= count($cclass) === 1 ? '1 código' : count($cclass) . ' códigos' ?>. <?= $config['tabela_completa'] ? 'Tabela completa: código que falta bloqueia a nota.' : 'Tabela ainda parcial: código que falta vira aviso.' ?> <a href="/configuracoes/modelo-cclass.csv">Baixar o modelo</a></p>
    <form method="post" enctype="multipart/form-data" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="cclass">
      <input type="file" name="csv" accept=".csv,.txt" required>
      <label><input type="checkbox" name="oficial_completa" value="1"> Esta importação completa a tabela oficial</label>
      <button type="submit">Importar a tabela</button>
    </form>
  </section>
  <section class="card">
    <h2>Regras por produto</h2>
    <p class="sub"><?= count($regras) ?> regras. <a href="/configuracoes/modelo-regras.csv">Baixar modelo</a></p>
    <form method="post" enctype="multipart/form-data" class="pilha">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="_acao" value="regras">
      <input type="file" name="csv" accept=".csv,.txt" required>
      <button type="submit">Importar regras</button>
    </form>
  </section>
</div>
</details>
