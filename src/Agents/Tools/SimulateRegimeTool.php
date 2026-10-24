<?php

declare(strict_types=1);

namespace App\Agents\Tools;

use App\Agents\Contracts\ToolInterface;
use App\Fiscal\Engine\TransitionSimulator;

final class SimulateRegimeTool implements ToolInterface
{
    public function __construct(private readonly TransitionSimulator $simulador = new TransitionSimulator())
    {
    }

    public function name(): string
    {
        return 'simular_carga_hibrido_vs_unificado';
    }

    public function description(): string
    {
        return 'Compara o caixa do regime unificado com o híbrido usando o motor determinístico. A narrativa deve repetir estes números, sem recalcular.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'ano' => ['type' => 'NUMBER'],
                'regime' => ['type' => 'STRING'],
                'anexo' => ['type' => 'STRING'],
                'rbt12' => ['type' => 'STRING'],
                'receita_mes' => ['type' => 'STRING'],
                'folha12' => ['type' => 'STRING'],
                'receita_pj' => ['type' => 'STRING'],
                'base_credito' => ['type' => 'STRING'],
                'aliquota_ibs' => ['type' => 'STRING'],
                'aliquota_cbs' => ['type' => 'STRING'],
            ],
            'required' => ['ano', 'regime', 'receita_mes', 'aliquota_ibs', 'aliquota_cbs'],
        ];
    }

    public function execute(array $argumentos): array
    {
        return $this->simulador->simular($argumentos);
    }
}
