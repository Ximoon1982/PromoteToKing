<?php
declare(strict_types=1);

/**
 * Controlled repair of one Green -> compatibility match projection.
 *
 * Usage: php repair-compat-match.php --match=1974492 --player=ximoon [--apply]
 * Without --apply, performs read-only checks. Does not fetch Chess.com data,
 * reset GAB, alter Green game facts, or insert an achievement manually.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use P2K\Green\GreenRepository;
use P2K\Green\GreenCompatibility;
use P2K\TeamPoints\AnalyticsBuilder;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}
$options = getopt('', ['match:', 'player:', 'apply']);
$id = filter_var($options['match'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$user = strtolower(trim((string)($options['player'] ?? '')));
if ($id === false || $user === '' || !preg_match('/^[a-z0-9_-]{2,40}$/', $user)) {
    fwrite(STDERR, "Usage: php repair-compat-match.php --match=1974492 --player=ximoon [--apply]\n");
    exit(2);
}
$apply = array_key_exists('apply', $options);
$repo = GreenRepository::open();
$core = $repo->core;
$analytics = $repo->analytics;
$club = $repo->clubSlug;

$matchQuery = $core->prepare("SELECT match_id,name,status,club_verified,time_class FROM p2k_g_matches WHERE match_id=? LIMIT 1");
$matchQuery->execute([$id]);
$match = $matchQuery->fetch(PDO::FETCH_ASSOC);
if (!$match || (int)$match['club_verified'] !== 1 || $match['time_class'] !== 'daily') {
    throw new RuntimeException('Match is absent or not an eligible Green club daily match.');
}

$source = $core->prepare("
 SELECT COUNT(DISTINCT CONCAT(g.board_no,':',GREATEST(1,g.game_index))) games,
        COALESCE(SUM(e.points),0) points
 FROM p2k_g_games g
 JOIN p2k_g_point_events e
   ON e.match_id=g.match_id AND e.board_no=g.board_no AND e.game_url=g.game_url
 LEFT JOIN p2k_g_identity_map im ON im.username_key=e.username_key
 WHERE g.match_id=? AND g.end_epoch>0
   AND COALESCE(im.canonical_username_key,e.username_key)=?
");
$source->execute([$id,$user]);
$expected = $source->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int)($expected['games'] ?? 0) < 1) {
    throw new RuntimeException('No completed Green games linked to the requested player; refusing repair.');
}

$compat = $core->prepare("
 SELECT COUNT(g.game_row_id) games,
        COALESCE(SUM(g.points_x2),0) points_x2,
        MAX(b.finished_game_count) finished_game_count,
        MAX(b.state) board_state
 FROM p2k_tp_boards b
 JOIN p2k_tp_members u ON u.member_id=b.member_id
 LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id
 WHERE u.club_slug=? AND u.username_key=? AND b.match_id=?
");
$compatRead = static function () use ($compat,$club,$user,$id): array {
    $compat->execute([$club,$user,$id]);
    return $compat->fetch(PDO::FETCH_ASSOC) ?: [];
};
$before = $compatRead();

$report = [
    'match_id' => $id,
    'player' => $user,
    'match' => $match['name'],
    'apply' => $apply,
    'green_completed_games' => (int)$expected['games'],
    'green_points' => (float)$expected['points'],
    'compat_before' => $before,
];
if (!$apply) {
    $report['next_action'] = 'Repeat with --apply to reproject this match and refresh the named player achievements.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Existing projector is the sole writer of compatibility board/game facts.
$projection = (new GreenCompatibility($repo))->projectMatch($id);
$after = $compatRead();
$report['projection'] = $projection;
$report['compat_after'] = $after;
$actualGames = (int)($after['games'] ?? 0);
$actualPoints = (int)($after['points_x2'] ?? 0);
$expectedPointsX2 = (int)round((float)$expected['points'] * 2);
if ($actualGames !== (int)$expected['games'] || $actualPoints !== $expectedPointsX2) {
    $report['error'] = 'Player game/point parity failed; achievement refresh deliberately skipped.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}

// Reuse existing achievement derivation, scoped to the affected player.
// Scheduled maintenance lock prevents a conflicting normal achievement sweep.
$builder = new AnalyticsBuilder($core,$analytics);
$report['achievement_refresh'] = $builder->refreshAchievementPlayers($club,[$user]);
$q = $analytics->prepare("SELECT achievement_key,earned_at,source_name FROM p2k_an_achievement_unlocks WHERE club_slug=? AND username_key=? AND achievement_key LIKE 'kotml-%' ORDER BY achievement_key");
$q->execute([$club,$user]);
$report['kotml_achievements'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
$report['ok'] = true;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
