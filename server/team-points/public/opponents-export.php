<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Auth,Http,Repository};

try {
    Http::method('GET');
    Auth::requireAdmin();
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $club = (string)$config['app']['club_slug'];
    $search = substr(trim((string)($_GET['search'] ?? '')), 0, 120);
    $filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
    if (!in_array($filter, ['all','active','registered','finished','disabled'], true)) $filter = 'all';
    $sort = strtolower(trim((string)($_GET['sort'] ?? 'total')));
    $direction = strtolower((string)($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where = ['club_slug=?'];
    $params = [$club];
    if ($search !== '') {
        $where[] = '(display_name LIKE ? OR opponent_slug LIKE ?)';
        $needle = '%' . $search . '%';
        $params[] = $needle;
        $params[] = $needle;
    }
    if ($filter === 'active') $where[] = 'ongoing>0';
    elseif ($filter === 'registered') $where[] = 'registered>0';
    elseif ($filter === 'finished') $where[] = 'finished>0';
    elseif ($filter === 'disabled') $where[] = 'disabled=1';

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $sortColumns = [
        'name'=>'display_name','total'=>'matches','ongoing'=>'ongoing','registered'=>'registered','finished'=>'finished',
        'wins'=>'wins','draws'=>'draws','losses'=>'losses','our_points'=>'our_points','their_points'=>'their_points',
        'balance'=>'(our_points-their_points)',
        'win_rate'=>'CASE WHEN (wins+draws+losses)>0 THEN wins/(wins+draws+losses) ELSE 0 END',
    ];
    $sortSql = $sortColumns[$sort] ?? 'matches';

    $q = $repository->analytics()->prepare(
        "SELECT *,ROUND(our_points-their_points,1) balance,(wins+draws+losses) result_covered,
                GREATEST(0,finished-(wins+draws+losses)) result_missing,
                CASE WHEN finished>0 THEN ROUND(100*(wins+draws+losses)/finished,1) ELSE 100 END result_coverage_percent,
                CASE WHEN (wins+draws+losses)>0 THEN ROUND(100*wins/(wins+draws+losses),1) ELSE 0 END win_rate
         FROM p2k_an_opponent_stats{$whereSql}
         ORDER BY {$sortSql} {$direction},display_name ASC"
    );
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $behaviour = [];
    $bq = $repository->analytics()->prepare(
        "SELECT opponent_slug,COUNT(*) sample_matches,ROUND(AVG(board_count),1) avg_boards,
                ROUND(AVG(opponent_avg_rating),0) avg_opponent_rating,
                SUM(is_league=1) league_matches,SUM(is_league=0) friendly_matches,
                SUM(end_time>=UTC_TIMESTAMP()-INTERVAL 90 DAY) matches_last_90d,
                ROUND(AVG(CASE WHEN status='finished' THEN p2k_score-opponent_score END),2) avg_score_margin
         FROM p2k_an_match_facts
         WHERE club_slug=? AND opponent_slug IS NOT NULL AND opponent_slug<>'' AND is_void=0
         GROUP BY opponent_slug"
    );
    $bq->execute([$club]);
    foreach ($bq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $behaviour[(string)$row['opponent_slug']] = $row;

    $core = [];
    $cq = $repository->core()->prepare(
        'SELECT opponent_slug,country_code,first_seen_at,last_seen_at,last_checked_at,icon_url,icon_checked_at,profile_updated_at,last_error
         FROM p2k_tp_opponents WHERE club_slug=?'
    );
    $cq->execute([$club]);
    foreach ($cq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $core[(string)$row['opponent_slug']] = $row;

    $filename = 'P2K_Opponent_Profiles_' . gmdate('Ymd\THis\Z') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) throw new RuntimeException('Unable to open CSV output stream.');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'opponent_slug','name','club_url','disabled','country_code',
        'matches','registered','ongoing','finished','wins','draws','losses',
        'result_covered','result_missing','result_coverage_percent',
        'our_points','their_points','balance','win_rate_percent','total_boards','avg_boards','avg_opponent_rating','league_matches','friendly_matches','matches_last_90d','avg_score_margin',
        'first_match_at_utc','last_match_at_utc',
        'first_seen_at_utc','last_seen_at_utc','last_checked_at_utc','profile_updated_at_utc',
        'icon_url','icon_checked_at_utc','last_error'
    ]);

    foreach ($rows as $row) {
        $slug = (string)$row['opponent_slug'];
        $m = $core[$slug] ?? [];
        $b = $behaviour[$slug] ?? [];
        fputcsv($out, [
            $slug,(string)$row['display_name'],(string)($row['club_url'] ?? ''),(int)$row['disabled'],(string)($m['country_code'] ?? ''),
            (int)$row['matches'],(int)$row['registered'],(int)$row['ongoing'],(int)$row['finished'],
            (int)$row['wins'],(int)$row['draws'],(int)$row['losses'],
            (int)$row['result_covered'],(int)$row['result_missing'],(float)$row['result_coverage_percent'],
            (float)$row['our_points'],(float)$row['their_points'],(float)$row['balance'],(float)$row['win_rate'],(int)$row['total_boards'],
            (float)($b['avg_boards'] ?? 0),$b['avg_opponent_rating']===null?'':(int)($b['avg_opponent_rating'] ?? 0),(int)($b['league_matches'] ?? 0),(int)($b['friendly_matches'] ?? 0),(int)($b['matches_last_90d'] ?? 0),(float)($b['avg_score_margin'] ?? 0),
            (string)($row['first_match_at'] ?? ''),(string)($row['last_match_at'] ?? ''),
            (string)($m['first_seen_at'] ?? ''),(string)($m['last_seen_at'] ?? ''),(string)($m['last_checked_at'] ?? ''),(string)($m['profile_updated_at'] ?? ''),
            (string)($m['icon_url'] ?? ''),(string)($m['icon_checked_at'] ?? ''),(string)($m['last_error'] ?? '')
        ]);
    }
    fclose($out);
    exit;
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Opponent profiles CSV export: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Opponent profiles CSV export is temporarily unavailable.']], 500);
}
