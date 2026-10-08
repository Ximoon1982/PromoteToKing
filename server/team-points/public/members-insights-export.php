<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Auth,Http,MemberInsightsTableService,Repository,ResponseCache};

function p2k_members_csv_utc(mixed $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value . (preg_match('/(?:Z|[+-]\d\d:?\d\d)$/', $value) ? '' : ' UTC'));
    return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
}
function p2k_members_csv_epoch(mixed $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $ts = strtotime($value . (preg_match('/(?:Z|[+-]\d\d:?\d\d)$/', $value) ? '' : ' UTC'));
    return $ts === false ? '' : (string)$ts;
}

try {
    Http::method('GET');
    Auth::requireAdmin();
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $club = (string)$config['app']['club_slug'];
    $generation = $repository->publicReadGenerationToken($club, true, true);
    $cache = new ResponseCache(is_array($config['storage'] ?? null) ? $config['storage'] : []);
    $service = new MemberInsightsTableService($repository, $cache, $generation);

    // Administrative export: complete known member population, independent of
    // the current UI page/search/current-former/activity filters.
    $payload = $service->table($club, [
        'page'=>1,'page_size'=>100000,'search'=>'','filter'=>'all',
        'sort'=>(string)($_GET['sort'] ?? 'username'),
        'direction'=>(string)($_GET['direction'] ?? 'asc'),
        'start'=>'','end'=>'','evolution'=>'1m','usernames'=>'','activity_status'=>'',
    ], false);
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

    $meta = [];
    $q = $repository->core()->prepare(
        'SELECT username_key,player_id,joined_at,daily_rating,chess960_rating,rating_updated_at,
                player_matches_checked_at,player_matches_observed_at,stats_checked_at,stats_observed_at,
                profile_observed_at,country_code,profile_status,profile_url,avatar_url,profile_updated_at,
                first_seen_at,last_seen_at
         FROM p2k_tp_members WHERE club_slug=?'
    );
    $q->execute([$club]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $meta[(string)$row['username_key']] = $row;

    $filename = 'P2K_Members_' . gmdate('Ymd\THis\Z') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) throw new RuntimeException('Unable to open CSV output stream.');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'username','username_key','member_status','current_member','activity_status','profile_status','country_code','player_id',
        'joined_utc','joined_epoch','first_seen_utc','first_seen_epoch','last_seen_utc','last_seen_epoch',
        'daily_rating','chess960_rating','rating_updated_utc','rating_updated_epoch',
        'daily_rank','team_position','position_change','team_points','matches','games','wins','draws','losses',
        'result_covered','result_missing','result_coverage_percent','win_rate_percent','points_per_game','current_matches',
        'mca_points','mca_arenas','mca_best_rank','achievement_count',
        'first_activity_utc','first_activity_epoch','last_activity_utc','last_activity_epoch',
        'last_standard_game_utc','last_standard_game_epoch','last_chess960_game_utc','last_chess960_game_epoch',
        'player_matches_checked_utc','player_matches_observed_utc','stats_checked_utc','stats_observed_utc',
        'profile_observed_utc','profile_updated_utc','profile_url','avatar_url'
    ]);

    foreach ($rows as $row) {
        $key = (string)($row['username_key'] ?? '');
        $m = $meta[$key] ?? [];
        $rank = is_array($row['daily_rank'] ?? null)
            ? (string)($row['daily_rank']['name'] ?? $row['daily_rank']['key'] ?? '')
            : (string)($row['daily_rank'] ?? '');
        fputcsv($out, [
            (string)($row['username'] ?? ''),$key,!empty($row['current_member'])?'current':'former',!empty($row['current_member'])?1:0,
            (string)($row['activity_status'] ?? 'unknown'),(string)($m['profile_status'] ?? ''),(string)($m['country_code'] ?? ''),(string)($m['player_id'] ?? ''),
            p2k_members_csv_utc($m['joined_at'] ?? null),p2k_members_csv_epoch($m['joined_at'] ?? null),
            p2k_members_csv_utc($m['first_seen_at'] ?? $row['first_seen_at'] ?? null),p2k_members_csv_epoch($m['first_seen_at'] ?? $row['first_seen_at'] ?? null),
            p2k_members_csv_utc($m['last_seen_at'] ?? $row['last_seen_at'] ?? null),p2k_members_csv_epoch($m['last_seen_at'] ?? $row['last_seen_at'] ?? null),
            $row['daily_rating'] ?? $m['daily_rating'] ?? '',$row['chess960_rating'] ?? $m['chess960_rating'] ?? '',
            p2k_members_csv_utc($row['rating_updated_at'] ?? $m['rating_updated_at'] ?? null),p2k_members_csv_epoch($row['rating_updated_at'] ?? $m['rating_updated_at'] ?? null),
            $rank,$row['team_position'] ?? '',$row['position_change'] ?? '',$row['points'] ?? 0,$row['matches'] ?? 0,$row['games'] ?? 0,
            $row['wins'] ?? 0,$row['draws'] ?? 0,$row['losses'] ?? 0,$row['result_covered'] ?? 0,$row['result_missing'] ?? 0,
            $row['result_coverage_percent'] ?? 0,$row['win_rate'] ?? '',$row['points_per_game'] ?? 0,$row['current_matches'] ?? 0,
            $row['live_points'] ?? 0,$row['live_arenas'] ?? 0,$row['live_best_rank'] ?? '',$row['achievement_count'] ?? 0,
            p2k_members_csv_utc($row['first_activity'] ?? null),p2k_members_csv_epoch($row['first_activity'] ?? null),
            p2k_members_csv_utc($row['last_activity'] ?? null),p2k_members_csv_epoch($row['last_activity'] ?? null),
            p2k_members_csv_utc($row['last_standard_game_at'] ?? null),p2k_members_csv_epoch($row['last_standard_game_at'] ?? null),
            p2k_members_csv_utc($row['last_chess960_game_at'] ?? null),p2k_members_csv_epoch($row['last_chess960_game_at'] ?? null),
            p2k_members_csv_utc($m['player_matches_checked_at'] ?? null),p2k_members_csv_utc($m['player_matches_observed_at'] ?? null),
            p2k_members_csv_utc($m['stats_checked_at'] ?? null),p2k_members_csv_utc($m['stats_observed_at'] ?? null),
            p2k_members_csv_utc($m['profile_observed_at'] ?? null),p2k_members_csv_utc($m['profile_updated_at'] ?? null),
            (string)($m['profile_url'] ?? ''),(string)($m['avatar_url'] ?? '')
        ]);
    }
    fclose($out);
    exit;
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K Members CSV export: ' . $e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Members CSV export is temporarily unavailable.']], 500);
}
