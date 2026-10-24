<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(private readonly string $diretorio)
    {
    }

    /** @param array<string, mixed> $dados */
    public function page(string $modelo, array $dados = []): void
    {
        $conteudo = $this->partial($modelo, $dados);
        $dados['conteudo'] = $conteudo;
        extract($dados, EXTR_SKIP);
        require $this->diretorio . '/layout.php';
    }

    /** @param array<string, mixed> $dados */
    public function partial(string $modelo, array $dados = []): string
    {
        extract($dados, EXTR_SKIP);
        ob_start();
        require $this->diretorio . '/' . $modelo . '.php';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $dados */
    public function raw(string $modelo, array $dados = []): void
    {
        extract($dados, EXTR_SKIP);
        require $this->diretorio . '/' . $modelo . '.php';
    }
}
