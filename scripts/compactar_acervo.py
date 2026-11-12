"""Gera um único leis.tar.xz com o texto do acervo.

A pasta solta passa de 10 GB. O xz dos textos jurídicos medidos fica entre
8 e 13 vezes menor, então o arquivo único cabe perto de 1,5 GB.
Caches de download não entram.
"""

from __future__ import annotations

import os
import sys
import tarfile
import time

ORIGEM = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "database", "acervo", "leis"))
DESTINO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "database", "acervo", "leis.tar.xz"))
IGNORAR = (
    "scripts/dados/",
    "scripts/estados/manifestos/",
    "scripts/estados/snapshots/",
)


def longo(caminho: str) -> str:
    caminho = os.path.abspath(caminho)
    if os.name == "nt" and not caminho.startswith("\\\\?\\"):
        return "\\\\?\\" + caminho
    return caminho


def ignorar(rel: str) -> bool:
    rel = rel.replace("\\", "/")
    if rel.startswith(".") or "/." in rel:
        return True
    return any(rel.startswith(prefixo) or ("/" + prefixo) in ("/" + rel) for prefixo in IGNORAR)


def main() -> int:
    if not os.path.isdir(longo(ORIGEM)):
        print("Pasta ausente:", ORIGEM, flush=True)
        return 1
    if os.path.exists(longo(DESTINO)):
        print("Arquivo já existe:", DESTINO, flush=True)
        return 1
    inicio = time.time()
    arquivos = 0
    bruto = 0
    with tarfile.open(DESTINO, "w:xz", preset=6) as pacote:
        for pasta, diretorios, nomes in os.walk(longo(ORIGEM)):
            diretorios.sort()
            nomes.sort()
            visivel = pasta[4:] if pasta.startswith("\\\\?\\") else pasta
            for nome in nomes:
                absoluto = os.path.join(visivel, nome)
                rel = os.path.relpath(absoluto, ORIGEM).replace("\\", "/")
                if ignorar(rel):
                    continue
                try:
                    tamanho = os.path.getsize(longo(absoluto))
                except OSError as erro:
                    print("pulado", rel, erro, flush=True)
                    continue
                info = tarfile.TarInfo(name="leis/" + rel)
                info.size = tamanho
                info.mtime = int(os.path.getmtime(longo(absoluto)))
                with open(longo(absoluto), "rb") as arquivo:
                    pacote.addfile(info, arquivo)
                arquivos += 1
                bruto += tamanho
                if arquivos % 20000 == 0:
                    print(f"{arquivos} arquivos, {bruto / 1024 / 1024:.0f} MB lidos", flush=True)
    final = os.path.getsize(DESTINO)
    print(
        f"COMPACTADO arquivos={arquivos} bruto_mb={bruto / 1024 / 1024:.0f} "
        f"xz_mb={final / 1024 / 1024:.0f} segundos={int(time.time() - inicio)}",
        flush=True,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
