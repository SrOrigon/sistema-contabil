<?php

declare(strict_types=1);

namespace App\Fiscal;

use App\Core\Database;
use App\Core\Format;

final class ReformaOficialSeeder
{
    public function __construct(private readonly Database $db)
    {
    }

    public function executar(string $pastaAcervo, string $pastaCsv): string
    {
        $linhas = ['Varredura da LC 214 e da LC 227'];
        $arquivos = $this->diplomas($pastaAcervo);
        $textosIndice = [];
        if ($arquivos === []) {
            $catalogo = new \App\KnowledgeBase\AcervoCatalog();
            foreach (['%lcp-214%', '%lcp-227%'] as $trecho) {
                foreach ($catalogo->textosComCaminho($trecho) as $item) {
                    $textosIndice[] = $item;
                }
            }
            if ($textosIndice === []) {
                $linhas[] = 'Nenhum arquivo da LC 214 ou da LC 227 foi encontrado em ' . $pastaAcervo . '.';
            }
        }
        $ncmNoTexto = 0;
        $cclassNoTexto = 0;
        foreach ($arquivos as $arquivo) {
            $varredura = $this->varrerArquivo($arquivo);
            $ncmNoTexto += $varredura['ncm'];
            $cclassNoTexto += $varredura['cclass'];
            $linhas[] = '- ' . $this->relativo($arquivo, $pastaAcervo) . ': ' . $varredura['linhas'] . ' linhas, ' . $varredura['ncm'] . ' NCM, ' . $varredura['cclass'] . ' cClassTrib. ' . $varredura['observacao'];
        }
        foreach ($textosIndice as $item) {
            $varredura = $this->varrerTexto($item['corpo'], $item['caminho']);
            $ncmNoTexto += $varredura['ncm'];
            $cclassNoTexto += $varredura['cclass'];
            $linhas[] = '- índice ' . $item['caminho'] . ': ' . $varredura['linhas'] . ' linhas, ' . $varredura['ncm'] . ' NCM, ' . $varredura['cclass'] . ' cClassTrib. ' . $varredura['observacao'];
        }
        if ($ncmNoTexto === 0 && $cclassNoTexto === 0) {
            $linhas[] = 'Nenhuma redução foi gravada a partir desses textos. Código que não está na tabela oficial não é inventado.';
        } else {
            $linhas[] = 'O texto cita NCM ou cClassTrib, mas não traz a tabela estruturada de redução. Nada foi gravado a partir da prosa.';
        }
        $csvCclass = $this->importarCsv($pastaCsv . '/cclass-trib.csv', 'cclass');
        $csvRegras = $this->importarCsv($pastaCsv . '/regras-ncm.csv', 'regras');
        if ($csvCclass === null && $csvRegras === null) {
            $linhas[] = 'Nenhum CSV em ' . $pastaCsv . '. Quando a Receita publicar a tabela, grave cclass-trib.csv e regras-ncm.csv nessa pasta e rode o comando de novo.';
        }
        if ($csvCclass !== null) {
            $linhas[] = 'CSV de cClassTrib: ' . $csvCclass . ' código(s) gravado(s). A tabela não foi marcada como oficial completa.';
        }
        if ($csvRegras !== null) {
            $linhas[] = 'CSV de regras de NCM: ' . $csvRegras . ' regra(s) gravada(s), substituindo o mesmo NCM e a mesma descrição.';
        }
        $regras = $this->db->one('SELECT COUNT(*) AS total FROM regras_ncm');
        $codigos = $this->db->one('SELECT COUNT(*) AS total FROM cclass_trib');
        $linhas[] = 'Regras de NCM no banco: ' . (int) ($regras['total'] ?? 0) . '. Códigos cClassTrib: ' . (int) ($codigos['total'] ?? 0) . '.';
        $completa = $this->db->one('SELECT valor FROM configuracoes WHERE chave = :chave', [':chave' => 'tabelas.cclass_oficial_completa']);
        if (($completa['valor'] ?? '0') !== '1') {
            $linhas[] = 'Código de cClassTrib ausente continua em alerta até a tabela oficial ser importada por completo.';
        }
        return implode("\n", $linhas);
    }

    /** @return array{linhas: int, ncm: int, cclass: int, anexos: int, observacao: string} */
    public function varrerArquivo(string $caminho): array
    {
        $fh = fopen($caminho, 'rb');
        if ($fh === false) {
            return ['linhas' => 0, 'ncm' => 0, 'cclass' => 0, 'anexos' => 0, 'observacao' => 'Arquivo ilegível.'];
        }
        $linhas = 0;
        $ncm = 0;
        $cclass = 0;
        $anexos = 0;
        $veto = false;
        while (($linha = fgets($fh)) !== false) {
            $contagem = $this->contarLinha($linha);
            $linhas++;
            $ncm += $contagem['ncm'];
            $cclass += $contagem['cclass'];
            $anexos += $contagem['anexos'];
            $veto = $veto || $contagem['veto'];
        }
        fclose($fh);
        return [
            'linhas' => $linhas,
            'ncm' => $ncm,
            'cclass' => $cclass,
            'anexos' => $anexos,
            'observacao' => $this->observacao($caminho, $linhas, $ncm, $cclass, $anexos, $veto),
        ];
    }

    /** @return array{linhas: int, ncm: int, cclass: int, anexos: int, observacao: string} */
    public function varrerTexto(string $texto, string $nome): array
    {
        $linhas = 0;
        $ncm = 0;
        $cclass = 0;
        $anexos = 0;
        $veto = false;
        foreach (preg_split("/\r\n|\n|\r/", $texto) ?: [] as $linha) {
            $contagem = $this->contarLinha($linha);
            $linhas++;
            $ncm += $contagem['ncm'];
            $cclass += $contagem['cclass'];
            $anexos += $contagem['anexos'];
            $veto = $veto || $contagem['veto'];
        }
        return [
            'linhas' => $linhas,
            'ncm' => $ncm,
            'cclass' => $cclass,
            'anexos' => $anexos,
            'observacao' => $this->observacao($nome, $linhas, $ncm, $cclass, $anexos, $veto),
        ];
    }

    /** @return list<string> */
    private function diplomas(string $pasta): array
    {
        $padroes = [
            $pasta . '/federal/leis-complementares/2025/lcp-214-*.md',
            $pasta . '/federal/leis-complementares/2026/lcp-227-*.md',
        ];
        $arquivos = [];
        foreach ($padroes as $padrao) {
            foreach (glob($padrao) ?: [] as $arquivo) {
                if (is_file($arquivo)) {
                    $arquivos[] = $arquivo;
                }
            }
        }
        sort($arquivos);
        return $arquivos;
    }

    /** @return array{ncm: int, cclass: int, anexos: int, veto: bool} */
    private function contarLinha(string $linha): array
    {
        $ncm = 0;
        if (preg_match_all('/\d{4}\.\d{2}(?:\.\d{2})?/', $linha, $achados) > 0) {
            $ncm = count($achados[0]);
        }
        return [
            'ncm' => $ncm,
            'cclass' => preg_match('/cClassTrib[^0-9]{0,40}(\d{6})/u', $linha) === 1 ? 1 : 0,
            'anexos' => preg_match('/ANEXO\s+[IVXLCDM0-9]+/u', $linha) === 1 ? 1 : 0,
            'veto' => stripos($linha, 'vetad') !== false,
        ];
    }

    private function observacao(string $caminho, int $linhas, int $ncm, int $cclass, int $anexos, bool $veto): string
    {
        $nome = strtolower(basename($caminho));
        if (str_contains($nome, '214') && $veto && $linhas < 200 && $ncm === 0 && $cclass === 0) {
            return 'O texto local é a republicação de dispositivos vetados, sem os anexos de NCM da LC 214.';
        }
        if ($ncm === 0 && $cclass === 0 && $anexos > 0) {
            return 'As menções a anexos são remissões. O arquivo não traz a lista de NCM nem a tabela de cClassTrib.';
        }
        if ($ncm === 0 && $cclass === 0) {
            return 'O arquivo não traz lista de NCM nem tabela de cClassTrib.';
        }
        return 'Há citação de NCM ou cClassTrib, sem grade de redução por código.';
    }

    private function importarCsv(string $caminho, string $tipo): ?int
    {
        if (!is_file($caminho)) {
            return null;
        }
        $linhas = $this->csv($caminho);
        $total = 0;
        foreach ($linhas as $linha) {
            if ($tipo === 'cclass' && $this->gravarCclass($linha)) {
                $total++;
            }
            if ($tipo === 'regras' && $this->gravarRegra($linha)) {
                $total++;
            }
        }
        return $total;
    }

    /** @param array<string, string> $linha */
    private function gravarCclass(array $linha): bool
    {
        $codigo = trim($linha['codigo'] ?? '');
        if ($codigo === '') {
            return false;
        }
        $this->db->run('DELETE FROM cclass_trib WHERE codigo = :codigo', [':codigo' => $codigo]);
        $this->db->insert('cclass_trib', [
            'codigo' => $codigo,
            'cst' => $linha['cst'] ?? '',
            'descricao' => $linha['descricao'] ?? '',
            'reducao_ibs' => $linha['reducao_ibs'] ?? '0',
            'reducao_cbs' => $linha['reducao_cbs'] ?? '0',
            'fundamento' => $linha['fundamento'] ?? 'Importado do CSV oficial',
            'vigencia_inicio' => $linha['vigencia_inicio'] ?? '2026-01-01',
            'vigencia_fim' => ($linha['vigencia_fim'] ?? '') !== '' ? $linha['vigencia_fim'] : null,
            'fonte' => 'importado',
        ]);
        return true;
    }

    /** @param array<string, string> $linha */
    private function gravarRegra(array $linha): bool
    {
        $ncm = Format::digitos($linha['ncm'] ?? '');
        if ($ncm === '') {
            return false;
        }
        $descricao = $linha['descricao'] ?? '';
        $inicio = $linha['vigencia_inicio'] ?? '2026-01-01';
        $this->db->run(
            'DELETE FROM regras_ncm WHERE ncm = :ncm AND descricao = :descricao AND vigencia_inicio = :inicio',
            [':ncm' => $ncm, ':descricao' => $descricao, ':inicio' => $inicio],
        );
        $incluir = $this->lista($linha['palavras_incluir'] ?? '');
        $excluir = $this->lista($linha['palavras_excluir'] ?? '');
        $this->db->insert('regras_ncm', [
            'ncm' => $ncm,
            'match_tipo' => ($linha['match_tipo'] ?? 'prefixo') === 'exato' ? 'exato' : 'prefixo',
            'descricao' => $descricao,
            'reducao' => $linha['reducao'] ?? '0',
            'cst' => $linha['cst'] ?? '000',
            'cclass_trib' => ($linha['cclass_trib'] ?? '') !== '' ? $linha['cclass_trib'] : null,
            'palavras_incluir' => json_encode($incluir, JSON_UNESCAPED_UNICODE),
            'palavras_excluir' => json_encode($excluir, JSON_UNESCAPED_UNICODE),
            'fundamento' => $linha['fundamento'] ?? 'Regra importada pelo escritório',
            'vigencia_inicio' => $inicio,
            'vigencia_fim' => ($linha['vigencia_fim'] ?? '') !== '' ? $linha['vigencia_fim'] : null,
            'prioridade' => (int) ($linha['prioridade'] ?? 50),
        ]);
        return true;
    }

    /** @return list<array<string, string>> */
    private function csv(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false || trim($conteudo) === '') {
            return [];
        }
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }
        $linhas = preg_split("/\r\n|\n|\r/", trim($conteudo)) ?: [];
        $delimitador = substr_count($linhas[0] ?? '', ';') >= substr_count($linhas[0] ?? '', ',') ? ';' : ',';
        $cabecalho = str_getcsv(array_shift($linhas) ?: '', $delimitador);
        $saida = [];
        foreach ($linhas as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $colunas = str_getcsv($linha, $delimitador);
            $registro = [];
            foreach ($cabecalho as $indice => $nome) {
                $registro[trim((string) $nome)] = trim((string) ($colunas[$indice] ?? ''));
            }
            $saida[] = $registro;
        }
        return $saida;
    }

    /** @return list<string> */
    private function lista(string $valor): array
    {
        if ($valor === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('|', $valor))));
    }

    private function relativo(string $arquivo, string $pasta): string
    {
        $pasta = rtrim(str_replace('\\', '/', $pasta), '/');
        $arquivo = str_replace('\\', '/', $arquivo);
        if (str_starts_with($arquivo, $pasta . '/')) {
            return substr($arquivo, strlen($pasta) + 1);
        }
        return basename($arquivo);
    }
}
