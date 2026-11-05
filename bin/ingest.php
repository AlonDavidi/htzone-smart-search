<?php
declare(strict_types=1);

/**
 * Loads the catalog CSV (+ optional enrichment JSON) into PostgreSQL.
 *
 * Usage: php bin/ingest.php [data/catalog.csv] [data/enrichment.json]
 *
 * Idempotent: re-running updates rows in place (ON CONFLICT). A row whose
 * searchable text changed gets its embedding reset so it is re-embedded.
 */

require __DIR__ . '/../vendor/autoload.php';
\App\Env::load(__DIR__ . '/../.env');

use App\Db;
use App\Normalizer;

$csvPath        = $argv[1] ?? __DIR__ . '/../data/catalog.csv';
$enrichmentPath = $argv[2] ?? __DIR__ . '/../data/enrichment.json';

$pdo = Db::get();
$pdo->exec((string) file_get_contents(__DIR__ . '/../db/schema.sql'));

$enrichment = is_file($enrichmentPath)
    ? json_decode((string) file_get_contents($enrichmentPath), true, flags: JSON_THROW_ON_ERROR)
    : [];

/** "הספק: 1400W | נפח: 6.2 ליטר" → ["הספק" => "1400W", "נפח" => "6.2 ליטר"] */
function parseAttributes(string $raw): array
{
    $out = [];
    foreach (explode('|', $raw) as $pair) {
        [$key, $value] = array_map('trim', explode(':', $pair, 2)) + [1 => ''];
        if ($key !== '') {
            $out[$key] = $value;
        }
    }
    return $out;
}

function readCsv(string $path): \Generator
{
    $fh = fopen($path, 'rb') ?: throw new \RuntimeException("Cannot open $path");
    $header = fgetcsv($fh, escape: '');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // strip UTF-8 BOM
    while (($row = fgetcsv($fh, escape: '')) !== false) {
        if ($row === [null]) {
            continue; // blank line
        }
        yield array_combine($header, $row);
    }
    fclose($fh);
}

$upsert = $pdo->prepare(<<<'SQL'
    INSERT INTO products (id, name, brand, category, subcategory, description,
                          attributes, image_url, aliases, name_text, search_text, content_hash)
    VALUES (:id, :name, :brand, :category, :subcategory, :description,
            :attributes, :image_url, :aliases, :name_text, :search_text, :content_hash)
    ON CONFLICT (id) DO UPDATE SET
        name         = EXCLUDED.name,
        brand        = EXCLUDED.brand,
        category     = EXCLUDED.category,
        subcategory  = EXCLUDED.subcategory,
        description  = EXCLUDED.description,
        attributes   = EXCLUDED.attributes,
        image_url    = EXCLUDED.image_url,
        aliases      = EXCLUDED.aliases,
        name_text    = EXCLUDED.name_text,
        search_text  = EXCLUDED.search_text,
        embedding    = CASE WHEN products.content_hash = EXCLUDED.content_hash
                            THEN products.embedding END,
        content_hash = EXCLUDED.content_hash,
        updated_at   = now()
SQL);

$count = 0;
$pdo->beginTransaction();
try {
    foreach (readCsv($csvPath) as $row) {
        $aliases = $enrichment[$row['id']]['aliases'] ?? [];
        $brand   = trim($row['brand']) !== '' ? trim($row['brand']) : null;

        $searchText = Normalizer::normalize(implode(' ', [
            $row['name'], $brand ?? '', implode(' ', $aliases),
            $row['category'], $row['subcategory'], $row['description'],
        ]));

        $upsert->execute([
            'id'           => $row['id'],
            'name'         => trim($row['name']),
            'brand'        => $brand,
            'category'     => $row['category'],
            'subcategory'  => $row['subcategory'],
            'description'  => $row['description'],
            'attributes'   => json_encode(parseAttributes($row['attributes']), JSON_UNESCAPED_UNICODE),
            'image_url'    => $row['image_url'],
            'aliases'      => '{' . implode(',', array_map(fn($a) => '"' . addcslashes($a, '"\\') . '"', $aliases)) . '}',
            'name_text'    => Normalizer::normalize($row['name'] . ' ' . ($brand ?? '') . ' ' . implode(' ', $aliases)),
            'search_text'  => $searchText,
            'content_hash' => hash('sha256', $searchText),
        ]);
        $count++;
    }

    // Rebuild the word index from the stored texts (cheap: ~1000 products).
    $pdo->exec('TRUNCATE product_terms');
    $pdo->exec(<<<'SQL'
        INSERT INTO product_terms (product_id, term, weight)
        SELECT id, term, max(weight)
        FROM (
            SELECT id, unnest(string_to_array(name_text, ' ')) AS term, 1.0 AS weight FROM products
            UNION ALL
            SELECT id, unnest(string_to_array(search_text, ' ')), 0.7 FROM products
        ) words
        WHERE length(term) >= 2
        GROUP BY id, term
    SQL);
    $pdo->exec('TRUNCATE terms CASCADE');
    $pdo->exec('INSERT INTO terms SELECT DISTINCT term FROM product_terms');

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Ingest failed, rolled back: {$e->getMessage()}\n");
    exit(1);
}

$pending = (int) $pdo->query('SELECT count(*) FROM products WHERE embedding IS NULL')->fetchColumn();
echo "Ingested $count products ($pending awaiting embeddings).\n";
