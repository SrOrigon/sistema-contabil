<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

/**
 * Calendário da opção do Simples pelo regime regular de IBS e CBS.
 * LC 123/2006, art. 13, §§ 9º e 10, com a redação da LC 227/2026:
 * setembro decide janeiro–junho; março decide julho–dezembro; a escolha é irretratável no semestre.
 * O primeiro semestre de 2027 foge dessa régua: o Manual da Receita Federal
 * fixa a janela de 01/09/2026 a 30/10/2026, e o comunicado da Receita de 01/09/2026
 * admite desistência até 30/11/2026.
 */
final class JanelaOpcaoHibrido
{
    /** @return array<string, mixed> */
    public static function decidir(string $hoje): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hoje)) {
            throw new \InvalidArgumentException('A data da janela precisa estar em AAAA-MM-DD.');
        }
        $ano = (int) substr($hoje, 0, 4);
        $todas = [];
        for ($candidata = $ano - 1; $candidata <= $ano + 2; $candidata++) {
            foreach ([1, 2] as $semestre) {
                $janela = self::semestre($candidata, $semestre);
                $janela['status'] = self::status($janela, $hoje);
                $todas[] = $janela;
            }
        }
        foreach (['aberta', 'so_desistencia', 'futura'] as $preferido) {
            foreach ($todas as $janela) {
                if ($janela['status'] === $preferido) {
                    return self::completar($janela, $todas);
                }
            }
        }
        $fechadas = array_values(array_filter($todas, static fn (array $janela): bool => $janela['status'] === 'fechada' && $janela['semestre_fim'] <= $hoje));
        $ultima = $fechadas === [] ? $todas[0] : $fechadas[array_key_last($fechadas)];
        return self::completar($ultima, $todas);
    }

    /** @return array<string, mixed> */
    private static function semestre(int $ano, int $qual): array
    {
        if ($qual === 1) {
            return [
                'semestre_inicio' => sprintf('%04d-01-01', $ano),
                'semestre_fim' => sprintf('%04d-06-30', $ano),
                'janela_inicio' => sprintf('%04d-09-01', $ano - 1),
                'janela_fim' => $ano === 2027 ? '2026-10-30' : sprintf('%04d-09-30', $ano - 1),
                'retratacao_ate' => $ano === 2027 ? '2026-11-30' : null,
                'fundamento' => $ano === 2027
                    ? 'LC 123/2006, art. 13, §§ 9º e 10, com a redação da LC 227/2026. Para o primeiro semestre de 2027, o Manual da Receita Federal abre a janela de 1º de setembro a 30 de outubro de 2026, e o comunicado da Receita de 1º de setembro de 2026 admite desistência até 30 de novembro de 2026. Sem manifestação, IBS e CBS permanecem no DAS.'
                    : 'LC 123/2006, art. 13, § 10, com a redação da LC 227/2026. A opção do semestre de janeiro a junho se exerce em setembro do ano anterior e é irretratável nesse semestre. Sem manifestação, IBS e CBS permanecem no DAS.',
            ];
        }
        return [
            'semestre_inicio' => sprintf('%04d-07-01', $ano),
            'semestre_fim' => sprintf('%04d-12-31', $ano),
            'janela_inicio' => sprintf('%04d-03-01', $ano),
            'janela_fim' => sprintf('%04d-03-31', $ano),
            'retratacao_ate' => null,
            'fundamento' => 'LC 123/2006, art. 13, § 10, com a redação da LC 227/2026. A opção do semestre de julho a dezembro se exerce em março do mesmo ano e é irretratável nesse semestre. Sem manifestação, IBS e CBS permanecem no DAS.',
        ];
    }

    /** @param array<string, mixed> $janela */
    private static function status(array $janela, string $hoje): string
    {
        if ($hoje < $janela['janela_inicio']) {
            return 'futura';
        }
        if ($hoje <= $janela['janela_fim']) {
            return 'aberta';
        }
        if ($janela['retratacao_ate'] !== null && $hoje <= $janela['retratacao_ate']) {
            return 'so_desistencia';
        }
        return 'fechada';
    }

    /**
     * @param array<string, mixed> $escolhida
     * @param list<array<string, mixed>> $todas
     * @return array<string, mixed>
     */
    private static function completar(array $escolhida, array $todas): array
    {
        $escolhida['rotulo_semestre'] = self::rotulo((string) $escolhida['semestre_inicio'], (string) $escolhida['semestre_fim']);
        $proxima = null;
        foreach ($todas as $janela) {
            if ($janela['janela_inicio'] > $escolhida['janela_inicio']) {
                $proxima = $janela;
                break;
            }
        }
        $escolhida['proxima_janela_inicio'] = $proxima['janela_inicio'] ?? '';
        $escolhida['proxima_rotulo'] = $proxima === null ? '' : self::rotulo((string) $proxima['semestre_inicio'], (string) $proxima['semestre_fim']);
        return $escolhida;
    }

    private static function rotulo(string $inicio, string $fim): string
    {
        $meses = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];
        $mesInicio = $meses[(int) substr($inicio, 5, 2)] ?? $inicio;
        $mesFim = $meses[(int) substr($fim, 5, 2)] ?? $fim;
        return $mesInicio . ' a ' . $mesFim . ' de ' . substr($inicio, 0, 4);
    }
}
