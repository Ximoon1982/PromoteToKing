<?php
declare(strict_types=1);
require_once __DIR__.'/../server/team-points/src/LeagueClassificationReconciler.php';
use P2K\TeamPoints\LeagueClassificationReconciler as L;
function verify(bool $condition,string $label): void {if(!$condition)throw new RuntimeException($label);}
foreach(['1WL R5','TCMAC Centurion S6','TMCL U1800 R1','KOTML S43 R5','PCL Finals','WKCL','CW - Match'] as $name)
    verify(L::isLeagueName($name),'league token '.$name);
foreach(['Friendly','ACW Team','NotTCMAC','No league match','TMCLeague','CW2026'] as $name)
    verify(!L::isLeagueName($name),'friendly token '.$name);
// Guard the critical property: only metadata league status and timestamps are written.
$source=(string)file_get_contents(__DIR__.'/../server/team-points/src/LeagueClassificationReconciler.php');
verify(str_contains($source,'SET is_league=?,updated_at=UTC_TIMESTAMP()'),'update-only flag');
verify(!str_contains($source,'DELETE FROM'),'no destructive writes');
verify(str_contains($source,'gm.club_verified=1'),'Green source scope');
$coord=(string)file_get_contents(__DIR__.'/../server/team-points/src/CronMaintenanceCoordinator.php');
verify(strpos($coord,"['pir']")<strpos($coord,"['league_flags']"),'PIR runs before repair');
$builder=(string)file_get_contents(__DIR__.'/../server/team-points/src/AnalyticsBuilder.php');
verify(str_contains($builder,'COALESCE(SUM(is_league),0)'),'league flag included in analytics watermark');
echo "PASS: league-token boundaries; metadata-only repair; maintenance order; analytics invalidation\n";
