<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Team Points maintenance requires PHP CLI.\n");
    exit(2);
}

require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\CronMaintenanceCoordinator;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\FairPlayPriorityRunner;
use P2K\TeamPoints\Repository;

$seconds=max(25,min(50,(int)($argv[1]??45)));
$started=microtime(true);
$hardDeadline=$started+$seconds;
$core=PublicReadDatabase::core();
$analytics=PublicReadDatabase::analytics();
$repository=new Repository($core,$analytics);
$trace=getenv('P2K_MAINT_TRACE')==='1';
$traceStage=static function(string $stage) use($trace,$started):void{
    if($trace)fwrite(STDERR,sprintf("[maintenance +%.3fs] %s\n",microtime(true)-$started,$stage));
};
$traceStage('database-connected');
$statementBudget=max(1.0,$hardDeadline-microtime(true)-2.0);
foreach([$core,$analytics] as $pdo){
    try{$pdo->exec('SET SESSION max_statement_time='.number_format(min(20.0,$statementBudget),3,'.',''));}catch(Throwable){}
}
if(!$repository->schemaInstalled())throw new RuntimeException('Team Points baseline schema is not ready.');
$traceStage('baseline-schema-ready');

$config=p2k_tp_config();
$club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
$lock='p2k_tp_maintenance_'.substr(preg_replace('/[^a-z0-9_-]+/i','-',$club)?:'club',0,48);
$q=$core->prepare('SELECT GET_LOCK(?,0)');
$q->execute([$lock]);
if((int)$q->fetchColumn()!==1){
    fwrite(STDOUT,json_encode(['ok'=>true,'busy'=>true,'version'=>'2.15.0'],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
}

$out=[
    'ok'=>true,
    'version'=>'2.15.1',
    'club_slug'=>$club,
    'database_route'=>'green-production',
    'core_database'=>(string)$core->query('SELECT DATABASE()')->fetchColumn(),
    'analytics_database'=>(string)$analytics->query('SELECT DATABASE()')->fetchColumn(),
    'fair_play_priority'=>null,
    'lane_order'=>[],
    'lanes'=>[],
];

try{
    // Preserve the current cron-club Fair Play fast path before the general
    // maintenance lanes. It checks newly-finished and active matches only;
    // historical backfill remains retired once complete.
    try{
        $traceStage('fair-play-priority-start');
        $priorityDeadline=min($hardDeadline-4.0,microtime(true)+5.0);
        $out['fair_play_priority']=(new FairPlayPriorityRunner(
            $core,
            $repository,
            new ChessApi($repository),
            $club
        ))->run($priorityDeadline,2);
        $traceStage('fair-play-priority-end');
    }catch(Throwable $e){
        $out['fair_play_priority']=['ran'=>false,'error'=>$e->getMessage()];
    }

    // Alternate which lane runs first each five-minute invocation. This prevents
    // Club or Player maintenance from being systematically starved if an earlier
    // class consumes most of the bounded shared-host runtime budget.
    $minute=(int)gmdate('i');
    $order=($minute%2===0)?['club','player']:['player','club'];
    $out['lane_order']=$order;

    foreach($order as $index=>$lane){
        $lanesLeft=count($order)-$index;
        $remaining=max(0.0,$hardDeadline-microtime(true)-2.0);
        if($remaining<6.0){
            $out['lanes'][$lane]=['ran'=>false,'deferred'=>true,'reason'=>'maintenance_budget_exhausted'];
            continue;
        }
        $slice=max(6.0,$remaining/$lanesLeft);
        $laneDeadline=min($hardDeadline-1.5,microtime(true)+$slice);
        $traceStage('lane-'.$lane.'-start');
        $out['lanes'][$lane]=(new CronMaintenanceCoordinator(
            $core,
            $analytics,
            $repository,
            $config,
            $lane,
            $laneDeadline
        ))->run();
        $traceStage('lane-'.$lane.'-end');
    }

    $traceStage('maintenance-complete');
    $out['elapsed_ms']=(int)round((microtime(true)-$started)*1000);
    $out['remaining_seconds']=max(0.0,round($hardDeadline-microtime(true),3));
    fwrite(STDOUT,json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
}finally{
    try{$r=$core->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lock]);}catch(Throwable){}
}
