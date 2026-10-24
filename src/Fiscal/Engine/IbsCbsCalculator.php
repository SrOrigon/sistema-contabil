<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Money;

final class IbsCbsCalculator
{
    /** @return array{aliquota_ibs_efetiva:string, aliquota_cbs_efetiva:string, ibs_centavos:int, cbs_centavos:int, total_centavos:int} */
    public function calcular(string $base, string $aliquotaIbs, string $aliquotaCbs, string $reducaoIbs = '0', string $reducaoCbs = '0'): array
    {
        $ibsEfetiva = $this->efetiva($aliquotaIbs, $reducaoIbs);
        $cbsEfetiva = $this->efetiva($aliquotaCbs, $reducaoCbs);
        $valor = Money::fromDecimal(Money::rate($base));
        $ibs = $valor->percent($ibsEfetiva);
        $cbs = $valor->percent($cbsEfetiva);
        return [
            'aliquota_ibs_efetiva' => $ibsEfetiva,
            'aliquota_cbs_efetiva' => $cbsEfetiva,
            'ibs_centavos' => $ibs->cents(),
            'cbs_centavos' => $cbs->cents(),
            'total_centavos' => $ibs->cents() + $cbs->cents(),
        ];
    }

    public function efetiva(string $aliquota, string $reducao): string
    {
        $aliquota = Money::rate($aliquota);
        $reducao = Money::rate($reducao);
        if (bccomp($reducao, '0', 4) < 0 || bccomp($reducao, '100', 4) > 0) {
            throw new \InvalidArgumentException('A redução precisa ficar entre 0 e 100.');
        }
        $fator = bcsub('1', bcdiv($reducao, '100', 10), 10);
        return rtrim(rtrim(bcmul($aliquota, $fator, 6), '0'), '.') ?: '0';
    }
}
