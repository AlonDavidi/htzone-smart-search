<?php
declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Orchestrates a search: runs the retrievers, applies a relevance cutoff and
 * loads the product rows for the response.
 *
 * Semantic search (embeddings + RRF fusion) plugs in here in the next step;
 * lexical-only is also the fallback when the embedder is unavailable.
 */
final class SearchService
{
    /** Lexical scores below this are noise (e.g. matching only the word "מתנה"). */
    private const LEXICAL_MIN_SCORE = 0.45;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LexicalSearch $lexical,
    ) {}

    /** @return array{results: list<array<string, mixed>>, mode: string} */
    public function search(string $query, int $limit = 24): array
    {
        $hits = array_filter(
            $this->lexical->search($query, $limit),
            fn(array $h) => $h['score'] >= self::LEXICAL_MIN_SCORE,
        );

        return ['results' => $this->hydrate(array_column($hits, 'id')), 'mode' => 'lexical'];
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
