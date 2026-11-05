<?php
declare(strict_types=1);

namespace App\Search;

use App\Normalizer;
use PDO;

/**
 * Keyword search over an inverted word index (terms / product_terms).
 *
 * 1. Each query token (and its prefix-stripped variants) is matched to similar
 *    vocabulary words with pg_trgm - this tolerates typos ("אייפן", "vacum").
 * 2. Each product gets, per token, its best match: similarity x field weight
 *    (name/brand/aliases = 1.0, description = 0.7).
 * 3. Score = weighted average over ALL query tokens, so products matching every
 *    word rank above products matching one word. A full-phrase hit in the name
 *    gets a bonus.
 */
final class LexicalSearch
{
    /** Minimum trigram similarity between a query word and a catalog word. */
    private const MIN_SIMILARITY = 0.45;

    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{id: string, score: float}> */
    public function search(string $query, int $limit = 50): array
    {
        $tokens = QueryParser::tokens($query);
        if ($tokens === []) {
            return [];
        }

        $rows = [];
        foreach ($tokens as $i => $t) {
            foreach ($t['variants'] as $v) {
                $rows[] = ['tok' => $i, 'w' => $t['weight'], 'v' => $v];
            }
        }
        $totalWeight = array_sum(array_column($tokens, 'weight'));

        $this->pdo->exec('SET pg_trgm.similarity_threshold = ' . self::MIN_SIMILARITY);
        $stmt = $this->pdo->prepare(<<<'SQL'
            WITH q AS (
                SELECT (r->>'tok')::int AS tok, (r->>'w')::float AS w, r->>'v' AS v
                FROM jsonb_array_elements(CAST(:rows AS jsonb)) AS r
            ),
            matched AS (                       -- query word -> similar catalog words (indexed)
                SELECT q.tok, q.w, t.term, similarity(q.v, t.term) AS sim
                FROM q JOIN terms t ON t.term % q.v
            ),
            per_token AS (                     -- best match per product per query token
                SELECT pt.product_id, m.tok, m.w, max(m.sim * pt.weight) AS s
                FROM matched m JOIN product_terms pt ON pt.term = m.term
                GROUP BY pt.product_id, m.tok, m.w
            )
            SELECT pt.product_id AS id,
                   sum(pt.w * pt.s) / :total_weight
                   + CASE WHEN p.name_text LIKE '%' || :phrase || '%' THEN 0.3 ELSE 0 END AS score
            FROM per_token pt JOIN products p ON p.id = pt.product_id
            GROUP BY pt.product_id, p.name_text
            ORDER BY score DESC, pt.product_id
            LIMIT :limit
        SQL);
        $stmt->bindValue('rows', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue('total_weight', $totalWeight);
        $stmt->bindValue('phrase', Normalizer::normalize($query));
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $r) => ['id' => $r['id'], 'score' => round((float) $r['score'], 4)],
            $stmt->fetchAll(),
        );
    }
}
