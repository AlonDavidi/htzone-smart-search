-- Idempotent schema: safe to run on every ingest.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS products (
    id           TEXT PRIMARY KEY,
    name         TEXT        NOT NULL,
    brand        TEXT,
    category     TEXT        NOT NULL,
    subcategory  TEXT        NOT NULL,
    description  TEXT        NOT NULL,
    attributes   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    image_url    TEXT        NOT NULL,
    aliases      TEXT[]      NOT NULL DEFAULT '{}',
    -- Normalized text used by the lexical (trigram) search.
    search_text  TEXT        NOT NULL,
    -- Semantic vector; NULL until the embedder has processed the row.
    embedding    vector(384),
    -- Hash of the text that was embedded, so re-ingest skips unchanged rows.
    content_hash TEXT        NOT NULL,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Trigram index: fast fuzzy/partial matching on Hebrew and English text.
CREATE INDEX IF NOT EXISTS products_search_trgm
    ON products USING gin (search_text gin_trgm_ops);

-- Cache of query embeddings (the expensive part of a search).
CREATE TABLE IF NOT EXISTS query_cache (
    query      TEXT PRIMARY KEY,
    embedding  vector(384) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
