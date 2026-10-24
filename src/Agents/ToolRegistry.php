<?php

declare(strict_types=1);

namespace App\Agents;

use App\Agents\Contracts\ToolInterface;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $ferramentas = [];

    public function registrar(ToolInterface $ferramenta): void
    {
        $this->ferramentas[$ferramenta->name()] = $ferramenta;
    }

    public function obter(string $nome): ToolInterface
    {
        if (!isset($this->ferramentas[$nome])) {
            throw new \InvalidArgumentException('Ferramenta desconhecida: ' . $nome);
        }
        return $this->ferramentas[$nome];
    }

    /** @return list<array<string, mixed>> */
    public function declaracoes(): array
    {
        $lista = [];
        foreach ($this->ferramentas as $ferramenta) {
            $lista[] = [
                'name' => $ferramenta->name(),
                'description' => $ferramenta->description(),
                'parameters' => $ferramenta->parameters(),
            ];
        }
        return $lista;
    }

    /** @param array<string, mixed> $argumentos @return array<string, mixed> */
    public function executar(string $nome, array $argumentos): array
    {
        return $this->obter($nome)->execute($argumentos);
    }
}
