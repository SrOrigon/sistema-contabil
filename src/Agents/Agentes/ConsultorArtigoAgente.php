<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;

/** Uma ferramenta: consultar_artigo_lei. É o agente que pode ocupar mais texto no Gemini. */
final class ConsultorArtigoAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'consultor-artigo-lei',
            'Consultor de artigo de lei',
            self::class,
            'consultar_artigo_lei',
            'Responde com os trechos vigentes na competência. Se a base não tiver o artigo, diz que não tem.',
            'Até três trechos de lei, e só quando a busca devolver mais de um.',
            [
                ['consulta', true, 'texto', 'Pergunta do contador.'],
                ['competencia', true, 'data', 'Data da operação. Norma revogada só vale no período em que vigiu.'],
            ],
            [
                ['EC 132/2023', 'Só entra se o trecho vigente for devolvido pela consulta.'],
                ['LC 214/2025', 'Só entra se o trecho vigente for devolvido pela consulta.'],
                ['núcleo do escritório', 'Memória operacional. Não substitui o texto oficial numa peça formal.'],
            ],
        );
    }

    /** @return array{ok:bool,valores:array<string,mixed>,mensagem:string} */
    public static function ler(string $consulta, string $competencia): array
    {
        return LeitorDeVariaveis::ler(self::contrato(), [
            'consulta' => $consulta,
            'competencia' => $competencia,
        ]);
    }

    public static function deveRedigir(int $quantidadeTrechos): bool
    {
        return $quantidadeTrechos >= 2;
    }
}
