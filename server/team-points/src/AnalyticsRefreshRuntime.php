<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\FilesystemCache;

/** Filesystem and retry mechanics for AnalyticsBuilder's compatibility facade. */
final class AnalyticsRefreshRuntime
{
    public static function paths(string $clubSlug, string $domain = 'all'): array
    {
        $config = \p2k_tp_config();
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        // Protected configuration is anchored at the physical site root even when
        // a CLI worker executes inside a read-only Release Control slot.
        // Its default runtime must remain shared across promotions and rollbacks.
        if (trim((string)($storage['runtime_dir'] ?? '')) === '') {
            $configPath = getenv('P2K_TP_CONFIG');
            $suffix = '/server/team-points/config/config.local.php';
            if (is_string($configPath) && str_ends_with(str_replace('\\\\', '/', $configPath), $suffix)) {
                $siteRoot = substr(str_replace('\\\\', '/', $configPath), 0, -strlen($suffix));
                if ($siteRoot !== '') $storage['runtime_dir'] = rtrim($siteRoot, '/') . '/data/runtime-v280';
            }
        }
        $root = FilesystemCache::runtimeRoot($storage) . '/analytics';
        FilesystemCache::ensureProtectedDirectory($root);
        $slug = preg_replace('/[^a-z0-9_-]+/i', '-', $clubSlug);
        $suffix = $domain === 'all' ? '' : '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $domain);
        return [$root . '/refresh-' . $slug . $suffix . '.json', $root . '/refresh-' . $slug . $suffix . '.lock'];
    }

    public static function completedEpoch(string $marker): int
    {
        if (!is_file($marker)) return 0;
        $payload = json_decode((string)@file_get_contents($marker), true);
        return is_array($payload) ? (int)($payload['completed_epoch'] ?? 0) : 0;
    }

    public static function isLockWaitTimeout(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'lock wait timeout') || str_contains($message, 'sqlstate[hy000]') && str_contains($message, '1205')) return true;
        if ($exception instanceof \PDOException && is_array($exception->errorInfo ?? null)) {
            return (int)($exception->errorInfo[1] ?? 0) === 1205;
        }
        return false;
    }
}
