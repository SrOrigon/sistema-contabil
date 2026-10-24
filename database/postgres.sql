-- Schema PostgreSQL 16+ do mesmo modelo lógico usado pelo SQLite operacional.
-- A aplicação fala SQL portátil. Este arquivo prepara o servidor quando o escritório migrar.

CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS escritorios (
    id BIGSERIAL PRIMARY KEY,
    nome TEXT NOT NULL,
    cnpj TEXT,
    crc TEXT,
    cor TEXT NOT NULL DEFAULT '#1f4b3a',
    logo_path TEXT,
    cidade TEXT,
    telefone TEXT,
    email TEXT
);

CREATE TABLE IF NOT EXISTS empresas (
    id BIGSERIAL PRIMARY KEY,
    escritorio_id BIGINT NOT NULL REFERENCES escritorios(id),
    razao_social TEXT NOT NULL,
    nome_fantasia TEXT,
    cnpj TEXT NOT NULL,
    ie TEXT,
    im TEXT,
    cnae TEXT,
    regime TEXT NOT NULL,
    anexo_simples TEXT,
    uf TEXT,
    municipio TEXT,
    capital_social_centavos BIGINT NOT NULL DEFAULT 0,
    inicio_atividade DATE,
    ativa BOOLEAN NOT NULL DEFAULT TRUE,
    criada_em TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS legislacao_chunks (
    id BIGSERIAL PRIMARY KEY,
    diploma_legal VARCHAR(80) NOT NULL,
    artigo VARCHAR(40),
    paragrafo_inciso VARCHAR(80),
    tipo_tributo VARCHAR(20),
    vigencia_inicio DATE NOT NULL,
    vigencia_fim DATE,
    titulo TEXT NOT NULL,
    conteudo TEXT NOT NULL,
    origem TEXT NOT NULL,
    embedding vector(1536)
);

CREATE INDEX IF NOT EXISTS idx_legislacao_vigencia
    ON legislacao_chunks (vigencia_inicio, vigencia_fim);
