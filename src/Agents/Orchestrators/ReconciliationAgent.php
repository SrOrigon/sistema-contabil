<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Agentes\ConciliadorExtratoAgente;
use App\Banking\TransactionMatcher;

final class ReconciliationAgent extends ConciliadorExtratoAgente
{
    public function __construct(TransactionMatcher $matcher = new TransactionMatcher())
    {
        parent::__construct($matcher);
    }
}
