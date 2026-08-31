<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=file_get_contents($root.'/'.$path);
    if($value===false)throw new RuntimeException('Unable to read '.$path);
    return $value;
};
$must=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$cron=$read('server/team-points/public/cron-club.php');
$priorityPos=strpos($cron,'new FairPlayPriorityRunner');
$greenPos=strpos($cron,"require __DIR__ . '/cron.php'");
$must($priorityPos!==false && $greenPos!==false && $priorityPos<$greenPos,'Fair Play priority must execute before the Green Club worker wrapper.');

$priority=$read('server/team-points/src/FairPlayPriorityRunner.php');
$must(str_contains($priority,"m.status='finished' AND f.finalized_at IS NULL"),'Priority runner must catch a newly-finished unfinalized match.');
$must(str_contains($priority,"m.status='in_progress'"),'Priority runner must check active matches.');
$must(!str_contains($priority,'cursor_match_id'),'Priority runner must not traverse historical backfill state.');

$backfill=$read('server/team-points/src/FairPlayBackfillRunner.php');
$must(str_contains($backfill,'SELECT GET_LOCK(?,0)'),'Backfill runner requires its own advisory lock.');
$must(str_contains($backfill,'$minimumIntervalMs = max(1000'),'Backfill runner must enforce at least one second between requests.');
$must(str_contains($backfill,"status='finished' AND match_id>?"),'Backfill runner must resume strictly after the durable cursor.');
$must(str_contains($backfill,'applyMatchPayload($id, $payload, true, true)'),'Historical processing must use canonical Fair Play reconciliation with backfill provenance.');
$must(str_contains($backfill,'cursor_match_id=GREATEST(cursor_match_id,?)'),'Backfill runner must durably advance the cursor after success.');

$cliWorker=$read('server/team-points/bin/fair-play-backfill.php');
$must(str_contains($cliWorker,"PHP_SAPI !== 'cli'"),'Historical worker must reject non-CLI PHP SAPIs.');
$cronInstaller=$read('server/team-points/bin/install-fair-play-backfill-cron.sh');
$must(str_contains($cronInstaller,'/usr/bin/php8.5-cli'),'IONOS PHP 8.5 CLI path must be considered.');
$must(str_contains($cronInstaller,"echo PHP_SAPI"),'CRON installer must verify that the selected PHP executable is CLI.');
$must(!str_contains($cronInstaller,'command -v php'),'CRON installer must never fall back to the legacy bare php command on IONOS.');

$endpoint=$read('server/team-points/public/fair-play-maintenance.php');
$must(str_contains($endpoint,"\$action==='process-match'"),'Authenticated targeted process-match action is missing.');
$must(str_contains($endpoint,"\$body['match_id']"),'Targeted process-match must accept match_id.');

$must(trim($read('VERSION'))==='2.10.9.7','VERSION not propagated to 2.10.9.7.');
$must(trim($read('MIGRATION_VERSION'))==='2.10.9.7','MIGRATION_VERSION not propagated to 2.10.9.7.');
$site=$read('assets/js/site-config.js');
$must(str_contains($site,'version: "2.10.9.7"'),'Runtime site-config version not propagated.');
$must(str_contains($site,'v=2.10.9.7-members-ranking-2'),'Members enhancement cache key not propagated.');
$manifest=json_decode($read('site-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$must(($manifest['version']??'')==='2.10.9.7','site-manifest version not propagated.');

echo "PASS v2.10.9.7 Fair Play scheduling, IONOS CLI safety, targeted repair and version propagation contract\n";
