# Smart Product Search

Search over a 1,000-product catalog in Hebrew, English, or a mix of both, by exact name (`iPhone 16`, `אייפון`) or by describing a need (`משהו שיעזור לנקות שערות של כלב מהספה` / "something to get dog hair off the couch").

- **Live site:** https://htzone-smart-search.onrender.com (free tier: the first load after idle can take ~30 s while the server wakes up)
- **Stack:** PHP 8.3 (no framework) · PostgreSQL 16 + pgvector + pg_trgm · a small Python embedding sidecar running a local ONNX model. No external APIs, no keys.

## Running locally

Requirements: Docker, PHP ≥ 8.2 with `pdo_pgsql`, Composer, Python ≥ 3.10.

```bash
docker compose up -d                              # Postgres + pgvector
cp .env.example .env
composer dump-autoload

pip install -r embedder/requirements.txt          # preferably in a venv / conda env
python embedder/download_models.py minilm         # model goes to models/ (not in git)
uvicorn app:app --app-dir embedder --port 8001    # separate terminal

php bin/ingest.php                                # load the catalog (~1 min)
php -S localhost:8080 -t public public/index.php  # http://localhost:8080
```

## Loading the catalog

`php bin/ingest.php [data/catalog.csv] [data/enrichment.json]`

Upserts all products in a single transaction, rebuilds the word index used by lexical search, and computes embeddings only for products whose text changed. It is safe to re-run. If the embedding service is unavailable, the load still completes and search runs in lexical-only mode.

## Deployment

| Part | Where | Why |
|---|---|---|
| PHP app (`Dockerfile`) | Render (free web service) | deploys from GitHub on push |
| Embedder (`embedder/Dockerfile`) | Google Cloud Run (free tier, 1 GB, scales to zero) | the model needs ~660 MB RAM, more than Render's free 512 MB |
| PostgreSQL + pgvector | Neon (free) | managed Postgres with pgvector built in |

Environment variables on the app: `DATABASE_URL`, `EMBEDDER_URL`, `EMBEDDER_TOKEN`. The embedder gets the same `EMBEDDER_TOKEN` as a secret, so only the app can call it. The catalog is loaded once by running `php bin/ingest.php` with `DATABASE_URL` pointing at Neon.

## How it works

```
query ─► normalize (niqqud, geresh, lowercase, Hebrew prefixes ו/ה/ב/ל/מ/ש/כ)
      ├─► lexical  (pg_trgm over a word index)   → names, brands, typos
      ├─► semantic (embedding + pgvector cosine) → meaning and intent
      └─► Reciprocal Rank Fusion ─► top 24
```

## Key decisions

- **PostgreSQL.** One engine for both trigram and vector search, over structured data. At 1,000 products the DB query takes 2–10 ms. Embedding the query is the slower step, so query embeddings are cached.
- **Hybrid search.** Lexical search is precise on names and brands but misses intent: hit@5 is 0.29 on intent queries. Semantic search gets intent but is weak on exact names and typos. RRF merges the two by rank, so the two scoring scales never need to be calibrated against each other.
- **Local embedding model** (`paraphrase-multilingual-MiniLM-L12-v2`). No API key, no per-query cost, deterministic. I chose it over `multilingual-e5-small` by measuring both on the eval set (see below).
- **Precomputed aliases** (`data/enrichment.json`). The catalog never says "iPhone" or "אייפון": iPhones are listed as "סמארטפון" (smartphone) with brand Apple. A generator script adds three kinds of aliases: Hebrew transliterations of brand names, English terms for each subcategory, and product-family names. The file is committed and reviewable as a diff, so no LLM runs in production.
- **Graceful degradation.** If the embedder fails or times out (1.5 s), search falls back to lexical-only instead of returning an error.
- **No prices.** The provided catalog (`catalog_no_prices.csv`) has no price column. I chose not to invent prices, so cards show image, name, brand and subcategory.

## Testing

```bash
php tests/eval.php hybrid -v             # 24 labeled queries: hit@5 / P@5 / MRR
php bin/debug-search.php "מתנה למישהי"   # per-retriever scores for one query
```

| Mode | hit@5 (all) | hit@5 (intent queries) | p50 latency |
|---|---|---|---|
| Lexical only | 0.79 | 0.29 | 6 ms |
| Hybrid | **1.00** | **1.00** | 10 ms |

| Query | Returns |
|---|---|
| `iPhone 16` / `אייפון` / `לאייפון` | the 3 Apple smartphones |
| `vacum` (typo) | vacuum cleaners |
| `Samsung טלויזיה` (mixed language) | Samsung TVs |
| `מתנה למישהו שאוהב לבשל` (gift for someone who loves cooking) | fondue pot, pancake pan, serving tray |
| `משהו שיעזור לנקות שערות של כלב מהספה` (dog hair off the couch) | pet hair removal brush, pet carpet cleaner |
| `מתנה למישהי` (gift for her) | women's gift set, bath set, no men's products |
| `xyzqq` | no results |

I also checked the UI with Playwright screenshots on desktop and mobile, covering the loading, no-results and error states, and checked the API with an empty query, an over-long query, the DB down and the embedder down.

## Known limitations and next steps

- **Small eval set.** It has 24 queries, and the thresholds were tuned on the same set. Next step: a separate held-out set, then logs of real queries.
- **No model numbers in the catalog.** `iPhone 16` returns all iPhones.
- **Typos in short Hebrew words** (`אייפן`) are missed, because short words share too few trigrams.
- **Gender intent** (`מתנה למישהי` → no men's products) is a hand-written word list. A larger embedding model or a cross-encoder reranker would handle it, and generally improve precision (P@5 is 0.46 on intent queries).

## Working with Claude

I used Claude for planning, coding, testing and debugging. Every step was verified by running it, by the eval, or by screenshots before I committed it.

- **Rejected:** while generating aliases, Claude added `iPhone 16` as an alias for the iPhones. The catalog has no model numbers, so that was invented data that would make the search look better than the data supports. I removed it.
- **Changed:** Claude recommended `multilingual-e5-small` because it is trained for retrieval. I had both models compared on the same eval set, and MiniLM won: intent MRR 0.83 vs 0.69. e5's scores also sat in a narrow band (gibberish 0.81 vs a real match 0.85), which made its thresholds fragile.
