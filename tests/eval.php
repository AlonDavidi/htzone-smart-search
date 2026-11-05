<?php
declare(strict_types=1);

/**
 * Relevance evaluation over tests/eval-cases.json.
 *
 * Usage: php tests/eval.php [hybrid|lexical|semantic] [-v]
 *
 * A result is relevant if its id, subcategory or category is listed in the
 * case's "relevant" spec, or its name contains one of "name_contains". Metrics per query (top 5):
 *   hit@5  - at least one relevant result in the top 5
 *   P@5    - share of the top 5 that is relevant
 *   MRR    - 1 / rank of the first relevant result
 * "none" cases pass when the search returns nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;
use App\Embedder;
use App\Env;
use App\Search\LexicalSearch;
use App\Search\SearchService;
use App\Search\SemanticSearch;

Env::load(__DIR__ . '/../.env');

$mode    = $argv[1] ?? 'hybrid';
$verbose = in_array('-v', $argv, true);
$pdo     = Db::get();
$embedder = Embedder::fromEnv();

$service = new SearchService(
    $pdo,
    new LexicalSearch($pdo),
    $mode !== 'lexical' && $embedder ? new SemanticSearch($pdo, $embedder) : null,
    useLexical: $mode !== 'semantic',
);

$cases = json_decode((string) file_get_contents(__DIR__ . '/eval-cases.json'), true, flags: JSON_THROW_ON_ERROR);
$totals = [];
$latencies = [];
$actualModes = [];

printf("%-42s %-7s %5s %5s %5s %6s\n", 'query', 'type', 'hit@5', 'P@5', 'MRR', 'ms');
foreach ($cases as $case) {
    $t = hrtime(true);
    $out = $service->search($case['q'], 24);
    $ms = (hrtime(true) - $t) / 1e6;
    $latencies[] = $ms;
    $actualModes[$out['mode']] = ($actualModes[$out['mode']] ?? 0) + 1;

    $rel = $case['relevant'];
    $isRelevant = fn(array $p) => in_array($p['id'], $rel['ids'] ?? [], true)
        || in_array($p['subcategory'], $rel['subcategory'] ?? [], true)
        || in_array($p['category'], $rel['category'] ?? [], true)
        || array_filter($rel['name_contains'] ?? [], fn($w) => mb_stripos($p['name'], $w) !== false) !== [];

    $top = array_slice($out['results'], 0, 5);
    if ($case['type'] === 'none') {
        $hit = $p5 = $rr = $out['results'] === [] ? 1.0 : 0.0;
    } else {
        $flags = array_map($isRelevant, $top);
        $first = array_search(true, $flags, true);
        $hit = $first === false ? 0.0 : 1.0;
        $p5  = $top === [] ? 0.0 : array_sum($flags) / 5;
        $rr  = $first === false ? 0.0 : 1 / ($first + 1);
    }
    foreach (['hit' => $hit, 'p5' => $p5, 'mrr' => $rr] as $k => $v) {
        $totals[$case['type']][$k][] = $v;
        $totals['ALL'][$k][] = $v;
    }

    printf("%-42s %-7s %5s %5.2f %5.2f %6.0f\n", mb_strimwidth($case['q'], 0, 40), $case['type'],
        $hit ? '✓' : '✗', $p5, $rr, $ms);
    if ($verbose) {
        foreach ($top as $p) {
            printf("      %s %s %s\n", $isRelevant($p) ? '+' : '-', $p['id'], $p['name']);
        }
    }
}

$avg = fn(array $xs) => array_sum($xs) / max(count($xs), 1);
echo "\nmode=$mode (actually ran: " . json_encode($actualModes) . ")\n";
if ($mode !== 'lexical' && !isset($actualModes['hybrid'])) {
    echo "  WARNING: semantic search did not run - check the embedder (php bin/debug-search.php \"test\")\n";
}
foreach ($totals as $type => $m) {
    printf("  %-7s n=%-2d hit@5=%.2f  P@5=%.2f  MRR=%.2f\n", $type, count($m['hit']), $avg($m['hit']), $avg($m['p5']), $avg($m['mrr']));
}
sort($latencies);
printf("  latency p50=%.0fms  p95=%.0fms\n", $latencies[intdiv(count($latencies), 2)], $latencies[(int) floor(count($latencies) * 0.95)]);
