<?php

declare(strict_types=1);

namespace App\Agents\Orchestrators;

use App\Agents\Agentes\AuditorEquacaoAgente;
use App\Core\Database;

final class SanityReverseAuditor extends AuditorEquacaoAgente
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }
}
