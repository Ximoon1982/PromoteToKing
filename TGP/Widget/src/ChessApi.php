<?php
declare(strict_types=1);

namespace TGP\Widget;

use RuntimeException;

final class ChessApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

final class ChessApi
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $userAgent,
        private readonly int $matchTtl = 45,
        private readonly int $clubTtl = 300,
    ) {}

    public function match(string $matchId, bool $force = false): array
    {
        if (!preg_match('/^[0-9]{1,18}$/D', $matchId)) throw new ChessApiException('Invalid match id.');
        return $this->getJson('https://api.chess.com/pub/match/' . rawurlencode($matchId), $this->matchTtl, $force);
    }

    public function club(string $slug, bool $force = false): array
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9_-]{1,160}$/D', $slug)) throw new ChessApiException('Invalid club slug.');
        return $this->getJson('https://api.chess.com/pub/club/' . rawurlencode($slug), $this->clubTtl, $force);
    }

    public function clubMatches(string $slug, bool $force = false): array
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9_-]{1,160}$/D', $slug)) throw new ChessApiException('Invalid club slug.');
        return $this->getJson('https://api.chess.com/pub/club/' . rawurlencode($slug) . '/matches', $this->matchTtl, $force);
    }

    private function getJson(string $url, int $ttl, bool $force): array
    {
        $cachePath = $this->cacheDir . '/' . hash('sha256', $url) . '.json';
        if (!$force && is_file($cachePath) && (time() - (int)filemtime($cachePath)) <= max(0, $ttl)) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached)) return $cached;
        }

        if (!function_exists('curl_init')) throw new ChessApiException('PHP cURL is required for Chess.com access.');
        $ch = curl_init($url);
        if ($ch === false) throw new ChessApiException('Unable to initialize Chess.com request.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            if (!$force && is_file($cachePath)) {
                $cached = json_decode((string)file_get_contents($cachePath), true);
                if (is_array($cached)) return $cached;
            }
            $message = $status === 404 ? 'Chess.com could not find this item.' : 'Chess.com data is temporarily unavailable.';
            if ($status === 0 && $error !== '') $message .= ' ' . $error;
            throw new ChessApiException($message, $status);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) throw new ChessApiException('Chess.com returned invalid JSON.', $status);

        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
        if (is_dir($this->cacheDir)) {
            $tmp = tempnam($this->cacheDir, '.cache.');
            if ($tmp !== false) {
                @file_put_contents($tmp, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                @chmod($tmp, 0664);
                @rename($tmp, $cachePath);
                if (is_file($tmp)) @unlink($tmp);
            }
        }
        return $decoded;
    }
}
