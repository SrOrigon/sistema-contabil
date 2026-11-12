<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;
use App\Agents\Llm\LlmClient;
use App\Core\Format;

/** Uma ferramenta: validar_regras_tributarias_item. O Flash só entra com duas hipóteses ou mais. */
class ValidadorRegrasAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'validador-regras-tributarias',
            'Validador de regras tributárias',
            self::class,
            'validar_regras_tributarias_item',
            'Confere NCM, CST, cClassTrib e redução do item e aceita o IBS/CBS em centavos que a calculadora devolver.',
            'Só a lista de hipóteses, quando houver duas ou mais.',
            [
                ['ncm', true, 'codigo_ncm', 'Oito dígitos do item. Sem NCM a ferramenta rejeita.'],
                ['descricao', true, 'texto', 'Texto do produto. Decide fralda, absorvente ou ambiguidade.'],
                ['competencia', true, 'data', 'Data da operação. Escolhe a regra vigente.'],
                ['cst', false, 'codigo', 'Código de situação tributária declarado na nota.'],
                ['cClassTrib', false, 'codigo', 'Classificação tributária declarada. Código ausente não é inventado.'],
                ['reducao_ibs', false, 'percentual', 'Percentual de redução de IBS declarado.'],
                ['reducao_cbs', false, 'percentual', 'Percentual de redução de CBS declarado.'],
                ['base', false, 'dinheiro', 'Base de cálculo em reais. O imposto sai em centavos.'],
                ['aliquota_ibs', false, 'percentual', 'Alíquota de IBS do ano, vinda da configuração do escritório.'],
                ['aliquota_cbs', false, 'percentual', 'Alíquota de CBS do ano, vinda da configuração do escritório.'],
            ],
            [
                ['EC 132/2023', 'Cria IBS, CBS e Imposto Seletivo.'],
                ['LC 214/2025', 'Cesta básica, NCM 9619, cClassTrib e base do IBS/CBS.'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $classificacao
     * @param list<array<string, mixed>> $feedback
     * @param list<string> $hipoteses
     * @return array{sugestao:string, confianca:int, modelo:string}
     */
    public function sugerir(array $item, array $classificacao, array $feedback, array $hipoteses, ?LlmClient $llm): array
    {
        $leitura = LeitorDeVariaveis::ler(self::contrato(), $item, ['ncm', 'descricao']);
        $soSemNcm = $leitura['invalidas'] === []
            && count($leitura['faltantes']) === 1
            && $leitura['faltantes'][0]['nome'] === 'ncm';
        if ($soSemNcm) {
            $status = (string) ($classificacao['status'] ?? 'alerta');
            return $this->resposta($leitura, [
                'sugestao' => (string) ($classificacao['diagnostico'] ?? 'Sem NCM de mercadoria. O modelo não foi chamado.'),
                'confianca' => $status === 'conforme' ? 100 : 60,
                'modelo' => 'deterministico',
            ], ['ncm']);
        }
        if (!$leitura['ok']) {
            return $this->resposta($leitura, [
                'sugestao' => $leitura['mensagem'],
                'confianca' => 0,
                'modelo' => 'variavel',
            ]);
        }
        $item = array_merge($item, $leitura['valores']);
        $aprendido = $this->feedbackSemelhante($item, $feedback);
        if ($aprendido !== null) {
            return $this->resposta($leitura, [
                'sugestao' => 'O escritório já corrigiu item parecido no NCM ' . Format::digitos((string) $item['ncm'])
                    . ' para CST ' . $aprendido['cst'] . ' e redução de ' . $aprendido['reducao'] . '%. A nota original não foi alterada.',
                'confianca' => 90,
                'modelo' => 'aprendizado-do-escritorio',
            ]);
        }
        if (($classificacao['status'] ?? '') === 'ambiguo' && $hipoteses !== [] && count($hipoteses) < 2) {
            return $this->resposta($leitura, [
                'sugestao' => 'Há uma leitura possível: ' . $hipoteses[0] . '. A ferramenta determinística continua mandando no número; confirme com um clique.',
                'confianca' => 60,
                'modelo' => 'deterministico',
            ]);
        }
        if (($classificacao['status'] ?? '') === 'ambiguo' && $llm !== null && $llm->configurado() && count($hipoteses) >= 2) {
            $indice = $llm->escolherHipotese((string) $item['descricao'], (string) $item['ncm'], $hipoteses);
            if ($indice !== null) {
                return $this->resposta($leitura, [
                    'sugestao' => 'O modelo escolheu a hipótese ' . ($indice + 1) . ': ' . $hipoteses[$indice] . '. A ferramenta determinística continua mandando no número; confirme com um clique.',
                    'confianca' => 70,
                    'modelo' => $llm->modelo(),
                ]);
            }
        }
        $status = (string) ($classificacao['status'] ?? 'alerta');
        $confianca = $status === 'conforme' ? 100 : ($status === 'ambiguo' ? 0 : 100);
        return $this->resposta($leitura, [
            'sugestao' => (string) ($classificacao['diagnostico'] ?? ''),
            'confianca' => $confianca,
            'modelo' => 'deterministico',
        ]);
    }

    /**
     * @param array{usadas:list<string>,ausentes:list<string>} $leitura
     * @param array{sugestao:string,confianca:int,modelo:string} $resposta
     * @param list<string> $ausentesExtra
     * @return array{sugestao:string,confianca:int,modelo:string,variaveis_usadas:list<string>,variaveis_ausentes:list<string>}
     */
    private function resposta(array $leitura, array $resposta, array $ausentesExtra = []): array
    {
        $resposta['variaveis_usadas'] = $leitura['usadas'];
        $resposta['variaveis_ausentes'] = array_values(array_unique(array_merge($leitura['ausentes'], $ausentesExtra)));
        return $resposta;
    }

    /** @param array<string, mixed> $item @param list<array<string, mixed>> $feedback @return ?array<string, mixed> */
    private function feedbackSemelhante(array $item, array $feedback): ?array
    {
        $descricao = Format::texto((string) ($item['descricao'] ?? ''));
        $ncm = Format::digitos((string) ($item['ncm'] ?? ''));
        $melhor = null;
        $maior = 0.0;
        foreach ($feedback as $evento) {
            if (Format::digitos((string) $evento['ncm']) !== $ncm) {
                continue;
            }
            similar_text($descricao, Format::texto((string) $evento['descricao_produto']), $percentual);
            if ($percentual > $maior) {
                $maior = $percentual;
                $melhor = $evento;
            }
        }
        return $maior >= 82 ? $melhor : null;
    }
}
