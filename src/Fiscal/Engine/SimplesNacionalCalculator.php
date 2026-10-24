<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Money;
use App\Core\SimplesLimitExceededException;

final class SimplesNacionalCalculator
{
    /**
     * Tabelas da LC 123/2006. A partilha de cada faixa soma 100.
     * Quando o tributo sai da guia, ele entra em por_fora e a partilha correspondente fica zerada.
     *
     * @var array<string, list<array{teto:string, aliquota:string, deducao:string, partilha:array<string,string>, por_fora:list<string>}>>
     */
    private const ANEXOS = [
        'I' => [
            ['teto' => '180000.00', 'aliquota' => '4.00', 'deducao' => '0.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '12.74', 'PIS' => '2.76', 'CPP' => '41.50', 'ICMS' => '34.00']],
            ['teto' => '360000.00', 'aliquota' => '7.30', 'deducao' => '5940.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '12.74', 'PIS' => '2.76', 'CPP' => '41.50', 'ICMS' => '34.00']],
            ['teto' => '720000.00', 'aliquota' => '9.50', 'deducao' => '13860.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '12.74', 'PIS' => '2.76', 'CPP' => '42.00', 'ICMS' => '33.50']],
            ['teto' => '1800000.00', 'aliquota' => '10.70', 'deducao' => '22500.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '12.74', 'PIS' => '2.76', 'CPP' => '42.00', 'ICMS' => '33.50']],
            ['teto' => '3600000.00', 'aliquota' => '14.30', 'deducao' => '87300.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '12.74', 'PIS' => '2.76', 'CPP' => '42.00', 'ICMS' => '33.50']],
            ['teto' => '4800000.00', 'aliquota' => '19.00', 'deducao' => '378000.00', 'por_fora' => ['ICMS'], 'partilha' => ['IRPJ' => '13.50', 'CSLL' => '10.00', 'COFINS' => '28.27', 'PIS' => '6.13', 'CPP' => '42.10', 'ICMS' => '0.00']],
        ],
        'II' => [
            ['teto' => '180000.00', 'aliquota' => '4.50', 'deducao' => '0.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '11.51', 'PIS' => '2.49', 'CPP' => '37.50', 'IPI' => '7.50', 'ICMS' => '32.00']],
            ['teto' => '360000.00', 'aliquota' => '7.80', 'deducao' => '5940.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '11.51', 'PIS' => '2.49', 'CPP' => '37.50', 'IPI' => '7.50', 'ICMS' => '32.00']],
            ['teto' => '720000.00', 'aliquota' => '10.00', 'deducao' => '13860.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '11.51', 'PIS' => '2.49', 'CPP' => '37.50', 'IPI' => '7.50', 'ICMS' => '32.00']],
            ['teto' => '1800000.00', 'aliquota' => '11.20', 'deducao' => '22500.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '11.51', 'PIS' => '2.49', 'CPP' => '37.50', 'IPI' => '7.50', 'ICMS' => '32.00']],
            ['teto' => '3600000.00', 'aliquota' => '14.70', 'deducao' => '85500.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '5.50', 'CSLL' => '3.50', 'COFINS' => '11.51', 'PIS' => '2.49', 'CPP' => '37.50', 'IPI' => '7.50', 'ICMS' => '32.00']],
            ['teto' => '4800000.00', 'aliquota' => '30.00', 'deducao' => '720000.00', 'por_fora' => ['ICMS'], 'partilha' => ['IRPJ' => '8.50', 'CSLL' => '7.50', 'COFINS' => '20.96', 'PIS' => '4.54', 'CPP' => '23.50', 'IPI' => '35.00', 'ICMS' => '0.00']],
        ],
        'III' => [
            ['teto' => '180000.00', 'aliquota' => '6.00', 'deducao' => '0.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '4.00', 'CSLL' => '3.50', 'COFINS' => '12.82', 'PIS' => '2.78', 'CPP' => '43.40', 'ISS' => '33.50']],
            ['teto' => '360000.00', 'aliquota' => '11.20', 'deducao' => '9360.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '4.00', 'CSLL' => '3.50', 'COFINS' => '14.05', 'PIS' => '3.05', 'CPP' => '43.40', 'ISS' => '32.00']],
            ['teto' => '720000.00', 'aliquota' => '13.50', 'deducao' => '17640.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '4.00', 'CSLL' => '3.50', 'COFINS' => '13.64', 'PIS' => '2.96', 'CPP' => '43.40', 'ISS' => '32.50']],
            ['teto' => '1800000.00', 'aliquota' => '16.00', 'deducao' => '35640.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '4.00', 'CSLL' => '3.50', 'COFINS' => '13.64', 'PIS' => '2.96', 'CPP' => '43.40', 'ISS' => '32.50']],
            ['teto' => '3600000.00', 'aliquota' => '21.00', 'deducao' => '125640.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '4.00', 'CSLL' => '3.50', 'COFINS' => '12.82', 'PIS' => '2.78', 'CPP' => '43.40', 'ISS' => '33.50']],
            ['teto' => '4800000.00', 'aliquota' => '33.00', 'deducao' => '648000.00', 'por_fora' => ['ISS'], 'partilha' => ['IRPJ' => '35.00', 'CSLL' => '15.00', 'COFINS' => '16.03', 'PIS' => '3.47', 'CPP' => '30.50', 'ISS' => '0.00']],
        ],
        'IV' => [
            ['teto' => '180000.00', 'aliquota' => '4.50', 'deducao' => '0.00', 'por_fora' => ['CPP'], 'partilha' => ['IRPJ' => '18.80', 'CSLL' => '15.20', 'COFINS' => '17.67', 'PIS' => '3.83', 'ISS' => '44.50']],
            ['teto' => '360000.00', 'aliquota' => '9.00', 'deducao' => '8100.00', 'por_fora' => ['CPP'], 'partilha' => ['IRPJ' => '19.80', 'CSLL' => '15.20', 'COFINS' => '20.55', 'PIS' => '4.45', 'ISS' => '40.00']],
            ['teto' => '720000.00', 'aliquota' => '10.20', 'deducao' => '12420.00', 'por_fora' => ['CPP'], 'partilha' => ['IRPJ' => '20.80', 'CSLL' => '15.20', 'COFINS' => '19.73', 'PIS' => '4.27', 'ISS' => '40.00']],
            ['teto' => '1800000.00', 'aliquota' => '14.00', 'deducao' => '39780.00', 'por_fora' => ['CPP'], 'partilha' => ['IRPJ' => '17.80', 'CSLL' => '19.20', 'COFINS' => '18.90', 'PIS' => '4.10', 'ISS' => '40.00']],
            ['teto' => '3600000.00', 'aliquota' => '22.00', 'deducao' => '183780.00', 'por_fora' => ['CPP'], 'partilha' => ['IRPJ' => '18.80', 'CSLL' => '19.20', 'COFINS' => '18.08', 'PIS' => '3.92', 'ISS' => '40.00']],
            ['teto' => '4800000.00', 'aliquota' => '33.00', 'deducao' => '828000.00', 'por_fora' => ['CPP', 'ISS'], 'partilha' => ['IRPJ' => '53.50', 'CSLL' => '21.50', 'COFINS' => '20.52', 'PIS' => '4.48', 'ISS' => '0.00']],
        ],
        'V' => [
            ['teto' => '180000.00', 'aliquota' => '15.50', 'deducao' => '0.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '25.00', 'CSLL' => '15.00', 'COFINS' => '14.10', 'PIS' => '3.05', 'CPP' => '28.85', 'ISS' => '14.00']],
            ['teto' => '360000.00', 'aliquota' => '18.00', 'deducao' => '4500.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '23.00', 'CSLL' => '15.00', 'COFINS' => '14.10', 'PIS' => '3.05', 'CPP' => '27.85', 'ISS' => '17.00']],
            ['teto' => '720000.00', 'aliquota' => '19.50', 'deducao' => '9900.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '24.00', 'CSLL' => '15.00', 'COFINS' => '14.92', 'PIS' => '3.23', 'CPP' => '23.85', 'ISS' => '19.00']],
            ['teto' => '1800000.00', 'aliquota' => '20.50', 'deducao' => '17100.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '21.00', 'CSLL' => '15.00', 'COFINS' => '15.74', 'PIS' => '3.41', 'CPP' => '23.85', 'ISS' => '21.00']],
            ['teto' => '3600000.00', 'aliquota' => '23.00', 'deducao' => '62100.00', 'por_fora' => [], 'partilha' => ['IRPJ' => '23.00', 'CSLL' => '12.50', 'COFINS' => '14.10', 'PIS' => '3.05', 'CPP' => '23.85', 'ISS' => '23.50']],
            ['teto' => '4800000.00', 'aliquota' => '30.50', 'deducao' => '540000.00', 'por_fora' => ['ISS'], 'partilha' => ['IRPJ' => '35.00', 'CSLL' => '15.50', 'COFINS' => '16.44', 'PIS' => '3.56', 'CPP' => '29.50', 'ISS' => '0.00']],
        ],
    ];

    /** @return array<string, mixed> */
    public function calcular(string $anexo, string $rbt12, string $receitaMes, ?string $folha12 = null): array
    {
        $anexo = $this->resolverAnexo($anexo, $rbt12, $folha12);
        $fator = $anexo['fator_r'];
        $codigo = $anexo['anexo'];
        $rbt = Money::rate($rbt12);
        if (bccomp($rbt, '0', 2) <= 0) {
            throw new \InvalidArgumentException('A receita bruta de 12 meses precisa ser maior que zero.');
        }
        if (bccomp($rbt, '4800000', 2) > 0) {
            throw new SimplesLimitExceededException('A receita bruta supera o teto de R$ 4.800.000,00 do Simples Nacional.');
        }
        $faixa = null;
        $numero = 0;
        foreach (self::ANEXOS[$codigo] as $indice => $candidata) {
            $numero = $indice + 1;
            if (bccomp($rbt, $candidata['teto'], 2) <= 0) {
                $faixa = $candidata;
                break;
            }
        }
        if ($faixa === null) {
            throw new SimplesLimitExceededException('Receita fora das faixas do Simples Nacional.');
        }
        $parcela = bcsub(bcmul($rbt, bcdiv($faixa['aliquota'], '100', 12), 12), $faixa['deducao'], 12);
        if (bccomp($parcela, '0', 8) < 0) {
            $parcela = '0';
        }
        $efetiva = bcmul(bcdiv($parcela, $rbt, 12), '100', 8);
        $das = Money::fromDecimal(Money::rate($receitaMes))->percent($efetiva);
        $partes = [];
        foreach ($faixa['partilha'] as $tributo => $percentual) {
            $partes[$tributo] = $das->percent($percentual)->cents();
        }
        $diferenca = $das->cents() - array_sum($partes);
        if ($diferenca !== 0 && $partes !== []) {
            $maior = array_key_first($partes);
            foreach ($partes as $tributo => $valor) {
                if ($valor > $partes[$maior]) {
                    $maior = $tributo;
                }
            }
            $partes[$maior] += $diferenca;
        }
        return [
            'anexo' => $codigo,
            'faixa' => $numero,
            'aliquota_nominal' => $faixa['aliquota'],
            'deducao' => $faixa['deducao'],
            'aliquota_efetiva' => rtrim(rtrim(Money::roundHalfUp($efetiva, 4), '0'), '.'),
            'das_centavos' => $das->cents(),
            'partilha_centavos' => $partes,
            'por_fora' => $faixa['por_fora'],
            'fator_r' => $fator,
        ];
    }

    /** @return array{anexo:string, fator_r:?string} */
    private function resolverAnexo(string $anexo, string $rbt12, ?string $folha12): array
    {
        $anexo = strtoupper(trim($anexo));
        if ($anexo === 'AUTO') {
            if ($folha12 === null || trim($folha12) === '') {
                throw new \InvalidArgumentException('Informe a folha de salários de 12 meses para o Fator R.');
            }
            $rbt = Money::rate($rbt12);
            if (bccomp($rbt, '0', 2) <= 0) {
                throw new \InvalidArgumentException('A receita bruta de 12 meses precisa ser maior que zero.');
            }
            $fator = bcdiv(Money::rate($folha12), $rbt, 8);
            return ['anexo' => bccomp($fator, '0.28', 8) >= 0 ? 'III' : 'V', 'fator_r' => $fator];
        }
        if (!isset(self::ANEXOS[$anexo])) {
            throw new \InvalidArgumentException('Anexo do Simples inválido. Use I, II, III, IV, V ou AUTO.');
        }
        return ['anexo' => $anexo, 'fator_r' => null];
    }
}
