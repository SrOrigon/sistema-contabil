<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$faltando = array_filter(
    ['pdo_sqlite', 'bcmath', 'mbstring', 'xmlreader', 'zip', 'curl'],
    static fn (string $extensao): bool => !extension_loaded($extensao),
);
if ($faltando !== []) {
    http_response_code(500);
    echo 'Extensões PHP ausentes: ' . implode(', ', $faltando);
    exit;
}

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$dsn = env('DB_DSN', 'sqlite:storage/contabil.sqlite');
if (str_starts_with((string) $dsn, 'sqlite:') && !str_starts_with((string) $dsn, 'sqlite::memory:')) {
    $caminho = substr((string) $dsn, 7);
    if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $caminho) && !str_starts_with($caminho, '\\\\')) {
        $caminho = ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $caminho);
        $dsn = 'sqlite:' . $caminho;
    }
    $pasta = dirname($caminho);
    if (!is_dir($pasta)) {
        mkdir($pasta, 0777, true);
    }
}

$banco = new App\Core\Database((string) $dsn);
(new App\Core\Migrator($banco))->migrate();
(new App\Http\Kernel($banco))->handle(new App\Core\Request());
