<?php

declare(strict_types=1);

namespace App\Fiscal\Engine;

use App\Core\Database;

/**
 * Classe de Roteamento de Arquivos:
 * Recebe o caminho de qualquer arquivo enviado (upload pelo cliente ou API),
 * detecta a assinatura de conteúdo, valida integridade e duplicidade por SHA-256,
 * extrai metadados prévios e resolve automaticamente o Tenant (empresa) antes da auditoria profunda.
 */
class RoteadorArquivosService
{
    private IngestorDocumentosEngine $ingestor;

    public function __construct(
        private readonly ?Database $db = null,
        ?IngestorDocumentosEngine $ingestor = null,
    ) {
        $this->ingestor = $ingestor ?? new IngestorDocumentosEngine($this->db);
    }

    /**
     * Método principal que recebe o caminho de qualquer arquivo e roteia para o Tenant e Agente corretos.
     *
     * @param string $caminhoArquivo Caminho do arquivo no disco
     * @param int|null $escritorioId ID do escritório (filtro multi-tenant opcional)
     * @return array<string, mixed>
     */
    public function rotear(string $caminhoArquivo, ?int $escritorioId = null): array
    {
        return $this->ingestor->rotear($caminhoArquivo, $escritorioId);
    }

    /**
     * Roteia um lote massivo de arquivos identificando os respectivos Tenants e Agentes de auditoria.
     *
     * @param list<string> $caminhosArquivos
     * @param int|null $escritorioId
     * @return array<string, mixed>
     */
    public function rotearLote(array $caminhosArquivos, ?int $escritorioId = null): array
    {
        $roteados = [];
        $sucesso = 0;
        $duplicados = 0;
        $falhas = 0;

        foreach ($caminhosArquivos as $caminho) {
            $res = $this->rotear($caminho, $escritorioId);
            $roteados[] = $res;

            match ($res['status'] ?? 'erro') {
                'sucesso' => $sucesso++,
                'duplicado' => $duplicados++,
                default => $falhas++,
            };
        }

        return [
            'total' => count($caminhosArquivos),
            'sucesso' => $sucesso,
            'duplicados' => $duplicados,
            'falhas' => $falhas,
            'arquivos' => $roteados,
        ];
    }
}
