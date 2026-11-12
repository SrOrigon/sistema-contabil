<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $caminho = rawurldecode((string) $caminho);
        if (str_starts_with($caminho, '/public/')) {
            $caminho = substr($caminho, strlen('/public')) ?: '/';
        } elseif ($caminho === '/public') {
            $caminho = '/';
        }
        if ($caminho === '') {
            return '/';
        }
        return rtrim($caminho, '/') ?: '/';
    }

    public function input(string $chave, string $padrao = ''): string
    {
        $valor = $_POST[$chave] ?? $_GET[$chave] ?? $padrao;
        return is_string($valor) ? trim($valor) : $padrao;
    }

    public function query(string $chave, string $padrao = ''): string
    {
        $valor = $_GET[$chave] ?? $padrao;
        return is_string($valor) ? trim($valor) : $padrao;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $bruto = file_get_contents('php://input') ?: '';
        if ($bruto === '') {
            return [];
        }
        $dados = json_decode($bruto, true);
        return is_array($dados) ? $dados : [];
    }

    public function header(string $nome): string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
        return (string) ($_SERVER[$chave] ?? '');
    }
}
