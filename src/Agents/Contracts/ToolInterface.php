<?php

declare(strict_types=1);

namespace App\Agents\Contracts;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function parameters(): array;

    /** @param array<string, mixed> $argumentos @return array<string, mixed> */
    public function execute(array $argumentos): array;
}
