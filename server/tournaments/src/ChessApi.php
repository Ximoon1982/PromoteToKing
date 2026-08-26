<?php
declare(strict_types=1);

namespace P2K\Tournaments;

use P2K\Shared\GatewayRetryableException;
use P2K\Shared\SharedChessGateway;
use P2K\TeamPoints\Database;

/** Tournament compatibility adapter backed by the site-wide Chess.com gateway. */
final class ChessApi
{
    public int $requestCount = 0;
    public int $cacheHits = 0;
    private readonly SharedChessGateway $gateway;

    public function __construct(private readonly array $config)
    {
        $teamApp = \p2k_tp_config()['app'] ?? [];
        $this->gateway = new SharedChessGateway(null, array_replace($config, $teamApp));
    }

    public function get(string $url, ?int $ttl = null, bool $allow404 = false): ?array
    {
        $beforeRequests = $this->gateway->requestCount();
        $beforeHits = $this->gateway->cacheHits();
        try {
            $result = $this->gateway->json($url, [
                'consumer' => 'tournaments',
                'cache_ttl_seconds' => $ttl ?? (int)($this->config['cache_ttl_seconds'] ?? 21600),
                'allow_not_found' => $allow404,
            ]);
        } catch (GatewayRetryableException $exception) {
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        } finally {
            $this->requestCount += max(0, $this->gateway->requestCount() - $beforeRequests);
            $this->cacheHits += max(0, $this->gateway->cacheHits() - $beforeHits);
        }
        if ($result === null && !$allow404) throw new \RuntimeException('Chess.com resource not found: ' . $url);
        return $result;
    }

    public function gatewayStatus(): array { return $this->gateway->status(); }
}
