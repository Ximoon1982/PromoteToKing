<?php
declare(strict_types=1);

require __DIR__.'/../server/tournaments/src/TournamentService.php';

use P2K\Tournaments\TournamentService;

if(TournamentService::profileClosureReason(['status'=>'closed','reason'=>'Fair Play'])!=='fair_play') throw new RuntimeException('fair play reason not detected');
if(TournamentService::profileClosureReason(['status'=>'closed','reason'=>'abuse'])!=='') throw new RuntimeException('non-fair-play closure incorrectly classified');
if(TournamentService::profileClosureReason(['status'=>'active'])!=='') throw new RuntimeException('active profile incorrectly classified');

$service=file_get_contents(__DIR__.'/../server/tournaments/src/TournamentService.php');
$browse=file_get_contents(__DIR__.'/../server/tournaments/src/BrowseIndex.php');
foreach(["'fair_play'","fairPlayCursor","auditFinishedTournamentFairPlay","cron_fair_play_batch_size"] as $needle)if(!str_contains($service,$needle))throw new RuntimeException('missing tournament fair play integration: '.$needle);
if(!str_contains($browse,"Hall of Fame tournament podiums")||!str_contains($browse,"!isset(\$excluded[\$name])"))throw new RuntimeException('Hall of Fame podium filtering missing');

echo "PASS tournament Hall of Fame Fair Play exclusion\n";
