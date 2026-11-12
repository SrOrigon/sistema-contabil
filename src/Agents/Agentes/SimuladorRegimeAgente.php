<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Agents\Tools\SimulateRegimeTool;
use App\Fiscal\Engine\TransitionSimulator;

/** Uma ferramenta: simular_carga_hibrido_vs_unificado. Não chama o modelo. */
class SimuladorRegimeAgente
{
    public function __construct(private readonly TransitionSimulator $simulador = new TransitionSimulator())
    {
    }

    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'simulador-regime',
            'Simulador de regime',
            self::class,
            'simular_carga_hibrido_vs_unificado',
            'Compara o caixa do regime unificado com o híbrido. A recomendação repete os centavos do simulador.',
            'Não chama o modelo.',
            [
                ['ano', true, 'ano', 'Ano da competência. Escolhe o fator da transição.'],
                ['regime', true, 'regime', 'Simples, MEI, lucro presumido ou real da empresa.'],
                ['receita_mes', true, 'dinheiro', 'Receita do mês em reais.'],
                ['aliquota_ibs', true, 'percentual', 'Alíquota de IBS usada na simulação, editável em Configurações.'],
                ['aliquota_cbs', true, 'percentual', 'Alíquota de CBS usada na simulação. 8,80% é estimativa, não texto legal.'],
                ['anexo', false, 'texto', 'Anexo do Simples.'],
                ['rbt12', false, 'dinheiro', 'Receita bruta dos últimos 12 meses.'],
                ['folha12', false, 'dinheiro', 'Folha dos últimos 12 meses, para o fator R.'],
                ['receita_pj', false, 'dinheiro', 'Parcela da receita de pessoa jurídica.'],
                ['base_credito', false, 'dinheiro', 'Aquisições que podem gerar crédito no regime híbrido.'],
                ['fator_icms_iss', false, 'percentual', 'Fração de ICMS ou ISS ainda vigente no ano.'],
                ['natureza_aliquota', false, 'texto', 'Diz se a alíquota é de teste, referência ou estimativa.'],
            ],
            [
                ['EC 132/2023', 'Transição constitucional até 2033.'],
                ['LC 214/2025', 'Teste de 2026, extinção de PIS/Cofins em 2027 e subida do IBS.'],
                ['LC 123/2006', 'Anexo, faixa e fator R do Simples. O DAS não é recalculado fora do motor.'],
            ],
        );
    }

    /** @param array<string, mixed> $entrada @return array<string, mixed> */
    public function simular(array $entrada): array
    {
        $leitura = LeitorDeVariaveis::ler(self::contrato(), $entrada);
        if (!$leitura['ok']) {
            return [
                'recomendacao' => $leitura['mensagem'],
                'memoria' => [],
                'modelo' => 'variavel',
                'agente' => self::contrato()['id'],
                'variaveis_usadas' => $leitura['usadas'],
                'variaveis_ausentes' => $leitura['ausentes'],
            ];
        }
        $argumentos = array_merge($entrada, $leitura['valores']);
        foreach ($leitura['ausentes'] as $nome) {
            unset($argumentos[$nome]);
        }
        $resultado = (new SimulateRegimeTool($this->simulador))->execute($argumentos);
        $resultado['modelo'] = 'deterministico';
        $resultado['agente'] = self::contrato()['id'];
        $resultado['variaveis_usadas'] = $leitura['usadas'];
        $resultado['variaveis_ausentes'] = $leitura['ausentes'];
        return $resultado;
    }
}
