<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Orchestrators\SanityReverseAuditor;
use App\Core\Database;
use App\Core\Format;
use App\Core\ReportBlockedException;
use App\Core\SimplePdf;

final class ReportService
{
    public function __construct(private readonly Database $db, private readonly StrategyService $estrategia)
    {
    }

    /** @return array<string, mixed> */
    public function parecer(int $empresaId, bool $emitir): array
    {
        $empresa = $this->db->one('SELECT * FROM empresas WHERE id = :id', [':id' => $empresaId]);
        $escritorio = $this->db->one('SELECT * FROM escritorios ORDER BY id LIMIT 1') ?? [];
        $simulacao = $this->estrategia->ultima($empresaId);
        $auditor = new SanityReverseAuditor($this->db);
        $competencia = $simulacao['competencia'] ?? null;
        $saude = $auditor->auditar($empresaId, is_string($competencia) ? $competencia : null);
        $pacote = [
            'empresa' => $empresa,
            'escritorio' => $escritorio,
            'simulacao' => $simulacao,
            'saude' => $saude,
        ];
        if ($emitir && !$saude['aprovado']) {
            throw new ReportBlockedException('O auditor reverso bloqueou a emissão. Resolva os itens rejeitados, ambíguos ou o desequilíbrio do razão antes de gerar o PDF.');
        }
        return $pacote;
    }

    /** @param array<string, mixed> $pacote */
    public function pdf(array $pacote): string
    {
        $empresa = $pacote['empresa'];
        $escritorio = $pacote['escritorio'];
        $simulacao = $pacote['simulacao'];
        $linhas = [
            (string) ($escritorio['nome'] ?? 'Escritório'),
            (string) ($empresa['razao_social'] ?? '') . ' — CNPJ ' . Format::documento((string) ($empresa['cnpj'] ?? '')),
            'Parecer executivo da competência ' . Format::competencia(($simulacao['competencia'] ?? date('Y-m')) . '-01'),
            '',
            (string) ($simulacao['recomendacao'] ?? 'Ainda não há simulação gravada para esta empresa.'),
            '',
            'Memória de cálculo',
        ];
        foreach ($simulacao['memoria'] ?? [] as $linha) {
            $linhas[] = $linha['rotulo'] . ': ' . $linha['valor'];
            $linhas[] = '    ' . $linha['nota'];
        }
        if (($pacote['saude']['alertas'] ?? []) !== []) {
            $linhas[] = '';
            $linhas[] = 'Alertas que não bloquearam a emissão';
            foreach ($pacote['saude']['alertas'] as $alerta) {
                $linhas[] = '- ' . $alerta;
            }
        }
        $linhas[] = '';
        $linhas[] = 'Documento gerado pelo motor determinístico. A nota fiscal original não é alterada.';
        return SimplePdf::montar('Parecer executivo', $linhas);
    }
}
