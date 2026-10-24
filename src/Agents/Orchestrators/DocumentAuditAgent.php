<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Llm\LlmClient;
use App\Core\Format;

final class DocumentAuditAgent
{
    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $classificacao
     * @param list<array<string, mixed>> $feedback
     * @param list<string> $hipoteses
     * @return array{sugestao:string, confianca:int, modelo:string}
     */
    public function sugerir(array $item, array $classificacao, array $feedback, array $hipoteses, ?LlmClient $llm): array
    {
        $aprendido = $this->feedbackSemelhante($item, $feedback);
        if ($aprendido !== null) {
            return [
                'sugestao' => 'O escritório já corrigiu item parecido no NCM ' . Format::digitos((string) $item['ncm'])
                    . ' para CST ' . $aprendido['cst'] . ' e redução de ' . $aprendido['reducao'] . '%. A nota original não foi alterada.',
                'confianca' => 90,
                'modelo' => 'aprendizado-do-escritorio',
            ];
        }
        if (($classificacao['status'] ?? '') === 'ambiguo' && $llm !== null && $llm->configurado() && $hipoteses !== []) {
            $indice = $llm->escolherHipotese((string) $item['descricao'], (string) $item['ncm'], $hipoteses);
            if ($indice !== null) {
                return [
                    'sugestao' => 'O modelo escolheu a hipótese ' . ($indice + 1) . ': ' . $hipoteses[$indice] . '. A ferramenta determinística continua mandando no número; confirme com um clique.',
                    'confianca' => 70,
                    'modelo' => $llm->modelo(),
                ];
            }
        }
        $status = (string) ($classificacao['status'] ?? 'alerta');
        $confianca = $status === 'conforme' ? 100 : ($status === 'ambiguo' ? 0 : 100);
        return [
            'sugestao' => (string) ($classificacao['diagnostico'] ?? ''),
            'confianca' => $confianca,
            'modelo' => 'deterministico',
        ];
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
