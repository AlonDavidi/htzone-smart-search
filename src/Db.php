<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    /** Connection from DATABASE_URL (postgres://user:pass@host:port/db?sslmode=require). */
    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $url = getenv('DATABASE_URL') ?: throw new \RuntimeException('DATABASE_URL is not set');
        $p = parse_url($url);
        if ($p === false || !isset($p['host'], $p['path'])) {
            throw new \RuntimeException('DATABASE_URL is malformed');
        }
        parse_str($p['query'] ?? '', $query);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'], '/'),
            $query['sslmode'] ?? 'prefer',
        );

        self::$pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }
}
