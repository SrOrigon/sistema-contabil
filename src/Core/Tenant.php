<?php

declare(strict_types=1);

namespace App\Core;

/** Escritório da requisição. Sem tenant, os serviços usam o primeiro escritório, como os testes. */
final class Tenant
{
    private static ?int $id = null;

    public static function definir(int $id): void
    {
        self::$id = $id;
    }

    public static function limpar(): void
    {
        self::$id = null;
    }

    public static function id(): ?int
    {
        return self::$id;
    }
}
