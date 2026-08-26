<?php
declare(strict_types=1);

namespace P2K\Green;

use PDO;
use RuntimeException;

final class GreenConfig
{
    public const VERSION = '2.10.6.10';

    public static function siteRoot(): string { return dirname(__DIR__, 3); }
    public static function localPath(): string { return dirname(__DIR__) . '/config/green.local.php'; }

    public static function blueConfig(): array
    {
        $config = \p2k_tp_config();
        return is_array($config) ? $config : [];
    }

    public static function load(bool $required = true): array
    {
        $path = self::localPath();
        if (!is_file($path)) {
            if ($required) throw new RuntimeException('Green database configuration has not been installed yet.');
            return [];
        }
        $config = require $path;
        if (!is_array($config)) throw new RuntimeException('Green configuration file is invalid.');
        return $config;
    }

    public static function clubSlug(): string
    {
        $blue = self::blueConfig();
        return strtolower(trim((string)($blue['app']['club_slug'] ?? 'promote-to-king')));
    }

    public static function authorizeAdmin(): void
    {
        // v2.10.4.6: keep the historical administrator-token path for explicit
        // server/manual tooling, but normal browser administration uses the secured
        // Team Points HttpOnly session + CSRF authority. The browser no longer needs
        // a second Green-only credential after OAuth administrator login.
        $blue = self::blueConfig();
        $expected = trim((string)($blue['app']['admin_token'] ?? ''));
        $provided = trim((string)($_SERVER['HTTP_X_P2K_ADMIN_TOKEN'] ?? ''));
        if ($provided !== '' && $expected !== '' && strncmp($expected, 'CHANGE_', 7) !== 0 && hash_equals($expected, $provided)) return;
        try {
            \P2K\TeamPoints\Auth::requireAdmin();
            return;
        } catch (\P2K\TeamPoints\ApiException $exception) {
            self::json([
                'ok'=>false,
                'error'=>$exception->getMessage(),
                'error_code'=>$exception->errorCode,
            ], $exception->httpStatus);
        }
    }

    public static function authorizeCron(): void
    {
        $config = self::load();
        $expected = trim((string)($config['app']['cron_token'] ?? ''));
        $provided = trim((string)($_GET['token'] ?? ''));
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            self::json(['ok'=>false,'error'=>'Green CRON authorization failed.'], 403);
        }
    }

    public static function pdo(array $db): PDO
    {
        foreach (['host','name','user'] as $field) {
            if (trim((string)($db[$field] ?? '')) === '') throw new RuntimeException("Green database {$field} is required.");
        }
        $host=(string)$db['host']; $port=max(1,(int)($db['port'] ?? 3306)); $name=(string)$db['name'];
        $charset=(string)($db['charset'] ?? 'utf8mb4');
        $dsn="mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        return new PDO($dsn,(string)$db['user'],(string)($db['password'] ?? ''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>max(2,(int)($db['connect_timeout_seconds'] ?? 5)),
        ]);
    }

    public static function core(): PDO { $c=self::load(); return self::pdo((array)($c['databases']['core'] ?? [])); }
    public static function analytics(): PDO { $c=self::load(); return self::pdo((array)($c['databases']['analytics'] ?? [])); }

    private static function validateDatabases(array $payload): array
    {
        $dbs=[];
        foreach (['core','analytics'] as $key) {
            $raw=is_array($payload[$key] ?? null)?$payload[$key]:[];
            $dbs[$key]=[
                'host'=>trim((string)($raw['host'] ?? '')),
                'port'=>max(1,(int)($raw['port'] ?? 3306)),
                'name'=>trim((string)($raw['name'] ?? '')),
                'user'=>trim((string)($raw['user'] ?? '')),
                'password'=>(string)($raw['password'] ?? ''),
                'charset'=>'utf8mb4',
                'connect_timeout_seconds'=>5,
                'quota_bytes'=>2147483648,
            ];
            $pdo=self::pdo($dbs[$key]);
            $dbName=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if (strcasecmp($dbName,$dbs[$key]['name']) !== 0) throw new RuntimeException("Connected {$key} database identity did not match the requested database name.");
        }
        if (strcasecmp($dbs['core']['host'],$dbs['analytics']['host'])===0 && strcasecmp($dbs['core']['name'],$dbs['analytics']['name'])===0) {
            throw new RuntimeException('Green Core and Green Analytics must be two different databases.');
        }

        // Refuse an obvious Blue collision using the configured Blue names.
        $blue=self::blueConfig();
        $blueDbs=is_array($blue['databases'] ?? null)?$blue['databases']:[];
        foreach (['core','analytics'] as $gKey) {
            foreach (['core','analytics'] as $bKey) {
                $b=is_array($blueDbs[$bKey] ?? null)?$blueDbs[$bKey]:[];
                if ($b && strcasecmp(trim((string)($b['host']??'')),$dbs[$gKey]['host'])===0 && strcasecmp(trim((string)($b['name']??'')),$dbs[$gKey]['name'])===0) {
                    throw new RuntimeException("Green {$gKey} points at the existing Blue {$bKey} database. Refusing initialization.");
                }
            }
        }

        return $dbs;
    }

    public static function test(array $payload): array
    {
        $dbs=self::validateDatabases($payload);
        $details=[];
        foreach (['core','analytics'] as $key) {
            $pdo=self::pdo($dbs[$key]);
            $row=$pdo->query("SELECT VERSION() server_version, DATABASE() database_name, @@character_set_database charset_name, @@collation_database collation_name")->fetch() ?: [];
            $sizeQ=$pdo->prepare("SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?");
            $sizeQ->execute([$dbs[$key]['name']]);
            $tablesQ=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?");
            $tablesQ->execute([$dbs[$key]['name']]);
            $details[$key]=self::redact($dbs[$key])+[
                'server_version'=>(string)($row['server_version']??''),
                'database_name'=>(string)($row['database_name']??''),
                'charset'=>(string)($row['charset_name']??''),
                'collation'=>(string)($row['collation_name']??''),
                'estimated_bytes'=>(int)$sizeQ->fetchColumn(),
                'table_count'=>(int)$tablesQ->fetchColumn(),
            ];
        }
        return $details;
    }

    public static function save(array $payload): array
    {
        $dbs=self::validateDatabases($payload);
        $existing=self::load(false);
        $cron=(string)($existing['app']['cron_token'] ?? '');
        if ($cron==='') $cron=bin2hex(random_bytes(24));
        $config=[
            'version'=>self::VERSION,
            'databases'=>$dbs,
            'app'=>[
                'club_slug'=>self::clubSlug(),
                'cron_token'=>$cron,
                'worker_budget_seconds'=>42,
                'request_spacing_ms'=>650,
                'stats_refresh_seconds'=>259200,
                'historical_match_recheck_seconds'=>2592000,
                'historical_match_rechecks_per_cycle'=>25,
            ],
        ];
        $path=self::localPath(); $dir=dirname($path);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Unable to create Green config directory.');
        $php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(5));
        if (file_put_contents($tmp,$php,LOCK_EX)===false) throw new RuntimeException('Unable to write Green configuration.');
        @chmod($tmp,0600);
        if (!@rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('Unable to atomically install Green configuration.'); }
        @chmod($path,0600);
        return ['core'=>self::redact($dbs['core']),'analytics'=>self::redact($dbs['analytics']),'cron_token'=>$cron];
    }

    public static function redact(array $db): array
    {
        return ['host'=>$db['host']??'','port'=>$db['port']??3306,'name'=>$db['name']??'','user'=>$db['user']??'','password_set'=>((string)($db['password']??''))!==''];
    }

    public static function body(): array
    {
        $raw=file_get_contents('php://input');
        if ($raw===false || trim($raw)==='') return [];
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('JSON request body must be an object.');
        return $data;
    }

    public static function json(array $data,int $status=200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        exit;
    }
}
