<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;

/** Uma ferramenta: apurar_assinatura. O preço sai da tabela de planos, não de uma estimativa. */
class GestorAssinaturaAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'gestor-assinatura',
            'Gestor de assinatura',
            self::class,
            'apurar_assinatura',
            'Conta as empresas ativas, compara com o teto do plano e emite a fatura do mês pelo preço gravado na tabela.',
            'Não chama o modelo.',
            [
                ['plano', true, 'texto', 'Código do plano na troca: essencial, profissional ou avancado.'],
            ],
            [
                ['Código Civil, arts. 421 e 422', 'O plano, o teto de empresas e o preço da fatura são o contrato que está na tabela.'],
            ],
        );
    }

    /** @param array<string, mixed> $entrada @return array<string, mixed> */
    public static function ler(array $entrada): array
    {
        return LeitorDeVariaveis::ler(self::contrato(), $entrada, ['plano']);
    }
}
