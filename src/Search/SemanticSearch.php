<?php
declare(strict_types=1);

namespace App\Search;

use App\Embedder;
use App\Normalizer;
use PDO;

/**
 * Meaning-based search: embed the query, rank products by cosine similarity.
 *
 * Query vectors are cached in query_cache - the embedding call is the slowest
 * step of a search, and popular queries repeat.
 */
final class SemanticSearch
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Embedder $embedder,
    ) {}

    /** @return list<array{id: string, score: float}> */
    public function search(string $query, int $limit = 50): array
    {
        $vector = $this->queryVector($query);

        // <=> is cosine distance; 1 - distance = cosine similarity.
        // 1000 rows: an exact scan takes a few ms, no ANN index needed.
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT id, 1 - (embedding <=> CAST(:v AS vector)) AS score
            FROM products
            WHERE embedding IS NOT NULL
            ORDER BY embedding <=> CAST(:v AS vector)
            LIMIT :limit
        SQL);
        $stmt->bindValue('v', $vector);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $r) => ['id' => $r['id'], 'score' => round((float) $r['score'], 4)],
            $stmt->fetchAll(),
        );
    }

    /** pgvector literal for the query, from cache or freshly embedded. */
    private function queryVector(string $query): string
    {
        $key = Normalizer::normalize($query);

        $stmt = $this->pdo->prepare('SELECT embedding::text FROM query_cache WHERE query = ?');
        $stmt->execute([$key]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false) {
            return $cached;
        }

        $vector = Embedder::toPg($this->embedder->embed([$query], 'query')[0]);
        $this->pdo
            ->prepare('INSERT INTO query_cache (query, embedding) VALUES (?, CAST(? AS vector)) ON CONFLICT DO NOTHING')
            ->execute([$key, $vector]);

        return $vector;
    }
}
