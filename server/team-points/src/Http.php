<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

final class Http
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        RuntimeTelemetry::noteResponse(strlen($encoded), 'no-store', false);
        echo $encoded;
        exit;
    }

    public static function jsonCacheable(
        array $payload,
        int $status = 200,
        int $maxAge = 60,
        int $staleWhileRevalidate = 300,
        ?string $etag = null
    ): never {
        $maxAge = max(0, min(3600, $maxAge));
        $staleWhileRevalidate = max(0, min(86400, $staleWhileRevalidate));
        $etag ??= '"' . hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '"';
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=' . $staleWhileRevalidate);
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($status === 200 && $ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
            http_response_code(304);
            RuntimeTelemetry::noteResponse(0, 'public-revalidate', true);
            exit;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        RuntimeTelemetry::noteResponse(strlen($encoded), 'public-revalidate', false);
        echo $encoded;
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException('Request body is not valid JSON.', 400, 'INVALID_JSON');
        }
        if (!is_array($value)) {
            throw new ApiException('Request JSON must be an object.', 400, 'INVALID_JSON_OBJECT');
        }
        return $value;
    }

    public static function method(string $expected): void
    {
        $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($actual !== strtoupper($expected)) {
            throw new ApiException("Use {$expected} for this action.", 405, 'METHOD_NOT_ALLOWED');
        }
    }
}
