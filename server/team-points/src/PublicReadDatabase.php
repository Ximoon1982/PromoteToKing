<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use RuntimeException;

/**
 * Explicit source router for public/read-compatible Team Points endpoints.
 *
 * Blue workers continue using Database::core()/analytics() directly. Only endpoints
 * which explicitly opt into this class can switch to Green, preventing a public
 * cutover from redirecting Blue workers by accident.
 *
 * Once the Green state is reachable and explicitly says public_read_target=green,
 * the router uses Green immediately. Bootstrap/convergence completeness is operational
 * telemetry, not a routing prerequisite. The router remains fail-closed only for
 * technical failures that would make Green reads unsafe to execute at all (connection
 * or schema incompatibility), rather than silently falling back to Blue after cutover.
 */
final class PublicReadDatabase
{
    private static ?string $source = null;
    private static ?PDO $greenCore = null;
    private static ?PDO $greenAnalytics = null;

    public static function source(): string
    {
        if (self::$source !== null) return self::$source;
        self::$source = 'blue';
        $greenSelected=false;
        try {
            $root = dirname(__DIR__, 3);
            $greenConfig = $root . '/server/team-points-green/src/GreenConfig.php';
            if (!is_file($greenConfig)) return self::$source;
            require_once $greenConfig;
            $core = \P2K\Green\GreenConfig::core();
            $q = $core->prepare("SELECT public_read_target FROM p2k_g_state WHERE club_slug=? LIMIT 1");
            $q->execute([\P2K\Green\GreenConfig::clubSlug()]);
            $state = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state) || (string)($state['public_read_target'] ?? 'blue') !== 'green') return self::$source;

            $greenSelected=true;
            $analytics = \P2K\Green\GreenConfig::analytics();
            $coreReady = (int)$core->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn() >= 17;
            $analyticsReady = (int)$analytics->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn() >= 9;
            if (!$coreReady || !$analyticsReady) throw new RuntimeException('Green compatibility schema is incomplete after public cutover.');
            self::$greenCore = $core; self::$greenAnalytics = $analytics; self::$source = 'green';
        } catch (\Throwable $e) {
            self::$greenCore = null; self::$greenAnalytics = null;
            if ($greenSelected) {
                self::$source = 'green';
                error_log('P2K Green public-read router fail-closed: '.$e->getMessage());
                throw $e;
            }
            // Before Green is explicitly selected, Blue remains the rollback-safe default.
            error_log('P2K Green public-read router pre-cutover fallback: '.$e->getMessage());
            self::$source = 'blue';
        }
        return self::$source;
    }

    public static function core(): PDO
    {
        if (self::source()==='green') {
            if (!self::$greenCore) throw new RuntimeException('Green Core public-read connection is unavailable after cutover.');
            return self::$greenCore;
        }
        return Database::core();
    }
    public static function connection(): PDO { return self::core(); }
    public static function analytics(): PDO
    {
        if (self::source()==='green') {
            if (!self::$greenAnalytics) throw new RuntimeException('Green Analytics public-read connection is unavailable after cutover.');
            return self::$greenAnalytics;
        }
        return Database::analytics();
    }
    public static function reset(): void { self::$source=null;self::$greenCore=null;self::$greenAnalytics=null; }
}
