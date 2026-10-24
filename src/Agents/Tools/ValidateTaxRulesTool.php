<?php

declare(strict_types=1);

namespace App\Agents\Tools;

use App\Agents\Contracts\ToolInterface;
use App\Core\Database;
use App\Core\Format;
use App\Core\NcmNotFoundException;
use App\Fiscal\Engine\IbsCbsCalculator;
use App\Fiscal\Validators\CclassTribValidator;
use App\Fiscal\Validators\NcmCorrelationValidator;

final class ValidateTaxRulesTool implements ToolInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly IbsCbsCalculator $calculadora = new IbsCbsCalculator(),
    ) {
    }

    public function name(): string
    {
        return 'validar_regras_tributarias_item';
    }

    public function description(): string
    {
        return 'Executa a validação determinística de NCM, CST, cClassTrib e redução, e calcula IBS/CBS em centavos. Não aceite outro número além do que esta ferramenta devolver.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'ncm' => ['type' => 'STRING', 'description' => 'NCM com 8 dígitos'],
                'descricao' => ['type' => 'STRING', 'description' => 'Descrição livre do item'],
                'cst' => ['type' => 'STRING'],
                'cClassTrib' => ['type' => 'STRING'],
                'reducao_ibs' => ['type' => 'STRING'],
                'reducao_cbs' => ['type' => 'STRING'],
                'base' => ['type' => 'STRING', 'description' => 'Base de cálculo em reais, com ponto decimal'],
                'competencia' => ['type' => 'STRING', 'description' => 'Data AAAA-MM-DD'],
                'aliquota_ibs' => ['type' => 'STRING'],
                'aliquota_cbs' => ['type' => 'STRING'],
            ],
            'required' => ['ncm', 'descricao', 'competencia'],
        ];
    }

    public function execute(array $argumentos): array
    {
        $ncm = Format::digitos((string) ($argumentos['ncm'] ?? ''));
        if ($ncm === '') {
            throw new NcmNotFoundException('A ferramenta foi chamada sem NCM.');
        }
        $competencia = (string) ($argumentos['competencia'] ?? date('Y-m-d'));
        $validador = new NcmCorrelationValidator(
            $this->db->all('SELECT * FROM regras_ncm'),
            new CclassTribValidator(
                $this->db->all('SELECT * FROM cclass_trib'),
                (($this->db->one('SELECT valor FROM configuracoes WHERE chave = :c', [':c' => 'tabelas.cclass_oficial_completa']) ?? ['valor' => '0'])['valor'] ?? '0') === '1',
            ),
        );
        try {
            $classificacao = $validador->validar([
                'ncm' => $ncm,
                'descricao' => (string) ($argumentos['descricao'] ?? ''),
                'cst' => (string) ($argumentos['cst'] ?? ''),
                'cclass_trib' => (string) ($argumentos['cClassTrib'] ?? ''),
                'reducao_ibs' => (string) ($argumentos['reducao_ibs'] ?? ''),
                'reducao_cbs' => (string) ($argumentos['reducao_cbs'] ?? ''),
            ], $competencia);
        } catch (NcmNotFoundException $erro) {
            return ['status' => 'rejeitado', 'diagnostico' => $erro->getMessage(), 'calculo' => null];
        }
        $calculo = null;
        $base = (string) ($argumentos['base'] ?? '');
        if ($base !== '' && isset($argumentos['aliquota_ibs'], $argumentos['aliquota_cbs'])) {
            $reducao = (string) ($classificacao['reducao_esperada'] ?? $argumentos['reducao_ibs'] ?? '0');
            $calculo = $this->calculadora->calcular($base, (string) $argumentos['aliquota_ibs'], (string) $argumentos['aliquota_cbs'], $reducao, $reducao);
        }
        return $classificacao + ['calculo' => $calculo];
    }
}
