<?php

declare(strict_types=1);

namespace App\Banking\OpenFinance;

final class PluggyClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function configurado(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function autenticar(): string
    {
        $resposta = $this->chamar('POST', '/auth', [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
        ], null);
        $token = (string) ($resposta['apiKey'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('A Pluggy não devolveu a chave de API.');
        }
        return $token;
    }

    /** @return array<string, mixed> */
    public function criarConnectToken(string $apiKey): array
    {
        return $this->chamar('POST', '/connect_token', ['clientUserId' => 'escritorio-local'], $apiKey);
    }

    /** @return list<array<string, mixed>> */
    public function listarTransacoes(string $apiKey, string $accountId, string $de, string $ate): array
    {
        $resposta = $this->chamar(
            'GET',
            '/transactions?accountId=' . rawurlencode($accountId) . '&from=' . rawurlencode($de) . '&to=' . rawurlencode($ate) . '&pageSize=500',
            null,
            $apiKey,
        );
        $lista = $resposta['results'] ?? [];
        return is_array($lista) ? $lista : [];
    }

    /** @param array<string, mixed>|null $corpo @return array<string, mixed> */
    private function chamar(string $metodo, string $caminho, ?array $corpo, ?string $apiKey): array
    {
        if (!$this->configurado()) {
            throw new \RuntimeException('Informe o client id e o client secret da Pluggy em Configurações.');
        }
        $curl = curl_init(rtrim($this->baseUrl, '/') . $caminho);
        if ($curl === false) {
            throw new \RuntimeException('Não foi possível iniciar a conexão com a Pluggy.');
        }
        $cabecalhos = ['Accept: application/json', 'Content-Type: application/json'];
        if ($apiKey !== null) {
            $cabecalhos[] = 'X-API-KEY: ' . $apiKey;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $cabecalhos,
        ]);
        if ($corpo !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
        }
        $bruto = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($curl);
        curl_close($curl);
        if ($bruto === false) {
            throw new \RuntimeException('Falha de rede ao falar com a Pluggy: ' . $erro);
        }
        $json = json_decode($bruto, true);
        if ($status >= 400) {
            $mensagem = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? $bruto) : $bruto;
            throw new \RuntimeException('A Pluggy respondeu ' . $status . ': ' . $mensagem);
        }
        return is_array($json) ? $json : [];
    }
}
