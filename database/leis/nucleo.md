# Núcleo legislativo operacional

Este arquivo é a memória de trabalho do escritório. Cada bloco resume a regra e aponta o diploma. Não substitui a leitura do texto oficial antes de uma peça formal. Importe novos markdown pela tela de Legislação quando a norma mudar.

## Emenda Constitucional 132/2023
@diploma: EC 132/2023
@artigo: EC 132/2023
@tipo: IBS
@inicio: 2023-12-20
@fim:

A EC 132/2023 alterou a Constituição para criar o IBS, de competência compartilhada entre estados, municípios e Distrito Federal, e a CBS, contribuição federal. O Imposto Seletivo ficou reservado a bens e serviços prejudiciais à saúde ou ao meio ambiente. A emenda também fixou a transição que a LC 214/2025 detalhou: teste em 2026, extinção de PIS e Cofins a partir de 2027 e substituição gradual de ICMS e ISS até 2033.

## Instituição do IBS e da CBS
@diploma: LC 214/2025
@artigo: LC 214/2025
@tipo: IBS
@inicio: 2025-01-16
@fim:

A Lei Complementar 214, de 16 de janeiro de 2025, institui o IBS, a CBS e o Imposto Seletivo. O planejamento que citava LC 214/2024 refere-se ao projeto discutido em 2024; o diploma vigente é a LC 214/2025. A base interna não inventa número de artigo quando a redação exata não está carregada. Importe o texto oficial para citação literal.

## Não cumulatividade
@diploma: LC 214/2025
@artigo: Não cumulatividade
@tipo: IBS
@inicio: 2025-01-16
@fim:

IBS e CBS são não cumulativos. O contribuinte do regime normal desconta o imposto pago nas aquisições das saídas. Uso pessoal e outras vedações legais não geram crédito. No Simples, o crédito do adquirente depende de o IBS e a CBS serem destacados por fora da guia única. Por isso a simulação separa regime unificado, sem crédito de entrada e sem crédito ao cliente, de regime híbrido, com débito menos crédito.

## Teste de 2026
@diploma: LC 214/2025
@artigo: Transição 2026
@tipo: CBS
@inicio: 2026-01-01
@fim: 2026-12-31

Em 2026 a CBS é cobrada à alíquota de teste de 0,9% e o IBS à alíquota de teste de 0,1%. PIS, Cofins, ICMS e ISS continuam. A CBS de teste é compensável com PIS/Cofins e o IBS de teste com ICMS ou ISS, de modo que o desembolso adicional tende a zero quando a parcela antiga cobre o teste. O grupo IBS/CBS passa a ser informado nos documentos. Ausência do grupo em nota de 2026 é achado de auditoria.

## Transição 2027 e 2028
@diploma: LC 214/2025
@artigo: Transição 2027
@tipo: CBS
@inicio: 2027-01-01
@fim: 2028-12-31

A partir de 2027, PIS e Cofins se extinguem e a CBS passa à alíquota de referência. O IBS permanece em 0,1% em 2027 e 2028. A alíquota de referência da CBS não está cravada na lei em percentual eterno: o Senado a fixa para manter a carga. O sistema guarda 8,80% como estimativa de mercado e exige que o escritório a substitua quando a resolução sair. Tratar 8,80% como número legal é erro.

## Transição de 2029 a 2033
@diploma: LC 214/2025
@artigo: Transição 2029
@tipo: IBS
@inicio: 2029-01-01
@fim:

De 2029 a 2032 o IBS sobe em frações da alíquota de referência, enquanto ICMS e ISS caem na mesma proporção: 10% em 2029, 20% em 2030, 30% em 2031 e 40% em 2032. Em 2033 ICMS e ISS se extinguem e o IBS chega à alíquota cheia. A referência estadual e municipal usada aqui, 17,70% somados, é estimativa editável em Configurações. O motor aplica o fator do ano sobre a parcela de ICMS ou ISS do DAS e calcula o IBS pela alíquota do ano.

## Cesta básica nacional
@diploma: LC 214/2025
@artigo: Cesta básica
@tipo: IBS
@inicio: 2026-01-01
@fim:

A cesta básica nacional de alimentos tem alíquota zero de IBS e CBS. O núcleo local reconhece, entre outros, arroz beneficiado, café torrado, leite fluido, feijão comum, açúcar, óleo de soja refinado, farinha de trigo e massas alimentícias não cozidas, pelos prefixos NCM cadastrados na tabela de regras. Item fora dessa lista não ganha alíquota zero por semelhança. Se a nota declarar redução sem regra correspondente, a auditoria marca o benefício para revisão. Café cru não usa a regra do café torrado.

## NCM 9619 e a descrição do produto
@diploma: LC 214/2025
@artigo: NCM 9619.00.00
@tipo: IBS
@inicio: 2026-01-01
@fim:

O NCM 9619.00.00 reúne fraldas e absorventes. Fralda, inclusive geriátrica e infantil, fica na redução de 60%. Absorvente, tampão e produto de higiene menstrual ficam na alíquota zero. As duas palavras na mesma descrição, ou nenhuma delas, deixam o item ambíguo. O sistema não escolhe sozinho. O contador confirma com um clique, e essa correção entra no aprendizado do escritório para descrições parecidas.

## cClassTrib, CST e NCM
@diploma: LC 214/2025
@artigo: cClassTrib
@tipo: IBS
@inicio: 2026-01-01
@fim:

O cClassTrib classifica a situação tributária do IBS e da CBS e precisa ser compatível com o CST e com a redução. O núcleo traz apenas o código 000001, de tributação integral, porque inventar os demais códigos de seis dígitos produziria rejeição falsa. A tabela oficial entra por CSV em Configurações. Enquanto ela não for marcada como completa, código desconhecido gera alerta. Depois de marcada, código desconhecido bloqueia o item. A redução declarada que divergir da regra do NCM também bloqueia.

## Split payment
@diploma: LC 214/2025
@artigo: Split payment
@tipo: IBS
@inicio: 2027-01-01
@fim:

No split payment, a instituição de pagamento retém o IBS e a CBS na liquidação da venda, em especial na operação com adquirente contribuinte. A retenção não é tributo novo: é o mesmo imposto, tirado do caixa na hora do recebimento. A simulação mostra essa retenção só sobre a parcela vendida a pessoa jurídica e não a soma de novo ao caixa já contado.

## Opção do Simples na reforma
@diploma: LC 214/2025
@artigo: Simples híbrido
@tipo: SIMPLES
@inicio: 2027-01-01
@fim:

Empresa do Simples pode permanecer com IBS e CBS dentro da guia única ou destacá-los por fora. Na guia única o adquirente não toma crédito e a empresa não abate o crédito das compras. No destaque por fora, o imposto é não cumulativo e o cliente pessoa jurídica aproveita crédito. A escolha é anual ou na janela legal da opção, não item a item. O motor compara o caixa dos dois caminhos e escreve a recomendação com a memória de cálculo. MEI não usa essa opção.

## Cálculo do DAS
@diploma: LC 123/2006
@artigo: Art. 18
@tipo: SIMPLES
@inicio: 2007-07-01
@fim:

O valor mensal devido no Simples aplica ao mês a alíquota efetiva da faixa da receita bruta de doze meses. A efetiva é a alíquota nominal da faixa menos a parcela a deduzir, dividida pela receita de doze meses. Não se fatia a receita do mês entre faixas. O teto de permanência é R$ 4.800.000,00 de receita bruta acumulada. Acima disso o motor recusa o DAS e avisa que a empresa saiu do regime.

## Fator R
@diploma: LC 123/2006
@artigo: Art. 18
@paragrafo: Fator R, LC 155/2016
@tipo: SIMPLES
@inicio: 2018-01-01
@fim:

Atividades sujeitas ao Fator R usam o Anexo III quando a folha de salários de doze meses, incluídos encargos na forma da lei, é igual ou superior a 28% da receita bruta de doze meses. Abaixo disso, usam o Anexo V. O sistema recebe a folha informada pelo escritório e não estima pró-labore.

## Anexo IV e tributo por fora
@diploma: LC 123/2006
@artigo: Anexo IV
@tipo: SIMPLES
@inicio: 2007-07-01
@fim:

No Anexo IV a contribuição patronal previdenciária não entra no DAS. Na última faixa de vários anexos, ICMS ou ISS também fica por fora. O resultado do motor lista esses tributos em por_fora. Sem o valor informado pelo escritório, o caixa mostrado não inclui essa parcela e o parecer diz isso.

## MEI
@diploma: LC 123/2006
@artigo: Art. 18-A
@tipo: SIMPLES
@inicio: 2009-01-01
@fim:

O MEI tem teto de receita bruta anual de R$ 81.000,00 e DAS fixo, que muda por ano e por atividade. O sistema não guarda um valor de DAS-MEI chutado. O escritório informa o valor pago no mês. A comparação de IBS e CBS aparece para medir o documento, sem tratar o MEI como optante do regime híbrido.

## Como ampliar esta base
@diploma: Base interna
@artigo: Ingestão
@tipo: GOVERNANCA
@inicio: 2025-01-16
@fim:

Novas leis, soluções de consulta e pareceres entram como markdown com os campos @diploma, @artigo, @tipo, @inicio e @fim, quebrados por título de segundo nível. A busca só devolve trecho vigente na competência da operação. Trecho vencido permanece no banco para auditoria histórica e não responde pergunta de competência posterior. A LC 227 citada em rascunhos de planejamento não está neste núcleo: importe o arquivo quando houver texto oficial.
