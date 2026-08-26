<?php
declare(strict_types=1);

const P2K_TP_ROOT = __DIR__ . '/..';

require_once dirname(__DIR__, 2) . '/shared/SharedChessGateway.php';
require_once dirname(__DIR__, 2) . '/shared/BoundedRateLimiter.php';
require_once dirname(__DIR__, 2) . '/shared/MatchTrackingRetention.php';
require_once dirname(__DIR__, 2) . '/shared/FilesystemRetention.php';
require_once dirname(__DIR__, 2) . '/shared/TaskRegistry.php';
require_once dirname(__DIR__, 2) . '/shared/TrafficAnalytics.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'P2K\\TeamPoints\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function p2k_tp_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $custom = getenv('P2K_TP_CONFIG');
    $path = $custom !== false && $custom !== ''
        ? $custom
        : P2K_TP_ROOT . '/config/config.local.php';

    if (!is_file($path)) {
        throw new RuntimeException(
            'Team Points server configuration is unavailable. Create the protected config.local.php from config.example.php on the server.'
        );
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('The Team Points configuration file must return an array.');
    }

    $config = $loaded;
    return $config;
}

function p2k_tp_utc_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function p2k_tp_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
        substr($hex, 16, 4), substr($hex, 20, 12)
    );
}

function p2k_tp_username_key(string $username): string
{
    return strtolower(trim($username));
}

function p2k_tp_json_decode(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}


/** Convert Chess.com PubAPI URLs into human-facing Chess.com URLs. */
function p2k_tp_chess_web_url(?string $url, string $sourceType = ''): string
{
    $url=trim((string)$url); if($url==='')return '';$parts=@parse_url($url);if(!is_array($parts))return '';$host=strtolower((string)($parts['host']??''));$path=(string)($parts['path']??'');
    if($host==='www.chess.com'||$host==='chess.com')return 'https://www.chess.com'.$path.(!empty($parts['query'])?'?'.$parts['query']:'');
    if($host!=='api.chess.com')return $url;
    if(preg_match('~^/pub/match/(\d+)(?:/|$)~i',$path,$m))return 'https://www.chess.com/club/matches/'.$m[1];
    if(preg_match('~^/pub/tournament/([^/]+)~i',$path,$m))return 'https://www.chess.com/tournament/'.rawurlencode(rawurldecode($m[1]));
    if(preg_match('~^/pub/player/([^/]+)~i',$path,$m))return 'https://www.chess.com/member/'.rawurlencode(rawurldecode($m[1]));
    if(preg_match('~^/pub/club/([^/]+)~i',$path,$m))return 'https://www.chess.com/club/'.rawurlencode(rawurldecode($m[1]));
    return '';
}


// v2.8.8 protected, best-effort endpoint performance telemetry.
if(PHP_SAPI!=='cli'&&!defined('P2K_TP_REQUEST_TELEMETRY_REGISTERED')){define('P2K_TP_REQUEST_TELEMETRY_REGISTERED',true);$p2kTpRequestStartedAt=microtime(true);register_shutdown_function(static function()use($p2kTpRequestStartedAt):void{try{\P2K\TeamPoints\RuntimeTelemetry::recordRequest($p2kTpRequestStartedAt);}catch(\Throwable){}});}
