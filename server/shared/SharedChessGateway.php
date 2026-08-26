<?php
declare(strict_types=1);

namespace P2K\Shared;

use PDO;

require_once __DIR__ . '/FilesystemCache.php';

final class GatewayRetryableException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30)
    {
        parent::__construct($message);
    }
}

/**
 * v2.8.0 shared Chess.com gateway.
 *
 * API payloads, gateway state and the inter-process rate-limit lock are all kept
 * on the protected filesystem. MariaDB is deliberately not part of the request
 * path, so a DB outage cannot grow/poison a cache table or prevent non-DB API
 * features from using Chess.com.
 */
final class SharedChessGateway
{
    private int $requestCount = 0;
    private int $cacheHits = 0;
    private readonly array $app;
    private readonly array $storage;
    private readonly FilesystemCache $cache;
    private string $statePath;
    private string $lockPath;
    /** @var resource|null */
    private $lockHandle = null;

    /** PDO is accepted for backward source compatibility but intentionally unused. */
    public function __construct(?PDO $pdo = null, array $app = [])
    {
        $this->app = $app;
        $config = function_exists('p2k_tp_config') ? \p2k_tp_config() : [];
        $this->storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $this->cache = new FilesystemCache($this->storage);
        $runtime = FilesystemCache::runtimeRoot($this->storage) . '/gateway';
        FilesystemCache::ensureProtectedDirectory($runtime);
        $this->statePath = $runtime . '/state.json';
        $this->lockPath = $runtime . '/gateway.lock';
        if (!is_file($this->statePath)) {
            $this->writeState([
                'health_status' => 'unknown',
                'health_message' => null,
                'known_valid_url' => (string)($this->app['gateway_health_url'] ?? 'https://api.chess.com/pub/club/promote-to-king'),
                'last_health_check_at' => null,
                'last_success_at' => null,
                'last_request_at' => null,
                'consecutive_failures' => 0,
                'incident_started_at' => null,
                'updated_at' => gmdate('c'),
            ]);
        }
    }

    /** Kept as a no-op for callers from pre-2.8.0. */
    public static function ensureSchema(PDO $pdo): void {}

    /** @return array<string,mixed>|null */
    public function json(string $url, array $options = []): ?array
    {
        $allowNotFound = (bool)($options['allow_not_found'] ?? false);
        $force = (bool)($options['force'] ?? false);
        $consumer = substr((string)($options['consumer'] ?? 'shared'), 0, 80);
        $ttl = max(0, (int)($options['cache_ttl_seconds'] ?? $this->app['http_cache_ttl_seconds'] ?? 21600));
        $maxStale = max(0, (int)($options['max_stale_seconds'] ?? $this->app['gateway_max_stale_seconds'] ?? 604800));
        $canonicalVerification = (bool)($options['canonical_verification'] ?? false);
        $allowStale = (bool)($options['allow_stale_on_error'] ?? false) && !$canonicalVerification;
        $healthProbe = (bool)($options['health_probe'] ?? false);
        $deadlineAt = max(0.0, (float)($options['deadline_at'] ?? 0.0));
        $attestedReuseSeconds = max(0, min(600, (int)($options['reuse_attested_oauth_seconds'] ?? 0)));

        $cache = $this->cache->get($url);
        $attestedReusable = $force && $canonicalVerification && $attestedReuseSeconds > 0 && $this->isRecentAttestedOAuth($cache, $attestedReuseSeconds);
        if ((!$force || $attestedReusable) && $this->isFresh($cache)) {
            $this->cacheHits++;
            $status = (int)($cache['status_code'] ?? 0);
            if ($allowNotFound && in_array($status, [404, 410], true)) return null;
            return $this->decodeCache($cache);
        }

        $lockWait = $this->lockWaitSeconds($deadlineAt);
        if ($lockWait <= 0.0 || !$this->acquireLock($lockWait)) {
            if ($allowStale && $this->isUsableStale($cache, $maxStale)) {
                $this->cacheHits++;
                return $this->decodeCache($cache);
            }
            throw new GatewayRetryableException('The shared Chess.com gateway is busy.', 5);
        }

        try {
            $cache = $this->cache->get($url);
            $attestedReusable = $force && $canonicalVerification && $attestedReuseSeconds > 0 && $this->isRecentAttestedOAuth($cache, $attestedReuseSeconds);
            if ((!$force || $attestedReusable) && $this->isFresh($cache)) {
                $this->cacheHits++;
                $status = (int)($cache['status_code'] ?? 0);
                if ($allowNotFound && in_array($status, [404, 410], true)) return null;
                return $this->decodeCache($cache);
            }

            $this->respectGlobalDelay();
            $headers = [
                'Accept: application/json',
                'User-Agent: ' . (string)($this->app['shared_gateway_user_agent'] ?? $this->app['user_agent'] ?? 'PromoteToKing-Gateway/2.9.5 (+https://www.promotetoking.org/)'),
            ];
            if (!empty($cache['etag'])) $headers[] = 'If-None-Match: ' . $cache['etag'];
            if (!empty($cache['last_modified'])) $headers[] = 'If-Modified-Since: ' . $cache['last_modified'];
            // CTAR: the lock protects only launch pacing/state reservation. Never hold
            // one global exclusive lock across the upstream HTTP transfer.
            $this->recordRequestTimestamp();
            $this->releaseLock();
            $response = $this->request($url, $headers, $deadlineAt);
            $this->requestCount++;

            $status = (int)$response['status'];
            if ($status === 304 && $cache !== null && !empty($cache['body_json'])) {
                $this->cache->put($url, 200, (string)$cache['body_json'], $response['headers']['etag'] ?? ($cache['etag'] ?? null), $response['headers']['last-modified'] ?? ($cache['last_modified'] ?? null), $ttl, $consumer);
                $this->markHealthy('Conditional Chess.com response succeeded.');
                return $this->decodeCache($cache);
            }

            if (in_array($status, [404, 410], true)) {
                if ($healthProbe) {
                    $this->markUnavailable("Known-valid Chess.com health endpoint returned HTTP {$status}.");
                    throw new GatewayRetryableException('Chess.com PubAPI health check failed with an abnormal not-found response.', 300);
                }
                if (!$this->probeHealthInsideLock()) {
                    if ($allowStale && $this->isUsableStale($cache, $maxStale)) {
                        $this->cacheHits++;
                        return $this->decodeCache($cache);
                    }
                    throw new GatewayRetryableException('Chess.com PubAPI is unhealthy; not-found responses are not being treated as conclusive.', 300);
                }
                if ($allowNotFound) {
                    $this->cache->put($url, $status, null, $response['headers']['etag'] ?? null, $response['headers']['last-modified'] ?? null, min($ttl, 21600), $consumer);
                    return null;
                }
                if ($allowStale && $this->isUsableStale($cache, $maxStale)) {
                    $this->cacheHits++;
                    return $this->decodeCache($cache);
                }
                throw new \RuntimeException("Chess.com returned HTTP {$status} for {$url}");
            }

            if ($status === 429) {
                $retry = max(5, (int)($response['headers']['retry-after'] ?? 60));
                $this->markDegraded('Chess.com rate limit reached.');
                if ($allowStale && $this->isUsableStale($cache, $maxStale)) {
                    $this->cacheHits++;
                    return $this->decodeCache($cache);
                }
                throw new GatewayRetryableException("Chess.com rate-limited {$url}", $retry);
            }
            if ($status >= 500 || $status === 0) {
                $this->markDegraded("Chess.com returned HTTP {$status}.");
                if ($allowStale && $this->isUsableStale($cache, $maxStale)) {
                    $this->cacheHits++;
                    return $this->decodeCache($cache);
                }
                throw new GatewayRetryableException("Chess.com returned HTTP {$status} for {$url}", 30);
            }
            if ($status < 200 || $status >= 300) throw new \RuntimeException("Chess.com returned HTTP {$status} for {$url}");

            $body = (string)$response['body'];
            try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new GatewayRetryableException("Chess.com returned invalid JSON for {$url}", 30); }
            if (!is_array($decoded)) throw new GatewayRetryableException("Chess.com returned an unexpected payload for {$url}", 30);

            $this->cache->put($url, $status, $body, $response['headers']['etag'] ?? null, $response['headers']['last-modified'] ?? null, $ttl, $consumer);
            $this->markHealthy('Chess.com request succeeded.');
            return $decoded;
        } finally {
            $this->releaseLock();
        }
    }

    public function status(): array
    {
        $state = $this->readState();
        $cache = $this->cache->stats();
        return [
            'health_status' => (string)($state['health_status'] ?? 'unknown'),
            'health_message' => $state['health_message'] ?? null,
            'known_valid_url' => $state['known_valid_url'] ?? null,
            'last_health_check_at' => $state['last_health_check_at'] ?? null,
            'last_success_at' => $state['last_success_at'] ?? null,
            'last_request_at' => $state['last_request_at'] ?? null,
            'consecutive_failures' => (int)($state['consecutive_failures'] ?? 0),
            'incident_started_at' => $state['incident_started_at'] ?? null,
            'cache_entries' => (int)($cache['entries'] ?? 0),
            'fresh_cache_entries' => (int)($cache['fresh_entries'] ?? 0),
            'cache_bytes' => (int)($cache['bytes'] ?? 0),
            'cache_max_bytes' => (int)($cache['max_bytes'] ?? 0),
            'cache_backend' => 'gzip_filesystem',
            'request_count' => $this->requestCount,
            'cache_hits' => $this->cacheHits,
            'false_404_protection' => true,
            'database_independent' => true,
        ];
    }

    public function requestCount(): int { return $this->requestCount; }
    public function cacheHits(): int { return $this->cacheHits; }

    private function probeHealthInsideLock(): bool
    {
        $state = $this->readState();
        $last = !empty($state['last_health_check_at']) ? strtotime((string)$state['last_health_check_at']) : false;
        if ($last !== false && $last > time() - 120) return (string)($state['health_status'] ?? '') === 'healthy';
        $url = trim((string)($state['known_valid_url'] ?? $this->app['gateway_health_url'] ?? 'https://api.chess.com/pub/club/promote-to-king'));
        if (!$this->acquireLock(1.5)) return false;
        try { $this->respectGlobalDelay(); $this->recordRequestTimestamp(); }
        finally { $this->releaseLock(); }
        $response = $this->request($url, [
            'Accept: application/json',
            'User-Agent: ' . (string)($this->app['shared_gateway_user_agent'] ?? $this->app['user_agent'] ?? 'PromoteToKing-Gateway/2.9.5 (+https://www.promotetoking.org/)'),
        ]);
        $this->requestCount++;
        $status = (int)$response['status'];
        if ($status >= 200 && $status < 300) { $this->markHealthy('Known-valid Chess.com health endpoint returned successfully.'); return true; }
        $this->markUnavailable("Known-valid Chess.com health endpoint returned HTTP {$status}.");
        return false;
    }

    private function isFresh(?array $cache): bool
    {
        if ($cache === null) return false;
        $expires = (int)($cache['_expires_epoch'] ?? 0);
        return $expires > time();
    }

    private function isUsableStale(?array $cache, int $maxStale): bool
    {
        if ($cache === null || empty($cache['body_json']) || (int)($cache['status_code'] ?? 0) !== 200) return false;
        return (int)($cache['_fetched_epoch'] ?? 0) >= time() - $maxStale;
    }

    private function decodeCache(array $cache): array
    {
        $decoded = json_decode((string)($cache['body_json'] ?? ''), true);
        if (!is_array($decoded)) throw new GatewayRetryableException('The filesystem Chess.com cache contains invalid JSON.', 30);
        return $decoded;
    }


    private function lockWaitSeconds(float $deadlineAt): float
    {
        if ($deadlineAt <= 0.0) return 2.0;
        return max(0.0, min(2.0, $deadlineAt - microtime(true) - 1.5));
    }

    private function isRecentAttestedOAuth(?array $cache, int $seconds): bool
    {
        if ($cache === null || (int)($cache['status_code'] ?? 0) !== 200) return false;
        if (!str_starts_with((string)($cache['consumer'] ?? ''), 'oauth-attested')) return false;
        return (int)($cache['_fetched_epoch'] ?? 0) >= time() - max(1, $seconds);
    }

    public function ingestAttestedOAuth(string $url, int $status, string $body, array $headers = [], int $ttlSeconds = 120): bool
    {
        if ($status < 200 || $status >= 300 || $body === '') return false;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return false;
        $this->cache->put($url, $status, $body, (string)($headers['etag'] ?? '') ?: null, (string)($headers['last-modified'] ?? '') ?: null, max(30, min(600, $ttlSeconds)), 'oauth-attested-v2917');
        return true;
    }

    private function acquireLock(float $seconds): bool
    {
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false) throw new GatewayRetryableException('Unable to open the filesystem gateway lock.', 30);
        $deadline = microtime(true) + max(0, $seconds);
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) { $this->lockHandle = $handle; return true; }
            usleep(100000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        return false;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    private function respectGlobalDelay(): void
    {
        $delayMs = max(0, (int)($this->app['shared_gateway_request_delay_ms'] ?? $this->app['request_delay_ms'] ?? 350));
        if ($delayMs <= 0) return;
        $state = $this->readState();
        $last = isset($state['last_request_epoch_micro']) ? (float)$state['last_request_epoch_micro'] : 0.0;
        if ($last <= 0) return;
        $remaining = ($delayMs / 1000) - (microtime(true) - $last);
        if ($remaining > 0) usleep((int)round($remaining * 1000000));
    }

    private function recordRequestTimestamp(): void
    {
        $state = $this->readState();
        $state['last_request_epoch_micro'] = microtime(true);
        $state['last_request_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $this->writeState($state);
    }

    private function markHealthy(string $message): void
    {
        $state = $this->readState();
        $state['health_status']='healthy'; $state['health_message']=$message; $state['last_health_check_at']=gmdate('c');
        $state['last_success_at']=gmdate('c'); $state['consecutive_failures']=0; $state['incident_started_at']=null; $state['updated_at']=gmdate('c');
        $this->writeState($state);
    }

    private function markDegraded(string $message): void
    {
        $state = $this->readState();
        $state['health_status']='degraded'; $state['health_message']=$message; $state['last_health_check_at']=gmdate('c');
        $state['consecutive_failures']=(int)($state['consecutive_failures']??0)+1;
        $state['incident_started_at']=$state['incident_started_at']??gmdate('c'); $state['updated_at']=gmdate('c');
        $this->writeState($state);
    }

    private function markUnavailable(string $message): void
    {
        $state = $this->readState();
        $state['health_status']='unavailable'; $state['health_message']=$message; $state['last_health_check_at']=gmdate('c');
        $state['consecutive_failures']=(int)($state['consecutive_failures']??0)+1;
        $state['incident_started_at']=$state['incident_started_at']??gmdate('c'); $state['updated_at']=gmdate('c');
        $this->writeState($state);
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $raw = @file_get_contents($this->statePath);
        if ($raw === false || $raw === '') return [];
        $state = json_decode($raw, true);
        return is_array($state) ? $state : [];
    }

    private function writeState(array $state): void
    {
        $tmp = $this->statePath . '.tmp-' . bin2hex(random_bytes(3));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) return;
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, $this->statePath);
        @unlink($tmp);
    }

    private function request(string $url, array $headers, float $deadlineAt = 0.0): array
    {
        $configured = max(3, min(30, (int)($this->app['request_timeout_seconds'] ?? 15)));
        $remaining = $deadlineAt > 0.0 ? max(0.0, $deadlineAt - microtime(true) - 0.75) : (float)$configured;
        if ($deadlineAt > 0.0 && $remaining < 2.0) throw new GatewayRetryableException('The authoritative request deadline was reached before upstream launch.', 5);
        $timeout = max(2, min($configured, (int)floor($remaining)));
        if (function_exists('curl_init')) {
            $responseHeaders = [];
            $curl = curl_init($url);
            if ($curl === false) throw new GatewayRetryableException('Unable to initialize cURL.', 30);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,CURLOPT_FOLLOWLOCATION => true,CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => min(4,$timeout),CURLOPT_TIMEOUT => $timeout,CURLOPT_HTTPHEADER => $headers,CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($handle,string $line) use (&$responseHeaders): int {
                    $length=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return $length;
                },
            ]);
            $body=curl_exec($curl);
            if($body===false){$error=curl_error($curl);curl_close($curl);throw new GatewayRetryableException('Unable to reach Chess.com: '.$error,30);}
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            return ['status'=>$status,'headers'=>$responseHeaders,'body'=>(string)$body];
        }
        $context=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'timeout'=>$timeout,'ignore_errors'=>true]]);
        $body=@file_get_contents($url,false,$context);$rawHeaders=$http_response_header??[];
        if($body===false&&$rawHeaders===[])throw new GatewayRetryableException("Unable to reach Chess.com for {$url}",30);
        $status=0;$parsed=[];foreach($rawHeaders as $index=>$line){if($index===0&&preg_match('/\s(\d{3})\s/',$line,$match)){$status=(int)$match[1];continue;}$parts=explode(':',$line,2);if(count($parts)===2)$parsed[strtolower(trim($parts[0]))]=trim($parts[1]);}
        return ['status'=>$status,'headers'=>$parsed,'body'=>$body===false?'':$body];
    }
}
