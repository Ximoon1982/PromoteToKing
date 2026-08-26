<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\GatewayRetryableException;
use P2K\Shared\SharedChessGateway;

/** Backward-compatible Team Points adapter for the shared Chess.com gateway. */
final class ChessApi
{
    private readonly SharedChessGateway $gateway;
    private float $deadlineAt = 0.0;

    public function __construct(private readonly Repository $repository)
    {
        $this->gateway = new SharedChessGateway(null, \p2k_tp_config()['app'] ?? []);
    }

    public function setDeadlineAt(float $deadlineAt): void { $this->deadlineAt=max(0.0,$deadlineAt); }

    public function json(string $url, bool $force = false): array
    {
        try {
            $result = $this->gateway->json($url, $this->options($url,$force,'team-points',false));
        } catch (GatewayRetryableException $exception) {
            throw new RetryableException($exception->getMessage(), $exception->retryAfterSeconds);
        }
        if (!is_array($result)) throw new \RuntimeException("Chess.com returned no JSON payload for {$url}");
        return $result;
    }

    public function jsonIfExists(string $url, bool $force = false): ?array
    {
        try {
            return $this->gateway->json($url, $this->options($url,$force,'team-points-discovery',true));
        } catch (GatewayRetryableException $exception) {
            throw new RetryableException($exception->getMessage(), $exception->retryAfterSeconds);
        }
    }

    /** @return array<string,mixed> */
    private function options(string $url,bool $force,string $consumer,bool $allowNotFound): array
    {
        $path=strtolower((string)(parse_url($url,PHP_URL_PATH)?:''));
        $ttl=1800;$stale=7*86400;
        if(preg_match('~/pub/player/[^/]+$~',$path)){$ttl=30*86400;$stale=180*86400;}
        elseif(preg_match('~/pub/player/[^/]+/stats$~',$path)){$ttl=6*3600;$stale=30*86400;}
        elseif(preg_match('~/pub/player/[^/]+/matches$~',$path)){$ttl=1800;$stale=7*86400;}
        elseif(preg_match('~/pub/player/[^/]+/games/archives$~',$path)){$ttl=21600;$stale=30*86400;}
        elseif(preg_match('~/pub/player/[^/]+/games/(20\d{2})/(0[1-9]|1[0-2])$~',$path,$m)){
            $current=$m[1].'-'.$m[2]===gmdate('Y-m');$ttl=$current?3600:30*86400;$stale=365*86400;
        }
        elseif(preg_match('~/pub/club/[^/]+/members$~',$path)){$ttl=1800;$stale=86400;}
        elseif(preg_match('~/pub/club/[^/]+/matches$~',$path)){$ttl=300;$stale=86400;}
        elseif(str_contains($path,'/pub/match/')){$ttl=300;$stale=7*86400;}
        return ['force'=>$force,'consumer'=>$consumer,'allow_not_found'=>$allowNotFound,'cache_ttl_seconds'=>$ttl,'max_stale_seconds'=>$stale,'allow_stale_on_error'=>!$force,'canonical_verification'=>$force,'deadline_at'=>$this->deadlineAt,'reuse_attested_oauth_seconds'=>$force?120:0];
    }

    public function gatewayStatus(): array
    {
        return $this->gateway->status();
    }
}
