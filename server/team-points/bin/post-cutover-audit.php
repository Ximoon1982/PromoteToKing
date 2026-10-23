<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Post-cutover audit requires PHP CLI.\n");exit(2);}
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\AnalyticsBuilder;
use P2K\TeamPoints\PublicReadDatabase;

$core=PublicReadDatabase::core();
$analytics=PublicReadDatabase::analytics();
$config=p2k_tp_config();
$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
$one=static function(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();};
$row=static function(PDO $pdo,string $sql,array $args=[]): array {$q=$pdo->prepare($sql);$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:[];};

$builder=new AnalyticsBuilder($core,$analytics);
$currentWatermark=$builder->sourceWatermark($club);
$currentAchievementWatermark=$builder->achievementSourceWatermark($club);
$refresh=[];
$q=$analytics->prepare("SELECT domain_key,source_watermark,refreshed_at,row_count,last_error FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key IN ('all','achievements')");
$q->execute([$club]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$refresh[(string)$r['domain_key']]=$r;

$greenCurrent=(int)$one($core,"SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1");
$compatCurrent=(int)$one($core,"SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=1",[$club]);
$greenMatches=(int)$one($core,"SELECT COUNT(*) FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily' AND is_void=0");
$compatMatches=(int)$one($core,"SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND is_void=0",[$club]);
$greenPoint=$row($core,"SELECT ROUND(COALESCE(SUM(e.points),0),1) points,COUNT(*) games,COALESCE(SUM(e.points=1.0),0) wins,COALESCE(SUM(e.points=0.5),0) draws,COALESCE(SUM(e.points=0.0),0) losses FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id WHERE m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')");
$analyticsPoint=$row($analytics,"SELECT ROUND(COALESCE(SUM(points),0),1) points,COALESCE(SUM(games),0) games,COALESCE(SUM(wins),0) wins,COALESCE(SUM(draws),0) draws,COALESCE(SUM(losses),0) losses FROM p2k_an_player_totals WHERE club_slug=?",[$club]);
$greenClubPoints=(float)$one($core,"SELECT COALESCE(SUM(CASE WHEN scoring_eligible=1 THEN competition_points ELSE 0 END),0) FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily' AND is_void=0");
$analyticsClubPoints=(float)$one($analytics,"SELECT COALESCE(MAX(club_points),0) FROM p2k_tp_club_totals WHERE club_slug=?",[$club]);
$insight=$row($analytics,"SELECT COUNT(*) rows_count,MAX(activity_date) latest_activity_date,MAX(computed_at) latest_computed_at FROM p2k_tp_insight_daily WHERE club_slug=?",[$club]);
$opponents=$row($analytics,"SELECT COUNT(*) rows_count,MAX(updated_at) latest_updated_at FROM p2k_an_opponent_stats WHERE club_slug=?",[$club]);
$greenState=$row($core,"SELECT cycle_no,last_worker_finish,last_worker_status,last_roster_fetch,last_index_fetch,compat_analytics_rebuilt_at,last_error FROM p2k_g_state WHERE club_slug=? LIMIT 1",[$club]);
$all=$refresh['all']??[];$ach=$refresh['achievements']??[];
$allMatch=(string)($all['source_watermark']??'')!==''&&hash_equals((string)$all['source_watermark'],$currentWatermark);
$achMatch=(string)($ach['source_watermark']??'')!==''&&hash_equals((string)$ach['source_watermark'],$currentAchievementWatermark);

$checks=[
 ['key'=>'database_route','pass'=>true,'green_core'=>(string)$core->query('SELECT DATABASE()')->fetchColumn(),'green_analytics'=>(string)$analytics->query('SELECT DATABASE()')->fetchColumn()],
 ['key'=>'current_members','pass'=>$greenCurrent===$compatCurrent,'green'=>$greenCurrent,'compatibility'=>$compatCurrent,'delta'=>$compatCurrent-$greenCurrent],
 ['key'=>'matches','pass'=>$greenMatches===$compatMatches,'green'=>$greenMatches,'compatibility'=>$compatMatches,'delta'=>$compatMatches-$greenMatches],
 ['key'=>'player_points','pass'=>abs((float)($greenPoint['points']??0)-(float)($analyticsPoint['points']??0))<0.001,'green'=>$greenPoint,'analytics'=>$analyticsPoint],
 ['key'=>'club_points','pass'=>abs($greenClubPoints-$analyticsClubPoints)<0.001,'green'=>$greenClubPoints,'analytics'=>$analyticsClubPoints,'delta'=>$analyticsClubPoints-$greenClubPoints],
 ['key'=>'analytics_watermark','pass'=>$allMatch&&trim((string)($all['last_error']??''))==='','current'=>$currentWatermark,'materialized'=>$all['source_watermark']??null,'refreshed_at'=>$all['refreshed_at']??null,'last_error'=>$all['last_error']??null],
 ['key'=>'achievement_watermark','pass'=>$achMatch&&trim((string)($ach['last_error']??''))==='','current'=>$currentAchievementWatermark,'materialized'=>$ach['source_watermark']??null,'refreshed_at'=>$ach['refreshed_at']??null,'last_error'=>$ach['last_error']??null],
 ['key'=>'team_insights','pass'=>(int)($insight['rows_count']??0)>0,'rows'=>(int)($insight['rows_count']??0),'latest_activity_date'=>$insight['latest_activity_date']??null,'latest_computed_at'=>$insight['latest_computed_at']??null],
 ['key'=>'opponent_insights','pass'=>(int)($opponents['rows_count']??0)>0,'rows'=>(int)($opponents['rows_count']??0),'latest_updated_at'=>$opponents['latest_updated_at']??null],
];
$failed=count(array_filter($checks,static fn(array $c):bool=>empty($c['pass'])));
$out=['ok'=>$failed===0,'version'=>'2.15.1','club_slug'=>$club,'generated_at'=>gmdate(DATE_ATOM),'failed_checks'=>$failed,'green_state'=>$greenState,'checks'=>$checks];
fwrite(STDOUT,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
exit($failed===0?0:1);
