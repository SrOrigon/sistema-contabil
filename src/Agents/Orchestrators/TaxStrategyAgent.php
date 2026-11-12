<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Agentes\SimuladorRegimeAgente;
use App\Fiscal\Engine\TransitionSimulator;

final class TaxStrategyAgent extends SimuladorRegimeAgente
{
    public function __construct(TransitionSimulator $simulador = new TransitionSimulator())
    {
        parent::__construct($simulador);
    }
}
