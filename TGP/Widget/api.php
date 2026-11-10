<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/ChessApi.php';
require_once __DIR__ . '/src/Auth.php';

use TGP\Widget\Auth;
use TGP\Widget\ChessApi;
use TGP\Widget\ChessApiException;
use TGP\Widget\RevisionConflict;
use TGP\Widget\Store;

$config = require __DIR__ . '/config.php';
$teamSlug = strtolower(trim((string)($config['team_slug'] ?? 'the-golden-phoenix')));
$sessionLifetime = max(300, (int)($config['admin_session_seconds'] ?? 3600));
$store = new Store(__DIR__, max(4, (int)($config['max_configured_matches'] ?? 100)));
$chess = new ChessApi(
    __DIR__ . '/data/cache',
    (string)($config['user_agent'] ?? 'TGP-Match-Showcase/0.2.0'),
    max(0, (int)($config['cache_ttl_match_seconds'] ?? 45)),
    max(0, (int)($config['cache_ttl_club_seconds'] ?? 300)),
);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function reply(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 262144) throw new InvalidArgumentException('Invalid request body.');
    if (trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new InvalidArgumentException('Request body must be JSON.');
    return $decoded;
}
function request_method(string $expected): void {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) throw new RuntimeException('Method not allowed.', 405);
}
function match_reference_id(string $reference): string {
    $reference = trim($reference);
    if ($reference === '') return '';
    if (preg_match('/^[0-9]{1,18}$/D', $reference)) return $reference;
    $patterns = [
        '~(?:api\.chess\.com/pub/match/|/match/)([0-9]{1,18})(?:[/?#]|$)~i',
        '~/(?:club/)?matches/(?:[^/?#]+/)*([0-9]{1,18})(?:[/?#]|$)~i',
        '~(?:^|[-_/])([0-9]{4,18})(?:[/?#]|$)~',
        '~[?&](?:id|match|match_id)=([0-9]{1,18})(?:&|$)~i',
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $reference, $m)) return $m[1];
    return '';
}
function slug_key(string $value): string {
    $value = rawurldecode(trim($value));
    $path = (string)(parse_url($value, PHP_URL_PATH) ?: '');
    if ($path !== '') {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
        if ($parts !== []) $value = (string)end($parts);
    }
    $value = strtolower(trim($value));
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = strtolower($ascii);
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-');
}
function row_match_id(array $row): string {
    foreach (['match_id', 'id', '@id', 'url'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') continue;
        if (preg_match('/(?:match\/|matches\/(?:[^\/?#]+\/)*)?([0-9]{1,18})(?:[\/?#]|$)/i', $value, $m)) return $m[1];
    }
    return '';
}
function club_slug_from_url(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $path = trim((string)(parse_url($value, PHP_URL_PATH) ?: ''), '/');
    $parts = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
    foreach ($parts as $i => $part) {
        if (strtolower($part) === 'club' && isset($parts[$i + 1])) return strtolower(rawurldecode((string)$parts[$i + 1]));
    }
    return $parts !== [] ? strtolower(rawurldecode((string)end($parts))) : '';
}
function title_from_slug(string $slug): string {
    $slug = trim($slug);
    if ($slug === '') return 'Opponent';
    return ucwords(str_replace(['-', '_'], ' ', $slug));
}
function registration_index_rows(array $index, string $teamSlug): array {
    $registered = is_array($index['registered'] ?? null) ? $index['registered'] : [];
    $rows = [];
    $seen = [];
    foreach ($registered as $row) {
        if (!is_array($row)) continue;
        $timeClass = strtolower(trim((string)($row['time_class'] ?? '')));
        if ($timeClass !== 'daily') continue;
        $id = row_match_id($row);
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $opponentSlug = club_slug_from_url((string)($row['opponent'] ?? ''));
        $name = trim((string)($row['name'] ?? '')) ?: 'Match #' . $id;
        $rows[] = [
            'matchId' => $id,
            'name' => $name,
            'apiUrl' => trim((string)($row['@id'] ?? '')) ?: 'https://api.chess.com/pub/match/' . rawurlencode($id),
            'url' => 'https://www.chess.com/club/matches/' . rawurlencode($teamSlug) . '/' . rawurlencode($id),
            'opponentSlug' => $opponentSlug,
            'opponentName' => title_from_slug($opponentSlug),
            'timeClass' => 'daily',
        ];
    }
    return $rows;
}
function club_daily_match_ids(array $index): array {
    $ids = [];
    foreach (['registered', 'in_progress', 'finished'] as $bucketName) {
        $bucket = is_array($index[$bucketName] ?? null) ? $index[$bucketName] : [];
        foreach ($bucket as $row) {
            if (!is_array($row)) continue;
            $timeClass = strtolower(trim((string)($row['time_class'] ?? '')));
            if ($timeClass !== 'daily') continue;
            $id = row_match_id($row);
            if ($id !== '') $ids[$id] = true;
        }
    }
    return $ids;
}
function club_daily_match_map(array $index, string $teamSlug): array {
    $map = [];
    foreach (['registered', 'in_progress', 'finished'] as $bucketName) {
        $bucket = is_array($index[$bucketName] ?? null) ? $index[$bucketName] : [];
        foreach ($bucket as $row) {
            if (!is_array($row)) continue;
            $timeClass = strtolower(trim((string)($row['time_class'] ?? '')));
            if ($timeClass !== 'daily') continue;
            $id = row_match_id($row);
            if ($id === '') continue;
            $opponentSlug = club_slug_from_url((string)($row['opponent'] ?? ''));
            $map[$id] = [
                'matchId' => $id,
                'name' => trim((string)($row['name'] ?? '')) ?: 'Match #' . $id,
                'apiUrl' => trim((string)($row['@id'] ?? '')) ?: 'https://api.chess.com/pub/match/' . rawurlencode($id),
                'url' => 'https://www.chess.com/club/matches/' . rawurlencode($teamSlug) . '/' . rawurlencode($id),
                'opponentSlug' => $opponentSlug,
                'opponentName' => title_from_slug($opponentSlug),
                'status' => $bucketName,
            ];
        }
    }
    return $map;
}
function admin_item_payload(array $item, array $matchMap, string $teamSlug): array {
    $id = (string)($item['matchId'] ?? '');
    $match = $matchMap[$id] ?? [
        'matchId' => $id,
        'name' => 'Match #' . $id,
        'url' => 'https://www.chess.com/club/matches/' . rawurlencode($teamSlug) . '/' . rawurlencode($id),
        'opponentName' => 'Opponent',
    ];
    return array_merge($item, ['resolved' => isset($matchMap[$id]), 'match' => $match, 'error' => isset($matchMap[$id]) ? '' : 'Not present in the current club match index.']);
}
function resolve_match_reference(string $reference, string $teamSlug, ChessApi $chess): string {
    $id = match_reference_id($reference);
    if ($id !== '') return $id;
    $needle = slug_key($reference);
    if ($needle === '') return '';
    $index = $chess->clubMatches($teamSlug, true);
    $matches = [];
    foreach ($index as $bucket) {
        if (!is_array($bucket)) continue;
        foreach ($bucket as $row) {
            if (!is_array($row)) continue;
            $candidateId = row_match_id($row);
            if ($candidateId === '') continue;
            $keys = [];
            foreach (['url', '@id', 'name'] as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') $keys[] = slug_key($value);
            }
            if (in_array($needle, $keys, true)) $matches[$candidateId] = true;
        }
    }
    $ids = array_keys($matches);
    if (count($ids) === 1) return $ids[0];
    if (count($ids) > 1) throw new InvalidArgumentException('That match slug is ambiguous. Paste the Chess.com match URL or numeric id instead.');
    return '';
}
function team_slug_from_team(mixed $team): string {
    if (!is_array($team)) return '';
    foreach (['@id', 'url'] as $field) {
        $value = trim((string)($team[$field] ?? ''));
        if ($value === '') continue;
        $path = trim((string)(parse_url($value, PHP_URL_PATH) ?: ''), '/');
        $parts = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn($x) => $x !== ''));
        foreach ($parts as $i => $part) {
            if (strtolower($part) === 'club' && isset($parts[$i + 1])) return strtolower(rawurldecode($parts[$i + 1]));
        }
        if ($parts !== []) return strtolower(rawurldecode((string)end($parts)));
    }
    return '';
}
function match_teams(array $match): array {
    $teams = $match['teams'] ?? [];
    return is_array($teams) ? array_values(array_filter($teams, 'is_array')) : [];
}
function validate_match(array $match, string $teamSlug): array {
    $timeClass = strtolower(trim((string)($match['time_class'] ?? ($match['settings']['time_class'] ?? ''))));
    if ($timeClass !== '' && $timeClass !== 'daily') throw new InvalidArgumentException('Only Daily Chess team matches are supported.');
    $our = null; $opponent = null;
    foreach (match_teams($match) as $team) {
        if (team_slug_from_team($team) === $teamSlug) $our = $team;
        elseif ($opponent === null) $opponent = $team;
    }
    if (!is_array($our)) throw new InvalidArgumentException('This match does not include the configured club ' . $teamSlug . '.');
    if (!is_array($opponent)) {
        foreach (match_teams($match) as $team) if ($team !== $our) { $opponent = $team; break; }
    }
    if (!is_array($opponent)) throw new InvalidArgumentException('Unable to determine the opponent club.');
    return [$our, $opponent];
}
function league_codes(string $name, array $config): array {
    $codes = is_array($config['league_acronyms'] ?? null) ? $config['league_acronyms'] : [];
    $matches = [];
    foreach ($codes as $code) {
        $code = trim((string)$code);
        if ($code === '') continue;
        if (preg_match('/(^|[^A-Z0-9])' . preg_quote($code, '/') . '([^A-Z0-9]|$)/i', $name)) $matches[] = $code;
    }
    return $matches;
}
function card_payload(array $match, string $matchId, bool $urgent, string $teamSlug, ChessApi $chess, array $config): array {
    [$our, $opponent] = validate_match($match, $teamSlug);
    $opponentSlug = team_slug_from_team($opponent);
    $opponentName = trim((string)($opponent['name'] ?? '')) ?: ($opponentSlug !== '' ? str_replace('-', ' ', $opponentSlug) : 'Opponent');
    $logo = '';
    if ($opponentSlug !== '') {
        try {
            $club = $chess->club($opponentSlug);
            $logo = trim((string)($club['icon'] ?? $club['image'] ?? ''));
        } catch (Throwable) {}
    }
    $name = trim((string)($match['name'] ?? '')) ?: 'Match #' . $matchId;
    $codes = league_codes($name, $config);
    $apiUrl = trim((string)($match['@id'] ?? '')) ?: 'https://api.chess.com/pub/match/' . rawurlencode($matchId);
    $url = trim((string)($match['url'] ?? '')) ?: 'https://www.chess.com/club/matches/' . rawurlencode($teamSlug) . '/' . rawurlencode($matchId);
    $start = $match['start_time'] ?? $match['startTime'] ?? null;
    $start = is_numeric($start) && (int)$start > 0 ? (int)$start : null;
    return [
        'matchId' => $matchId,
        'name' => $name,
        'url' => $url,
        'apiUrl' => $apiUrl,
        'startTime' => $start,
        'urgent' => $urgent,
        'category' => $codes !== [] ? 'league' : 'friendly',
        'leagueAcronyms' => $codes,
        'opponentSlug' => $opponentSlug,
        'opponentName' => $opponentName,
        'opponentLogo' => $logo,
        'settings' => is_array($match['settings'] ?? null) ? $match['settings'] : [],
        'time_control' => $match['time_control'] ?? ($match['settings']['time_control'] ?? null),
        'ourTeam' => $our,
        'opponentTeam' => $opponent,
        'status' => $match['status'] ?? null,
    ];
}
function item_payload(array $item, string $teamSlug, ChessApi $chess, array $config): array {
    $id = (string)($item['matchId'] ?? '');
    try {
        $match = $chess->match($id);
        return array_merge($item, ['resolved' => true, 'match' => card_payload($match, $id, ($item['urgent'] ?? false) === true, $teamSlug, $chess, $config)]);
    } catch (Throwable $e) {
        return array_merge($item, ['resolved' => false, 'error' => $e->getMessage(), 'match' => ['matchId'=>$id,'name'=>'Match #'.$id,'url'=>'https://www.chess.com/club/matches/'.rawurlencode($teamSlug).'/'.rawurlencode($id),'urgent'=>(($item['urgent']??false)===true)]]);
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? 'cards')));
try {
    if ($action === 'cards') {
        request_method('GET');
        $state = $store->read();
        $limit = max(1, min(4, (int)($config['max_display_matches'] ?? 4)));
        $items = array_slice(array_values(array_filter($state['items'], static fn(array $item): bool => ($item['enabled'] ?? true) === true)), 0, $limit);
        $cards = [];
        foreach ($items as $item) $cards[] = item_payload($item, $teamSlug, $chess, $config)['match'];
        reply(200, ['ok'=>true,'version'=>$config['version'] ?? '0.2.0','teamSlug'=>$teamSlug,'cards'=>$cards,'arenas'=>array_slice(array_values(array_filter($state['arenas'] ?? [], static fn(array $a): bool => ($a['active'] ?? false) === true)), 0, 2)]);
    }

    if ($action === 'admin-status') {
        request_method('GET');
        reply(200, ['ok'=>true,'session'=>Auth::info($sessionLifetime),'teamSlug'=>$teamSlug,'teamName'=>$config['team_name'] ?? $teamSlug]);
    }

    if ($action === 'admin-session') {
        request_method('POST');
        Auth::enforceSameOrigin(true);
        $body = body_json();
        $assertion = trim((string)($body['bootstrap_assertion'] ?? ''));
        if ($assertion === '') throw new RuntimeException('Chess.com OAuth login is required.', 401);
        $root = dirname(__DIR__, 2);
        $bootstrap = $root . '/server/team-points/src/bootstrap.php';
        if (!is_file($bootstrap)) throw new RuntimeException('P2K OAuth bootstrap is unavailable.', 503);
        require_once $bootstrap;
        $username = \P2K\TeamPoints\OAuthSession::verifyAdminBootstrapAssertion($assertion);
        if ($username === '') throw new RuntimeException('OAuth identity assertion is invalid or expired.', 401);
        $club = $chess->club($teamSlug, true);
        if (!Auth::clubProfileHasAdmin($club, $username)) throw new RuntimeException('The authenticated Chess.com account is not a current administrator of ' . $teamSlug . '.', 403);
        reply(200, ['ok'=>true,'session'=>Auth::create($username, $sessionLifetime),'teamSlug'=>$teamSlug]);
    }

    if ($action === 'admin-state') {
        request_method('GET');
        Auth::requireAdmin($sessionLifetime, false);
        $state = $store->read();
        // Admin rows need only the lightweight club match index: no per-match detail calls.
        try {
            $index = $chess->clubMatches($teamSlug);
        } catch (ChessApiException $e) {
            // Admins must still be able to update arenas during a Chess.com API outage.
            $index = [];
        }
        $matchMap = club_daily_match_map($index, $teamSlug);
        $items = [];
        foreach ($state['items'] as $item) $items[] = admin_item_payload($item, $matchMap, $teamSlug);
        reply(200, ['ok'=>true,'schemaVersion'=>$state['schemaVersion'],'revision'=>$state['revision'],'updatedAt'=>$state['updatedAt'],'items'=>$items,'arenas'=>$state['arenas'] ?? [],'teamSlug'=>$teamSlug,'teamName'=>$config['team_name'] ?? $teamSlug]);
    }

    if ($action === 'registration-list') {
        request_method('GET');
        Auth::requireAdmin($sessionLifetime, false);
        $force = (string)($_GET['refresh'] ?? '') === '1';
        $index = $chess->clubMatches($teamSlug, $force);
        reply(200, [
            'ok'=>true,
            'source'=>'https://api.chess.com/pub/club/' . $teamSlug . '/matches',
            'loadedAt'=>time(),
            'matches'=>registration_index_rows($index, $teamSlug),
        ]);
    }

    if ($action === 'resolve') {
        request_method('POST');
        Auth::requireAdmin($sessionLifetime, true);
        $body = body_json();
        $reference = trim((string)($body['reference'] ?? ''));
        $id = resolve_match_reference($reference, $teamSlug, $chess);
        if ($id === '') throw new InvalidArgumentException('No Daily match for the configured club matched that URL, id or slug.');
        $match = $chess->match($id, true);
        $normalized = card_payload($match, $id, false, $teamSlug, $chess, $config);
        reply(200, ['ok'=>true,'match'=>$normalized]);
    }

    if ($action === 'save') {
        request_method('POST');
        Auth::requireAdmin($sessionLifetime, true);
        $body = body_json();
        $expected = filter_var($body['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) throw new InvalidArgumentException('revision must be a non-negative integer.');
        // Arena-only edits and changes to existing match flags must not depend on Chess.com API uptime.
        // Validate newly introduced Daily IDs only; the stored revision is checked atomically by Store.
        $current = $store->read();
        $currentIds = [];
        foreach ($current['items'] as $existing) $currentIds[(string)$existing['matchId']] = true;
        $newIds = [];
        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $item) {
            $id = trim((string)($item['matchId'] ?? ''));
            if ($id === '' || !preg_match('/^[0-9]{1,18}$/D', $id)) throw new InvalidArgumentException('Invalid match id.');
            if (!isset($currentIds[$id])) $newIds[$id] = true;
        }
        if ($newIds !== []) {
            $index = $chess->clubMatches($teamSlug);
            $knownDailyIds = club_daily_match_ids($index);
            foreach (array_keys($newIds) as $id) {
                if (isset($knownDailyIds[$id])) continue;
                validate_match($chess->match($id), $teamSlug);
            }
        }
        $saved = $store->save($body, (int)$expected);
        reply(200, array_merge(['ok'=>true], $saved));
    }

    if ($action === 'logout') {
        request_method('POST');
        Auth::requireAdmin($sessionLifetime, true);
        Auth::logout($sessionLifetime);
        reply(200, ['ok'=>true]);
    }

    reply(404, ['ok'=>false,'error'=>['message'=>'Unknown action.']]);
} catch (RevisionConflict $e) {
    reply(409, ['ok'=>false,'error'=>['message'=>$e->getMessage(),'code'=>'REVISION_CONFLICT'],'current'=>$e->current]);
} catch (InvalidArgumentException $e) {
    reply(400, ['ok'=>false,'error'=>['message'=>$e->getMessage(),'code'=>'INVALID_REQUEST']]);
} catch (ChessApiException $e) {
    $status = $e->status === 404 ? 404 : 502;
    reply($status, ['ok'=>false,'error'=>['message'=>$e->getMessage(),'code'=>'CHESS_API_ERROR']]);
} catch (RuntimeException $e) {
    $status = $e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    reply($status, ['ok'=>false,'error'=>['message'=>$e->getMessage(),'code'=>'RUNTIME_ERROR']]);
} catch (Throwable $e) {
    error_log('TGP Widget: ' . $e);
    reply(500, ['ok'=>false,'error'=>['message'=>'The Match Showcase encountered an unexpected server error.','code'=>'SERVER_ERROR']]);
}
