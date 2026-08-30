<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Http,MemberInsightsTableService,Repository,ResponseCache};

try {
    Http::method('GET');
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $club = (string)$config['app']['club_slug'];
    $options = [
        'page' => 1,
        'page_size' => 100,
        'search' => (string)($_GET['search'] ?? ''),
        'filter' => (string)($_GET['filter'] ?? 'current'),
        'sort' => (string)($_GET['sort'] ?? 'points'),
        'direction' => (string)($_GET['direction'] ?? 'desc'),
        'start' => (string)($_GET['start'] ?? ''),
        'end' => (string)($_GET['end'] ?? ''),
        'evolution' => (string)($_GET['evolution'] ?? '1m'),
        'usernames' => (string)($_GET['usernames'] ?? ''),
        'activity_status' => (string)($_GET['activity_status'] ?? ''),
    ];

    $generation = $repository->publicReadGenerationToken($club, true, true);
    $cache = new ResponseCache(is_array($config['storage'] ?? null) ? $config['storage'] : []);
    $service = new MemberInsightsTableService($repository, $cache, $generation);
    $payload = $service->table($club, $options, false);
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

    $start = trim((string)($options['start'] ?? '')) ?: 'all';
    $end = trim((string)($options['end'] ?? '')) ?: gmdate('Y-m-d');
    $evolution = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($payload['evolution']['key'] ?? '1m')) ?: '1m';
    $filename = sprintf('P2K_Members_%s_to_%s_evolution-%s.csv', $start, $end, $evolution);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) throw new RuntimeException('Unable to open CSV output stream.');

    // UTF-8 BOM keeps Excel from guessing a legacy code page for player names.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Team position','Position change','Previous position','Username','Member status','Activity status','Daily rank',
        'Points','Matches','Games','Wins','Draws','Losses','Net wins','Win rate %','Result coverage %','Points per game',
        'Current matches','Live points','Last activity'
    ]);

    foreach ($rows as $row) {
        $position = $row['team_position'] ?? null;
        $previous = $row['previous_position'] ?? null;
        $change = $row['position_change'] ?? null;
        $changeLabel = !empty($row['position_new']) ? 'NEW' : ($change === null ? '' : (string)(int)$change);
        $rank = is_array($row['daily_rank'] ?? null) ? (string)($row['daily_rank']['name'] ?? 'Unranked') : (string)($row['daily_rank'] ?? 'Unranked');
        fputcsv($out, [
            $position === null ? '' : (int)$position,
            $changeLabel,
            $previous === null ? '' : (int)$previous,
            (string)($row['username'] ?? ''),
            !empty($row['current_member']) ? 'Current member' : 'Former member',
            (string)($row['activity_status'] ?? 'unknown'),
            $rank,
            (float)($row['points'] ?? 0),
            (int)($row['matches'] ?? 0),
            (int)($row['games'] ?? 0),
            (int)($row['wins'] ?? 0),
            (int)($row['draws'] ?? 0),
            (int)($row['losses'] ?? 0),
            (int)($row['net_wins'] ?? 0),
            $row['win_rate'] === null ? '' : (float)$row['win_rate'],
            (float)($row['result_coverage_percent'] ?? 0),
            (float)($row['points_per_game'] ?? 0),
            (int)($row['current_matches'] ?? 0),
            (float)($row['live_points'] ?? 0),
            (string)($row['last_activity'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
} catch (ApiException $e) {
    Http::json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Members CSV export: ' . $e);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Member CSV export is temporarily unavailable.']], 500);
}
