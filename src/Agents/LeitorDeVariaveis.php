<?php

declare(strict_types=1);

namespace App\Agents;

use App\Core\Format;
use App\Core\Money;

/**
 * Lê o payload no tipo declarado pelo agente. Obrigatória vazia ou valor ilegível interrompe.
 * Opcional vazia fica de fora: o agente não inventa zero, código nem data.
 */
final class LeitorDeVariaveis
{
    /** @var array<string, list<string>> */
    private const APELIDOS = [
        'cClassTrib' => ['cclass_trib', 'cclass'],
        'consulta' => ['pergunta'],
    ];

    /**
     * @param array<string, mixed> $contrato
     * @param array<string, mixed> $entrada
     * @param list<string>|null $destaEtapa nomes que esta etapa exige; null exige todas as obrigatórias
     * @return array{ok:bool,valores:array<string,mixed>,faltantes:list<array{nome:string,sentido:string}>,invalidas:list<array{nome:string,sentido:string,motivo:string}>,usadas:list<string>,ausentes:list<string>,mensagem:string}
     */
    public static function ler(array $contrato, array $entrada, ?array $destaEtapa = null): array
    {
        $valores = [];
        $faltantes = [];
        $invalidas = [];
        $usadas = [];
        $ausentes = [];
        foreach ($contrato['variaveis'] as $variavel) {
            $nome = (string) $variavel['nome'];
            $bruto = self::bruto($entrada, $nome);
            $exige = $variavel['obrigatoria'] && ($destaEtapa === null || in_array($nome, $destaEtapa, true));
            if (self::vazio($bruto)) {
                if ($exige) {
                    $faltantes[] = ['nome' => $nome, 'sentido' => (string) $variavel['sentido']];
                } elseif ($destaEtapa === null || !$variavel['obrigatoria'] || in_array($nome, $destaEtapa, true)) {
                    if (!$variavel['obrigatoria']) {
                        $ausentes[] = $nome;
                    }
                }
                continue;
            }
            $lido = self::normalizar((string) $variavel['tipo'], $bruto);
            if (!$lido['ok']) {
                $invalidas[] = ['nome' => $nome, 'sentido' => (string) $variavel['sentido'], 'motivo' => $lido['motivo']];
                continue;
            }
            $valores[$nome] = $lido['valor'];
            $usadas[] = $nome;
        }
        $partes = [];
        foreach ($faltantes as $faltante) {
            $partes[] = 'Falta a variável obrigatória ' . $faltante['nome'] . ': ' . $faltante['sentido'];
        }
        foreach ($invalidas as $invalida) {
            $partes[] = 'A variável ' . $invalida['nome'] . ' não pôde ser lida (' . $invalida['motivo'] . '). ' . $invalida['sentido'];
        }
        return [
            'ok' => $faltantes === [] && $invalidas === [],
            'valores' => $valores,
            'faltantes' => $faltantes,
            'invalidas' => $invalidas,
            'usadas' => $usadas,
            'ausentes' => $ausentes,
            'mensagem' => implode(' ', $partes),
        ];
    }

    /** @param array<string, mixed> $entrada */
    private static function bruto(array $entrada, string $nome): mixed
    {
        foreach (array_merge([$nome], self::APELIDOS[$nome] ?? []) as $chave) {
            if (!array_key_exists($chave, $entrada)) {
                continue;
            }
            if (!self::vazio($entrada[$chave])) {
                return $entrada[$chave];
            }
        }
        return null;
    }

    private static function vazio(mixed $valor): bool
    {
        if ($valor === null) {
            return true;
        }
        if (is_string($valor) && trim($valor) === '') {
            return true;
        }
        return is_array($valor) && $valor === [];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function normalizar(string $tipo, mixed $bruto): array
    {
        return match ($tipo) {
            'codigo_ncm' => self::codigo($bruto, 8),
            'codigo' => self::codigo($bruto, null),
            'texto' => self::texto($bruto),
            'data' => self::data($bruto),
            'ano' => self::ano($bruto),
            'dinheiro' => self::decimal($bruto, 'reais'),
            'percentual' => self::decimal($bruto, 'percentual'),
            'centavos' => self::centavos($bruto),
            'inteiro' => self::inteiro($bruto),
            'regime' => self::regime($bruto),
            'status' => self::status($bruto),
            'decimal' => self::decimal($bruto, 'número'),
            'escolha' => self::escolha($bruto),
            default => ['ok' => false, 'valor' => null, 'motivo' => 'tipo ' . $tipo . ' desconhecido'],
        };
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function codigo(mixed $bruto, ?int $tamanho): array
    {
        $digitos = Format::digitos((string) $bruto);
        if ($digitos === '') {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não há dígitos'];
        }
        if ($tamanho !== null && strlen($digitos) !== $tamanho) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'precisa ter ' . $tamanho . ' dígitos'];
        }
        return ['ok' => true, 'valor' => $digitos, 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function texto(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é texto'];
        }
        $texto = trim((string) $bruto);
        if ($texto === '') {
            return ['ok' => false, 'valor' => null, 'motivo' => 'texto vazio'];
        }
        return ['ok' => true, 'valor' => $texto, 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function data(mixed $bruto): array
    {
        $texto = trim((string) $bruto);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m) === 1) {
            $ano = (int) $m[1];
            $mes = (int) $m[2];
            $dia = (int) $m[3];
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $texto, $m) === 1) {
            $dia = (int) $m[1];
            $mes = (int) $m[2];
            $ano = (int) $m[3];
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $texto, $m) === 1) {
            $ano = (int) $m[1];
            $mes = (int) $m[2];
            $dia = (int) $m[3];
        } else {
            return ['ok' => false, 'valor' => null, 'motivo' => 'use AAAA-MM-DD'];
        }
        if (!checkdate($mes, $dia, $ano)) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'data inexistente'];
        }
        return ['ok' => true, 'valor' => sprintf('%04d-%02d-%02d', $ano, $mes, $dia), 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function ano(mixed $bruto): array
    {
        $inteiro = self::inteiro($bruto);
        if (!$inteiro['ok']) {
            return $inteiro;
        }
        $ano = (int) $inteiro['valor'];
        if ($ano < 2000 || $ano > 2100) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'ano fora de 2000 a 2100'];
        }
        return ['ok' => true, 'valor' => $ano, 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function inteiro(mixed $bruto): array
    {
        if (is_int($bruto)) {
            return ['ok' => true, 'valor' => $bruto, 'motivo' => ''];
        }
        if (is_string($bruto) && preg_match('/^-?\d+$/', trim($bruto)) === 1) {
            return ['ok' => true, 'valor' => (int) trim($bruto), 'motivo' => ''];
        }
        return ['ok' => false, 'valor' => null, 'motivo' => 'não é inteiro'];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function centavos(mixed $bruto): array
    {
        if (is_int($bruto)) {
            return ['ok' => true, 'valor' => $bruto, 'motivo' => ''];
        }
        if (is_string($bruto) && preg_match('/^-?\d+$/', trim($bruto)) === 1) {
            return ['ok' => true, 'valor' => (int) trim($bruto), 'motivo' => ''];
        }
        if (!is_string($bruto) && !is_int($bruto)) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é valor em centavos'];
        }
        try {
            return ['ok' => true, 'valor' => Format::parseMoney((string) $bruto), 'motivo' => ''];
        } catch (\Throwable) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é valor em centavos'];
        }
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function decimal(mixed $bruto, string $rotulo): array
    {
        if (is_int($bruto)) {
            $bruto = (string) $bruto;
        }
        if (!is_string($bruto)) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é ' . $rotulo];
        }
        $texto = trim(str_replace(['R$', 'r$', '%', ' '], '', $bruto));
        if ($texto === '' || preg_match('/\d/', $texto) !== 1) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é ' . $rotulo];
        }
        try {
            return ['ok' => true, 'valor' => Money::ofCents(Format::parseMoney($texto))->toDecimal(), 'motivo' => ''];
        } catch (\Throwable) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'não é ' . $rotulo];
        }
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function regime(mixed $bruto): array
    {
        $texto = mb_strtolower(trim((string) $bruto));
        $mapa = [
            'simples' => 'simples',
            'mei' => 'mei',
            'presumido' => 'presumido',
            'lucro presumido' => 'presumido',
            'real' => 'real',
            'lucro real' => 'real',
        ];
        if (!isset($mapa[$texto])) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'use simples, mei, presumido ou real'];
        }
        return ['ok' => true, 'valor' => $mapa[$texto], 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function status(mixed $bruto): array
    {
        $texto = Format::texto((string) $bruto);
        if (!in_array($texto, ['conforme', 'alerta', 'ambiguo', 'rejeitado'], true)) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'use conforme, alerta, ambíguo ou rejeitado'];
        }
        return ['ok' => true, 'valor' => $texto, 'motivo' => ''];
    }

    /** @return array{ok:bool,valor:mixed,motivo:string} */
    private static function escolha(mixed $bruto): array
    {
        $texto = mb_strtolower(trim((string) $bruto));
        $mapa = [
            'unificado' => 'unificado',
            'puro' => 'unificado',
            'hibrido' => 'hibrido',
            'híbrido' => 'hibrido',
            'regular' => 'hibrido',
            'regime regular' => 'hibrido',
        ];
        if (!isset($mapa[$texto])) {
            return ['ok' => false, 'valor' => null, 'motivo' => 'use unificado ou híbrido'];
        }
        return ['ok' => true, 'valor' => $mapa[$texto], 'motivo' => ''];
    }
}
