<?php

declare(strict_types=1);

namespace App\Fiscal\Validators;

use App\Core\Format;
use App\Core\NcmNotFoundException;

final class CclassTribValidator
{
    /** @param list<array<string, mixed>> $tabela */
    public function __construct(private readonly array $tabela, private readonly bool $tabelaOficialCompleta)
    {
    }

    /** @return array{alertas:list<array{severidade:string,codigo:string,mensagem:string}>, cclass:?array<string,mixed>} */
    public function avaliar(string $codigo, string $cst, string $reducaoIbs, string $reducaoCbs, string $competencia): array
    {
        $codigo = trim($codigo);
        $alertas = [];
        if ($codigo === '') {
            return ['alertas' => $alertas, 'cclass' => null];
        }
        $encontrado = null;
        foreach ($this->tabela as $linha) {
            if ((string) $linha['codigo'] !== $codigo) {
                continue;
            }
            if (!$this->vigente($linha, $competencia)) {
                continue;
            }
            $encontrado = $linha;
            break;
        }
        if ($encontrado === null) {
            $alertas[] = [
                'severidade' => $this->tabelaOficialCompleta ? 'bloqueio' : 'alerta',
                'codigo' => 'CCLASS_DESCONHECIDO',
                'mensagem' => 'O cClassTrib ' . $codigo . ' não consta da tabela carregada para a competência ' . Format::data($competencia) . '.',
            ];
            return ['alertas' => $alertas, 'cclass' => null];
        }
        if ($cst !== '' && $cst !== (string) $encontrado['cst']) {
            $alertas[] = [
                'severidade' => 'bloqueio',
                'codigo' => 'CST_CCLASS',
                'mensagem' => 'O CST ' . $cst . ' não corresponde ao cClassTrib ' . $codigo . ', cujo CST oficial é ' . $encontrado['cst'] . '.',
            ];
        }
        foreach (['IBS' => $reducaoIbs, 'CBS' => $reducaoCbs] as $tributo => $declarada) {
            if ($declarada === '') {
                continue;
            }
            $oficial = (string) ($tributo === 'IBS' ? $encontrado['reducao_ibs'] : $encontrado['reducao_cbs']);
            if (bccomp(Format::digitos($declarada) === '' ? '0' : str_replace(',', '.', $declarada), $oficial, 2) !== 0) {
                $alertas[] = [
                    'severidade' => 'bloqueio',
                    'codigo' => 'REDUCAO_CCLASS',
                    'mensagem' => 'A redução de ' . $tributo . ' informada (' . $declarada . '%) difere da redução do cClassTrib ' . $codigo . ' (' . $oficial . '%).',
                ];
            }
        }
        return ['alertas' => $alertas, 'cclass' => $encontrado];
    }

    /** @param array<string, mixed> $linha */
    private function vigente(array $linha, string $competencia): bool
    {
        $inicio = (string) ($linha['vigencia_inicio'] ?? '1900-01-01');
        $fim = (string) ($linha['vigencia_fim'] ?? '');
        return $competencia >= $inicio && ($fim === '' || $competencia <= $fim);
    }

    public static function exigirCodigo(string $codigo): void
    {
        if (trim($codigo) === '') {
            throw new NcmNotFoundException('A ferramenta recebeu um item sem cClassTrib para uma consulta que exige o código.');
        }
    }
}
