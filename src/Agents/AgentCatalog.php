<?php

declare(strict_types=1);

namespace App\Agents;

use App\Agents\Agentes\AuditorComplianceAgente;
use App\Agents\Agentes\AuditorEquacaoAgente;
use App\Agents\Agentes\ConferencistaMensalAgente;
use App\Agents\Agentes\ConciliadorExtratoAgente;
use App\Agents\Agentes\ConsultorArtigoAgente;
use App\Agents\Agentes\GestorAssinaturaAgente;
use App\Agents\Agentes\PorteiroAcessoAgente;
use App\Agents\Agentes\SimuladorRegimeAgente;
use App\Agents\Agentes\TesoureiroPreditivoAgente;
use App\Agents\Agentes\ValidadorRegrasAgente;

/**
 * Reúne um agente por ferramenta. O contrato de cada um mora no próprio arquivo.
 */
final class AgentCatalog
{
    public static function trava(): string
    {
        return 'Nenhum agente calcula imposto por conta própria. Alíquota, redução, base, débito, crédito e centavos saem só da ferramenta determinística deste agente. Artigo e diploma saem só da consulta da lei vigente na data da operação. Cada variável é lida no próprio tipo: código, data, ano, reais, percentual, centavos, regime ou status. Obrigatória vazia ou valor ilegível interrompe o cálculo. Opcional vazia não vira zero nem código inventado. Estimativa de mercado não é texto de lei. Se houver modelo de linguagem, ele só escolhe uma hipótese já listada ou redige os trechos recebidos.';
    }

    /** @return list<array<string, mixed>> */
    public static function todos(): array
    {
        return [
            ValidadorRegrasAgente::contrato(),
            ConferencistaMensalAgente::contrato(),
            SimuladorRegimeAgente::contrato(),
            ConciliadorExtratoAgente::contrato(),
            TesoureiroPreditivoAgente::contrato(),
            AuditorComplianceAgente::contrato(),
            ConsultorArtigoAgente::contrato(),
            AuditorEquacaoAgente::contrato(),
            PorteiroAcessoAgente::contrato(),
            GestorAssinaturaAgente::contrato(),
        ];
    }

    /** @return array<string, mixed> */
    public static function porId(string $id): array
    {
        foreach (self::todos() as $agente) {
            if ($agente['id'] === $id) {
                return $agente;
            }
        }
        throw new \InvalidArgumentException('Agente desconhecido: ' . $id);
    }

    public static function breve(string $id): string
    {
        $agente = self::porId($id);
        return $agente['nome'] . ': ' . $agente['papel'];
    }

    public static function instrucao(string $id): string
    {
        $agente = self::porId($id);
        $linhas = [
            self::trava(),
            'Agente: ' . $agente['nome'] . '.',
            $agente['papel'],
            'Ferramenta: ' . $agente['ferramenta'] . '.',
            'Espaço: ' . $agente['espaco'],
            'Variáveis:',
        ];
        foreach ($agente['variaveis'] as $variavel) {
            $linhas[] = '- ' . $variavel['nome'] . ($variavel['obrigatoria'] ? ' (obrigatória' : ' (opcional') . ', ' . self::tipoLegivel((string) $variavel['tipo']) . '): ' . $variavel['sentido'];
        }
        $linhas[] = 'Leis:';
        foreach ($agente['leis'] as $lei) {
            $linhas[] = '- ' . $lei['diploma'] . ': ' . $lei['uso'];
        }
        return implode("\n", $linhas);
    }

    public static function tipoLegivel(string $tipo): string
    {
        return match ($tipo) {
            'codigo_ncm' => 'código de 8 dígitos',
            'codigo' => 'código',
            'texto' => 'texto',
            'data' => 'data',
            'ano' => 'ano',
            'dinheiro' => 'reais',
            'percentual' => 'percentual',
            'centavos' => 'centavos',
            'inteiro' => 'inteiro',
            'regime' => 'regime',
            'status' => 'status',
            'decimal' => 'número',
            'escolha' => 'unificado ou híbrido',
            default => $tipo,
        };
    }

    /**
     * @param list<array{0:string,1:bool,2:string,3:string}> $variaveis
     * @param list<array{0:string,1:string}> $leis
     * @return array<string, mixed>
     */
    public static function definir(string $id, string $nome, string $classe, string $ferramenta, string $papel, string $espaco, array $variaveis, array $leis): array
    {
        $listaVariaveis = [];
        foreach ($variaveis as $variavel) {
            $listaVariaveis[] = [
                'nome' => $variavel[0],
                'obrigatoria' => $variavel[1],
                'tipo' => $variavel[2],
                'sentido' => $variavel[3],
            ];
        }
        $listaLeis = [];
        foreach ($leis as $lei) {
            $listaLeis[] = ['diploma' => $lei[0], 'uso' => $lei[1]];
        }
        return [
            'id' => $id,
            'nome' => $nome,
            'classe' => $classe,
            'ferramenta' => $ferramenta,
            'papel' => $papel,
            'espaco' => $espaco,
            'variaveis' => $listaVariaveis,
            'leis' => $listaLeis,
        ];
    }
}
