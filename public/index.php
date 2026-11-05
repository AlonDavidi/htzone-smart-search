<?php
declare(strict_types=1);

/**
 * Front controller.
 *   GET /api/search?q=...  -> JSON results
 *   GET /api/health        -> DB connectivity check
 *   anything else          -> static files from /public (index.html)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Env;
use App\Embedder;
use App\Search\LexicalSearch;
use App\Search\SemanticSearch;
use App\Search\SearchService;

Env::load(__DIR__ . '/../.env');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// php -S: let the built-in server serve existing static files directly.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

function json_response(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    switch ($path) {
        case '/api/search':
            $q = trim((string) ($_GET['q'] ?? ''));
            if ($q === '') {
                json_response(400, ['error' => 'missing_query', 'message' => 'יש להזין טקסט לחיפוש']);
            }
            if (mb_strlen($q) > 200) {
                json_response(400, ['error' => 'query_too_long', 'message' => 'החיפוש ארוך מדי']);
            }

            $started = hrtime(true);
            $pdo = Db::get();
            $embedder = Embedder::fromEnv();
            $service = new SearchService(
                $pdo,
                new LexicalSearch($pdo),
                $embedder ? new SemanticSearch($pdo, $embedder) : null,
            );
            $out = $service->search($q);

            json_response(200, [
                'query'   => $q,
                'mode'    => $out['mode'],
                'took_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'count'   => count($out['results']),
                'results' => $out['results'],
            ]);

        case '/api/health':
            Db::get()->query('SELECT 1');
            json_response(200, ['status' => 'ok']);

        default:
            header('Content-Type: text/html; charset=utf-8');
            readfile(__DIR__ . '/index.html');
    }
} catch (\Throwable $e) {
    error_log('[search] ' . $e->getMessage()); // details go to the log, never to the client
    json_response(500, ['error' => 'internal_error', 'message' => 'משהו השתבש. נסו שוב בעוד רגע']);
}
