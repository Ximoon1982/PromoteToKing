<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use RuntimeException;

/**
 * v2.11.0 production read router.
 *
 * Green Core + Analytics are the only production Team Points databases. Blue is a
 * static recovery/reference copy and is never selected automatically. A Green
 * configuration/schema failure is therefore fail-closed instead of silently routing
 * live traffic back to Blue.
 */
final class PublicReadDatabase
{
    private static ?PDO $greenCore = null;
    private static ?PDO $greenAnalytics = null;
    private static bool $validated = false;

    public static function source(): string
    {
        self::connectGreen();
        return 'green';
    }

    public static function core(): PDO
    {
        self::connectGreen();
        if (!self::$greenCore) {
            throw new RuntimeException('Green Core production connection is unavailable.');
        }
        return self::$greenCore;
    }

    public static function connection(): PDO
    {
        return self::core();
    }

    public static function analytics(): PDO
    {
        self::connectGreen();
        if (!self::$greenAnalytics) {
            throw new RuntimeException('Green Analytics production connection is unavailable.');
        }
        return self::$greenAnalytics;
    }

    public static function reset(): void
    {
        self::$greenCore = null;
        self::$greenAnalytics = null;
        self::$validated = false;
    }

    private static function connectGreen(): void
    {
        if (self::$validated && self::$greenCore && self::$greenAnalytics) return;

        $root = dirname(__DIR__, 3);
        $greenConfig = $root . '/server/team-points-green/src/GreenConfig.php';
        if (!is_file($greenConfig)) {
            throw new RuntimeException('Green database support is required by Promote to King 2.11.0.');
        }

        require_once $greenConfig;
        $core = \P2K\Green\GreenConfig::core();
        $analytics = \P2K\Green\GreenConfig::analytics();

        $coreReady = (int)$core->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn() >= 17;
        $analyticsReady = (int)$analytics->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn() >= 9;
        if (!$coreReady || !$analyticsReady) {
            throw new RuntimeException('Green production schema is incomplete; Blue fallback is disabled in Promote to King 2.11.0.');
        }

        self::$greenCore = $core;
        self::$greenAnalytics = $analytics;
        self::$validated = true;
    }
}
