<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;

const P2K_RECRUITMENT_SCHEMA = 1;

function p2k_recruitment_dir(): string
{
    $config = p2k_tp_config();
    $base = rtrim((string)($config['storage']['runtime_dir'] ?? dirname(__DIR__, 3) . '/data/runtime-v280'), '/\\');
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
        if (count($out) >= 10000) break;
    }
    return $out;
}

function p2k_recruitment_number(array $body, string $key, ?float $default): ?float
{
    if (!array_key_exists($key, $body) || $body[$key] === '' || $body[$key] === null) return $default;
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
        'parallelWorkers' => max(1, min(12, (int)($body['parallelWorkers'] ?? 4))),
    ];
    foreach (['minRating','maxRating','maxTimeout','maxRd','minGames','maxGames','minCompleted','maxOffline','maxSpm','minAge'] as $key) {
        if ($criteria[$key] !== null && $criteria[$key] < 0) throw new ApiException($key . ' cannot be negative.', 400, 'INVALID_CRITERIA');
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

function p2k_recruitment_evaluate(array $data, array $criteria): array
{
    $fail = [];
    if (!empty($data['error'])) $fail[] = 'profile data unavailable: ' . trim((string)$data['error']);
    if (!empty($data['closed'])) $fail[] = 'account closed / unavailable';
    $p2k = strtolower(trim((string)($data['p2k_state'] ?? 'none')));
    if ($p2k === 'current') $fail[] = 'already current P2K member';
    if (!empty($criteria['excludeFormer']) && $p2k === 'former') $fail[] = 'former P2K member';

    $checks = [
        ['daily_rating', 'minRating', 'min', 'Daily rating below minimum'],
        ['daily_rating', 'maxRating', 'max', 'Daily rating above maximum'],
        ['timeout_percent', 'maxTimeout', 'max', 'timeout rate too high'],
        ['daily_rd', 'maxRd', 'max', 'Daily rating deviation too high'],
        ['current_daily_games', 'minGames', 'min', 'too few current Daily games'],
        ['current_daily_games', 'maxGames', 'max', 'too many current Daily games'],
        ['completed_daily_games', 'minCompleted', 'min', 'too few completed Daily games'],
        ['last_online_days', 'maxOffline', 'max', 'last online too old'],
        ['avg_seconds_per_move', 'maxSpm', 'max', 'average seconds per move too high'],
        ['account_age_days', 'minAge', 'min', 'account too new'],
    ];
    foreach ($checks as [$field, $criterion, $mode, $message]) {
        $limit = $criteria[$criterion] ?? null;
        if ($limit === null) continue;
        $value = p2k_recruitment_metric($data, $field);
        if ($value === null) {
            $fail[] = str_replace(['too high','below minimum','above maximum','too few','too many','too old','too new'], 'unavailable', $message);
            continue;
        }
        if (($mode === 'min' && $value < (float)$limit) || ($mode === 'max' && $value > (float)$limit)) $fail[] = $message;
    }
    return ['selected' => $fail === [], 'decision' => $fail === [] ? 'selected' : 'rejected', 'reason' => $fail === [] ? 'All criteria passed.' : implode('; ', array_values(array_unique($fail)))];
}

function p2k_recruitment_public_run(array $run): array
{
    if ($run === []) return [];
    $total = count((array)($run['candidates'] ?? []));
    $results = array_values((array)($run['results'] ?? []));
    $selected = count(array_filter($results, static fn(array $row): bool => !empty($row['selected'])));
    $errors = count(array_filter($results, static fn(array $row): bool => !empty($row['data']['error'])));
    $run['results'] = $results;
    $run['summary'] = ['total' => $total, 'checked' => count($results), 'pending' => max(0, $total - count($results)), 'selected' => $selected, 'errors' => $errors];
    return $run;
}

try {
    Auth::requireAdmin();
    $action = strtolower(trim((string)($_GET['action'] ?? 'state')));

    if ($action === 'state') {
        Http::method('GET');
        $pool = p2k_recruitment_read('pool', ['schemaVersion'=>P2K_RECRUITMENT_SCHEMA,'revision'=>0,'updatedAt'=>null,'candidates'=>[]]);
        Http::json(['ok'=>true,'pool'=>$pool,'run'=>p2k_recruitment_public_run(p2k_recruitment_read('run', []))]);
    }

    if ($action === 'save-pool') {
        Http::method('POST');
        $body = Http::body();
        $candidates = p2k_recruitment_normalize_pool($body['candidates'] ?? '');
        $current = p2k_recruitment_read('pool', ['revision'=>0]);
        $pool = ['schemaVersion'=>P2K_RECRUITMENT_SCHEMA,'revision'=>(int)($current['revision']??0)+1,'updatedAt'=>gmdate(DATE_ATOM),'candidates'=>$candidates];
        p2k_recruitment_write('pool', $pool);
        Http::json(['ok'=>true,'pool'=>$pool]);
    }

    if ($action === 'start') {
        Http::method('POST');
        $body = Http::body();
        $pool = p2k_recruitment_read('pool', []);
        $candidates = (array)($pool['candidates'] ?? []);
        if ($candidates === []) throw new ApiException('Save at least one candidate before starting a scan.', 400, 'EMPTY_POOL');
        $requestedCriteria = p2k_recruitment_criteria(is_array($body['criteria'] ?? null) ? $body['criteria'] : []);
        $run = p2k_recruitment_locked_run(static function(array $existing) use ($candidates, $pool, $requestedCriteria): array {
            $poolHash = hash('sha256', json_encode(array_map('strtolower', $candidates), JSON_UNESCAPED_SLASHES));
            $resumable = $existing !== [] && ($existing['poolHash'] ?? '') === $poolHash && !in_array((string)($existing['status'] ?? ''), ['completed','stopped'], true);
            if ($resumable) {
                $existing['status'] = 'running';
                $existing['updatedAt'] = gmdate(DATE_ATOM);
                return $existing;
            }
            return [
                'schemaVersion'=>P2K_RECRUITMENT_SCHEMA,
                'id'=>gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3)),
                'status'=>'running',
                'createdAt'=>gmdate(DATE_ATOM),
                'updatedAt'=>gmdate(DATE_ATOM),
                'poolRevision'=>(int)($pool['revision']??0),
                'poolHash'=>$poolHash,
                'criteria'=>$requestedCriteria,
                'candidates'=>array_values($candidates),
                'results'=>[],
            ];
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run)]);
    }

    if ($action === 'pause') {
        Http::method('POST');
        $run = p2k_recruitment_locked_run(static function(array $run): array {
            if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
            if (($run['status'] ?? '') !== 'completed') $run['status'] = 'paused';
            $run['updatedAt'] = gmdate(DATE_ATOM);
            return $run;
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run)]);
    }

    if ($action === 'restart') {
        Http::method('POST');
        $path = p2k_recruitment_path('run');
        if (is_file($path) && !@unlink($path)) throw new RuntimeException('Unable to clear the recruitment run.');
        Http::json(['ok'=>true,'run'=>[]]);
    }

    if ($action === 'checkpoint') {
        Http::method('POST');
        $body = Http::body();
        $incoming = is_array($body['results'] ?? null) ? array_slice($body['results'], 0, 50) : [];
        if ($incoming === []) throw new ApiException('No recruitment results supplied.', 400, 'EMPTY_CHECKPOINT');
        $run = p2k_recruitment_locked_run(static function(array $run) use ($incoming): array {
            if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
            if (($run['status'] ?? '') === 'paused') throw new ApiException('The recruitment run is paused.', 409, 'RUN_PAUSED');
            $candidateKeys = [];
            foreach ((array)($run['candidates'] ?? []) as $candidate) $candidateKeys[strtolower((string)$candidate)] = (string)$candidate;
            $results = is_array($run['results'] ?? null) ? $run['results'] : [];
            $byKey = [];
            foreach ($results as $index=>$row) if (is_array($row)) $byKey[strtolower((string)($row['username']??''))] = $index;
            foreach ($incoming as $row) {
                if (!is_array($row)) continue;
                $username = p2k_recruitment_username((string)($row['username'] ?? ''));
                $key = strtolower($username);
                if ($username === '' || !isset($candidateKeys[$key])) continue;
                $data = is_array($row['data'] ?? null) ? $row['data'] : [];
                $safe = [
                    'daily_rating'=>p2k_recruitment_metric($data,'daily_rating'),
                    'timeout_percent'=>p2k_recruitment_metric($data,'timeout_percent'),
                    'current_daily_games'=>p2k_recruitment_metric($data,'current_daily_games'),
                    'daily_rd'=>p2k_recruitment_metric($data,'daily_rd'),
                    'completed_daily_games'=>p2k_recruitment_metric($data,'completed_daily_games'),
                    'avg_seconds_per_move'=>p2k_recruitment_metric($data,'avg_seconds_per_move'),
                    'last_online_days'=>p2k_recruitment_metric($data,'last_online_days'),
                    'account_age_days'=>p2k_recruitment_metric($data,'account_age_days'),
                    'p2k_state'=>in_array(strtolower((string)($data['p2k_state']??'none')),['current','former','none'],true)?strtolower((string)($data['p2k_state']??'none')):'none',
                    'closed'=>!empty($data['closed']),
                    'error'=>trim(substr((string)($data['error']??''),0,500)),
                ];
                $evaluation = p2k_recruitment_evaluate($safe, (array)($run['criteria'] ?? []));
                $record = ['username'=>$candidateKeys[$key],'checkedAt'=>gmdate(DATE_ATOM),'data'=>$safe] + $evaluation;
                if (isset($byKey[$key])) $results[$byKey[$key]] = $record;
                else { $byKey[$key] = count($results); $results[] = $record; }
            }
            $run['results'] = $results;
            if (count($results) >= count((array)($run['candidates'] ?? []))) $run['status'] = 'completed';
            $run['updatedAt'] = gmdate(DATE_ATOM);
            return $run;
        });
        Http::json(['ok'=>true,'run'=>p2k_recruitment_public_run($run)]);
    }

    if ($action === 'csv') {
        Http::method('GET');
        $run = p2k_recruitment_read('run', []);
        if ($run === []) throw new ApiException('No recruitment run exists.', 404, 'NO_RUN');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="p2k-recruitment-selected.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['username','daily_rating','timeout_percent','current_daily_games','daily_rd','completed_daily_games','avg_seconds_per_move','last_online_days','account_age_days','p2k_state','decision_reason']);
        foreach ((array)($run['results'] ?? []) as $row) {
            if (!is_array($row) || empty($row['selected'])) continue;
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            fputcsv($out, [$row['username']??'', $d['daily_rating']??'', $d['timeout_percent']??'', $d['current_daily_games']??'', $d['daily_rd']??'', $d['completed_daily_games']??'', $d['avg_seconds_per_move']??'', $d['last_online_days']??'', $d['account_age_days']??'', $d['p2k_state']??'', $row['reason']??'']);
        }
        fclose($out);
        exit;
    }

    throw new ApiException('Unknown recruitment action.', 404, 'UNKNOWN_ACTION');
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K recruitment admin: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>$e->getMessage()]], 500);
}
