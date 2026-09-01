<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\PublicReadDatabase;

const P2K_RECRUITMENT_SCHEMA = 2;
const P2K_RECRUITMENT_MAX_CANDIDATES = 100000;
const P2K_RECRUITMENT_MEMBERSHIP_BATCH_MAX = 2000;
const P2K_RECRUITMENT_CHECKPOINT_MAX = 500;

function p2k_recruitment_dir(): string
{
    $config = p2k_tp_config();
    $configured = trim((string)($config['storage']['runtime_dir'] ?? ''));
    $base = $configured !== ''
        ? rtrim($configured, '/\\')
        : dirname(__DIR__, 3) . '/data/runtime-v280';
    $dir = $base . '/recruitment-admin';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create the recruitment runtime directory.');
    }
    return $dir;
}

function p2k_recruitment_path(string $name): string
{
    return p2k_recruitment_dir() . '/' . $name . '.json';
}

function p2k_recruitment_read(string $name, array $default = []): array
{
    $path = p2k_recruitment_path($name);
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function p2k_recruitment_write(string $name, array $payload): void
{
    $path = p2k_recruitment_path($name);
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to persist recruitment state.');
    }
}

function p2k_recruitment_locked_run(callable $callback): array
{
    $lockPath = p2k_recruitment_dir() . '/run.lock';
    $handle = @fopen($lockPath, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Unable to lock recruitment state.');
    try {
        $run = p2k_recruitment_read('run', []);
        $next = $callback($run);
        if (!is_array($next)) throw new RuntimeException('Invalid recruitment state update.');
        p2k_recruitment_write('run', $next);
        return $next;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function p2k_recruitment_username(string $raw): string
{
    $value = trim($raw);
    $value = preg_replace('~^https?://(?:www\.)?chess\.com/member/~i', '', $value) ?? $value;
    $value = preg_replace('~^https?://api\.chess\.com/pub/player/~i', '', $value) ?? $value;
    $value = preg_replace('~/.*$~', '', $value) ?? $value;
    $value = trim($value);
    if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return '';
    return $value;
}

function p2k_recruitment_normalize_pool(mixed $input): array
{
    $raw = is_array($input) ? $input : preg_split('/[\r\n,;]+/', (string)$input);
    $out = [];
    $seen = [];
    foreach ((array)$raw as $item) {
        $username = p2k_recruitment_username((string)$item);
        $key = strtolower($username);
        if ($username === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $username;
        if (count($out) >= P2K_RECRUITMENT_MAX_CANDIDATES) break;
    }
    return $out;
}

function p2k_recruitment_number(array $body, string $key, ?float $default): ?float
{
    if (!array_key_exists($key, $body)) return $default;
    if ($body[$key] === '' || $body[$key] === null) return null;
    if (!is_numeric($body[$key])) throw new ApiException($key . ' must be numeric or empty.', 400, 'INVALID_CRITERIA');
    return (float)$body[$key];
}

function p2k_recruitment_criteria(array $body): array
{
    $criteria = [
        'minRating' => p2k_recruitment_number($body, 'minRating', 1300),
        'maxRating' => p2k_recruitment_number($body, 'maxRating', null),
        'maxTimeout' => p2k_recruitment_number($body, 'maxTimeout', 8),
        'maxRd' => p2k_recruitment_number($body, 'maxRd', 100),
        'minGames' => p2k_recruitment_number($body, 'minGames', 2),
        'maxGames' => p2k_recruitment_number($body, 'maxGames', 25),
        'minCompleted' => p2k_recruitment_number($body, 'minCompleted', 50),
        'maxOffline' => p2k_recruitment_number($body, 'maxOffline', 14),
        'maxSpm' => p2k_recruitment_number($body, 'maxSpm', null),
        'minAge' => p2k_recruitment_number($body, 'minAge', null),
        'excludeFormer' => !empty($body['excludeFormer']),
    ];
    foreach (['minRating','maxRating','maxTimeout','maxRd','minGames','maxGames','minCompleted','maxOffline','maxSpm','minAge'] as $key) {
        if ($criteria[$key] !== null && $criteria[$key] < 0) {
            throw new ApiException($key . ' cannot be negative.', 400, 'INVALID_CRITERIA');
        }
    }
    if ($criteria['minRating'] !== null && $criteria['maxRating'] !== null && $criteria['minRating'] > $criteria['maxRating']) {
        throw new ApiException('Minimum rating cannot exceed maximum rating.', 400, 'INVALID_CRITERIA');
    }
    if ($criteria['minGames'] !== null && $criteria['maxGames'] !== null && $criteria['minGames'] > $criteria['maxGames']) {
        throw new ApiException('Minimum current games cannot exceed maximum current games.', 400, 'INVALID_CRITERIA');
    }
    return $criteria;
}

function p2k_recruitment_metric(array $data, string $field): ?float
{
    if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') return null;
    return is_numeric($data[$field]) ? (float)$data[$field] : null;
}

/**
 * Resolve Recruitment membership directly from canonical Green identity/player data.
 * Returns lowercase input username => current|former|none.
 */
function p2k_recruitment_membership_states(PDO $pdo, array $usernames): array
{
    $input = [];
    foreach ($usernames as $raw) {
        $username = p2k_recruitment_username((string)$raw);
        if ($username === '') continue;
        $input[strtolower($username)] = $username;
    }
    $states = array_fill_keys(array_keys($input), 'none');
    if ($input === []) return $states;

    foreach (array_chunk(array_keys($input), 500) as $keys) {
        $marks = implode(',', array_fill(0, count($keys), '?'));

        $q = $pdo->prepare("SELECT username_key,current_member FROM p2k_g_players WHERE username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }

        $q = $pdo->prepare("SELECT m.username_key,p.current_member
            FROM p2k_g_identity_map m
            JOIN p2k_g_players p ON p.username_key=m.canonical_username_key
            WHERE m.username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }

        $q = $pdo->prepare("SELECT a.username_key,p.current_member
            FROM p2k_g_player_aliases a
            JOIN p2k_g_players p ON p.chess_player_id=a.chess_player_id
            WHERE a.username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }
    }
    return $states;
}

function p2k_recruitment_membership_state(PDO $pdo, string $username): string
{
    $states = p2k_recruitment_membership_states($pdo, [$username]);
    return $states[strtolower(p2k_recruitment_username($username))] ?? 'none';
}

function p2k_recruitment_evaluate(array $data, array $criteria): array
{
    $fail = [];
    $error = trim((string)($data['error'] ?? ''));
    $closed = !empty($data['closed']);
    $p2k = strtolower(trim((string)($data['p2k_state'] ?? 'none')));

    if ($error !== '') $fail[] = 'profile data unavailable: ' . $error;
    if ($closed) $fail[] = 'account closed / unavailable';
    if ($p2k === 'current') $fail[] = 'already current P2K member';
    if (!empty($criteria['excludeFormer']) && $p2k === 'former') $fail[] = 'former P2K member';

    $membershipRejected = $p2k === 'current' || (!empty($criteria['excludeFormer']) && $p2k === 'former');
    if (!$closed && $error === '' && !$membershipRejected) {
        $checks = [
            ['daily_rating', 'minRating', 'min', 'Daily rating below minimum', 'Daily rating unavailable'],
            ['daily_rating', 'maxRating', 'max', 'Daily rating above maximum', 'Daily rating unavailable'],
            ['timeout_percent', 'maxTimeout', 'max', 'timeout rate too high', 'timeout rate unavailable'],
            ['daily_rd', 'maxRd', 'max', 'Daily rating deviation too high', 'Daily rating deviation unavailable'],
            ['current_daily_games', 'minGames', 'min', 'too few current Daily games', 'current Daily games unavailable'],
            ['current_daily_games', 'maxGames', 'max', 'too many current Daily games', 'current Daily games unavailable'],
            ['completed_daily_games', 'minCompleted', 'min', 'too few completed Daily games', 'completed Daily games unavailable'],
            ['last_online_days', 'maxOffline', 'max', 'last online too old', 'last online unavailable'],
            ['avg_seconds_per_move', 'maxSpm', 'max', 'average seconds per move too high', 'average seconds per move unavailable'],
            ['account_age_days', 'minAge', 'min', 'account too new', 'account age unavailable'],
        ];
        foreach ($checks as [$field, $criterion, $mode, $message, $missing]) {
            $limit = $criteria[$criterion] ?? null;
            if ($limit === null) continue;
            $value = p2k_recruitment_metric($data, $field);
            if ($value === null) { $fail[] = $missing; continue; }
            if (($mode === 'min' && $value < (float)$limit) || ($mode === 'max' && $value > (float)$limit)) $fail[] = $message;
        }
    }

    $fail = array_values(array_unique($fail));
    return [
        'selected' => $fail === [],
        'decision' => $fail === [] ? 'selected' : 'rejected',
        'reason' => $fail === [] ? 'All criteria passed.' : implode('; ', $fail),
    ];
}

function p2k_recruitment_public_run(array $run, bool $includeCandidates = true, bool $includeResults = true): array
{
    if ($run === []) return [];
    $candidates = array_values((array)($run['candidates'] ?? []));
    $order = [];
    foreach ($candidates as $index => $candidate) $order[strtolower((string)$candidate)] = $index;
    $results = array_values(array_filter((array)($run['results'] ?? []), 'is_array'));
    usort($results, static function(array $left, array $right) use ($order): int {
        $a = $order[strtolower((string)($left['username'] ?? ''))] ?? PHP_INT_MAX;
        $b = $order[strtolower((string)($right['username'] ?? ''))] ?? PHP_INT_MAX;
        return $a <=> $b;
    });
    $selected = count(array_filter($results, static fn(array $row): bool => !empty($row['selected'])));
    $errors = count(array_filter($results, static fn(array $row): bool => trim((string)($row['data']['error'] ?? '')) !== ''));
    $run['summary'] = [
        'total' => count($candidates),
        'checked' => count($results),
        'pending' => max(0, count($candidates) - count($results)),
        'selected' => $selected,
        'errors' => $errors,
    ];
    if ($includeCandidates) $run['candidates'] = $candidates; else unset($run['candidates']);
    if ($includeResults) $run['results'] = $results; else unset($run['results']);
    return $run;
}

try {
    Auth::requireAdmin();
    $action = strtolower(trim((string)($_GET['action'] ?? 'state')));

    if ($action === 'state') {
        Http::method('GET');
        $pool = p2k_recruitment_read('pool', [
            'schemaVersion'=>P2K_RECRUITMENT_SCHEMA,
            'revision'=>0,
            'updatedAt'=>null,
            'candidates'=>[],
        ]);
        $pool['maximumCandidates'] = P2K_RECRUITMENT_MAX_CANDIDATES;
        Http::json(['ok'=>true,'pool'=>$pool,'run'=>p2k_recruitment_public_run(p2k_recruitment_read('run', []))]);
    }

    if ($action === 'save-pool') {
        Http::method('POST');
        $body = Http::body();
        $candidates = p2k_recruitment_normalize_pool($body['candidates'] ?? '');
        $current = p2k_recruitment_read('pool', ['revision'=>0]);
        $pool = [
            'schemaVersion'=>P2K_RECRUITMENT_SCHEMA,
            'revision'=>(int)($current['revision']??0)+1,
            'updatedAt'=>gmdate(DATE_ATOM),
            'maximumCandidates'=>P2K_RECRUITMENT_MAX_CANDIDATES,
            'candidates'=>$candidates,
        ];
        p2k_recruitment_write('pool', $pool);
        Http::json(['ok'=>true,'pool'=>$pool]);
    }

    if ($action === 'membership-batch') {
        Http::method('POST');
        $body = Http::body();
        $raw = is_array($body['usernames'] ?? null) ? $body['usernames'] : [];
        if (count($raw) > P2K_RECRUITMENT_MEMBERSHIP_BATCH_MAX) {
            throw new ApiException('Membership batch exceeds the supported size.', 400, 'MEMBERSHIP_BATCH_TOO_LARGE');
        }
        $usernames = [];
        foreach ($raw as $value) {
            $username = p2k_recruitment_username((string)$value);
            if ($username !== '') $usernames[] = $username;
        }
        $states = p2k_recruitment_membership_states(PublicReadDatabase::core(), $usernames);
        Http::json(['ok'=>true,'states'=>$states,'count'=>count($states),'source'=>'green_native_core']);
    }

    if ($action === 'start') {
        Http::method('POST');
        $body = Http::body();
        $pool = p2k_recruitment_read('pool', []);
        $candidates = array_values((array)($pool['candidates'] ?? []));
        if ($candidates === []) throw new ApiException('Save at least one candidate before starting a scan.', 400, 'EMPTY_POOL');
        $requestedCriteria = p2k_recruitment_criteria(is_array($body['criteria'] ?? null) ? $body['criteria'] : []);
        $run = p2k_recruitment_locked_run(static function(array $existing) use ($candidates, $pool, $requestedCriteria): array {
            $poolHash = hash('sha256', json_encode(array_map('strtolower', $candidates), JSON_UNESCAPED_SLASHES));
            $resumable = $existing !== [] && ($existing['poolHash'] ?? '') === $poolHash && !in_array((string)($existing['status'] ?? ''), ['completed','stopped'], true);
            if ($resumable) { $existing['status'] = 'running'; $existing['updatedAt'] = gmdate(DATE_ATOM); return $existing; }
            return [
                'schemaVersion'=>P2K_RECRUITMENT_SCHEMA,
                'id'=>gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)),
                'status'=>'running','createdAt'=>gmdate(DATE_ATOM),'updatedAt'=>gmdate(DATE_ATOM),
                'poolRevision'=>(int)($pool['revision']??0),'poolHash'=>$poolHash,'criteria'=>$requestedCriteria,'candidates'=>$candidates,'results'=>[],
            ];
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run)]);
    }

    if ($action === 'pause') {
        Http::method('POST');
        $run = p2k_recruitment_locked_run(static function(array $run): array {
            if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
            if (($run['status'] ?? '') !== 'completed') $run['status'] = 'paused';
            $run['updatedAt'] = gmdate(DATE_ATOM); return $run;
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run, false, false)]);
    }

    if ($action === 'restart') {
        Http::method('POST'); p2k_recruitment_locked_run(static fn(array $run): array => []); Http::json(['ok'=>true,'run'=>[]]);
    }

    if ($action === 'checkpoint') {
        Http::method('POST');
        $body = Http::body();
        $runId = trim((string)($body['runId'] ?? ''));
        if ($runId === '') throw new ApiException('Recruitment run ID is required.', 400, 'RUN_ID_REQUIRED');
        $incoming = is_array($body['results'] ?? null) ? array_slice($body['results'], 0, P2K_RECRUITMENT_CHECKPOINT_MAX) : [];
        if ($incoming === []) throw new ApiException('No recruitment results supplied.', 400, 'EMPTY_CHECKPOINT');

        $core = PublicReadDatabase::core();
        $incomingNames = [];
        foreach ($incoming as $row) if (is_array($row)) $incomingNames[] = (string)($row['username'] ?? '');
        $membership = p2k_recruitment_membership_states($core, $incomingNames);
        $accepted = [];

        $run = p2k_recruitment_locked_run(static function(array $run) use ($incoming, $runId, $membership, &$accepted): array {
            if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
            if (!hash_equals((string)($run['id'] ?? ''), $runId)) throw new ApiException('The recruitment run changed. Reload before checkpointing more results.', 409, 'RUN_CHANGED');
            if (($run['status'] ?? '') === 'paused') throw new ApiException('The recruitment run is paused.', 409, 'RUN_PAUSED');
            if (($run['status'] ?? '') === 'completed') throw new ApiException('The recruitment run is already complete.', 409, 'RUN_COMPLETED');

            $candidateKeys = [];
            foreach ((array)($run['candidates'] ?? []) as $candidate) $candidateKeys[strtolower((string)$candidate)] = (string)$candidate;
            $results = is_array($run['results'] ?? null) ? array_values($run['results']) : [];
            $byKey = [];
            foreach ($results as $index => $row) if (is_array($row)) $byKey[strtolower((string)($row['username'] ?? ''))] = $index;

            foreach ($incoming as $row) {
                if (!is_array($row)) continue;
                $username = p2k_recruitment_username((string)($row['username'] ?? ''));
                $key = strtolower($username);
                if ($username === '' || !isset($candidateKeys[$key])) continue;
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                $safe = [
                    'daily_rating'=>p2k_recruitment_metric($data,'daily_rating'),'timeout_percent'=>p2k_recruitment_metric($data,'timeout_percent'),
                    'current_daily_games'=>p2k_recruitment_metric($data,'current_daily_games'),'daily_rd'=>p2k_recruitment_metric($data,'daily_rd'),
                    'completed_daily_games'=>p2k_recruitment_metric($data,'completed_daily_games'),'avg_seconds_per_move'=>p2k_recruitment_metric($data,'avg_seconds_per_move'),
                    'last_online_days'=>p2k_recruitment_metric($data,'last_online_days'),'account_age_days'=>p2k_recruitment_metric($data,'account_age_days'),
                    'p2k_state'=>$membership[$key] ?? 'none','closed'=>!empty($data['closed']),'error'=>trim(substr((string)($data['error']??''),0,500)),
                ];
                $evaluation = p2k_recruitment_evaluate($safe, (array)($run['criteria'] ?? []));
                $record = ['username'=>$candidateKeys[$key],'checkedAt'=>gmdate(DATE_ATOM),'data'=>$safe] + $evaluation;
                if (isset($byKey[$key])) $results[$byKey[$key]] = $record; else { $byKey[$key] = count($results); $results[] = $record; }
                $accepted[] = $record;
            }
            $run['results'] = $results;
            if (count($results) >= count((array)($run['candidates'] ?? []))) $run['status'] = 'completed';
            $run['updatedAt'] = gmdate(DATE_ATOM); return $run;
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run, false, false),'results'=>$accepted]);
    }

    if ($action === 'csv') {
        Http::method('GET');
        $run = p2k_recruitment_read('run', []);
        if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="p2k-recruitment-selected.csv"'); header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb'); if ($out === false) throw new RuntimeException('Unable to open CSV output.');
        fputcsv($out, ['username','daily_rating','timeout_percent','current_daily_games','daily_rd','completed_daily_games','avg_seconds_per_move','last_online_days','account_age_days','p2k_state','decision_reason'], ',', '"', '');
        foreach ((array)($run['results'] ?? []) as $row) {
            if (!is_array($row) || empty($row['selected'])) continue; $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            fputcsv($out, [$row['username']??'',$d['daily_rating']??'',$d['timeout_percent']??'',$d['current_daily_games']??'',$d['daily_rd']??'',$d['completed_daily_games']??'',$d['avg_seconds_per_move']??'',$d['last_online_days']??'',$d['account_age_days']??'',$d['p2k_state']??'',$row['reason']??''], ',', '"', '');
        }
        fclose($out); exit;
    }

    throw new ApiException('Unknown recruitment action.', 404, 'UNKNOWN_ACTION');
} catch(ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch(Throwable $e) {
    error_log('P2K recruitment admin: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]], 500);
}
