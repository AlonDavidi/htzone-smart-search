<?php
declare(strict_types=1);

/**
 * Shows what each retriever sees for a query - for tuning and debugging.
 * Usage: php bin/debug-search.php "מתנה למישהו שאוהב לבשל" ["another query" ...]
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Embedder;
use App\Env;
use App\Search\LexicalSearch;
use App\Search\QueryParser;
use App\Search\SemanticSearch;

Env::load(__DIR__ . '/../.env');
$pdo = Db::get();
$names = $pdo->query('SELECT id, name FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
$meta = $pdo->query("SELECT value FROM meta WHERE key = 'embed_model'")->fetchColumn() ?: '(none)';
$embedder = Embedder::fromEnv();

echo "DB vectors model: $meta\n";
try {
    echo 'Embedder model:   ' . ($embedder?->model() ?? '(EMBEDDER_URL not set)') . "\n";
} catch (\Throwable $e) {
    echo "Embedder:         UNREACHABLE ({$e->getMessage()})\n";
}

foreach (array_slice($argv, 1) as $q) {
    echo "\n== $q\n";
    echo '  tokens: ' . json_encode(QueryParser::tokens($q), JSON_UNESCAPED_UNICODE) . "\n";

    echo "  lexical (top 5):\n";
    foreach (array_slice((new LexicalSearch($pdo))->search($q), 0, 5) as $h) {
        printf("    %.3f %s %s\n", $h['score'], $h['id'], $names[$h['id']]);
    }

    if ($embedder) {
        echo "  semantic (top 5):\n";
        try {
            foreach ((new SemanticSearch($pdo, $embedder))->search($q, 5) as $h) {
                printf("    %.3f %s %s\n", $h['score'], $h['id'], $names[$h['id']]);
            }
        } catch (\Throwable $e) {
            echo "    ERROR: {$e->getMessage()}\n";
        }
    }
}
