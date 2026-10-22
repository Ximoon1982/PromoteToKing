<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$must=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};

$worker=$read('server/team-points/bin/maintenance.php');
$must(str_contains($worker,"PHP_SAPI !== 'cli'"),'Maintenance worker must reject CGI SAPIs.');
$must(str_contains($worker,"p2k_tp_maintenance_"),'Maintenance worker must serialize itself with a dedicated advisory lock.');
$must(str_contains($worker,'new FairPlayPriorityRunner'),'Maintenance worker must preserve the Fair Play priority slice.');
$must(str_contains($worker,"['club','player']")&&str_contains($worker,"['player','club']"),'Maintenance worker must alternate Club/Player first priority.');
$must(str_contains($worker,'new CronMaintenanceCoordinator'),'Maintenance worker must run the existing bounded maintenance coordinator.');
$must(str_contains($worker,'PublicReadDatabase::core()')&&str_contains($worker,'PublicReadDatabase::analytics()'),'Maintenance worker must write the Green production database pair.');
$must(!str_contains($worker,'Database::core()')&&!str_contains($worker,'Database::analytics()'),'Maintenance worker must never target the legacy/Blue database abstraction.');
$must(!str_contains($worker,'upgradeExistingSchema'),'Maintenance worker must never perform schema migration inside its bounded runtime.');
$must(str_contains($worker,"getenv('P2K_MAINT_TRACE')==='1'"),'Maintenance worker must support opt-in stage tracing for shared-host diagnostics.');
$must(str_contains($worker,'SET SESSION max_statement_time'),'Maintenance worker must install an early database statement budget.');
$priority=$read('server/team-points/src/FairPlayPriorityRunner.php');
$must(str_contains($priority,'$this->api->setDeadlineAt($deadline);'),'Fair Play priority Chess API calls must inherit the maintenance deadline.');
$must(!str_contains($worker,'new Worker('),'Maintenance worker must not run legacy Blue acquisition.');
$must(!str_contains($worker,'new CronLoop('),'Maintenance worker must not run the legacy Blue queue loop.');

$coord=$read('server/team-points/src/CronMaintenanceCoordinator.php');
$must(substr_count($coord,'$api->setDeadlineAt($deadline);')>=2,'Coordinator Chess API users must inherit their slice deadlines.');
$must(str_contains($coord,"'classes']['analytics']=['ran'=>false,'reason'=>'dedicated_convergence_owner'"),'Club maintenance must leave mature all-analytics to dedicated convergence.');
$must(str_contains($coord,"runClass('intelligence'"),'Club intelligence maintenance must remain.');
$must(str_contains($coord,"runClass('fair_play'"),'Ongoing Fair Play maintenance must remain.');
$must(str_contains($coord,"runClass('pir'"),'Point integrity maintenance must remain.');
$must(str_contains($coord,"runClass('achievements'"),'Player achievement maintenance must remain.');
$must(str_contains($coord,"runClass('mira'"),'MIRA maintenance must remain.');
$must(str_contains($coord,"runClass('housekeeping'"),'Housekeeping maintenance must remain.');
$must(str_contains($coord,"runClass('storage'"),'Storage metrics maintenance must remain.');

$convergence=$read('server/team-points/bin/analytics-convergence.php');
$must(str_contains($convergence,'PublicReadDatabase::core()')&&str_contains($convergence,'PublicReadDatabase::analytics()'),'Analytics convergence must target Green production databases.');
$must(!str_contains($convergence,'Database::core()')&&!str_contains($convergence,'Database::analytics()'),'Analytics convergence must never target the legacy/Blue database abstraction.');
$installer=$read('server/team-points/bin/install-analytics-convergence-cron.sh');
$must(str_contains($installer,'team-points-analytics-active.sh'),'Analytics CRON must use an active-slot resolver.');
$must(str_contains($installer,'runtime-trees'),'Analytics resolver must follow Release Control public runtime trees.');
$must(str_contains($installer,'public_release'),'Analytics resolver must follow the promoted public release.');
$must(str_contains($installer,'P2K_TP_CONFIG'),'Analytics resolver must preserve the protected Team Points config path.');

$cutover=$read('server/team-points/bin/cutover-blue-maintenance-cron.sh');
$must(str_contains($cutover,'3-59/5 * * * *'),'Replacement maintenance must run every five minutes.');
$must(str_contains($cutover,'team-points-maintenance-active.sh'),'Cutover must install a stable active-slot resolver.');
$must(str_contains($cutover,'runtime-trees'),'Active-slot resolver must follow the current public runtime tree.');
$must(str_contains($cutover,'public_release'),'Active-slot resolver must resolve the public release dynamically.');
$must(str_contains($cutover,'P2K_TP_CONFIG'),'Active-slot resolver must provide the protected shared Team Points config explicitly.');
$must(str_contains($cutover,'cron-dispatch-v2\\.[0-9.]+\\.sh[[:space:]]+club'),'Cutover must remove only the legacy Club dispatcher line.');
$must(str_contains($cutover,'cron-dispatch-v2\\.[0-9.]+\\.sh[[:space:]]+player'),'Cutover must remove only the legacy Player dispatcher line.');
$must(str_contains($cutover,'tournaments'),'Cutover verification must preserve tournaments.');
$must(str_contains($cutover,'match-tracking'),'Cutover verification must preserve match tracking.');
$must(str_contains($cutover,'install-analytics-convergence-cron.sh'),'Cutover must install/verify the dedicated Green Analytics convergence worker.');
$must(str_contains($cutover,'team-points-analytics-active.sh'),'Cutover must verify the active-slot Analytics resolver.');
$must(str_contains($cutover,'restoring previous crontab'),'Cutover must rollback automatically on verification failure.');
$must(str_contains($cutover,'crontab-before-p2k-maintenance-'),'Cutover must save the pre-change crontab.');

$repo=$read('server/team-points/src/Repository.php');
$healthStart=strpos($repo,'public function publicInsightsHealth');
$healthEnd=strpos($repo,'public function opponentAdminRows',$healthStart);
$health=substr($repo,$healthStart,$healthEnd-$healthStart);
$must(str_contains($health,'sourceWatermark($clubSlug)'),'Insights health must compare the current production source watermark.');
$must(str_contains($health,"'status'=>$analyticsStatus"),'Insights health must expose stale/error Analytics status.');
$must(str_contains($health,"'watermark_matches'=>$allMatches"),'Insights health must expose watermark convergence.');
$must(str_contains($health,"'certified'=>"),'Insights health must expose an overall certification state.');
$must(str_contains($health,'last_roster_fetch')&&str_contains($health,'last_index_fetch'),'Insights health must check Green roster and club-index freshness.');

echo "PASS v2.15.1 post-Blue production routing and freshness contract\n";
