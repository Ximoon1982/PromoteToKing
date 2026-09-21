<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/api/_common.php';
require_once dirname(__DIR__) . '/src/EventsShowcaseStore.php';

use P2K\EventsShowcase\EventsShowcaseStore;
use P2K\EventsShowcase\RevisionConflict;
use P2K\TeamPoints\PublicReadDatabase;

function p2k_events_showcase_catalog(?array $matchIds = null, bool $includeStarted = false): array {
    $config = p2k_tp_config();
    $club = strtolower(trim((string)($config['app']['club_slug'] ?? DEFAULT_CLUB_SLUG)));
    $pdo = PublicReadDatabase::core();
    // Registered-match membership and timing come directly from authoritative
    // Green facts. Optional opponent icons remain an enrichment cache only and
    // can never suppress or delay a newly discovered match.
    $where = ["m.club_verified=1", "m.verified_club_slug=?", "m.is_void=0",
        "COALESCE(NULLIF(m.time_class,''),NULLIF(m.index_time_class,''))='daily'",
        "(CASE WHEN m.status='unknown' THEN COALESCE(m.index_bucket,'unknown') ELSE m.status END)='registered'"];
    $params = [$club];
    if (is_array($matchIds)) {
        $ids = array_values(array_unique(array_filter(array_map(static function(mixed $value): int {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            return $id === false || $id <= 0 ? 0 : (int)$id;
        }, $matchIds))));
        if ($ids === []) return [];
        $where[] = 'm.match_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }
    $sql = "SELECT m.match_id,m.api_url,m.web_url,m.name,m.start_epoch,m.time_control,m.opponent_url,m.opponent_name
            FROM p2k_g_matches m
            WHERE " . implode(' AND ', $where) . "
            ORDER BY CASE WHEN m.start_epoch IS NULL THEN 1 ELSE 0 END,m.start_epoch ASC,m.match_id ASC";
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $nativeRows = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $slugs = [];
    foreach ($nativeRows as &$row) {
        $opponentUrl = trim((string)($row['opponent_url'] ?? ''));
        $slug = '';
        if ($opponentUrl !== '') {
            $path = trim((string)(parse_url($opponentUrl, PHP_URL_PATH) ?: ''), '/');
            $parts = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn($v) => $v !== ''));
            $candidate = strtolower((string)end($parts));
            if ($candidate !== '' && preg_match('/^[a-z0-9_-]{1,160}$/', $candidate)) $slug = $candidate;
        }
        $row['_opponent_slug'] = $slug;
        if ($slug !== '') $slugs[$slug] = true;
    }
    unset($row);

    $logos = [];
    if ($slugs !== []) {
        try {
            $keys = array_keys($slugs);
            $logoQuery = $pdo->prepare('SELECT opponent_slug,icon_url FROM p2k_tp_opponents WHERE club_slug=? AND opponent_slug IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
            $logoQuery->execute(array_merge([$club], $keys));
            foreach ($logoQuery->fetchAll(PDO::FETCH_ASSOC) ?: [] as $logoRow) {
                $slug = strtolower(trim((string)($logoRow['opponent_slug'] ?? '')));
                if ($slug !== '') $logos[$slug] = trim((string)($logoRow['icon_url'] ?? ''));
            }
        } catch (Throwable) {
            // Enrichment failure must never hide authoritative Green matches.
        }
    }

    $rows = [];
    $now = time();
    foreach ($nativeRows as $row) {
        $id = (string)($row['match_id'] ?? '');
        if ($id === '') continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') $name = 'Match #' . $id;
        $startEpoch = is_numeric($row['start_epoch'] ?? null) && (int)$row['start_epoch'] > 0 ? (int)$row['start_epoch'] : null;
        $started = $startEpoch !== null && $startEpoch <= $now;
        if ($started && !$includeStarted) continue;
        $leagueAcronyms = league_codes($name);
        $isLeague = $leagueAcronyms !== [];
        $opponentSlug = trim((string)($row['_opponent_slug'] ?? ''));
        $rows[] = [
            'matchId' => $id,
            'name' => $name,
            'url' => trim((string)($row['web_url'] ?? '')) ?: 'https://www.chess.com/club/matches/' . rawurlencode($club) . '/' . $id,
            'apiUrl' => trim((string)($row['api_url'] ?? '')) ?: chess_match_url($id),
            'category' => $isLeague ? 'league' : 'friendly',
            'isLeague' => $isLeague,
            'leagueAcronyms' => $leagueAcronyms,
            'startTime' => $startEpoch,
            'started' => $started,
            'timeControl' => $row['time_control'] ?? null,
            'opponentSlug' => $opponentSlug,
            'opponentName' => trim((string)($row['opponent_name'] ?? '')) ?: 'Opponent',
            'opponentLogo' => $logos[$opponentSlug] ?? '',
            'joinable' => true,
            'source' => 'p2k-green',
        ];
    }
    return $rows;
}

$store = new EventsShowcaseStore(root_dir());
$action = trim((string)($_GET['action'] ?? 'state'));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($action === 'state' && $method === 'GET') {
        $state = $store->read();
        $ids = array_map(static fn(array $item): string => (string)($item['matchId'] ?? ''), array_values(array_filter($state['items'] ?? [], 'is_array')));
        $catalog = p2k_events_showcase_catalog($ids, true);
        $startedIds = [];
        foreach ($catalog as $match) {
            if (($match['started'] ?? false) === true) {
                $startedIds[(string)($match['matchId'] ?? '')] = true;
            }
        }
        if ($startedIds !== []) {
            $state['items'] = array_values(array_filter(
                array_values(array_filter($state['items'] ?? [], 'is_array')),
                static fn(array $item): bool => !isset($startedIds[(string)($item['matchId'] ?? '')])
            ));
        }
        $state['catalog'] = array_values(array_filter(
            $catalog,
            static fn(array $match): bool => ($match['started'] ?? false) !== true
        ));
        $state['catalogLoadedAt'] = (int)round(microtime(true) * 1000);
        json_response(200, array_merge(['ok'=>true], $state));
    }
    if ($action === 'catalog' && $method === 'GET') {
        json_response(200, ['ok'=>true,'source'=>'p2k-core','loadedAt'=>(int)round(microtime(true) * 1000),'matches'=>p2k_events_showcase_catalog(null)]);
    }
    if ($action === 'metrics' && $method === 'GET') json_response(200, ['ok'=>true,'metrics'=>$store->metrics()]);
    if ($action === 'save' && in_array($method, ['PUT','POST'], true)) {
        require_admin_write('events-showcase');
        $body = body_json(262144);
        $expected = filter_var($body['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) throw new InvalidArgumentException('revision must be a non-negative integer');
        json_response(200, array_merge(['ok'=>true], $store->save($body, (int)$expected)));
    }
    api_error(405, 'METHOD_NOT_ALLOWED', 'Method/action not allowed.');
} catch (RevisionConflict $error) {
    api_error(409, 'REVISION_CONFLICT', $error->getMessage(), ['current'=>$error->current]);
} catch (\P2K\TeamPoints\ApiException $error) {
    api_error($error->httpStatus, $error->errorCode, $error->getMessage(), $error->details);
} catch (InvalidArgumentException $error) {
    api_error(400, 'INVALID_REQUEST', $error->getMessage());
} catch (Throwable $error) {
    api_error(500, 'SERVER_ERROR', $error->getMessage());
}
