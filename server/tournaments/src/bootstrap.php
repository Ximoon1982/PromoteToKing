<?php
declare(strict_types=1);

const P2K_TOURNAMENT_ROOT = __DIR__ . '/..';
const P2K_TOURNAMENT_SITE_ROOT = __DIR__ . '/../../..';

require_once P2K_TOURNAMENT_SITE_ROOT . '/server/team-points/src/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'P2K\\Tournaments\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

function p2k_tournament_config(): array
{
    static $config = null;
    if (is_array($config)) return $config;
    $local = P2K_TOURNAMENT_ROOT . '/config/config.local.php';
    $config = require (is_file($local) ? $local : P2K_TOURNAMENT_ROOT . '/config/config.example.php');
    if (!is_array($config)) throw new RuntimeException('Tournament configuration must return an array.');
    return $config;
}

function p2k_tournament_archive_path(): string { return P2K_TOURNAMENT_SITE_ROOT . '/data/tournaments/archive.json'; }
function p2k_tournament_cache_dir(): string { return P2K_TOURNAMENT_SITE_ROOT . '/data/tournaments/cache'; }
function p2k_tournament_lock_path(): string { return P2K_TOURNAMENT_SITE_ROOT . '/data/tournaments/locks/refresh.lock'; }
function p2k_tournament_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
