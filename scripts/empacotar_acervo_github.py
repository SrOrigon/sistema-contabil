"""Junta o acervo de leis em poucos arquivos de texto para caber no GitHub.

A pasta original tem mais de um milhão de arquivos. O GitHub recusa arquivo
acima de 100 MB e um repositório com essa quantidade de arquivos não clona.
Aqui cada volume fica perto de 18 MB, com o texto integral e um índice.
Caches de download em scripts/dados e scripts/estados não entram.
"""

from __future__ import annotations

import hashlib
import json
import os
import sys
import time

ORIGEM = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "database", "acervo", "leis")
)
DESTINO = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "..", "..", "acervo-leis")
)
LIMITE_VOLUME = 18 * 1024 * 1024
LIMITE_INDICE = 40 * 1024 * 1024
EXT_TEXTO = {".md", ".txt", ".html", ".htm"}
IGNORAR_PREFIXOS = (
    "scripts/dados/",
    "scripts/estados/manifestos/",
    "scripts/estados/snapshots/",
)


def longo(caminho: str) -> str:
    caminho = os.path.abspath(caminho)
    if os.name == "nt" and not caminho.startswith("\\\\?\\"):
        return "\\\\?\\" + caminho
    return caminho


def relativo(caminho: str) -> str:
    if caminho.startswith("\\\\?\\"):
        caminho = caminho[4:]
    return os.path.relpath(os.path.abspath(caminho), os.path.abspath(ORIGEM)).replace("\\", "/")


def ignorar(rel: str) -> bool:
    if rel.startswith(".") or "/." in rel:
        return True
    for parte in rel.split("/"):
        if parte.startswith("_"):
            return True
    return any(rel.startswith(prefixo) for prefixo in IGNORAR_PREFIXOS)


def grupo(rel: str) -> str:
    partes = rel.split("/")
    if partes[0] == "federal" and len(partes) > 1:
        return "/".join(partes[:2])
    return partes[0]


def titulo_de(texto: str, rel: str) -> str:
    if texto.startswith("---\n"):
        fim = texto.find("\n---", 4)
        bloco = texto[4:fim] if fim != -1 else ""
        for linha in bloco.splitlines():
            if linha.startswith("titulo:"):
                valor = linha.split(":", 1)[1].strip().strip("\"'")
                if valor:
                    return valor[:300]
    return os.path.basename(rel)


class Volume:
    def __init__(self) -> None:
        self.arquivo = None
        self.caminho_rel = ""
        self.bytes = 0
        self.normas = 0
        self.grupo = ""
        self.sequencia = 0

    def abrir(self, nome_grupo: str, sequencia: int) -> None:
        self.fechar()
        self.grupo = nome_grupo
        self.sequencia = sequencia
        self.caminho_rel = f"{nome_grupo}/parte-{sequencia:04d}.md"
        absoluto = os.path.join(DESTINO, *self.caminho_rel.split("/"))
        os.makedirs(os.path.dirname(absoluto), exist_ok=True)
        self.arquivo = open(longo(absoluto), "w", encoding="utf-8", newline="\n")
        self.arquivo.write(f"# {nome_grupo} — parte {sequencia:04d}\n\n")
        self.bytes = self.arquivo.tell() if hasattr(self.arquivo, "tell") else 0
        self.bytes = len(f"# {nome_grupo} — parte {sequencia:04d}\n\n".encode("utf-8"))
        self.normas = 0

    def fechar(self) -> None:
        if self.arquivo is not None:
            self.arquivo.close()
            self.arquivo = None


class Indice:
    def __init__(self) -> None:
        self.numero = 0
        self.arquivo = None
        self.bytes = 0
        self.caminho_rel = ""

    def abrir(self) -> None:
        self.fechar()
        self.numero += 1
        self.caminho_rel = f"indice/parte-{self.numero:04d}.jsonl"
        absoluto = os.path.join(DESTINO, "indice", f"parte-{self.numero:04d}.jsonl")
        os.makedirs(os.path.dirname(absoluto), exist_ok=True)
        self.arquivo = open(longo(absoluto), "w", encoding="utf-8", newline="\n")
        self.bytes = 0

    def linha(self, registro: dict) -> None:
        if self.arquivo is None:
            self.abrir()
        texto = json.dumps(registro, ensure_ascii=False, separators=(",", ":")) + "\n"
        tamanho = len(texto.encode("utf-8"))
        if self.bytes + tamanho > LIMITE_INDICE and self.bytes > 0:
            self.abrir()
        self.arquivo.write(texto)
        self.bytes += tamanho

    def fechar(self) -> None:
        if self.arquivo is not None:
            self.arquivo.close()
            self.arquivo = None


def escrever_leia_me(totais: dict) -> None:
    texto = f"""# Acervo de leis

Texto integral das normas que estavam em `database/acervo/leis` do sistema de contabilidade.
Os arquivos originais eram mais de um milhão. Aqui o mesmo texto está reunido em volumes de até 18 MB.

Cada norma começa com uma linha `@@@NORMA caminho@@@` e termina com `@@@FIM caminho@@@`.
O caminho é o mesmo da pasta antiga, por exemplo `federal/leis-complementares/2025/lcp-214-2025.md`.

O índice fica em `indice/parte-NNNN.jsonl`. Cada linha tem `caminho`, `titulo`, `volume` e `bytes`.
Para achar uma lei, procure o número ou o título no índice e abra o volume indicado.

Totais deste pacote: {totais['normas']} normas em {totais['volumes']} volumes.
Caches de download (`scripts/dados` e manifestos estaduais) ficaram de fora: não são o texto da norma e dá para baixar de novo.

O sistema em produção não lê estes volumes. Ele consulta o índice SQLite publicado no release `indice-2026-10-26`.
"""
    with open(longo(os.path.join(DESTINO, "LEIA-ME.md")), "w", encoding="utf-8", newline="\n") as arquivo:
        arquivo.write(texto)


def main() -> int:
    if not os.path.isdir(longo(ORIGEM)):
        print("Pasta de origem ausente:", ORIGEM, flush=True)
        return 1
    if os.path.exists(longo(DESTINO)):
        print("Destino já existe, não vou misturar com uma execução anterior:", DESTINO, flush=True)
        return 1
    os.makedirs(longo(DESTINO))
    inicio = time.time()
    volume = Volume()
    indice = Indice()
    indice.abrir()
    sequencias: dict[str, int] = {}
    normas = 0
    copiados = 0
    omitidos = []
    amostras = []
    extensoes: dict[str, int] = {}
    grupo_atual = ""

    for pasta, diretorios, arquivos in os.walk(longo(ORIGEM)):
        diretorios.sort()
        arquivos.sort()
        for nome in arquivos:
            absoluto = os.path.join(pasta, nome)
            if absoluto.startswith("\\\\?\\"):
                visivel = absoluto[4:]
            else:
                visivel = absoluto
            rel = relativo(visivel)
            if ignorar(rel):
                continue
            ext = os.path.splitext(nome)[1].lower()
            extensoes[ext or "(sem)"] = extensoes.get(ext or "(sem)", 0) + 1
            try:
                tamanho = os.path.getsize(absoluto)
            except OSError as erro:
                omitidos.append(f"{rel}\t{erro}")
                continue
            if ext not in EXT_TEXTO:
                if tamanho > 90 * 1024 * 1024:
                    omitidos.append(f"{rel}\tmuito grande ({tamanho})")
                    continue
                destino_arq = os.path.join(DESTINO, *rel.split("/"))
                os.makedirs(longo(os.path.dirname(destino_arq)), exist_ok=True)
                with open(absoluto, "rb") as origem, open(longo(destino_arq), "wb") as saida:
                    while True:
                        pedaco = origem.read(1024 * 1024)
                        if not pedaco:
                            break
                        saida.write(pedaco)
                copiados += 1
                continue
            with open(absoluto, "r", encoding="utf-8", errors="replace", newline="") as origem:
                texto = origem.read().replace("\r\n", "\n").replace("\r", "\n")
            if texto.startswith("\ufeff"):
                texto = texto[1:]
            bloco = f"@@@NORMA {rel}@@@\n{texto}\n@@@FIM {rel}@@@\n\n"
            codificado = bloco.encode("utf-8")
            nome_grupo = grupo(rel)
            if (
                volume.arquivo is None
                or nome_grupo != grupo_atual
                or volume.bytes + len(codificado) > LIMITE_VOLUME
            ):
                sequencias[nome_grupo] = sequencias.get(nome_grupo, 0) + 1
                volume.abrir(nome_grupo, sequencias[nome_grupo])
                grupo_atual = nome_grupo
            volume.arquivo.write(bloco)
            volume.bytes += len(codificado)
            volume.normas += 1
            normas += 1
            indice.linha(
                {
                    "caminho": rel,
                    "titulo": titulo_de(texto, rel),
                    "volume": volume.caminho_rel,
                    "bytes": len(codificado),
                }
            )
            if len(amostras) < 30 and normas % 37000 == 1:
                amostras.append((rel, hashlib.sha256(texto.encode("utf-8")).hexdigest(), volume.caminho_rel))
            if normas % 5000 == 0:
                print(f"{normas} normas", flush=True)

    volume.fechar()
    indice.fechar()
    falhas = []
    for rel, digest, volume_rel in amostras:
        absoluto = os.path.join(DESTINO, *volume_rel.split("/"))
        with open(longo(absoluto), "r", encoding="utf-8", errors="replace") as arquivo:
            conteudo = arquivo.read()
        marca = f"@@@NORMA {rel}@@@\n"
        fim = f"\n@@@FIM {rel}@@@\n"
        inicio_marca = conteudo.find(marca)
        fim_marca = conteudo.find(fim, inicio_marca + len(marca)) if inicio_marca != -1 else -1
        if inicio_marca == -1 or fim_marca == -1:
            falhas.append(rel)
            continue
        trecho = conteudo[inicio_marca + len(marca) : fim_marca]
        if hashlib.sha256(trecho.encode("utf-8")).hexdigest() != digest:
            falhas.append(rel)
    if omitidos:
        with open(longo(os.path.join(DESTINO, "OMITIDOS.txt")), "w", encoding="utf-8", newline="\n") as arquivo:
            arquivo.write("\n".join(omitidos))
    with open(longo(os.path.join(DESTINO, ".gitattributes")), "w", encoding="utf-8", newline="\n") as arquivo:
        arquivo.write("* -text\n")
    totais = {"normas": normas, "volumes": sum(sequencias.values()), "copiados": copiados, "omitidos": len(omitidos)}
    escrever_leia_me(totais)
    resumo = {
        **totais,
        "extensoes": extensoes,
        "amostras_ok": len(amostras) - len(falhas),
        "amostras_falhas": falhas,
        "segundos": round(time.time() - inicio),
    }
    with open(longo(os.path.join(DESTINO, "indice", "resumo.json")), "w", encoding="utf-8", newline="\n") as arquivo:
        json.dump(resumo, arquivo, ensure_ascii=False, indent=2)
    print("EMPACOTADO", json.dumps(resumo, ensure_ascii=False), flush=True)
    return 1 if falhas else 0


if __name__ == "__main__":
    sys.exit(main())
