<?php

declare(strict_types=1);

namespace App\Core;

final class Format
{
    public static function money(int $centavos): string
    {
        $negativo = $centavos < 0;
        $absoluto = abs($centavos);
        $reais = (string) intdiv($absoluto, 100);
        $centavosTexto = str_pad((string) ($absoluto % 100), 2, '0', STR_PAD_LEFT);
        $reais = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $reais) ?? $reais;
        return ($negativo ? '-R$ ' : 'R$ ') . $reais . ',' . $centavosTexto;
    }

    public static function parseMoney(string $texto): int
    {
        $texto = trim($texto);
        if ($texto === '') {
            return 0;
        }
        $negativo = str_starts_with($texto, '-');
        $limpo = preg_replace('/[^\d,.\-]/', '', $texto) ?? '';
        $limpo = ltrim($limpo, '-');
        if (str_contains($limpo, ',') && str_contains($limpo, '.')) {
            $limpo = strrpos($limpo, ',') > strrpos($limpo, '.')
                ? str_replace('.', '', $limpo)
                : str_replace(',', '', $limpo);
            $limpo = str_replace(',', '.', $limpo);
        } elseif (str_contains($limpo, ',')) {
            $limpo = str_replace(',', '.', $limpo);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $limpo)) {
            $limpo = str_replace('.', '', $limpo);
        }
        return Money::fromDecimal(($negativo ? '-' : '') . $limpo)->cents();
    }

    public static function data(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        $parte = substr($iso, 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $parte, $m)) {
            return $iso;
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    public static function competencia(?string $iso): string
    {
        if ($iso === null || strlen($iso) < 7) {
            return '—';
        }
        return substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
    }

    public static function documento(string $digitos): string
    {
        $digitos = self::digitos($digitos);
        if (strlen($digitos) === 14) {
            return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3)
                . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
        }
        if (strlen($digitos) === 11) {
            return substr($digitos, 0, 3) . '.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3)
                . '-' . substr($digitos, 9, 2);
        }
        return $digitos !== '' ? $digitos : '—';
    }

    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    public static function percentual(string $valor): string
    {
        $valor = trim(str_replace('.', ',', $valor));
        $valor = rtrim(rtrim($valor, '0'), ',');
        return ($valor === '' ? '0' : $valor) . '%';
    }

    public static function cnpjValido(string $digitos): bool
    {
        $digitos = self::digitos($digitos);
        if (strlen($digitos) !== 14 || preg_match('/^(\d)\1{13}$/', $digitos)) {
            return false;
        }
        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        return self::digito($digitos, $pesos1) === (int) $digitos[12]
            && self::digito($digitos, $pesos2) === (int) $digitos[13];
    }

    public static function cpfValido(string $digitos): bool
    {
        $digitos = self::digitos($digitos);
        if (strlen($digitos) !== 11 || preg_match('/^(\d)\1{10}$/', $digitos)) {
            return false;
        }
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += (int) $digitos[$i] * (10 - $i);
        }
        $d1 = ($soma % 11) < 2 ? 0 : 11 - ($soma % 11);
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += (int) $digitos[$i] * (11 - $i);
        }
        $d2 = ($soma % 11) < 2 ? 0 : 11 - ($soma % 11);
        return $d1 === (int) $digitos[9] && $d2 === (int) $digitos[10];
    }

    /** @param list<int> $pesos */
    private static function digito(string $base, array $pesos): int
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (int) $base[$i] * $peso;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    }

    public static function rotulo(string $codigo): string
    {
        return [
            'simples' => 'Simples Nacional',
            'mei' => 'MEI',
            'presumido' => 'Lucro presumido',
            'real' => 'Lucro real',
            'ativa' => 'Ativa',
            'suspensa' => 'Suspensa',
            'aberta' => 'Em aberto',
            'paga' => 'Recebida',
            'plataforma' => 'Plataforma',
            'dono' => 'Dono',
            'contador' => 'Contador',
            'cliente' => 'Cliente',
            'fornecedor' => 'Fornecedor',
            'ambos' => 'Cliente e fornecedor',
            'essencial' => 'Essencial',
            'profissional' => 'Profissional',
            'avancado' => 'Avançado',
            'unificado' => 'No Simples',
            'hibrido' => 'Com imposto destacado',
            'estimada' => 'Estimativa',
            'legal' => 'Legal',
        ][$codigo] ?? $codigo;
    }

    public static function texto(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $valor = strtr($valor, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        return preg_replace('/\s+/', ' ', $valor) ?? $valor;
    }
}
