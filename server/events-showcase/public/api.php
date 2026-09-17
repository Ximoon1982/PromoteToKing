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
    $where = ["m.club_slug=?", "m.status='registered'", "m.is_void=0"];
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
    $sql = "SELECT m.match_id,m.match_name,m.match_url,m.is_league,m.start_time,m.time_control,m.opponent_slug,m.opponent_name,o.icon_url
            FROM p2k_tp_match_metadata m
            LEFT JOIN p2k_tp_opponents o ON o.club_slug=m.club_slug AND o.opponent_slug=m.opponent_slug
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.is_league DESC,CASE WHEN m.start_time IS NULL THEN 1 ELSE 0 END,m.start_time ASC,m.match_id ASC";
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $rows = [];
    $now = time();
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (string)($row['match_id'] ?? '');
        if ($id === '') continue;
        $name = trim((string)($row['match_name'] ?? ''));
        if ($name === '') $name = 'Match #' . $id;
        $start = trim((string)($row['start_time'] ?? ''));
        $startEpoch = $start === '' ? null : strtotime($start . ' UTC');
        $started = $startEpoch !== null && $startEpoch !== false && $startEpoch <= $now;
        if ($started && !$includeStarted) continue;
        $leagueAcronyms = league_codes($name);
        $isLeague = !empty($row['is_league']) || $leagueAcronyms !== [];
        $rows[] = [
            'matchId' => $id,
            'name' => $name,
            'url' => trim((string)($row['match_url'] ?? '')) ?: 'https://www.chess.com/club/matches/' . rawurlencode($club) . '/' . $id,
            'apiUrl' => chess_match_url($id),
            'category' => $isLeague ? 'league' : 'friendly',
            'isLeague' => $isLeague,
            'leagueAcronyms' => $leagueAcronyms,
            'startTime' => $startEpoch === false ? null : $startEpoch,
            'started' => $started,
            'timeControl' => $row['time_control'] ?? null,
            'opponentSlug' => trim((string)($row['opponent_slug'] ?? '')),
            'opponentName' => trim((string)($row['opponent_name'] ?? '')) ?: 'Opponent',
            'opponentLogo' => trim((string)($row['icon_url'] ?? '')),
            'joinable' => true,
            'source' => 'p2k-core',
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
