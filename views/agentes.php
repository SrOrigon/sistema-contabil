<?php
$oficios = [
    'validador-regras-tributarias' => ['Lê a nota', 'Confere cada produto da nota e diz se o imposto destacado está certo.', '/auditoria'],
    'conferencista-mensal' => ['Fecha o mês', 'Junta as notas do período, compara ficar no Simples ou destacar o imposto novo, e guarda a escolha.', '/conferencia'],
    'simulador-regime' => ['Compara os caminhos', 'Calcula o caixa dos dois regimes com a alíquota do ano. Você só informa o que a nota não traz.', '/estrategia'],
    'conciliador-extrato' => ['Cruza o banco', 'Acha a nota que combina com o lançamento. Você confirma ou recusa.', '/conciliacao'],
    'consultor-artigo-lei' => ['Responde a pergunta', 'Busca o texto que valia na data da operação.', '/legislacao'],
    'auditor-equacao' => ['Confere a conta', 'Vê se débitos e créditos fecham antes de soltar um parecer.', '/contabil'],
    'porteiro-acesso' => ['Cuida de quem entra', 'Confere a senha e inclui a equipe neste escritório, sem abrir a carteira de outro.', '/assinatura'],
    'gestor-assinatura' => ['Cuida da mensalidade', 'Conta as empresas do plano e mantém a fatura do mês.', '/assinatura'],
    'tesoureiro-preditivo' => ['Tesouraria inteligente', 'Reconhece gastos recorrentes (aluguel, luz, folha) e alerta desvios ou anomalias de caixa.', '/conciliacao'],
    'auditor-compliance' => ['Compliance preventivo', 'Cruza notas e extratos em tempo real para evitar malha fina e divergências com o fisco.', '/auditoria'],
];
$nomesAcao = [
    'validar_regras_tributarias_item' => 'Leu um item de nota',
    'conferir_cbs_ibs_do_periodo' => 'Conferiu o mês',
    'simular_regime' => 'Comparou os regimes',
    'conciliar_extrato' => 'Cruzou um lançamento',
    'consultar_artigo' => 'Respondeu uma pergunta',
    'auditar_equacao' => 'Conferiu se as contas fecham',
    'autenticar_usuario' => 'Alguém entrou',
    'cadastrar_usuario' => 'Incluiu alguém na equipe',
    'apurar_assinatura' => 'Atualizou a mensalidade',
    'classificar_tesouraria_preditiva' => 'Classificou despesa recorrente no banco',
    'auditar_cruzamento_compliance' => 'Auditou cruzamento de compliance fiscal',
];
?>
<section class="card">
  <p>Você escolhe a empresa, envia a nota ou o extrato e confirma a decisão. O cálculo, a lei e o registro ficam com o sistema.</p>
</section>
<section class="pilha">
  <?php foreach ($catalogo as $agente): ?>
    <?php $oficio = $oficios[$agente['id']] ?? [$agente['nome'], $agente['papel'], '/']; ?>
    <article class="card">
      <div class="rotulo"><?= e($oficio[0]) ?></div>
      <h2><?= e($agente['nome']) ?></h2>
      <p><?= e($oficio[1]) ?></p>
      <p><a class="btn pequeno" href="<?= e($oficio[2]) ?>">Abrir</a></p>
    </article>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>O que já foi feito</h2>
  <?php if ($logs === []): ?>
    <p class="vazio">Ainda não há registro. Envie uma nota ou confira o mês para a primeira linha aparecer aqui.</p>
  <?php else: ?>
    <div class="lista">
      <?php foreach ($logs as $log): ?>
        <div class="item-lista">
          <div>
            <strong><?= e($nomesAcao[(string) $log['acao']] ?? 'Registrou uma decisão') ?></strong>
            <div class="sub"><?= e(\App\Core\Format::data(substr((string) $log['criado_em'], 0, 10))) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
