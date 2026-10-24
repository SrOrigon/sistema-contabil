<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Tools\SimulateRegimeTool;
use App\Fiscal\Engine\TransitionSimulator;

final class TaxStrategyAgent
{
    public function __construct(private readonly TransitionSimulator $simulador = new TransitionSimulator())
    {
    }

    /** @param array<string, mixed> $entrada @return array<string, mixed> */
    public function simular(array $entrada): array
    {
        $ferramenta = new SimulateRegimeTool($this->simulador);
        $resultado = $ferramenta->execute($entrada);
        $resultado['modelo'] = 'deterministico';
        return $resultado;
    }
}
