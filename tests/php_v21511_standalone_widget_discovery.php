<?php
declare(strict_types=1);

require_once __DIR__.'/../TGP/Widget/src/Store.php';
require_once __DIR__.'/../TeamFrance/Widget/src/Store.php';
require_once __DIR__.'/../server/events-showcase/src/StandaloneArenaDiscoveryService.php';

use P2K\EventsShowcase\StandaloneArenaDiscoveryService;

function checkStandalone(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectStandalone(callable $fn, string $message): void {
    try {$fn();} catch (InvalidArgumentException|RuntimeException $e) {return;}
    throw new RuntimeException($message);
}
function rmStandalone(string $dir): void {
    if (!is_dir($dir))return;
    foreach (new FilesystemIterator($dir) as $item) {
        if ($item->isDir() && !$item->isLink())rmStandalone($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($dir);
}
$root=sys_get_temp_dir().'/p2k-standalone-mca-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try {
    $a=$root.'/TGP/Widget';
    $b=$root.'/TeamFrance/Widget';
    mkdir($a.'/data',0770,true);
    mkdir($b.'/data',0770,true);
    $aService=new StandaloneArenaDiscoveryService($a,$root);
    $bService=new StandaloneArenaDiscoveryService($b,$root);
    checkStandalone($aService->read()['revision']===0 && $bService->read()['revision']===0,
        'Each standalone discovery starts with an independent empty config');
    rejectStandalone(fn()=>$aService->begin(),'Empty organizer list must reject scan');
    $saved=$aService->saveOrganizers([['clubId'=>'12345','name'=>'Example organizer','enabled'=>true]],0);
    checkStandalone($saved['revision']===1 && count($saved['organizers'])===1,'TGP watchlist stored');
    checkStandalone($bService->read()['organizers']===[],'Team France must not inherit TGP watchlist');
    $savedB=$bService->saveOrganizers([['clubId'=>'67890','name'=>'France organizer','enabled'=>true]],0);
    checkStandalone($savedB['revision']===1,'Team France independent watchlist stored');
    rejectStandalone(fn()=>$aService->saveOrganizers([],0),'Concurrent organizer revision must be refused');
    rejectStandalone(fn()=>$aService->saveOrganizers([
        ['clubId'=>'12345','name'=>'Club A'],
        ['clubId'=>'12345','name'=>'Club B']
    ],1),'Duplicate organizer ID must be refused');
    rejectStandalone(fn()=>$aService->saveOrganizers([
        ['clubId'=>'https://chess.com/club/12345','name'=>'Bad ID']
    ],1),'Invalid organizer ID must be refused');
    $s=$aService->begin()['scan'];
    checkStandalone($s['status']==='running' && count($s['results'])===0,'On demand scan started');
    checkStandalone($s['cutoffEpoch']-strtotime($s['startedAt'])===7*86400,'Seven day horizon');
    checkStandalone(StandaloneArenaDiscoveryService::MAX_PAGES===20,'Page safety cap');
    checkStandalone($bService->read()['scan']===null,'TGP scan must not alter Team France scan');
    $cancelled=$aService->cancel($s['id']);
    checkStandalone($cancelled['scan']['status']==='cancelled','Explicit cancellation persisted');
    $fresh=$aService->begin();
    checkStandalone($fresh['scan']['id']!==$s['id'],'A new scan replaces cancelled scan');
    $aService->saveOrganizers([['clubId'=>'12345','name'=>'Example organizer','enabled'=>false]],1);
    checkStandalone($aService->read()['scan']===null,'Watchlist change invalidates scan results');
    rejectStandalone(fn()=>$aService->begin(),'All disabled organizers must reject scan');
    checkStandalone($bService->read()['organizers'][0]['clubId']==='67890',
        'Team France remains independent after TGP watchlist changes');

    foreach ([
        ['TGP\Widget\Store',$a],
        ['TeamFrance\Widget\Store',$b]
    ] as [$class,$dir]) {
        $store=new $class($dir,100);
        $arena=[
          'name'=>'Future Blitz','link'=>'https://www.chess.com/play/arena/31191474',
          'date'=>'2026-11-26','time'=>'19:00','duration'=>'2h','timeControl'=>'3+2',
          'active'=>true,'urgent'=>true,
        ];
        $saved=$store->save([
            'items'=>[
                ['matchId'=>'1000001','enabled'=>true,'urgent'=>true],
                ['matchId'=>'1000002','enabled'=>false,'urgent'=>false],
                ['matchId'=>'1000003','enabled'=>true,'urgent'=>false]
            ],
            'arenas'=>[$arena],
            'layout'=>['arenaRows'=>2,'dailyRows'=>3]
        ],0);
        checkStandalone($saved['revision']===1 && count($saved['items'])===3,
            "$class initial configured Daily items");
        checkStandalone(count($store->read()['arenas'])===1,"$class arena retained");
        $unchanged=$store->pruneStartedMatches(['9999999','not-an-id']);
        checkStandalone($unchanged['revision']===1 && count($unchanged['items'])===3,
            "$class unconfirmed/missing Daily IDs must not remove anything");
        $clean=$store->pruneStartedMatches(['1000001','1000002','1000001']);
        checkStandalone($clean['revision']===2 && count($clean['items'])===1,
            "$class only positively identified Daily matches removed");
        checkStandalone($clean['items'][0]['matchId']==='1000003',"$class remaining Daily preserved");
        checkStandalone($clean['arenas']===$saved['arenas'] && $clean['layout']===$saved['layout'],
            "$class MCA rows and arena configuration unchanged by Daily pruning");
        checkStandalone($store->pruneStartedMatches(['1000002'])['revision']===2,
            "$class repeat cleanup must not change revision");
        rejectStandalone(fn()=>$store->save(['items'=>[['matchId'=>'1000001']]],1),
            "$class stale administrator revision must reject overwriting auto cleanup");
    }

    foreach (['TGP','TeamFrance'] as $club) {
        $api=file_get_contents(__DIR__.'/../'.$club.'/Widget/api.php');
        checkStandalone(is_string($api),'API available for '.$club);
        foreach (['confirmed_started_daily_ids($index)','pruneStartedMatches($started)',
                  'Auth::requireAdmin($sessionLifetime, true)',
                  "arena-discovery-state","arena-discovery-page","arena-discovery-save",
                  "arena-discovery-begin","arena-discovery-cancel",
                  "is_started_daily_status("] as $marker)
            checkStandalone(str_contains($api,$marker),$club.' missing '.$marker);
        checkStandalone(str_contains($api,'catch (ChessApiException $e)'),
            $club.' must never prune on Chess.com index outage');
        checkStandalone(str_contains($api,"['in_progress','finished']"),
            $club.' must only prune known started/finished buckets');
        checkStandalone(str_contains($api,'isset($registered[$id])'),
            $club.' must preserve matches when Chess.com buckets conflict');
    }
    echo "PASS independent organizer watchlists/scans, seven-day and page limits, "
        ."no unsolicited Daily removal, atomic started-Daily prune with revision protection, "
        ."preserved arena and row layouts, admin-only discovery endpoints.\n";
} finally { rmStandalone($root); }
