<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);$must=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$coord=$read('server/team-points/src/CronMaintenanceCoordinator.php');
$must(str_contains($coord,"runClass('analytics',10.0,9.0"),'Analytics fallback slice must exceed AnalyticsBuilder 8-second start threshold.');
$cli=$read('server/team-points/bin/analytics-convergence.php');
$must(str_contains($cli,"PHP_SAPI !== 'cli'"),'Analytics convergence worker must reject CGI SAPIs.');
$must(str_contains($cli,'refreshIfDue($club,300,$deadline)'),'Analytics worker must invoke generation-aware convergence with a real deadline.');
$must(str_contains($cli,'SELECT GET_LOCK(?,0)'),'Analytics convergence must have an independent advisory lock.');
$cron=$read('server/team-points/bin/install-analytics-convergence-cron.sh');
$must(str_contains($cron,'/usr/bin/php8.5-cli'),'IONOS versioned PHP CLI must be considered.');
$must(!str_contains($cron,'command -v php'),'Installer must not fall back to bare php.');
$must(str_contains($cron,"* * * * * cd"),'Analytics convergence must run every minute.');
echo "PASS v2.10.9.7 R3 Analytics convergence scheduling contract\n";
