<?php

declare(strict_types=1);

namespace App\Fiscal\Validators;

use App\Core\Format;
use App\Core\Money;
use App\Core\NcmNotFoundException;

final class NcmCorrelationValidator
{
    /** @param list<array<string, mixed>> $regras */
    public function __construct(
        private readonly array $regras,
        private readonly CclassTribValidator $cclass,
    ) {
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function validar(array $item, string $competencia): array
    {
        $ncm = Format::digitos((string) ($item['ncm'] ?? ''));
        $descricao = Format::texto((string) ($item['descricao'] ?? ''));
        if ($ncm === '') {
            throw new NcmNotFoundException('Item sem NCM: ' . ($item['descricao'] ?? 'sem descrição'));
        }
        if (strlen($ncm) !== 8) {
            return $this->resultado('rejeitado', null, [
                $this->alerta('bloqueio', 'NCM_INVALIDO', 'O NCM ' . $ncm . ' não tem 8 dígitos.'),
            ], 'O NCM precisa ter 8 dígitos para a correlação da reforma.');
        }
        $doNcm = [];
        foreach ($this->regras as $regra) {
            if (!$this->ncmCasa($ncm, $regra) || !$this->vigente($regra, $competencia)) {
                continue;
            }
            $doNcm[] = $regra;
        }
        $comPalavra = array_values(array_filter($doNcm, fn (array $regra): bool => $this->palavras($regra, 'palavras_incluir') !== []));
        $genericas = array_values(array_filter($doNcm, fn (array $regra): bool => $this->palavras($regra, 'palavras_incluir') === []));
        $casaram = array_values(array_filter($comPalavra, fn (array $regra): bool => $this->descricaoCasa($descricao, $regra)));
        if (count($casaram) > 1 && !$this->concordam($casaram)) {
            return $this->ambiguo($casaram, 'A descrição aciona mais de uma regra com benefícios diferentes.');
        }
        if ($casaram === [] && $comPalavra !== []) {
            return $this->ambiguo($comPalavra, 'A descrição não permite separar as hipóteses cadastradas para o NCM ' . $ncm . '.');
        }
        $regra = $casaram[0] ?? $this->melhorGenerica($genericas, $ncm);
        $alertas = [];
        $cstEsperado = $regra['cst'] ?? null;
        $reducaoEsperada = $regra['reducao'] ?? null;
        $cclassEsperado = $regra['cclass_trib'] ?? null;
        $fundamento = $regra['fundamento'] ?? '';
        if ($regra === null) {
            $reducaoDeclarada = $this->maiorReducao($item);
            if (bccomp($reducaoDeclarada, '0', 2) > 0) {
                $alertas[] = $this->alerta('alerta', 'BENEFICIO_SEM_REGRA', 'A nota declara redução de ' . $reducaoDeclarada . '% e a base local não tem benefício para o NCM ' . $ncm . '.');
            } else {
                $alertas[] = $this->alerta('info', 'SEM_BENEFICIO_CADASTRADO', 'Nenhuma regra de benefício cadastrada para o NCM ' . $ncm . '. A tributação integral permanece como premissa até a importação da tabela oficial.');
            }
            $cstEsperado = bccomp($reducaoDeclarada, '0', 2) > 0 ? null : '000';
            $reducaoEsperada = bccomp($reducaoDeclarada, '0', 2) > 0 ? null : '0';
            $fundamento = 'Sem regra local de benefício.';
        } else {
            $alertas = array_merge($alertas, $this->compararDeclarado($item, $regra));
        }
        $cclass = $this->cclass->avaliar(
            (string) ($item['cclass_trib'] ?? ''),
            (string) ($item['cst'] ?? ''),
            (string) ($item['reducao_ibs'] ?? ''),
            (string) ($item['reducao_cbs'] ?? ''),
            $competencia,
        );
        $alertas = array_merge($alertas, $cclass['alertas']);
        if ($cclassEsperado === null && $cclass['cclass'] !== null) {
            $linha = $cclass['cclass'];
            $compativel = $regra === null
                || ((string) $linha['cst'] === (string) ($regra['cst'] ?? '')
                    && bccomp((string) $linha['reducao_ibs'], Money::rate((string) ($regra['reducao'] ?? '0')), 2) === 0
                    && bccomp((string) $linha['reducao_cbs'], Money::rate((string) ($regra['reducao'] ?? '0')), 2) === 0);
            if ($compativel) {
                $cclassEsperado = $linha['codigo'];
            } else {
                $alertas[] = $this->alerta(
                    'bloqueio',
                    'CCLASS_INCOMPATIVEL',
                    'O cClassTrib ' . $linha['codigo'] . ' é tributação de CST ' . $linha['cst'] . ' e redução ' . $linha['reducao_ibs'] . '%. Ele não cobre a regra deste NCM, que pede CST ' . $regra['cst'] . ' e redução de ' . $regra['reducao'] . '%.',
                );
            }
        }
        $status = $this->status($alertas);
        return $this->resultado($status, $regra, $alertas, $this->diagnostico($status, $fundamento, $alertas), [
            'cst_esperado' => $cstEsperado,
            'cclass_esperado' => $cclassEsperado,
            'reducao_esperada' => $reducaoEsperada,
            'fundamento' => $fundamento,
        ]);
    }

    /** @param array<string, mixed> $regra */
    private function ncmCasa(string $ncm, array $regra): bool
    {
        $codigo = Format::digitos((string) $regra['ncm']);
        return ($regra['match_tipo'] ?? 'prefixo') === 'exato' ? $ncm === $codigo : str_starts_with($ncm, $codigo);
    }

    /** @param array<string, mixed> $regra */
    private function vigente(array $regra, string $competencia): bool
    {
        $inicio = (string) ($regra['vigencia_inicio'] ?? '1900-01-01');
        $fim = (string) ($regra['vigencia_fim'] ?? '');
        return $competencia >= $inicio && ($fim === '' || $competencia <= $fim);
    }

    /** @param array<string, mixed> $regra @return list<string> */
    private function palavras(array $regra, string $campo): array
    {
        $valor = $regra[$campo] ?? [];
        if (is_string($valor)) {
            $valor = json_decode($valor, true) ?: [];
        }
        return array_values(array_filter(array_map(fn ($p): string => Format::texto((string) $p), is_array($valor) ? $valor : [])));
    }

    /** @param array<string, mixed> $regra */
    private function descricaoCasa(string $descricao, array $regra): bool
    {
        foreach ($this->palavras($regra, 'palavras_incluir') as $palavra) {
            if (!str_contains($descricao, $palavra)) {
                continue;
            }
            $inclui = true;
            foreach ($this->palavras($regra, 'palavras_excluir') as $exclusao) {
                if (str_contains($descricao, $exclusao)) {
                    return false;
                }
            }
            return $inclui;
        }
        return false;
    }

    /** @param list<array<string, mixed>> $regras */
    private function concordam(array $regras): bool
    {
        $assinatura = null;
        foreach ($regras as $regra) {
            $atual = $regra['reducao'] . '|' . $regra['cst'];
            if ($assinatura !== null && $assinatura !== $atual) {
                return false;
            }
            $assinatura = $atual;
        }
        return true;
    }

    /** @param list<array<string, mixed>> $regras @return ?array<string, mixed> */
    private function melhorGenerica(array $regras, string $ncm): ?array
    {
        $melhor = null;
        $tamanho = -1;
        foreach ($regras as $regra) {
            $codigo = Format::digitos((string) $regra['ncm']);
            if (strlen($codigo) > $tamanho) {
                $melhor = $regra;
                $tamanho = strlen($codigo);
            }
        }
        return $melhor;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $regra @return list<array{severidade:string,codigo:string,mensagem:string}> */
    private function compararDeclarado(array $item, array $regra): array
    {
        $alertas = [];
        $esperada = Money::rate((string) $regra['reducao']);
        foreach (['IBS' => 'reducao_ibs', 'CBS' => 'reducao_cbs'] as $tributo => $campo) {
            $declarada = trim((string) ($item[$campo] ?? ''));
            if ($declarada === '') {
                continue;
            }
            if (bccomp(str_replace(',', '.', $declarada), $esperada, 2) !== 0) {
                $alertas[] = $this->alerta('bloqueio', 'REDUCAO_DIVERGENTE', 'A redução de ' . $tributo . ' declarada (' . $declarada . '%) não é a redução de ' . $regra['reducao'] . '% prevista para esta descrição. Fundamento: ' . $regra['fundamento'] . '.');
            }
        }
        $cst = (string) ($item['cst'] ?? '');
        if ($cst !== '' && $cst !== (string) $regra['cst']) {
            $alertas[] = $this->alerta('bloqueio', 'CST_DIVERGENTE', 'O CST ' . $cst . ' não é o CST ' . $regra['cst'] . ' esperado para este benefício.');
        }
        $cclass = (string) ($item['cclass_trib'] ?? '');
        if ($cclass !== '' && !empty($regra['cclass_trib']) && $cclass !== (string) $regra['cclass_trib']) {
            $alertas[] = $this->alerta('bloqueio', 'CCLASS_DIVERGENTE', 'O cClassTrib ' . $cclass . ' difere do código ' . $regra['cclass_trib'] . ' vinculado à regra do NCM.');
        }
        if ($alertas === []) {
            $alertas[] = $this->alerta('info', 'REGRA_CONFIRMADA', (string) $regra['fundamento']);
        }
        return $alertas;
    }

    /** @param array<string, mixed> $item */
    private function maiorReducao(array $item): string
    {
        $valores = [];
        foreach (['reducao_ibs', 'reducao_cbs'] as $campo) {
            $valor = trim((string) ($item[$campo] ?? ''));
            if ($valor !== '') {
                $valores[] = str_replace(',', '.', $valor);
            }
        }
        if ($valores === []) {
            return '0';
        }
        $maior = $valores[0];
        foreach ($valores as $valor) {
            if (bccomp($valor, $maior, 2) > 0) {
                $maior = $valor;
            }
        }
        return $maior;
    }

    /** @param list<array<string, mixed>> $regras @return array<string, mixed> */
    private function ambiguo(array $regras, string $motivo): array
    {
        $hipoteses = [];
        foreach ($regras as $regra) {
            $hipoteses[] = $regra['descricao'] . ' — redução de ' . $regra['reducao'] . '% (' . $regra['fundamento'] . ')';
        }
        return $this->resultado('ambiguo', null, [
            $this->alerta('bloqueio', 'DESCRICAO_AMBIGUA', $motivo . ' Hipóteses: ' . implode(' | ', $hipoteses)),
        ], $motivo, ['hipoteses' => $hipoteses, 'fundamento' => 'Classificação pendente de decisão do contador.']);
    }

    /** @param list<array{severidade:string,codigo:string,mensagem:string}> $alertas */
    private function status(array $alertas): string
    {
        $temBloqueio = false;
        $temAlerta = false;
        foreach ($alertas as $alerta) {
            if ($alerta['codigo'] === 'DESCRICAO_AMBIGUA') {
                return 'ambiguo';
            }
            if ($alerta['severidade'] === 'bloqueio') {
                $temBloqueio = true;
            } elseif ($alerta['severidade'] === 'alerta') {
                $temAlerta = true;
            }
        }
        if ($temBloqueio) {
            return 'rejeitado';
        }
        return $temAlerta ? 'alerta' : 'conforme';
    }

    /** @param list<array{severidade:string,codigo:string,mensagem:string}> $alertas */
    private function diagnostico(string $status, string $fundamento, array $alertas): string
    {
        if ($status === 'conforme') {
            return $fundamento !== '' ? $fundamento : 'Item em conformidade com as regras carregadas.';
        }
        foreach ($alertas as $alerta) {
            if ($alerta['severidade'] !== 'info') {
                return $alerta['mensagem'];
            }
        }
        return $fundamento;
    }

    /** @param ?array<string, mixed> $regra @param list<array{severidade:string,codigo:string,mensagem:string}> $alertas @param array<string, mixed> $extra
     *  @return array<string, mixed>
     */
    private function resultado(string $status, ?array $regra, array $alertas, string $diagnostico, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'cst_esperado' => $regra['cst'] ?? null,
            'cclass_esperado' => $regra['cclass_trib'] ?? null,
            'reducao_esperada' => $regra['reducao'] ?? null,
            'fundamento' => $regra['fundamento'] ?? '',
            'diagnostico' => $diagnostico,
            'alertas' => $alertas,
            'hipoteses' => [],
        ], $extra);
    }

    /** @return array{severidade:string,codigo:string,mensagem:string} */
    private function alerta(string $severidade, string $codigo, string $mensagem): array
    {
        return ['severidade' => $severidade, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
