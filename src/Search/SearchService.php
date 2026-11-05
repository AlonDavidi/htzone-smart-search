<?php
declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Hybrid search: lexical (exact names, brands, typos) + semantic (meaning, intent),
 * merged with Reciprocal Rank Fusion.
 *
 * RRF uses ranks, not raw scores, so we don't have to calibrate trigram
 * similarity against cosine similarity: score = sum(1 / (K + rank)).
 *
 * If the embedder is down, search degrades to lexical-only (mode = "lexical")
 * instead of failing.
 */
final class SearchService
{
    /** Lexical scores below this are noise (e.g. matching only the word "מתנה"). */
    private const LEXICAL_MIN_SCORE = 0.45;
    /**
     * Semantic cutoffs, calibrated per model on tests/eval.php: each model has its own
     * similarity scale (e5 squeezes everything into 0.78-0.87, MiniLM spreads 0.3-0.6).
     *   min - absolute floor: below it the query has no real match (gibberish scores here)
     *   gap - keep only hits within this distance of the best hit
     */
    private const SEMANTIC_CUTOFFS = [
        'e5-small' => ['min' => 0.83, 'gap' => 0.03],
        'minilm'   => ['min' => 0.42, 'gap' => 0.12],
    ];
    private const RRF_K = 60;
    /** A lexical match is the more precise signal, so it counts more in the fusion. */
    private const LEXICAL_WEIGHT = 1.5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LexicalSearch $lexical,
        private readonly ?SemanticSearch $semantic,
        private readonly bool $useLexical = true, // false only for evaluation (semantic-only)
    ) {}

    /** @return array{results: list<array<string, mixed>>, mode: string} */
    public function search(string $query, int $limit = 24): array
    {
        $lexical = !$this->useLexical ? [] : array_values(array_filter(
            $this->lexical->search($query, 50),
            fn(array $h) => $h['score'] >= self::LEXICAL_MIN_SCORE,
        ));

        $semantic = [];
        $mode = 'lexical';
        if ($this->semantic !== null) {
            try {
                $semantic = $this->relevantSemantic($this->semantic->search($query, 50));
                $mode = 'hybrid';
            } catch (\Throwable $e) {
                error_log('[search] semantic unavailable, lexical only: ' . $e->getMessage());
            }
        }

        $ids = array_slice($this->fuse($lexical, $semantic), 0, $limit);

        return ['results' => $this->hydrate($ids), 'mode' => $mode];
    }

    /** Keep semantic hits that are both good in absolute terms and near the best hit. */
    private function relevantSemantic(array $hits): array
    {
        if ($hits === []) {
            return [];
        }
        $model = $this->pdo->query("SELECT value FROM meta WHERE key = 'embed_model'")->fetchColumn();
        $c = self::SEMANTIC_CUTOFFS[$model] ?? throw new \RuntimeException("No cutoffs calibrated for model '$model'");
        $cutoff = max($c['min'], $hits[0]['score'] - $c['gap']);
        return array_values(array_filter($hits, fn(array $h) => $h['score'] >= $cutoff));
    }

    /** Reciprocal Rank Fusion. @return list<string> ids, best first */
    private function fuse(array $lexical, array $semantic): array
    {
        $scores = [];
        foreach ($lexical as $rank => $h) {
            $scores[$h['id']] = ($scores[$h['id']] ?? 0) + self::LEXICAL_WEIGHT / (self::RRF_K + $rank + 1);
        }
        foreach ($semantic as $rank => $h) {
            $scores[$h['id']] = ($scores[$h['id']] ?? 0) + 1 / (self::RRF_K + $rank + 1);
        }
        arsort($scores);
        return array_keys($scores);
    }

    /** Load product rows, preserving the ranking order. */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, brand, category, subcategory, image_url FROM products WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $byId = array_column($stmt->fetchAll(), null, 'id');

        return array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
    }
}
