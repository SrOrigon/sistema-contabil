<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;

/** Uma ferramenta: conferir_cbs_ibs_do_periodo. A janela legal não é hipótese de modelo. */
class ConferencistaMensalAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'conferencista-mensal',
            'Conferencista mensal de CBS e IBS',
            self::class,
            'conferir_cbs_ibs_do_periodo',
            'Confere o período nota a nota e só compara unificado e híbrido depois de ler cada variável no próprio tipo. Redução de IBS e redução de CBS não são a mesma variável.',
            'Não chama o modelo.',
            [
                ['competencia', true, 'data', 'Primeiro mês conferido. O dia 01 representa a competência.'],
                ['rbt12', false, 'dinheiro', 'Receita bruta dos últimos 12 meses. Sem ela o DAS do Simples não é calculado.'],
                ['folha12', false, 'dinheiro', 'Folha de 12 meses. Sem ela o fator R não escolhe entre os anexos III e V.'],
                ['margem', false, 'percentual', 'Margem que o cliente quer preservar. Sem ela o sistema mostra a diferença de caixa e não grossa o preço.'],
                ['horas_adicionais', false, 'decimal', 'Horas a mais da reforma neste cliente. Sozinha não vira honorário.'],
                ['valor_hora', false, 'dinheiro', 'Valor da hora do escritório. Sem as horas, não vira honorário.'],
                ['escolha', false, 'escolha', 'Unificado ou híbrido. Só é gravada dentro da janela, ou como desistência até a data de retratação.'],
            ],
            [
                ['LC 214/2025, art. 41', 'O regime regular de IBS e CBS, e a opção do optante pelo Simples de apurar esses tributos fora do DAS.'],
                ['LC 123/2006, art. 13, §§ 9º e 10, redação da LC 227/2026', 'A opção é semestral: setembro para janeiro a junho, março para julho a dezembro, irretratável no semestre.'],
                ['LC 214/2025, anexos', 'O NCM não decide sozinho. A descrição separa tratamentos, e a redução de IBS pode divergir da redução de CBS.'],
            ],
        );
    }

    /** @param array<string, mixed> $entrada @return array<string, mixed> */
    public static function ler(array $entrada): array
    {
        $de = trim((string) ($entrada['de'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $de) === 1) {
            $entrada['competencia'] = $de . '-01';
        }
        return LeitorDeVariaveis::ler(self::contrato(), $entrada);
    }
}
