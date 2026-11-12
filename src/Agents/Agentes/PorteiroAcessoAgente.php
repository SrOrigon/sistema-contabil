<?php

declare(strict_types=1);

namespace App\Agents\Agentes;

use App\Agents\AgentCatalog;
use App\Agents\LeitorDeVariaveis;

/** Uma ferramenta: autenticar_usuario. A sessão nasce fora daqui, com o usuário que a ferramenta devolveu. */
class PorteiroAcessoAgente
{
    /** @return array<string, mixed> */
    public static function contrato(): array
    {
        return AgentCatalog::definir(
            'porteiro-acesso',
            'Porteiro de acesso',
            self::class,
            'autenticar_usuario',
            'Confere e-mail e senha do escritório e cadastra a equipe no mesmo escritório. Não abre a carteira de outro CNPJ.',
            'Não chama o modelo.',
            [
                ['email', true, 'texto', 'E-mail de quem entra. É a chave do acesso.'],
                ['senha', true, 'texto', 'Senha. Menos de 8 caracteres não cria acesso.'],
                ['nome', true, 'texto', 'Nome de quem entra na equipe. O login não pede; o cadastro pede.'],
            ],
            [
                ['Lei 13.709/2018', 'O acesso de um escritório não alcança os dados de outro. Cada escritório é um tratamento separado.'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $entrada
     * @param list<string> $etapa
     * @return array<string, mixed>
     */
    public static function ler(array $entrada, array $etapa): array
    {
        return LeitorDeVariaveis::ler(self::contrato(), $entrada, $etapa);
    }
}
