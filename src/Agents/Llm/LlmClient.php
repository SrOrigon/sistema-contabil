<?php

declare(strict_types=1);

namespace App\Agents\Llm;

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
        $pedido = "Você classifica a descrição de um item de nota fiscal.\nNCM: {$ncm}\nDescrição: {$descricao}\nHipóteses:\n{$lista}\nResponda somente JSON {\"escolha\": <indice>}. Não invente alíquota.";
        try {
            $texto = $this->provedor === 'gemini' ? $this->gemini($pedido) : $this->openai($pedido);
        } catch (\Throwable) {
            return null;
        }
        if (!preg_match('/\{\s*"escolha"\s*:\s*(\d+)\s*\}/', $texto, $encontrado)) {
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
        foreach ($trechos as $trecho) {
            $base .= '[' . $trecho['diploma_legal'] . ' ' . $trecho['artigo'] . "]\n" . $trecho['conteudo'] . "\n\n";
        }
        $pedido = "Responda em português, para um contador, usando somente os trechos abaixo. Cite diploma e artigo. Se a base não responder, diga isso.\n\nPergunta: {$pergunta}\n\nTrechos:\n{$base}";
        try {
            return $this->provedor === 'gemini' ? $this->gemini($pedido) : $this->openai($pedido);
        } catch (\Throwable) {
            return null;
        }
    }

    private function gemini(string $pedido): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->modelo()) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $resposta = $this->post($url, [
            'contents' => [['role' => 'user', 'parts' => [['text' => $pedido]]]],
            'generationConfig' => ['temperature' => 0],
        ], []);
        return (string) ($resposta['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    private function openai(string $pedido): string
    {
        $base = rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.openai.com/v1', '/');
        $resposta = $this->post($base . '/chat/completions', [
            'model' => $this->modelo(),
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => 'Você não calcula tributo. Você escolhe hipóteses ou redige com base nos trechos recebidos.'],
                ['role' => 'user', 'content' => $pedido],
            ],
        ], ['Authorization: Bearer ' . $this->apiKey]);
        return (string) ($resposta['choices'][0]['message']['content'] ?? '');
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
