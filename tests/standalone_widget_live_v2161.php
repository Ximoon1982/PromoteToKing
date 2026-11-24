<?php
declare(strict_types=1);
/**
 * Standalone TGP and Team France Live showcase regression, v0.3.5.
 * Uses temporary JSON stores only. No production paths, DB or network.
 */
require_once dirname(__DIR__).'/TeamFrance/Widget/src/Store.php';
require_once dirname(__DIR__).'/TGP/Widget/src/Store.php';

function liveAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function liveRemove(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) liveRemove($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($dir);
}
$root = sys_get_temp_dir().'/p2k-live-widget-v035-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try {
    foreach (['TeamFrance'=>'TeamFrance\\Widget\\Store','TGP'=>'TGP\\Widget\\Store'] as $club=>$storeClass) {
        $folder=$root.'/'.$club;
        mkdir($folder.'/data',0700,true);
        $store=new $storeClass($folder,100);
        $empty=$store->read();
        liveAssert($empty['liveItems']===[] && $empty['items']===[], $club.': empty lists');
        liveAssert($empty['layout']['liveRows']===1, $club.': one default Live row');
        liveAssert($empty['layout']['dailyRows']===2, $club.': Daily defaults unchanged');
        // Upgrade a real v3-like JSON document without discarding existing Daily.
        file_put_contents($folder.'/data/showcase.json',json_encode([
            'schemaVersion'=>3,'revision'=>9,'updatedAt'=>null,
            'items'=>[['matchId'=>'100','enabled'=>true,'urgent'=>true]],
            'arenas'=>[],'layout'=>['arenaRows'=>2,'dailyRows'=>2],
        ],JSON_THROW_ON_ERROR));
        $old=$store->read();
        liveAssert($old['schemaVersion']>=4 && $old['revision']===9,$club.': compatible v3 migration');
        liveAssert(count($old['items'])===1 && $old['liveItems']===[],$club.': keep original Daily');
        liveAssert($old['layout']['arenaRows']===2,$club.': keep original arena rows');
        $saved=$store->save([
            'items'=>[['matchId'=>'100','enabled'=>true,'urgent'=>true]],
            'liveItems'=>[
                ['matchId'=>'200','enabled'=>true,'urgent'=>true],
                ['matchId'=>'201','enabled'=>false,'urgent'=>false],
            ],
            'layout'=>['arenaRows'=>2,'dailyRows'=>3,'liveRows'=>2],
            'arenas'=>[],
        ],9);
        liveAssert($saved['revision']===10,$club.': atomic revision increment');
        liveAssert(count($saved['liveItems'])===2 && $saved['layout']['liveRows']===2,$club.': Live saved');
        // Existing arena-only callers do not send liveItems. They must preserve
        // saved Live matches, even when separate editors are open.
        $saved=$store->save(['items'=>$saved['items'],'arenas'=>[],
            'layout'=>['arenaRows'=>1,'dailyRows'=>1,'liveRows'=>2]],10);
        liveAssert($saved['revision']===11 && count($saved['liveItems'])===2,
            $club.': arena-only saves preserve Live items');
        $saved=$store->pruneStartedMatches(['100'],['200']);
        liveAssert($saved['revision']===12,$club.': one atomic cleanup revision');
        liveAssert($saved['items']===[] && count($saved['liveItems'])===1
            && $saved['liveItems'][0]['matchId']==='201',$club.': independent cleanup');
        $repeat=$store->pruneStartedMatches(['100'],['200']);
        liveAssert($repeat['revision']===12,$club.': cleanup is idempotent');
        $saved=$store->save(['items'=>[],'liveItems'=>$repeat['liveItems'],
            'layout'=>['arenaRows'=>9,'dailyRows'=>8,'liveRows'=>9]],12);
        liveAssert($saved['layout']===['arenaRows'=>2,'liveRows'=>2,'dailyRows'=>3],
            $club.': independently clamped row limits');
        try {
            $store->save(['items'=>[]],12);
            throw new RuntimeException($club.': stale revision was accepted');
        } catch (\RuntimeException $e) {
            liveAssert(str_contains($e->getMessage(),'configuration changed'),$club.': revision conflict');
        }
        $api=file_get_contents(dirname(__DIR__).'/'.$club.'/Widget/api.php');
        liveAssert(str_contains($api,"timeClass=live")===false,$club.': API is not hard-coded to Live');
        foreach (["'liveCards'=>\$liveCards","'liveItems'=>\$liveItems",
                  "confirmed_started_daily_ids(\$index, 'live')",
                  'A match cannot be configured as both Live and Daily',
                  "'timeClass'=>\$timeClass"] as $needle) {
            liveAssert(str_contains($api,$needle),$club.': missing API protection '.$needle);
        }
    }
} finally {
    liveRemove($root);
}
echo "PASS: standalone Live widget state, migration, pruning, row limits and guards\n";
