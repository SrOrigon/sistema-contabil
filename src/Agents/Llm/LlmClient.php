<?php

declare(strict_types=1);

namespace App\Agents\Llm;

use App\Agents\AgentCatalog;

final class LlmClient
{
    public function __construct(
        private readonly string $provedor,
        private readonly string $modelo,
        private readonly string $apiKey,
        private readonly string $baseUrl = '',
    ) {
    }

    public function configurado(): bool
    {
        return $this->apiKey !== '' && in_array($this->provedor, ['gemini', 'openai'], true);
    }

    public function modelo(): string
    {
        return $this->modelo !== '' ? $this->modelo : ($this->provedor === 'gemini' ? 'gemini-2.5-flash' : 'gpt-4.1-mini');
    }

    /**
     * Pede ao modelo que escolha o índice de uma hipótese. Devolve null se a resposta não for um índice válido.
     *
     * @param list<string> $hipoteses
     */
    public function escolherHipotese(string $descricao, string $ncm, array $hipoteses): ?int
    {
        if (!$this->configurado() || $hipoteses === []) {
            return null;
        }
        $lista = '';
        foreach ($hipoteses as $indice => $hipotese) {
            $lista .= $indice . '. ' . $hipotese . "\n";
        }
        $pedido = "NCM {$ncm}\nItem: {$descricao}\nHipóteses:\n{$lista}{\"escolha\": indice}";
        try {
            $texto = $this->provedor === 'gemini'
                ? $this->gemini($pedido, AgentCatalog::breve('validador-regras-tributarias'), true)
                : $this->openai($pedido, AgentCatalog::breve('validador-regras-tributarias'), true);
        } catch (\Throwable) {
            return null;
        }
        if (!preg_match('/"escolha"\s*:\s*"?(\d+)"?/', $texto, $encontrado)) {
            return null;
        }
        $indice = (int) $encontrado[1];
        return isset($hipoteses[$indice]) ? $indice : null;
    }

    /** @param list<array{artigo:string,diploma_legal:string,conteudo:string}> $trechos */
    public function redigirParecer(string $pergunta, array $trechos): ?string
    {
        if (!$this->configurado()) {
            return null;
        }
        $base = '';
        foreach (array_slice($trechos, 0, 3) as $trecho) {
            $base .= '[' . $trecho['diploma_legal'] . ' ' . $trecho['artigo'] . "]\n" . $this->recorte((string) $trecho['conteudo']) . "\n\n";
        }
        $pedido = "Una os trechos e cite diploma e artigo. Se não responderem a pergunta, diga isso.\n\nPergunta: {$pergunta}\n\nTrechos vigentes:\n{$base}";
        try {
            return $this->provedor === 'gemini'
                ? $this->gemini($pedido, AgentCatalog::breve('consultor-artigo-lei'), false)
                : $this->openai($pedido, AgentCatalog::breve('consultor-artigo-lei'), false);
        } catch (\Throwable) {
            return null;
        }
    }

    private function gemini(string $pedido, string $sistema, bool $json): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->modelo()) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $corpo = [
            'systemInstruction' => ['parts' => [['text' => $sistema]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $pedido]]]],
        ];
        if ($json) {
            $corpo['generationConfig'] = ['responseMimeType' => 'application/json'];
        }
        $resposta = $this->post($url, $corpo, []);
        $texto = '';
        foreach ($resposta['candidates'][0]['content']['parts'] ?? [] as $parte) {
            if (!is_array($parte) || !empty($parte['thought'])) {
                continue;
            }
            $texto .= (string) ($parte['text'] ?? '');
        }
        return $texto;
    }

    private function openai(string $pedido, string $sistema, bool $json): string
    {
        $base = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.openai.com/v1', '/');
        $corpo = [
            'model' => $this->modelo(),
            'messages' => [
                ['role' => 'system', 'content' => $sistema],
                ['role' => 'user', 'content' => $pedido],
            ],
        ];
        if ($json) {
            $corpo['response_format'] = ['type' => 'json_object'];
        }
        $resposta = $this->post($base . '/chat/completions', $corpo, ['Authorization: Bearer ' . $this->apiKey]);
        return (string) ($resposta['choices'][0]['message']['content'] ?? '');
    }

    private function recorte(string $texto): string
    {
        $texto = trim((string) preg_replace('/[ \t]+/', ' ', $texto));
        if (mb_strlen($texto) <= 900) {
            return $texto;
        }
        return mb_substr($texto, 0, 900) . '…';
    }

    /** @param array<string, mixed> $corpo @param list<string> $cabecalhos @return array<string, mixed> */
    private function post(string $url, array $corpo, array $cabecalhos): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Falha ao iniciar o cliente HTTP.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cabecalhos),
            CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
        ]);
        $bruto = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($bruto === false || $status >= 400) {
            throw new \RuntimeException('O modelo recusou ou não respondeu.');
        }
        $json = json_decode($bruto, true);
        return is_array($json) ? $json : [];
    }
}
