<?php
declare(strict_types=1);

namespace App;

/** Minimal .env loader. Real environment variables always win. */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return; // production: variables come from the platform
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (getenv($key) === false) {
                putenv("$key=" . trim($value, "\"'"));
            }
        }
    }
}
