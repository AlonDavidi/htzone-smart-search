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

-- Migrations for existing databases (keep idempotent).
-- name_text = name + brand + aliases: matches here weigh more than in the description.
ALTER TABLE products ADD COLUMN IF NOT EXISTS name_text TEXT NOT NULL DEFAULT '';

-- Trigram indexes: fast fuzzy/partial matching on Hebrew and English text.
CREATE INDEX IF NOT EXISTS products_search_trgm
    ON products USING gin (search_text gin_trgm_ops);
CREATE INDEX IF NOT EXISTS products_name_trgm
    ON products USING gin (name_text gin_trgm_ops);

-- Cache of query embeddings (the expensive part of a search).
CREATE TABLE IF NOT EXISTS query_cache (
    query      TEXT PRIMARY KEY,
    embedding  vector(384) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Inverted index for lexical search: every distinct word, and where it appears.
-- Comparing query words to short vocabulary words is much cheaper than running
-- trigram similarity over every long description.
CREATE TABLE IF NOT EXISTS terms (
    term TEXT PRIMARY KEY
);
CREATE INDEX IF NOT EXISTS terms_trgm ON terms USING gin (term gin_trgm_ops);

CREATE TABLE IF NOT EXISTS product_terms (
    product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    term       TEXT NOT NULL,
    -- 1.0 = in name/brand/aliases, 0.7 = only in category/description
    weight     REAL NOT NULL,
    PRIMARY KEY (product_id, term)
);
CREATE INDEX IF NOT EXISTS product_terms_term ON product_terms (term);
