<?php

declare(strict_types=1);

namespace App\App;

use App\Agents\Agentes\ConsultorArtigoAgente;
use App\Agents\Llm\LlmClient;
use App\Agents\RegistroDeAgente;
use App\Core\Database;
use App\Core\ValidationException;
use App\KnowledgeBase\HybridLawSearcher;
use App\KnowledgeBase\LawChunker;

final class KnowledgeService
{
    public function __construct(private readonly Database $db, private readonly SettingsService $config)
    {
    }

    /** @return list<array<string, mixed>> */
    public function pesquisar(string $consulta, string $competencia): array
    {
        $data = \DateTimeImmutable::createFromFormat('Y-m-d', $competencia) ?: new \DateTimeImmutable('today');
        return (new HybridLawSearcher($this->db))->search($consulta, $data, 8);
    }

    /** @return array{resposta:string, modelo:string, trechos:list<array<string,mixed>>} */
    public function responder(string $pergunta, string $competencia, ?int $empresaId): array
    {
        $leitura = ConsultorArtigoAgente::ler($pergunta, $competencia);
        if (!$leitura['ok']) {
            $contrato = ConsultorArtigoAgente::contrato();
            RegistroDeAgente::gravar($this->db, (string) $contrato['id'], (string) $contrato['ferramenta'], $empresaId, [
                'pergunta' => $pergunta,
                'competencia' => $competencia,
            ], [
                'resposta' => $leitura['mensagem'],
            ], 'variavel');
            return ['resposta' => $leitura['mensagem'], 'modelo' => 'variavel', 'trechos' => []];
        }
        $pergunta = (string) $leitura['valores']['consulta'];
        $competencia = (string) $leitura['valores']['competencia'];
        $trechos = $this->pesquisar($pergunta, $competencia);
        $llm = $this->config->llm();
        $redacao = null;
        if (ConsultorArtigoAgente::deveRedigir(count($trechos))) {
            $redacao = $llm->redigirParecer($pergunta, $trechos);
        }
        if ($redacao === null || trim($redacao) === '') {
            $redacao = $this->respostaLocal($pergunta, $trechos, $competencia);
            $modelo = 'deterministico';
        } else {
            $modelo = $llm->modelo();
        }
        $contrato = ConsultorArtigoAgente::contrato();
        RegistroDeAgente::gravar($this->db, (string) $contrato['id'], (string) $contrato['ferramenta'], $empresaId, [
            'pergunta' => $pergunta,
            'competencia' => $competencia,
            'variaveis_usadas' => $leitura['usadas'],
        ], [
            'resposta' => $redacao,
            'trechos' => count($trechos),
        ], $modelo);
        return ['resposta' => $redacao, 'modelo' => $modelo, 'trechos' => $trechos];
    }

    /** @param list<array<string, mixed>> $trechos */
    private function respostaLocal(string $pergunta, array $trechos, string $competencia): string
    {
        if ($trechos === []) {
            return 'Não há trecho vigente em ' . $competencia . ' para "' . $pergunta . '". Importe a norma ou mude a competência.';
        }
        $texto = 'Consulta em ' . $competencia . ', sem modelo de linguagem configurado. Os trechos vigentes abaixo são a resposta:';
        foreach ($trechos as $trecho) {
            $texto .= "\n\n" . $trecho['diploma_legal'] . ' — ' . $trecho['artigo'] . "\n" . $trecho['conteudo'];
        }
        return $texto;
    }

    public function importarMarkdown(string $conteudo, string $nome): int
    {
        $pasta = ROOT . '/storage/tmp';
        if (!is_dir($pasta)) {
            mkdir($pasta, 0777, true);
        }
        $caminho = $pasta . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $nome);
        file_put_contents($caminho, $conteudo);
        $chunks = (new LawChunker())->chunkArquivo($caminho);
        @unlink($caminho);
        if ($chunks === []) {
            throw new ValidationException('O markdown não tem blocos com @diploma e texto. Use o mesmo formato de database/leis/nucleo.md.');
        }
        foreach ($chunks as $chunk) {
            $chunk['origem'] = 'upload';
            $this->db->insert('legislacao_chunks', $chunk);
        }
        return count($chunks);
    }

    public function reindexarArquivos(): int
    {
        return (new \App\Core\Migrator($this->db))->importarLeis();
    }

    /** @return list<array<string, mixed>> */
    public function logs(?int $empresaId): array
    {
        if ($empresaId === null) {
            return $this->db->all('SELECT * FROM agent_logs ORDER BY id DESC LIMIT 40');
        }
        return $this->db->all('SELECT * FROM agent_logs WHERE empresa_id = :empresa OR empresa_id IS NULL ORDER BY id DESC LIMIT 40', [':empresa' => $empresaId]);
    }
}
