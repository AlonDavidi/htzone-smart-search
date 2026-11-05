<?php
declare(strict_types=1);

namespace App;

/**
 * HTTP client for the embedding sidecar (embedder/app.py).
 * Short timeouts: if the embedder is slow or down, search degrades to
 * lexical-only instead of hanging.
 */
final class Embedder
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutMs = 1500,
    ) {}

    public static function fromEnv(): ?self
    {
        $url = getenv('EMBEDDER_URL');
        return $url ? new self(rtrim($url, '/')) : null;
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, string $kind = 'query', ?int $timeoutMs = null): array
    {
        $ch = curl_init($this->baseUrl . '/embed');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['texts' => $texts, 'kind' => $kind], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS     => $timeoutMs ?? $this->timeoutMs,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new \RuntimeException("Embedder unavailable (HTTP $status) $error");
        }
        return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)['vectors'];
    }

    /** Name of the model the sidecar is serving (from /health). */
    public function model(): string
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 2000]);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Embedder unavailable');
        }
        return json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR)['model'];
    }

    /** pgvector literal: [0.1,0.2,...] */
    public static function toPg(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }
}
