<?php

declare(strict_types=1);

namespace App\Agents\Tools;

use App\Agents\Contracts\ToolInterface;
use App\KnowledgeBase\HybridLawSearcher;

final class QueryLawArticleTool implements ToolInterface
{
    public function __construct(private readonly HybridLawSearcher $busca)
    {
    }

    public function name(): string
    {
        return 'consultar_artigo_lei';
    }

    public function description(): string
    {
        return 'Busca trechos vigentes da base legislativa por artigo, diploma ou assunto. Cite somente o que esta ferramenta devolver.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'consulta' => ['type' => 'STRING'],
                'competencia' => ['type' => 'STRING', 'description' => 'Data AAAA-MM-DD da operação'],
            ],
            'required' => ['consulta', 'competencia'],
        ];
    }

    public function execute(array $argumentos): array
    {
        $data = new \DateTimeImmutable((string) ($argumentos['competencia'] ?? 'today'));
        return [
            'resultados' => $this->busca->search((string) ($argumentos['consulta'] ?? ''), $data, 5),
        ];
    }
}
