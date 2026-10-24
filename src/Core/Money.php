<?php

declare(strict_types=1);

namespace App\Core;

final class Money
{
    private function __construct(private readonly int $cents)
    {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromDecimal(string $decimal): self
    {
        $decimal = trim($decimal);
        if ($decimal === '' || !preg_match('/^-?(?:\d+)(?:\.(\d+))?$/', $decimal)) {
            throw new \InvalidArgumentException('Valor monetário inválido.');
        }
        $negativo = str_starts_with($decimal, '-');
        $absoluto = ltrim($decimal, '-');
        $arredondado = self::roundHalfUp($absoluto, 2);
        $centavos = (int) str_replace('.', '', $arredondado);
        return new self($negativo ? -$centavos : $centavos);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function toDecimal(): string
    {
        $negativo = $this->cents < 0;
        $absoluto = (string) abs($this->cents);
        $absoluto = str_pad($absoluto, 3, '0', STR_PAD_LEFT);
        $texto = substr($absoluto, 0, -2) . '.' . substr($absoluto, -2);
        return $negativo ? '-' . $texto : $texto;
    }

    public function add(self $outro): self
    {
        return new self($this->cents + $outro->cents);
    }

    public function sub(self $outro): self
    {
        return new self($this->cents - $outro->cents);
    }

    public function percent(string $aliquota): self
    {
        $aliquota = self::rate($aliquota);
        $bruto = bcmul($this->toDecimal(), bcdiv($aliquota, '100', 12), 12);
        return self::fromDecimal(self::roundHalfUp($bruto, 2));
    }

    public static function rate(string $valor): string
    {
        $valor = trim(str_replace(',', '.', $valor));
        if ($valor === '' || !preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            throw new \InvalidArgumentException('Alíquota inválida.');
        }
        return bcadd($valor, '0', 8);
    }

    public static function roundHalfUp(string $numero, int $escala): string
    {
        $negativo = str_starts_with($numero, '-');
        $absoluto = ltrim($numero, '-');
        if (!str_contains($absoluto, '.')) {
            $absoluto .= '.';
        }
        [$inteiro, $fracao] = explode('.', $absoluto, 2);
        $fracao = str_pad($fracao, $escala + 1, '0');
        $mantido = substr($fracao, 0, $escala);
        $seguinte = (int) substr($fracao, $escala, 1);
        $resultado = ($mantido === '' ? $inteiro : $inteiro . '.' . $mantido);
        if ($escala === 0) {
            $resultado = $inteiro;
        }
        if ($seguinte >= 5) {
            $incremento = $escala === 0 ? '1' : '0.' . str_repeat('0', $escala - 1) . '1';
            $resultado = bcadd($resultado, $incremento, $escala);
        } elseif ($escala > 0) {
            $resultado = bcadd($resultado, '0', $escala);
        }
        if ($negativo && bccomp($resultado, '0', $escala) !== 0) {
            return '-' . $resultado;
        }
        return $resultado;
    }
}
