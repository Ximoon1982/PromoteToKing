<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /** @var array<string,PDO> */
    private static array $connections = [];

    /** Backward-compatible alias: operational code defaults to Core. */
    public static function connection(): PDO
    {
        return self::core();
    }

    public static function core(): PDO
    {
        return self::named('core');
    }

    public static function analytics(): PDO
    {
        return self::named('analytics');
    }

    public static function named(string $name): PDO
    {
        $name = strtolower(trim($name));
        if (!in_array($name, ['core', 'analytics'], true)) {
            throw new RuntimeException("Unknown P2K database role: {$name}");
        }
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $all = \p2k_tp_config();
        $config = $all['databases'][$name] ?? null;
        // Transitional local-development fallback only. Production v2.8.0 should
        // configure both databases explicitly.
        if (!is_array($config) && $name === 'core' && is_array($all['database'] ?? null)) {
            $config = $all['database'];
        }
        if (!is_array($config)) {
            throw new RuntimeException("Missing {$name} database configuration. v2.8.0 requires separate Core and Analytics databases.");
        }
        foreach (['host', 'name', 'user', 'password'] as $key) {
            if (!isset($config[$key]) || trim((string)$config[$key]) === '') {
                throw new RuntimeException("Missing {$name} database configuration value: {$key}");
            }
        }

        $port = (int)($config['port'] ?? 3306);
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $connectTimeout = max(2, min(30, (int)($config['connect_timeout_seconds'] ?? 5)));
        $lockTimeout = max(2, min(30, (int)($config['lock_timeout_seconds'] ?? 5)));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $port, $config['name'], $charset);

        try {
            $pdo = new PDO($dsn, (string)$config['user'], (string)$config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => $connectTimeout,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException("Unable to connect to the P2K {$name} MariaDB database: " . $exception->getMessage(), 0, $exception);
        }

        foreach ([
            'SET SESSION innodb_lock_wait_timeout = ' . $lockTimeout,
            'SET SESSION lock_wait_timeout = ' . $lockTimeout,
        ] as $statement) {
            try { $pdo->exec($statement); } catch (\Throwable) { /* compatibility only */ }
        }
        self::$connections[$name] = $pdo;
        return $pdo;
    }

    public static function config(string $name): array
    {
        $all = \p2k_tp_config();
        $value = $all['databases'][$name] ?? ($name === 'core' ? ($all['database'] ?? null) : null);
        return is_array($value) ? $value : [];
    }

    public static function databaseName(string $name): string
    {
        return trim((string)(self::config($name)['name'] ?? ''));
    }

    public static function quotaBytes(string $name): int
    {
        return max(0, (int)(self::config($name)['quota_bytes'] ?? 0));
    }

    public static function resetConnections(): void
    {
        self::$connections = [];
    }
}
