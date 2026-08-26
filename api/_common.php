<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/team-points/src/bootstrap.php';
require_once dirname(__DIR__) . '/server/shared/TrafficAnalytics.php';
require_once dirname(__DIR__) . '/server/shared/MatchTrackingRetention.php';

const API_RUNTIME_USER_AGENT = 'ClubTools/2.9.3';
const DEFAULT_CLUB_SLUG = 'promote-to-king';
const SUPPORTED_LEAGUES = ['1WL', 'TCMAC', 'KOTML', 'TMCL', 'WKCL', 'PCL', 'CW'];
const MAX_DAYS = 366;
const MAX_ENTRIES = 2000;
const MAX_SNAPSHOTS = 2000;
const MATCH_MONITORING_FIRST_DAY_SECONDS = 86400;
const MATCH_MONITORING_DEFAULT_SECONDS = 43200;
const MATCH_MONITORING_96H_SECONDS = 21600;
const MATCH_MONITORING_48H_SECONDS = 3600;
const MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS = 86400;

function root_dir(): string { return dirname(__DIR__); }
function utc_stamp(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function chess_api_base(): string {
    $base = trim((string)(getenv('CLUB_TOOLS_CHESS_API_BASE') ?: 'https://api.chess.com/pub'));
    return rtrim($base, '/');
}
function chess_match_url(string $id): string { return chess_api_base().'/match/'.$id; }
function chess_club_matches_url(): string { return chess_api_base().'/club/'.DEFAULT_CLUB_SLUG.'/matches'; }
function tracking_root(): string { return root_dir().'/data/match-tracking'; }
function history_root(): string { return tracking_root().'/matches'; }
function follow_registry_path(): string { return tracking_root().'/index.json'; }
function quarantine_root(): string { return tracking_root().'/quarantine'; }
function legacy_history_root(): string { return root_dir().'/data/match-history'; }
function legacy_follow_registry_path(): string { return root_dir().'/data/followed-matches.json'; }

function json_response(int $status, array $value): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"error":{"code":"ENCODING_FAILED","message":"The server could not encode the response."}}';
    }
    header('Content-Length: '.strlen($json));
    echo $json;
    exit;
}
function api_error(int $status, string $code, string $message, array $extra = []): never {
    json_response($status, array_merge(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $extra));
}
function body_json(int $limit = 2097152): array {
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') api_error(415, 'JSON_REQUIRED', 'Content-Type must be application/json.');
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 0 || $length > $limit) api_error(413, 'PAYLOAD_TOO_LARGE', 'Request body is too large.');
    $raw = file_get_contents('php://input');
    $value = json_decode($raw ?: '', true);
    if (!is_array($value)) api_error(400, 'INVALID_REQUEST', 'Request body must be a JSON object.');
    return $value;
}
function same_origin(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return true;
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $parts = parse_url($origin);
    return is_array($parts)
        && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        && strtolower(($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')) === $host;
}
function require_write_header(string $value): void {
    if (!same_origin()) api_error(403, 'ORIGIN_MISMATCH', 'Cross-origin requests are not allowed.');
    $neutral = (string)($_SERVER['HTTP_X_CLUB_TOOLS_REQUEST'] ?? '');
    $legacy = (string)($_SERVER['HTTP_X_P2K_REQUEST'] ?? '');
    if ($neutral !== $value && $legacy !== $value) {
        api_error(400, 'REQUEST_HEADER_REQUIRED', 'Missing X-Club-Tools-Request header.');
    }
}
function require_admin_write(string $value): void {
    require_write_header($value);
    \P2K\TeamPoints\Auth::requireAdmin();
}
function read_json_file(string $path, array $default = []): array {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false) return $default;
    $value = json_decode($raw, true);
    return is_array($value) ? $value : $default;
}
function atomic_json(string $path, array $value, bool $backup = false): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create storage directory.');
    $tmp = tempnam($dir, '.'.basename($path).'.');
    if ($tmp === false) throw new RuntimeException('Unable to create temporary file.');
    $payload = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false || file_put_contents($tmp, $payload."\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write storage file.');
    }
    if ($backup && is_file($path)) @copy($path, $path.'.bak');
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace storage file.');
    }
}
function append_jsonl(string $dir, array $entry): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create log directory.');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false || file_put_contents($dir.'/'.gmdate('Y-m-d').'.jsonl', $line."\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to append log entry.');
    }
}

function match_id(mixed $value): string {
    $text = trim((string)$value);
    if ($text === '') throw new InvalidArgumentException('Enter a Chess.com match ID, URL, or slug.');
    if (preg_match('/^\d+$/', $text, $match)) return $match[0];
    $path = (string)(parse_url($text, PHP_URL_PATH) ?? $text);
    if (preg_match('/(?:^|[\/-])(\d+)(?:\/?$)/', rtrim($path, '/'), $match)) return $match[1];
    if (preg_match('/(?:^|[-_])(\d{4,})(?:$|[?#])/', $text, $match)) return $match[1];
    if (preg_match('/(\d{4,})(?!.*\d)/', $text, $match)) return $match[1];
    throw new InvalidArgumentException('The match reference does not contain a numeric Chess.com match ID.');
}
function league_codes(mixed $value): array {
    $text = strtoupper((string)$value);
    $out = [];
    foreach (SUPPORTED_LEAGUES as $code) {
        if (preg_match('/(^|[^A-Z0-9])'.preg_quote($code, '/').'([^A-Z0-9]|$)/', $text)) $out[] = $code;
    }
    return $out;
}
function chess_json(string $url): array {
    // The explicit local/mock base is retained for the packaged test and local
    // development servers. Production Chess.com traffic always uses the shared
    // database-backed gateway below.
    if (trim((string)getenv('CLUB_TOOLS_CHESS_API_BASE')) !== '') {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: " . API_RUNTIME_USER_AGENT . "\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if ($headers && preg_match('/\s(\d{3})\s/', (string)$headers[0], $match)) $status = (int)$match[1];
        if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException("Chess API returned HTTP {$status} for {$url}");
        $value = json_decode($body, true);
        if (!is_array($value)) throw new RuntimeException('Chess API returned invalid JSON.');
        return $value;
    }
    $gateway = new \P2K\Shared\SharedChessGateway(
        \P2K\TeamPoints\Database::connection(),
        \p2k_tp_config()['app'] ?? []
    );
    $value = $gateway->json($url, [
        'consumer' => 'league-match-tracking',
        'cache_ttl_seconds' => 300,
        'allow_not_found' => false,
    ]);
    if (!is_array($value)) throw new RuntimeException('Chess.com returned no JSON payload.');
    return $value;
}


function registry_from_path(string $path): array {
    $raw = read_json_file($path, []);
    $matches = $raw['matches'] ?? [];
    if (array_is_list($matches)) {
        $map = [];
        foreach ($matches as $entry) if (is_array($entry) && isset($entry['matchId'])) $map[(string)$entry['matchId']] = $entry;
        $matches = $map;
    }
    if (!is_array($matches)) $matches = [];
    return [
        'schemaVersion' => 3,
        'revision' => max(0, (int)($raw['revision'] ?? 0)),
        'updatedAt' => $raw['updatedAt'] ?? null,
        'migration' => is_array($raw['migration'] ?? null) ? $raw['migration'] : [],
        'scheduler' => is_array($raw['scheduler'] ?? null) ? $raw['scheduler'] : [],
        'matches' => $matches
    ];
}
function write_registry_path(string $path, array $registry, bool $backup = true): void {
    $registry['schemaVersion'] = 3;
    $registry['revision'] = max(0, (int)($registry['revision'] ?? 0)) + 1;
    $registry['updatedAt'] = utc_stamp();
    if (!is_array($registry['scheduler'] ?? null)) $registry['scheduler'] = [];
    if (!is_array($registry['matches'] ?? null)) $registry['matches'] = [];
    ksort($registry['matches']);
    atomic_json($path, $registry, $backup);
}
function migration_empty(): array {
    return ['convertedMatches' => 0, 'convertedSnapshots' => 0, 'quarantinedFiles' => 0, 'removedLegacyRegistry' => false, 'completedAt' => null];
}
function normalized_snapshot_record(array $raw, string $id, string $fallbackTrackedAt): ?array {
    $detail = is_array($raw['match'] ?? null) ? $raw['match'] : null;
    if ($detail === null && (isset($raw['@id']) || isset($raw['teams']) || isset($raw['name']))) $detail = $raw;
    if (!is_array($detail)) return null;
    try { $summary = match_summary($detail); }
    catch (Throwable) {
        $detail['@id'] = $detail['@id'] ?? chess_match_url($id);
        try { $summary = match_summary($detail); }
        catch (Throwable) { return null; }
    }
    $trackedAt = trim((string)($raw['trackedAt'] ?? ($raw['capturedAt'] ?? $fallbackTrackedAt)));
    if ($trackedAt === '') $trackedAt = $fallbackTrackedAt;
    return [
        'schemaVersion' => 2,
        'trackedAt' => $trackedAt,
        'matchId' => $summary['matchId'] ?: $id,
        'leagueAcronyms' => is_array($raw['leagueAcronyms'] ?? null) ? $raw['leagueAcronyms'] : $summary['leagueAcronyms'],
        'source' => (string)($raw['source'] ?? 'legacy-v2.1.2'),
        'match' => $detail
    ];
}
function migration_target_path(string $directory, string $name, array $record): string {
    $safe = preg_match('/^[A-Za-z0-9._-]+\.json$/', $name) ? $name : gmdate('Ymd\THis').'000000Z.json';
    $candidate = $directory.'/'.$safe;
    if (!is_file($candidate)) return $candidate;
    $existing = read_json_file($candidate, []);
    if ($existing === $record) return $candidate;
    $stem = pathinfo($safe, PATHINFO_FILENAME);
    $hash = substr(hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 10);
    return $directory.'/'.$stem.'-legacy-'.$hash.'.json';
}
function latest_record_in_directory(string $directory): ?array {
    $files = is_dir($directory) ? (glob($directory.'/*.json') ?: []) : [];
    rsort($files);
    foreach ($files as $file) {
        $record = read_json_file($file, []);
        if (is_array($record['match'] ?? null)) return $record;
    }
    return null;
}
function first_record_in_directory(string $directory): ?array {
    $files = is_dir($directory) ? (glob($directory.'/*.json') ?: []) : [];
    sort($files);
    foreach ($files as $file) {
        $record = read_json_file($file, []);
        if (is_array($record['match'] ?? null)) return $record;
    }
    return null;
}
function migrate_legacy_tracking(): array {
    static $result = null;
    if (is_array($result)) return $result;
    $result = migration_empty();
    $newRegistryPath = follow_registry_path();
    $registry = registry_from_path($newRegistryPath);
    $registryBackup = registry_from_path($newRegistryPath . '.bak');
    $primaryCount = count($registry['matches'] ?? []);
    $backupCount = count($registryBackup['matches'] ?? []);
    if ($backupCount > $primaryCount || (int)($registryBackup['revision'] ?? 0) > (int)($registry['revision'] ?? 0)) {
        $registry = $registryBackup;
        $result['recoveredRegistryBackup'] = true;
    }
    $legacyRegistryPath = legacy_follow_registry_path();
    $legacyRegistryBackupPath = $legacyRegistryPath.'.bak';
    $legacyRegistrySource = is_file($legacyRegistryPath) ? $legacyRegistryPath : (is_file($legacyRegistryBackupPath) ? $legacyRegistryBackupPath : $legacyRegistryPath);
    $legacyRegistry = registry_from_path($legacyRegistrySource);
    $legacyRoot = legacy_history_root();
    $migratedIds = [];

    if (is_dir($legacyRoot)) {
        foreach (scandir($legacyRoot) ?: [] as $id) {
            if (!ctype_digit((string)$id)) continue;
            $sourceDirectory = $legacyRoot.'/'.$id;
            if (!is_dir($sourceDirectory)) continue;
            $targetDirectory = history_root().'/'.$id;
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) throw new RuntimeException('Unable to create unified match tracking directory.');
            $convertedForMatch = 0;
            foreach (scandir($sourceDirectory) ?: [] as $sourceName) {
                if ($sourceName === '.' || $sourceName === '..') continue;
                $sourcePath = $sourceDirectory.'/'.$sourceName;
                if (!is_file($sourcePath)) continue;
                $fallback = gmdate('Y-m-d\TH:i:s\Z', (int)(filemtime($sourcePath) ?: time()));
                $raw = read_json_file($sourcePath, []);
                $record = normalized_snapshot_record($raw, (string)$id, $fallback);
                if ($record === null) {
                    $quarantine = quarantine_root().'/'.$id;
                    if (!is_dir($quarantine) && !mkdir($quarantine, 0775, true) && !is_dir($quarantine)) throw new RuntimeException('Unable to create tracking quarantine directory.');
                    $target = $quarantine.'/'.basename($sourcePath);
                    if (!@rename($sourcePath, $target)) {
                        if (!@copy($sourcePath, $target) || !@unlink($sourcePath)) throw new RuntimeException('Unable to quarantine an invalid legacy tracking file.');
                    }
                    $result['quarantinedFiles']++;
                    continue;
                }
                $target = migration_target_path($targetDirectory, basename($sourcePath), $record);
                if (!is_file($target)) atomic_json($target, $record);
                if (!is_file($target)) throw new RuntimeException('Unable to verify a converted tracking snapshot.');
                if (!@unlink($sourcePath)) throw new RuntimeException('Unable to remove a converted legacy tracking snapshot.');
                $result['convertedSnapshots']++;
                $convertedForMatch++;
            }
            if ($convertedForMatch > 0 || is_dir($targetDirectory)) $migratedIds[(string)$id] = true;
            @rmdir($sourceDirectory);
        }
        foreach (scandir($legacyRoot) ?: [] as $leftoverName) {
            if ($leftoverName === '.' || $leftoverName === '..') continue;
            $leftover = $legacyRoot.'/'.$leftoverName;
            if (is_file($leftover) && in_array(strtolower($leftoverName), ['readme.md', '.gitkeep'], true)) @unlink($leftover);
        }
        @rmdir($legacyRoot);
    }

    foreach ($legacyRegistry['matches'] as $id => $entry) {
        if (!is_array($entry)) continue;
        $id = (string)$id;
        if (!is_array($registry['matches'][$id] ?? null)) $registry['matches'][$id] = $entry;
        $migratedIds[$id] = true;
    }

    if (is_dir(history_root())) {
        foreach (scandir(history_root()) ?: [] as $id) {
            if (!ctype_digit((string)$id) || !is_dir(history_root().'/'.$id)) continue;
            if (!is_array($registry['matches'][(string)$id] ?? null)) $migratedIds[(string)$id] = true;
        }
    }

    foreach (array_keys($migratedIds) as $id) {
        $directory = history_root().'/'.$id;
        $latest = latest_record_in_directory($directory);
        $first = first_record_in_directory($directory);
        $existing = is_array($registry['matches'][$id] ?? null) ? $registry['matches'][$id] : [];
        $summary = $latest ? match_summary($latest['match']) : [
            'matchId' => $id,
            'name' => (string)($existing['name'] ?? ('Match '.$id)),
            'url' => (string)($existing['url'] ?? ('https://www.chess.com/club/matches/'.$id)),
            'apiUrl' => (string)($existing['apiUrl'] ?? chess_match_url($id)),
            'leagueAcronyms' => is_array($existing['leagueAcronyms'] ?? null) ? $existing['leagueAcronyms'] : [],
            'status' => (string)($existing['status'] ?? 'registration'),
            'startTime' => $existing['startTime'] ?? null,
            'endTime' => $existing['endTime'] ?? null,
            'boardCount' => (int)($existing['boardCount'] ?? 0),
            'teams' => is_array($existing['teams'] ?? null) ? $existing['teams'] : []
        ];
        $explicitlyUnfollowed = (($existing['followed'] ?? null) === false) && !empty($existing['unfollowedAt']);
        $registry['matches'][$id] = array_merge($existing, $summary, [
            'matchId' => $id,
            'followed' => !$explicitlyUnfollowed,
            'source' => (string)($existing['source'] ?? 'legacy-v2.1.2'),
            'addedAt' => $existing['addedAt'] ?? ($first['trackedAt'] ?? utc_stamp()),
            'lastCapturedAt' => $latest['trackedAt'] ?? ($existing['lastCapturedAt'] ?? null),
            'unfollowedAt' => $explicitlyUnfollowed ? $existing['unfollowedAt'] : null
        ]);
    }

    $hasLegacyRegistry = is_file($legacyRegistryPath) || is_file($legacyRegistryBackupPath);
    $needsWrite = !is_file($newRegistryPath) || $migratedIds || $hasLegacyRegistry || !empty($result['recoveredRegistryBackup']);
    if ($needsWrite) {
        $result['convertedMatches'] = count($migratedIds);
        $result['removedLegacyRegistry'] = $hasLegacyRegistry;
        $result['completedAt'] = utc_stamp();
        $registry['migration'] = array_merge(is_array($registry['migration'] ?? null) ? $registry['migration'] : [], $result, ['sourceVersion' => '2.1.2']);
        write_registry_path($newRegistryPath, $registry, empty($result['recoveredRegistryBackup']));
    }
    if ($hasLegacyRegistry) {
        if (is_file($legacyRegistryPath) && !@unlink($legacyRegistryPath)) throw new RuntimeException('Unable to remove the converted legacy follow registry.');
        if (is_file($legacyRegistryBackupPath) && !@unlink($legacyRegistryBackupPath)) throw new RuntimeException('Unable to remove the converted legacy follow-registry backup.');
        $result['removedLegacyRegistry'] = true;
    }
    return $result;
}
function read_follow_registry(): array {
    migrate_legacy_tracking();
    return registry_from_path(follow_registry_path());
}
function write_follow_registry(array $registry): void {
    write_registry_path(follow_registry_path(), $registry, true);
}
function team_rows(array $detail): array {
    $raw = $detail['teams'] ?? [];
    if (!is_array($raw)) return [];
    $rows = array_is_list($raw) ? $raw : array_values($raw);
    return array_values(array_filter($rows, 'is_array'));
}
function team_names(array $detail): array {
    $names = [];
    foreach (team_rows($detail) as $team) {
        $name = trim((string)($team['name'] ?? $team['team_name'] ?? $team['club_name'] ?? ''));
        if ($name !== '') $names[] = $name;
    }
    return array_values(array_unique($names));
}
function match_status(array $detail): string {
    foreach (['status', 'state', 'match_status'] as $key) {
        $raw = strtolower(trim((string)($detail[$key] ?? '')));
        if ($raw === '') continue;
        if (preg_match('/finish|complete|closed|ended/', $raw)) return 'finished';
        if (preg_match('/progress|ongoing|started|active/', $raw)) return 'ongoing';
        if (preg_match('/register|upcoming|pending|open/', $raw)) return 'registration';
    }
    if ((int)($detail['end_time'] ?? 0) > 0) return 'finished';
    $start = (int)($detail['start_time'] ?? 0);
    if ($start > 0) return $start <= time() ? 'ongoing' : 'registration';
    return 'registration';
}
function board_count(array $detail): int {
    foreach (['boards', 'board_count', 'size', 'max_players'] as $key) {
        if (isset($detail[$key]) && is_numeric($detail[$key])) {
            $value = max(0, (int)$detail[$key]);
            if ($value > 0) return $value;
        }
    }
    $counts = [];
    foreach (team_rows($detail) as $team) {
        $players = $team['players'] ?? [];
        if (is_array($players)) $counts[] = count($players);
    }
    $positive = array_values(array_filter($counts, fn($value) => $value > 0));
    return $positive ? min($positive) : 0;
}

function monitoring_epoch(mixed $value): ?int {
    if ($value === null || $value === '') return null;
    if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit(trim($value)))) {
        $epoch = (int)$value;
        return $epoch > 0 ? $epoch : null;
    }
    $epoch = strtotime((string)$value);
    return $epoch === false ? null : $epoch;
}
function continuous_tracking_expired(array $entry, ?int $now = null): bool {
    $now ??= time();
    $startTime = monitoring_epoch($entry['startTime'] ?? ($entry['start_time'] ?? null));
    return $startTime !== null && $now >= ($startTime + MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS);
}
function apply_tracking_start_expiry(array $entry, ?int $now = null): array {
    if (!($entry['followed'] ?? false) || !continuous_tracking_expired($entry, $now)) return $entry;
    $stamp = utc_stamp();
    $entry['followed'] = false;
    $entry['unfollowedAt'] = $stamp;
    $entry['autoStoppedAt'] = $stamp;
    $entry['autoStopReason'] = 'started-over-24h';
    $entry['samplingDue'] = false;
    $entry['samplingPhase'] = 'auto-stopped';
    $entry['samplingLabel'] = 'Continuous tracking stopped 24 hours after match start';
    $entry['samplingIntervalSeconds'] = 0;
    $entry['nextCaptureAt'] = null;
    return $entry;
}
function match_monitoring_schedule(array $entry, ?int $now = null): array {
    $now ??= time();
    if (continuous_tracking_expired($entry, $now)) {
        return [
            'due' => false,
            'phase' => 'auto-stopped',
            'label' => 'Continuous tracking stopped 24 hours after match start',
            'intervalSeconds' => 0,
            'ageSeconds' => 0,
            'secondsUntilStart' => null,
            'nextCaptureAt' => null,
        ];
    }
    $addedAt = monitoring_epoch($entry['addedAt'] ?? ($entry['firstDiscoveredAt'] ?? null));
    $lastCapturedAt = monitoring_epoch($entry['lastCapturedAt'] ?? null);
    $startTime = monitoring_epoch($entry['startTime'] ?? ($entry['start_time'] ?? null));
    $ageSeconds = $addedAt === null ? 0 : max(0, $now - $addedAt);
    $untilStart = $startTime === null ? null : $startTime - $now;

    if ($addedAt === null || $ageSeconds < MATCH_MONITORING_FIRST_DAY_SECONDS) {
        $interval = MATCH_MONITORING_48H_SECONDS;
        $phase = 'first-24-hours';
        $label = 'Hourly during the first 24 hours after discovery';
    } elseif ($untilStart !== null && $untilStart > 0 && $untilStart <= 48 * 3600) {
        $interval = MATCH_MONITORING_48H_SECONDS;
        $phase = 'within-48-hours';
        $label = 'Hourly within 48 hours of the start';
    } elseif ($untilStart !== null && $untilStart > 0 && $untilStart <= 96 * 3600) {
        $interval = MATCH_MONITORING_96H_SECONDS;
        $phase = 'within-96-hours';
        $label = 'Every 6 hours within 96 hours of the start';
    } else {
        $interval = MATCH_MONITORING_DEFAULT_SECONDS;
        $phase = 'standard';
        $label = 'Every 12 hours';
    }

    $due = $lastCapturedAt === null || ($lastCapturedAt + $interval) <= $now;
    $nextCaptureEpoch = $lastCapturedAt === null ? $now : $lastCapturedAt + $interval;
    return [
        'due' => $due,
        'phase' => $phase,
        'label' => $label,
        'intervalSeconds' => $interval,
        'ageSeconds' => $ageSeconds,
        'secondsUntilStart' => $untilStart,
        'nextCaptureAt' => gmdate('Y-m-d\\TH:i:s\\Z', max($now, $nextCaptureEpoch)),
    ];
}
function decorate_monitoring_reference(array $reference, array $existing = [], ?int $now = null): array {
    $entry = array_merge($existing, $reference);
    if (empty($entry['addedAt'])) $entry['addedAt'] = utc_stamp();
    $schedule = match_monitoring_schedule($entry, $now);
    return array_merge($entry, [
        'samplingDue' => $schedule['due'],
        'samplingPhase' => $schedule['phase'],
        'samplingLabel' => $schedule['label'],
        'samplingIntervalSeconds' => $schedule['intervalSeconds'],
        'nextCaptureAt' => $schedule['nextCaptureAt'],
    ]);
}

function match_summary(array $detail): array {
    $id = match_id($detail['@id'] ?? ($detail['url'] ?? ''));
    return [
        'matchId' => $id,
        'name' => (string)($detail['name'] ?? ('Match '.$id)),
        'url' => (string)($detail['url'] ?? ('https://www.chess.com/club/matches/'.$id)),
        'apiUrl' => (string)($detail['@id'] ?? chess_match_url($id)),
        'leagueAcronyms' => league_codes($detail['name'] ?? ''),
        'status' => match_status($detail),
        'startTime' => ((int)($detail['start_time'] ?? 0)) ?: null,
        'endTime' => ((int)($detail['end_time'] ?? 0)) ?: null,
        'boardCount' => board_count($detail),
        'teams' => team_names($detail)
    ];
}
function update_follow_from_detail(array $detail, bool $followed = true, string $source = 'manual'): array {
    $summary = match_summary($detail);
    $registry = read_follow_registry();
    $id = $summary['matchId'];
    $existing = is_array($registry['matches'][$id] ?? null) ? $registry['matches'][$id] : [];
    $timestamp = utc_stamp();
    $entry = array_merge($existing, $summary, [
        'followed' => $followed,
        'source' => (string)($existing['source'] ?? $source),
        'addedAt' => $existing['addedAt'] ?? $timestamp,
        'lastCapturedAt' => $timestamp,
        'unfollowedAt' => $followed ? null : ($existing['unfollowedAt'] ?? $timestamp)
    ]);
    $schedule = match_monitoring_schedule($entry);
    $entry['samplingPhase'] = $schedule['phase'];
    $entry['samplingLabel'] = $schedule['label'];
    $entry['samplingIntervalSeconds'] = $schedule['intervalSeconds'];
    $entry['nextCaptureAt'] = $schedule['nextCaptureAt'];
    if ($followed) {
        $entry['source'] = $source;
        $entry['unfollowedAt'] = null;
    }
    $entry = apply_tracking_start_expiry($entry);
    $registry['matches'][$id] = $entry;
    write_follow_registry($registry);
    return $entry;
}
function latest_snapshot_record(string $id): ?array {
    $files = glob(history_root().'/'.$id.'/*.json') ?: [];
    rsort($files);
    foreach ($files as $file) {
        $record = read_json_file($file, []);
        if (isset($record['match']) && is_array($record['match'])) return $record;
    }
    return null;
}
function ensure_registry_entry(string $id): array {
    $registry = read_follow_registry();
    if (is_array($registry['matches'][$id] ?? null)) return $registry['matches'][$id];
    $snapshot = latest_snapshot_record($id);
    $timestamp = utc_stamp();
    $summary = $snapshot ? match_summary($snapshot['match']) : [
        'matchId' => $id,
        'name' => 'Match '.$id,
        'url' => 'https://www.chess.com/club/matches/'.$id,
        'apiUrl' => chess_match_url($id),
        'leagueAcronyms' => [],
        'status' => 'registration',
        'startTime' => null,
        'endTime' => null,
        'boardCount' => 0,
        'teams' => []
    ];
    $entry = array_merge($summary, [
        'followed' => true,
        'source' => 'legacy',
        'addedAt' => $snapshot['trackedAt'] ?? $timestamp,
        'lastCapturedAt' => $snapshot['trackedAt'] ?? null,
        'unfollowedAt' => null
    ]);
    $registry['matches'][$id] = $entry;
    write_follow_registry($registry);
    return $entry;
}
function set_follow_state(string $id, bool $followed, string $source = 'manual'): array {
    $registry = read_follow_registry();
    $entry = is_array($registry['matches'][$id] ?? null) ? $registry['matches'][$id] : ensure_registry_entry($id);
    $registry = read_follow_registry();
    $timestamp = utc_stamp();
    $entry['followed'] = $followed;
    $entry['source'] = $source;
    $entry['unfollowedAt'] = $followed ? null : $timestamp;
    if ($followed && empty($entry['addedAt'])) $entry['addedAt'] = $timestamp;
    if ($followed) {
        $schedule = match_monitoring_schedule($entry);
        $entry['samplingPhase'] = $schedule['phase'];
        $entry['samplingLabel'] = $schedule['label'];
        $entry['samplingIntervalSeconds'] = $schedule['intervalSeconds'];
        $entry['nextCaptureAt'] = $schedule['nextCaptureAt'];
    }
    $entry = apply_tracking_start_expiry($entry);
    $registry['matches'][$id] = $entry;
    write_follow_registry($registry);
    return $entry;
}
function save_snapshot(array $detail, bool $ensureFollow = true, string $source = 'capture'): array {
    $summary = match_summary($detail);
    $tracked = utc_stamp();
    $dir = history_root().'/'.$summary['matchId'];
    $micro = sprintf('%06d', (int)((microtime(true) - floor(microtime(true))) * 1000000));
    $file = gmdate('Ymd\THis').$micro.'Z.json';
    atomic_json($dir.'/'.$file, [
        'schemaVersion' => 1,
        'trackedAt' => $tracked,
        'matchId' => $summary['matchId'],
        'leagueAcronyms' => $summary['leagueAcronyms'],
        'match' => $detail
    ]);
    // Keep dense recent monitoring history but bound long-term inode growth.
    try{$storage=p2k_tp_config()['storage']??[];\P2K\Shared\MatchTrackingRetention::pruneMatchDirectory($dir,[
        'recent_days'=>(int)($storage['match_tracking_recent_days']??7),
        'dense_days'=>(int)($storage['match_tracking_dense_days']??30),
        'daily_days'=>(int)($storage['match_tracking_daily_days']??180),
        'hard_cap'=>(int)($storage['match_tracking_max_snapshots_per_match']??1200),
    ]);}catch(\Throwable){}
    if ($ensureFollow) update_follow_from_detail($detail, true, $source);
    return array_merge($summary, ['file' => $file, 'trackedAt' => $tracked]);
}
function follow_and_capture(mixed $reference): array {
    migrate_legacy_tracking();
    $id = match_id($reference);
    $registry = read_follow_registry();
    $hasArchive = latest_snapshot_record($id) !== null;
    $hasRegistry = is_array($registry['matches'][$id] ?? null);
    if ($hasArchive || $hasRegistry) set_follow_state($id, true, 'manual');
    try {
        $detail = chess_json(chess_match_url($id));
        $stored = save_snapshot($detail, true, 'manual');
        return ['captured' => true, 'stored' => $stored, 'match' => ensure_registry_entry($id)];
    } catch (RuntimeException $error) {
        if (!$hasArchive && !$hasRegistry) throw $error;
        return [
            'captured' => false,
            'stored' => null,
            'match' => set_follow_state($id, true, 'manual'),
            'captureWarning' => $error->getMessage()
        ];
    }
}

function automatic_league_references(): array {
    migrate_legacy_tracking();
    $data = chess_json(chess_club_matches_url());
    $registered = is_array($data['registered'] ?? null) ? $data['registered'] : [];
    $registry = read_follow_registry();
    $refs = [];
    $registryChanged = false;
    foreach ($registered as $ref) {
        if (!is_array($ref)) continue;
        $codes = league_codes($ref['name'] ?? '');
        if (!$codes) continue;
        $api = (string)($ref['@id'] ?? '');
        if ($api === '') continue;
        $id = match_id($api);
        $existing = is_array($registry['matches'][$id] ?? null) ? $registry['matches'][$id] : null;
        if ($existing && ($existing['followed'] ?? true) === false) continue;
        $base = [
            'matchId' => $id,
            'name' => (string)($ref['name'] ?? ('Match '.$id)),
            'apiUrl' => $api,
            'url' => (string)($ref['url'] ?? ''),
            'startTime' => isset($ref['start_time']) ? (int)$ref['start_time'] : null,
            'leagueAcronyms' => $codes,
            'status' => (string)($existing['status'] ?? 'registration'),
            'endTime' => $existing['endTime'] ?? null,
            'boardCount' => (int)($existing['boardCount'] ?? 0),
            'teams' => is_array($existing['teams'] ?? null) ? $existing['teams'] : [],
            'followed' => true,
            'source' => 'automatic-league',
            'addedAt' => $existing['addedAt'] ?? utc_stamp(),
            'lastCapturedAt' => $existing['lastCapturedAt'] ?? null,
            'unfollowedAt' => null,
        ];
        $decorated = decorate_monitoring_reference($base, $existing ?: []);
        $refs[$id] = $decorated;
        if ($existing === null || $decorated != $existing) {
            $registry['matches'][$id] = $decorated;
            $registryChanged = true;
        }
    }
    if ($registryChanged) write_follow_registry($registry);
    return ['registeredReferences' => count($registered), 'references' => array_values($refs)];
}
function expire_started_tracking(array &$registry, ?int $now = null): int {
    $now ??= time();
    $changed = 0;
    foreach ($registry['matches'] as $id => $entry) {
        if (!is_array($entry) || !($entry['followed'] ?? false)) continue;
        if (!continuous_tracking_expired($entry, $now)) continue;
        $registry['matches'][(string)$id] = apply_tracking_start_expiry($entry, $now);
        $changed++;
    }
    if ($changed > 0) write_follow_registry($registry);
    return $changed;
}
// Compatibility alias retained for older callers/tests; expiry is no longer source-specific.
function expire_started_automatic_tracking(array &$registry, ?int $now = null): int {
    return expire_started_tracking($registry, $now);
}

function tracking_references(): array {
    $automatic = automatic_league_references();
    $registry = read_follow_registry();
    $autoStoppedAfterStart = expire_started_tracking($registry);
    if ($autoStoppedAfterStart > 0) $registry = read_follow_registry();
    $refs = [];
    foreach ($automatic['references'] as $ref) {
        if (continuous_tracking_expired($ref)) continue;
        $refs[$ref['matchId']] = $ref;
    }
    $manualCount = 0;
    foreach ($registry['matches'] as $id => $entry) {
        if (!is_array($entry) || !($entry['followed'] ?? false)) continue;
        if (($entry['status'] ?? '') === 'finished') continue;
        $manualCount++;
        if (!isset($refs[(string)$id])) {
            $refs[(string)$id] = decorate_monitoring_reference([
                'matchId' => (string)$id,
                'name' => (string)($entry['name'] ?? ('Match '.$id)),
                'apiUrl' => (string)($entry['apiUrl'] ?? chess_match_url((string)$id)),
                'url' => (string)($entry['url'] ?? ''),
                'startTime' => $entry['startTime'] ?? null,
                'leagueAcronyms' => is_array($entry['leagueAcronyms'] ?? null) ? $entry['leagueAcronyms'] : [],
                'source' => 'followed'
            ], $entry);
        }
    }
    return [
        'registeredReferences' => $automatic['registeredReferences'],
        'autoLeagueReferences' => count($automatic['references']),
        'followedReferences' => $manualCount,
        'autoStoppedAfterStart' => $autoStoppedAfterStart,
        'references' => array_values($refs)
    ];
}
function league_references(): array { return tracking_references(); }
function validate_reference(mixed $value): array {
    $id = match_id($value);
    foreach (tracking_references()['references'] as $ref) if ($ref['matchId'] === $id) return $ref;
    throw new InvalidArgumentException('The match is not currently configured for tracking.');
}

function task_identifier(string $value, string $fallback): string {
    $clean = strtolower(trim($value));
    $clean = preg_replace('/[^a-z0-9._:-]+/', '-', $clean) ?: '';
    $clean = trim($clean, '-');
    return substr($clean !== '' ? $clean : $fallback, 0, 120);
}
function task_run_id(string $taskId): string {
    try { $suffix = bin2hex(random_bytes(4)); } catch (Throwable) { $suffix = substr(sha1(uniqid('', true)), 0, 8); }
    return task_identifier($taskId, 'task').'-'.gmdate('Ymd\THis\Z').'-'.$suffix;
}
function task_entry(string $source, string $status, array $value): array {
    $int = fn(string $key): int => max(0, (int)($value[$key] ?? 0));
    $tracked = $int('trackedReferences') ?: $int('leagueReferences');
    $taskType = task_identifier((string)($value['taskType'] ?? 'match-update'), 'match-update');
    $taskId = task_identifier((string)($value['taskId'] ?? 'track-active-matches'), 'track-active-matches');
    $runId = task_identifier((string)($value['runId'] ?? ''), task_run_id($taskId));
    $ids = static function(mixed $raw): array {
        if (!is_array($raw)) return [];
        $out = []; $seen = [];
        foreach ($raw as $value) {
            if (preg_match('/(\d+)/', (string)$value, $match)) {
                $id = (string)$match[1];
                if (!isset($seen[$id])) { $seen[$id] = true; $out[] = $id; }
            }
            if (count($out) >= 200) break;
        }
        return $out;
    };
    return [
        'schemaVersion' => 3,
        'event' => 'match-snapshot-task',
        'timestamp' => (string)($value['endedAt'] ?? utc_stamp()),
        'startedAt' => (string)($value['startedAt'] ?? ''),
        'endedAt' => (string)($value['endedAt'] ?? ''),
        'taskType' => $taskType,
        'taskId' => $taskId,
        'runId' => $runId,
        'source' => $source,
        'status' => $status,
        'registeredReferences' => $int('registeredReferences'),
        'autoLeagueReferences' => $int('autoLeagueReferences'),
        'followedReferences' => $int('followedReferences'),
        'trackedReferences' => $tracked,
        'leagueReferences' => $tracked,
        'processedReferences' => $int('processedReferences'),
        'dueReferences' => $int('dueReferences'),
        'deferredReferences' => $int('deferredReferences'),
        'storedMatches' => $int('storedMatches'),
        'skippedMatches' => $int('skippedMatches'),
        'failedMatches' => $int('failedMatches'),
        'durationMs' => $int('durationMs'),
        'storedMatchIds' => $ids($value['storedMatchIds'] ?? []),
        'failedMatchIds' => $ids($value['failedMatchIds'] ?? []),
        'updatedItems' => $int('updatedItems'),
        'excludedItems' => $int('excludedItems'),
        'details' => is_array($value['details'] ?? null) ? $value['details'] : [],
        'message' => substr((string)($value['message'] ?? ''), 0, 500)
    ];
}

function track_all(string $source, ?int $maxSeconds = null): array {
    $start = microtime(true);
    $startedAt = utc_stamp();
    $taskId = 'track-upcoming-league-matches';
    $runId = task_run_id($taskId);
    $listing = tracking_references();
    $references = is_array($listing['references'] ?? null) ? array_values($listing['references']) : [];
    $referenceCount = count($references);
    $stored = [];
    $errors = [];
    $processed = 0;
    $examined = 0;
    $deferred = 0;
    $dueTotal = count(array_filter($references, fn(array $ref): bool => (bool)($ref['samplingDue'] ?? true)));
    $deadlineCheckpoint = false;
    $deadline = $maxSeconds === null ? null : ($start + max(10, min(45, $maxSeconds)));

    $registry = read_follow_registry();
    $scheduler = is_array($registry['scheduler'] ?? null) ? $registry['scheduler'] : [];
    $cursor = $source === 'cron' && $referenceCount > 0
        ? max(0, (int)($scheduler['matchTrackingCursor'] ?? 0)) % $referenceCount
        : 0;
    $nextCursor = $cursor;

    for ($offset = 0; $offset < $referenceCount; $offset++) {
        $index = ($cursor + $offset) % $referenceCount;
        $ref = $references[$index];
        $examined++;
        $nextCursor = ($index + 1) % max(1, $referenceCount);
        if (!(bool)($ref['samplingDue'] ?? true)) {
            $deferred++;
            continue;
        }
        if ($deadline !== null && ($deadline - microtime(true)) < 17.0) {
            $deadlineCheckpoint = true;
            break;
        }
        try {
            $stored[] = save_snapshot(chess_json((string)$ref['apiUrl']), true, (string)($ref['source'] ?? $source));
        } catch (Throwable $error) {
            $errors[] = ['match' => $ref['apiUrl'], 'message' => $error->getMessage()];
        }
        $processed++;
    }

    if ($source === 'cron') {
        $freshRegistry = read_follow_registry();
        if (!is_array($freshRegistry['scheduler'] ?? null)) $freshRegistry['scheduler'] = [];
        $freshRegistry['scheduler']['matchTrackingCursor'] = $referenceCount > 0 ? $nextCursor : 0;
        $freshRegistry['scheduler']['matchTrackingLastCheckpointAt'] = utc_stamp();
        $freshRegistry['scheduler']['matchTrackingLastProcessed'] = $processed;
        $freshRegistry['scheduler']['matchTrackingLastExamined'] = $examined;
        $freshRegistry['scheduler']['matchTrackingReferenceCount'] = $referenceCount;
        $freshRegistry['scheduler']['matchTrackingDueCount'] = $dueTotal;
        $freshRegistry['scheduler']['matchTrackingDeferredCount'] = $deferred;
        $freshRegistry['scheduler']['matchTrackingDeadlineCheckpoint'] = $deadlineCheckpoint;
        write_follow_registry($freshRegistry);
    }

    $status = $deadlineCheckpoint ? 'partial' : (!$errors ? 'success' : ($stored ? 'partial' : 'failed'));
    $remainingDue = max(0, $dueTotal - $processed);
    $autoStoppedAfterStart = (int)($listing['autoStoppedAfterStart'] ?? 0);
    $message = $deadlineCheckpoint
        ? "Checkpointed after sampling {$processed} due match(es); {$remainingDue} due match(es) remain."
        : "Sampled {$processed} due match(es); {$deferred} tracked match(es) were not due.";
    if ($autoStoppedAfterStart > 0) $message .= " Automatically stopped Cron tracking for {$autoStoppedAfterStart} match(es) that started more than 24 hours ago.";
    $entry = task_entry($source, $status, [
        'taskType' => 'match-update',
        'taskId' => $taskId,
        'runId' => $runId,
        'startedAt' => $startedAt,
        'endedAt' => utc_stamp(),
        'registeredReferences' => $listing['registeredReferences'],
        'autoLeagueReferences' => $listing['autoLeagueReferences'],
        'followedReferences' => $listing['followedReferences'],
        'trackedReferences' => $referenceCount,
        'dueReferences' => $dueTotal,
        'deferredReferences' => $deferred,
        'processedReferences' => $processed,
        'storedMatches' => count($stored),
        'skippedMatches' => max(0, $listing['registeredReferences'] - $listing['autoLeagueReferences']) + $deferred,
        'failedMatches' => count($errors),
        'durationMs' => (int)round((microtime(true) - $start) * 1000),
        'storedMatchIds' => array_map(fn($item) => (string)($item['matchId'] ?? ''), $stored),
        'failedMatchIds' => array_map(fn($item) => (string)($item['match'] ?? ''), $errors),
        'message' => $message,
        'details' => [
            'deadlineCheckpoint' => $deadlineCheckpoint,
            'remainingDueReferences' => $remainingDue,
            'nextCursor' => $nextCursor,
            'endpointBudgetSeconds' => $maxSeconds,
            'cronCadenceSeconds' => 3600,
            'autoStoppedAfterStart' => $autoStoppedAfterStart,
            'autoStopAfterStartSeconds' => MATCH_MONITORING_AUTO_STOP_AFTER_START_SECONDS,
            'samplingPolicy' => [
                'first24Hours' => 3600,
                'standard' => 43200,
                'within96Hours' => 21600,
                'within48Hours' => 3600,
            ],
        ],
    ]);
    append_jsonl(root_dir().'/logs/scheduled-tasks', $entry);
    return array_merge([
        'ok' => $status !== 'failed',
        'status' => $status,
        'trackedAt' => $entry['timestamp'],
        'stored' => $stored,
        'errors' => $errors,
        'deadlineCheckpoint' => $deadlineCheckpoint,
        'dueReferences' => $dueTotal,
        'deferredReferences' => $deferred,
        'remainingDueReferences' => $remainingDue,
        'nextCursor' => $nextCursor,
        'message' => $message,
    ], array_intersect_key($entry, array_flip([
        'registeredReferences', 'autoLeagueReferences', 'followedReferences', 'trackedReferences',
        'leagueReferences', 'dueReferences', 'deferredReferences', 'processedReferences', 'storedMatches', 'skippedMatches',
        'failedMatches', 'durationMs', 'taskType', 'taskId', 'runId', 'startedAt', 'endedAt', 'storedMatchIds', 'failedMatchIds', 'updatedItems', 'excludedItems', 'details'
    ])));
}

function date_range(): array {
    $to = $_GET['to'] ?? gmdate('Y-m-d');
    $from = $_GET['from'] ?? gmdate('Y-m-d', time() - 6 * 86400);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$from, new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$to, new DateTimeZone('UTC'));
    if (!$start || !$end || $start > $end || (int)$start->diff($end)->days + 1 > MAX_DAYS) {
        throw new InvalidArgumentException('The selected period must contain 1 to '.MAX_DAYS.' days');
    }
    return [$start, $end];
}
function log_lines(string $dir, DateTimeImmutable $from, DateTimeImmutable $to): Generator {
    for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
        $path = $dir.'/'.$date->format('Y-m-d').'.jsonl';
        if (!is_file($path)) continue;
        $handle = @fopen($path, 'rb');
        if (!$handle) { yield [null, $date->format('Y-m-d')]; continue; }
        while (($line = fgets($handle)) !== false) if (trim($line) !== '') yield [$line, $date->format('Y-m-d')];
        fclose($handle);
    }
}
function match_logs_response(): array {
    [$from, $to] = date_range();
    $query = strtolower(trim((string)($_GET['user'] ?? '')));
    if (strlen($query) > 128) throw new InvalidArgumentException('user filter is too long');
    $daily = [];
    for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
        $daily[$date->format('Y-m-d')] = ['date' => $date->format('Y-m-d'), 'analyses' => 0, 'matchesFound' => 0, 'users' => []];
    }
    $entries = []; $users = []; $invalid = 0;
    foreach (log_lines(root_dir().'/logs/match-assistant', $from, $to) as [$line, $day]) {
        if ($line === null) { $invalid++; continue; }
        $record = json_decode($line, true);
        $username = strtolower(trim((string)($record['username'] ?? '')));
        $count = filter_var($record['matchesFound'] ?? null, FILTER_VALIDATE_INT);
        $timestamp = (string)($record['timestamp'] ?? '');
        if ($username === '' || strlen($username) > 128 || $count === false || $count < 0 || $timestamp === '') { $invalid++; continue; }
        if ($query !== '' && !str_contains($username, $query)) continue;
        $entries[] = ['timestamp' => $timestamp, 'username' => $username, 'matchesFound' => $count];
        $daily[$day]['analyses']++; $daily[$day]['matchesFound'] += $count; $daily[$day]['users'][$username] = true;
        if (!isset($users[$username])) $users[$username] = ['username' => $username, 'analyses' => 0, 'matchesFound' => 0];
        $users[$username]['analyses']++; $users[$username]['matchesFound'] += $count;
    }
    usort($entries, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
    $dailyRows = array_values(array_map(fn($row) => ['date' => $row['date'], 'analyses' => $row['analyses'], 'matchesFound' => $row['matchesFound'], 'distinctUsers' => count($row['users'])], $daily));
    usort($dailyRows, fn($a, $b) => strcmp($b['date'], $a['date']));
    $userRows = array_values($users);
    usort($userRows, fn($a, $b) => $b['analyses'] <=> $a['analyses'] ?: $b['matchesFound'] <=> $a['matchesFound'] ?: strcmp($a['username'], $b['username']));
    return [
        'ok' => true,
        'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        'summary' => ['analyses' => count($entries), 'matchesFound' => array_sum(array_column($entries, 'matchesFound')), 'distinctUsers' => count($users)],
        'daily' => $dailyRows,
        'users' => $userRows,
        'entries' => array_slice($entries, 0, MAX_ENTRIES),
        'truncated' => count($entries) > MAX_ENTRIES,
        'invalidLines' => $invalid
    ];
}
function task_logs_response(): array {
    [$from, $to] = date_range();
    $source = strtolower((string)($_GET['source'] ?? ($_GET['type'] ?? '')));
    if ($source === 'scheduled') $source = 'cron';
    $status = strtolower((string)($_GET['status'] ?? ''));
    $taskType = task_identifier((string)($_GET['taskType'] ?? ''), '');
    if (!in_array($source, ['', 'cron', 'manual'], true) || !in_array($status, ['', 'success', 'partial', 'failed', 'errors'], true)) {
        throw new InvalidArgumentException('invalid scheduled task filter');
    }
    $entries = []; $invalid = 0;
    foreach (log_lines(root_dir().'/logs/scheduled-tasks', $from, $to) as [$line]) {
        if ($line === null) { $invalid++; continue; }
        $raw = json_decode($line, true);
        if (!is_array($raw)) { $invalid++; continue; }
        $event = (string)($raw['event'] ?? '');
        if ($event === 'scheduled-task-run') {
            $oldType = strtolower((string)($raw['entryType'] ?? ''));
            $currentSource = $oldType === 'scheduled' ? 'cron' : $oldType;
            $oldStatus = strtolower((string)($raw['status'] ?? ''));
            $currentStatus = $oldStatus === 'error' ? 'failed' : $oldStatus;
            $timestamp = (string)($raw['startedAt'] ?? ($raw['timestamp'] ?? ''));
            $registered = max(0, (int)($raw['registeredReferences'] ?? 0));
            $stored = max(0, (int)($raw['recordedMatches'] ?? 0));
            $failed = max(0, (int)($raw['failedMatches'] ?? 0));
            $record = [
                'schemaVersion' => 1,
                'event' => 'match-snapshot-task',
                'timestamp' => $timestamp,
                'source' => $currentSource,
                'status' => $currentStatus,
                'registeredReferences' => $registered,
                'autoLeagueReferences' => 0,
                'followedReferences' => 0,
                'trackedReferences' => $registered,
                'leagueReferences' => $registered,
                'processedReferences' => $stored + $failed,
                'storedMatches' => $stored,
                'skippedMatches' => max(0, (int)($raw['skippedMatches'] ?? 0)),
                'failedMatches' => $failed,
                'durationMs' => max(0, (int)($raw['durationMs'] ?? 0)),
                'message' => substr((string)($raw['message'] ?? ''), 0, 500),
                'startedAt' => $timestamp,
                'endedAt' => (string)($raw['endedAt'] ?? ''),
                'taskType' => 'legacy-tracking',
                'taskId' => 'legacy-scheduled-task',
                'runId' => '',
                'storedMatchIds' => [],
                'failedMatchIds' => [],
                'legacySchema' => true
            ];
        } elseif (in_array($event, ['league-snapshot-task', 'match-snapshot-task'], true)) {
            $record = $raw;
            $currentSource = strtolower((string)($record['source'] ?? ''));
            $currentStatus = strtolower((string)($record['status'] ?? ''));
            $timestamp = (string)($record['timestamp'] ?? '');
            foreach (['registeredReferences', 'autoLeagueReferences', 'followedReferences', 'trackedReferences', 'leagueReferences', 'processedReferences', 'storedMatches', 'skippedMatches', 'failedMatches', 'durationMs'] as $key) {
                $record[$key] = max(0, (int)($record[$key] ?? 0));
            }
            if (!$record['trackedReferences']) $record['trackedReferences'] = $record['leagueReferences'];
            $record['taskType'] = task_identifier((string)($record['taskType'] ?? 'legacy-tracking'), 'legacy-tracking');
            $record['taskId'] = task_identifier((string)($record['taskId'] ?? 'legacy-scheduled-task'), 'legacy-scheduled-task');
            $record['runId'] = task_identifier((string)($record['runId'] ?? ''), '');
            $record['startedAt'] = (string)($record['startedAt'] ?? $timestamp);
            $record['endedAt'] = (string)($record['endedAt'] ?? $timestamp);
            $record['storedMatchIds'] = is_array($record['storedMatchIds'] ?? null) ? $record['storedMatchIds'] : [];
            $record['failedMatchIds'] = is_array($record['failedMatchIds'] ?? null) ? $record['failedMatchIds'] : [];
            $record['updatedItems'] = max(0, (int)($record['updatedItems'] ?? $record['storedMatches'] ?? 0));
            $record['excludedItems'] = max(0, (int)($record['excludedItems'] ?? 0));
            $record['details'] = is_array($record['details'] ?? null) ? $record['details'] : [];
        } else { $invalid++; continue; }

        if (!in_array($currentSource, ['cron', 'manual'], true) || !in_array($currentStatus, ['success', 'partial', 'failed'], true) || $timestamp === '') { $invalid++; continue; }
        $record['taskType'] = task_identifier((string)($record['taskType'] ?? 'legacy-tracking'), 'legacy-tracking');
        $record['taskId'] = task_identifier((string)($record['taskId'] ?? 'legacy-scheduled-task'), 'legacy-scheduled-task');
        $record['runId'] = task_identifier((string)($record['runId'] ?? ''), '');
        $record['startedAt'] = (string)($record['startedAt'] ?? $timestamp);
        $record['endedAt'] = (string)($record['endedAt'] ?? $timestamp);
        $record['storedMatchIds'] = is_array($record['storedMatchIds'] ?? null) ? $record['storedMatchIds'] : [];
        $record['failedMatchIds'] = is_array($record['failedMatchIds'] ?? null) ? $record['failedMatchIds'] : [];
        $record['updatedItems'] = max(0, (int)($record['updatedItems'] ?? $record['storedMatches'] ?? 0));
        $record['excludedItems'] = max(0, (int)($record['excludedItems'] ?? 0));
        $record['details'] = is_array($record['details'] ?? null) ? $record['details'] : [];
        if ($source !== '' && $currentSource !== $source) continue;
        if ($taskType !== '' && $record['taskType'] !== $taskType) continue;
        if ($status === 'errors' && $currentStatus === 'success') continue;
        if (!in_array($status, ['', 'errors'], true) && $currentStatus !== $status) continue;
        $record['source'] = $currentSource;
        $record['status'] = $currentStatus;
        $record['timestamp'] = $timestamp;
        $entries[] = $record;
    }
    usort($entries, fn($a, $b) => strcmp((string)$b['timestamp'], (string)$a['timestamp']));
    return [
        'ok' => true,
        'summary' => [
            'runs' => count($entries),
            'storedMatches' => array_sum(array_column($entries, 'storedMatches')),
            'failedMatches' => array_sum(array_column($entries, 'failedMatches')),
            'manualRuns' => count(array_filter($entries, fn($entry) => $entry['source'] === 'manual')),
            'cronRuns' => count(array_filter($entries, fn($entry) => $entry['source'] === 'cron'))
        ],
        'entries' => array_slice($entries, 0, MAX_ENTRIES),
        'truncated' => count($entries) > MAX_ENTRIES,
        'invalidLines' => $invalid
    ];
}

function tracked_records(): array {
    migrate_legacy_tracking();
    $registry = read_follow_registry();
    $expiredAfterStart = expire_started_tracking($registry);
    if ($expiredAfterStart > 0) $registry = read_follow_registry();
    $ids = array_fill_keys(array_map('strval', array_keys($registry['matches'])), true);
    if (is_dir(history_root())) {
        foreach (scandir(history_root()) ?: [] as $id) if (ctype_digit($id) && is_dir(history_root().'/'.$id)) $ids[$id] = true;
    }
    $out = [];
    foreach (array_keys($ids) as $rawId) {
        $id = (string)$rawId;
        $entry = is_array($registry['matches'][$id] ?? null) ? $registry['matches'][$id] : null;
        $files = glob(history_root().'/'.$id.'/*.json') ?: [];
        sort($files);
        $valid = [];
        foreach ($files as $file) {
            $record = read_json_file($file, []);
            if (isset($record['match']) && is_array($record['match'])) $valid[] = $record;
        }
        $first = $valid[0] ?? null;
        $last = $valid ? $valid[count($valid) - 1] : null;
        $summary = $last ? match_summary($last['match']) : ($entry ?: [
            'matchId' => $id, 'name' => 'Match '.$id, 'url' => 'https://www.chess.com/club/matches/'.$id,
            'apiUrl' => chess_match_url($id), 'leagueAcronyms' => [], 'status' => 'registration',
            'startTime' => null, 'endTime' => null, 'boardCount' => 0, 'teams' => []
        ]);
        $record = array_merge($entry ?: [], $summary, [
            'matchId' => $id,
            'followed' => $entry ? (bool)($entry['followed'] ?? false) : true,
            'source' => (string)($entry['source'] ?? 'legacy'),
            'fileCount' => count($files),
            'validFileCount' => count($valid),
            'hasData' => count($valid) > 0,
            'firstTrackedAt' => (string)($first['trackedAt'] ?? ''),
            'lastTrackedAt' => (string)($last['trackedAt'] ?? ($entry['lastCapturedAt'] ?? ''))
        ]);
        if (($record['autoStopReason'] ?? '') === 'started-over-24h' && !($record['followed'] ?? false)) {
            $record['samplingDue'] = false;
            $record['samplingPhase'] = 'auto-stopped';
            $record['samplingLabel'] = 'Continuous tracking stopped 24 hours after match start';
            $record['samplingIntervalSeconds'] = 0;
            $record['nextCaptureAt'] = null;
        } else {
            $schedule = match_monitoring_schedule($record);
            $record['samplingDue'] = $schedule['due'];
            $record['samplingPhase'] = $schedule['phase'];
            $record['samplingLabel'] = $schedule['label'];
            $record['samplingIntervalSeconds'] = $schedule['intervalSeconds'];
            $record['nextCaptureAt'] = $schedule['nextCaptureAt'];
        }
        $record['started'] = in_array($record['status'], ['ongoing', 'finished'], true);
        $out[] = $record;
    }
    usort($out, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
    return $out;
}
function remove_match_data(string $id): array {
    $dir = history_root().'/'.$id;
    $files = is_dir($dir) ? (glob($dir.'/*') ?: []) : [];
    $count = count(array_filter($files, 'is_file'));
    if (is_dir($dir)) {
        foreach ($files as $file) if (is_file($file)) @unlink($file);
        @rmdir($dir);
    }
    return ['matchId' => $id, 'deletedFiles' => $count];
}
function remove_finished_data(): array {
    $matches = tracked_records();
    $deletedMatches = 0; $deletedFiles = 0; $ids = [];
    foreach ($matches as $match) {
        if (($match['status'] ?? '') !== 'finished' || (int)($match['fileCount'] ?? 0) <= 0) continue;
        $result = remove_match_data((string)$match['matchId']);
        $deletedMatches++;
        $deletedFiles += $result['deletedFiles'];
        $ids[] = (string)$match['matchId'];
    }
    return ['deletedMatches' => $deletedMatches, 'deletedFiles' => $deletedFiles, 'matchIds' => $ids];
}
