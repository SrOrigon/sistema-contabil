<?php

declare(strict_types=1);

namespace App\Banking;

use App\Core\Format;

final class TransactionMatcher
{
    /**
     * @param array{valor_centavos:int,data:string,descricao:string} $transacao
     * @param list<array<string, mixed>> $documentos
     * @return array{documento_id:?int,pontuacao:int,confianca:string,motivo:string,automatica:bool}
     */
    public function comparar(array $transacao, array $documentos): array
    {
        $valor = abs((int) $transacao['valor_centavos']);
        $entrada = (int) $transacao['valor_centavos'] > 0;
        $candidatos = [];
        foreach ($documentos as $documento) {
            $direcao = (string) ($documento['tipo_operacao'] ?? '');
            if ($entrada && $direcao !== 'saida') {
                continue;
            }
            if (!$entrada && $direcao !== 'entrada') {
                continue;
            }
            if ((int) $documento['valor_nota_centavos'] !== $valor) {
                continue;
            }
            $pontosData = $this->pontosData((string) $transacao['data'], (string) ($documento['emissao'] ?? ''));
            $nome = $entrada ? (string) $documento['destinatario_nome'] : (string) $documento['emitente_nome'];
            $doc = $entrada ? (string) $documento['destinatario_doc'] : (string) $documento['emitente_doc'];
            $pontosNome = $this->pontosNome((string) $transacao['descricao'], $nome, $doc);
            $pontos = 50 + $pontosData + $pontosNome;
            $candidatos[] = [
                'id' => (int) $documento['id'],
                'pontos' => $pontos,
                'data' => $pontosData,
                'nome' => $pontosNome,
                'rotulo' => trim(((string) $documento['numero']) . ' ' . $nome),
            ];
        }
        if ($candidatos === []) {
            return [
                'documento_id' => null,
                'pontuacao' => 0,
                'confianca' => '0.00',
                'motivo' => 'Nenhum documento com o mesmo valor e a direção compatível.',
                'automatica' => false,
            ];
        }
        usort($candidatos, static fn (array $a, array $b): int => $b['pontos'] <=> $a['pontos']);
        $melhor = $candidatos[0];
        $ambiguo = isset($candidatos[1]) && $candidatos[1]['pontos'] >= 80;
        $pontos = $ambiguo ? min($melhor['pontos'], 94) : $melhor['pontos'];
        $motivo = 'Valor exato (50)';
        $motivo .= $melhor['data'] > 0 ? ', data em até 3 dias (30)' : ', data fora da janela de 3 dias (0)';
        $motivo .= ', similaridade do favorecido (' . $melhor['nome'] . ')';
        $motivo .= '. Documento ' . $melhor['rotulo'] . '.';
        if ($ambiguo) {
            $motivo .= ' Há outro documento com pontuação próxima; a conciliação fica na fila.';
        }
        return [
            'documento_id' => $melhor['id'],
            'pontuacao' => $pontos,
            'confianca' => number_format($pontos / 100, 2, '.', ''),
            'motivo' => $motivo,
            'automatica' => $pontos >= 95,
        ];
    }

    private function pontosData(string $dataTransacao, string $dataDocumento): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataTransacao) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDocumento)) {
            return 0;
        }
        $dias = abs((int) ((strtotime($dataTransacao) - strtotime($dataDocumento)) / 86400));
        return $dias <= 3 ? 30 : 0;
    }

    private function pontosNome(string $descricao, string $nome, string $documento): int
    {
        $documento = Format::digitos($documento);
        $digitosDescricao = Format::digitos($descricao);
        if (strlen($documento) >= 11 && $documento !== '' && str_contains($digitosDescricao, $documento)) {
            return 20;
        }
        $textoNome = Format::texto($nome);
        if ($textoNome === '') {
            return 0;
        }
        similar_text(Format::texto($descricao), $textoNome, $percentual);
        if ($percentual >= 70) {
            return 20;
        }
        foreach (preg_split('/\s+/', $textoNome) ?: [] as $token) {
            if (strlen($token) >= 4 && str_contains(Format::texto($descricao), $token)) {
                return 14;
            }
        }
        return $percentual >= 45 ? 8 : 0;
    }
}
