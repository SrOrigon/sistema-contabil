<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/src/Core/Exceptions.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function chip(string $status): string
{
    $rotulos = [
        'conforme' => 'Certa',
        'alerta' => 'Para olhar',
        'rejeitado' => 'Com erro',
        'ambiguo' => 'Em dúvida',
        'corrigido' => 'Corrigida',
        'pendente' => 'Pendente',
        'conciliado' => 'Casado',
        'ignorado' => 'Ignorado',
        'sugerida' => 'Sugestão',
        'automatica' => 'Casada',
        'confirmada' => 'Confirmada',
        'rejeitada' => 'Recusada',
        'entrada' => 'Compra',
        'saida' => 'Venda',
        'indeterminada' => 'Sem direção',
    ];
    return '<span class="chip chip-' . e($status) . '">' . e($rotulos[$status] ?? $status) . '</span>';
}

function carregarEnv(string $arquivo): void
{
    if (!is_file($arquivo)) {
        return;
    }
    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        $valor = trim($valor, " \t\"'");
        if ($chave !== '' && getenv($chave) === false) {
            putenv($chave . '=' . $valor);
            $_ENV[$chave] = $valor;
        }
    }
}

carregarEnv(ROOT . '/.env');

function env(string $chave, ?string $padrao = null): ?string
{
    $valor = $_ENV[$chave] ?? getenv($chave);
    if ($valor === false || $valor === null || $valor === '') {
        return $padrao;
    }
    return (string) $valor;
}
