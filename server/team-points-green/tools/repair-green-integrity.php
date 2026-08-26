<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors','1');

require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\Green\GreenConfig;
use P2K\Green\GreenLegacyFacts;
use P2K\Green\GreenRepository;

function argValue(string $name,?string $default=null): ?string
{
    global $argv;$prefix='--'.$name.'=';
    foreach($argv as $arg)if(strpos($arg,$prefix)===0)return substr($arg,strlen($prefix));
    return $default;
}

function fetchJson(string $url,int $attempts=5): array
{
    $last=['http'=>0,'error'=>'','body'=>''];
    for($attempt=1;$attempt<=$attempts;$attempt++){
        if(function_exists('curl_init')){
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: PromoteToKing-Green-integrity-consolidation/2.10.4']]);
            $body=curl_exec($ch);$error=(string)curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        }else{
            $ctx=stream_context_create(['http'=>['timeout'=>35,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nUser-Agent: PromoteToKing-Green-integrity-consolidation/2.10.4\r\n"]]);
            $body=@file_get_contents($url,false,$ctx);$error=$body===false?'HTTP fetch failed':'';$http=0;
            foreach($http_response_header??[] as $h)if(preg_match('~^HTTP/\S+\s+(\d+)~',$h,$m))$http=(int)$m[1];
        }
        $last=['http'=>$http,'error'=>$error,'body'=>(string)$body];
        if($http===200&&is_string($body)){
            $json=json_decode($body,true);if(is_array($json))return ['http'=>200,'json'=>$json,'error'=>''];
        }
        if(!in_array($http,[429,500,502,503,504],true))break;
        sleep(min(8,$attempt*2));
    }
    return ['http'=>$last['http'],'json'=>null,'error'=>$last['error']];
}

function printLine(string $label,$value): void
{
    echo str_pad($label,44).' '.(is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))."\n";
}

$siteRoot=(string)(argValue('site-root',GreenConfig::siteRoot())??GreenConfig::siteRoot());
$file=(string)(argValue('file','')??'');
$refreshCap=max(0,min(100,(int)(argValue('refresh-current','24')??'24')));

$repo=GreenRepository::open();
$lock=(int)$repo->core->query("SELECT GET_LOCK('p2k_green_worker',10)")->fetchColumn();
if($lock!==1)throw new RuntimeException('Green worker is currently busy. Re-run after the current slice finishes.');

$original=$repo->state();
try{
    echo "P2K v2.10.4 CUMULATIVE GREEN INTEGRITY REPAIR\n";
    echo str_repeat('=',88)."\n";
    echo "READ/WRITE GREEN ONLY. Blue is not modified. Existing cycle/stage are preserved.\n\n";

    $repo->setControl(['worker_target'=>'paused','client_ingest_target'=>'off']);
    $repo->core->exec("DELETE FROM p2k_g_work_claims WHERE claimed_by LIKE 'browser-%'");

    echo "[1/6] Upgrading Green schema in place...\n";
    $repo->initializeSchemas();

    if($file===''||!is_file($file))$file=(string)(GreenLegacyFacts::locateTrustedFile($siteRoot)??'');
    if($file==='')throw new RuntimeException('Exact trusted FinishedMatches-P2K CSV was not found. Pass --file=/path/to/FinishedMatches-P2K.csv.');

    echo "[2/6] Importing and locking verified legacy finished-match facts...\n";
    $legacy=(new GreenLegacyFacts($repo))->import($file);
    printLine('Trusted source SHA-256',$legacy['sha256']);
    printLine('Trusted rows',$legacy['rows']);
    printLine('Trusted valid / void',$legacy['valid_finished'].' / '.$legacy['void']);
    printLine('Trusted Club Points',$legacy['points']);
    printLine('Trusted W/D/L',$legacy['wins'].' / '.$legacy['draws'].' / '.$legacy['losses']);
    printLine('Missing board skeletons created',$legacy['board_skeletons_created']);

    echo "\n[3/6] Refreshing exact current P2K club index...\n";
    $indexUrl='https://api.chess.com/pub/club/'.rawurlencode($repo->clubSlug).'/matches';
    $indexFetch=fetchJson($indexUrl);
    if((int)$indexFetch['http']!==200||!is_array($indexFetch['json']))throw new RuntimeException('Current P2K club index fetch failed HTTP '.(int)$indexFetch['http'].' '.$indexFetch['error']);
    $index=$repo->upsertIndex($indexFetch['json']);
    printLine('Current Daily index high ID',$index['highest']??0);

    echo "\n[4/6] Normalizing Daily-only discovery watermark...\n";
    $watermark=$repo->normalizeDailyDiscoveryWatermark();
    foreach($watermark as $k=>$v)printLine($k,$v);

    echo "\n[5/6] Rehydrating urgent current-index mismatches...\n";
    $refreshed=[];$failed=[];
    if($refreshCap>0){
        $sql="SELECT match_id,api_url,index_bucket,index_time_class,status,time_class FROM p2k_g_matches WHERE discovery_index=1 AND trusted_legacy=0 AND (retry_after IS NULL OR retry_after<=UTC_TIMESTAMP()) AND (club_verified=0 OR (index_bucket='registered' AND status='unknown') OR (index_bucket='finished' AND status IN ('registered','in_progress','unknown')) OR (index_bucket='in_progress' AND status IN ('registered','unknown')) OR (index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class)) ORDER BY CASE WHEN index_bucket='finished' AND status IN ('registered','in_progress','unknown') THEN 0 WHEN index_time_class IS NOT NULL AND index_time_class<>'' AND COALESCE(time_class,'')<>index_time_class THEN 1 WHEN club_verified=0 THEN 2 ELSE 3 END,match_id DESC LIMIT ".$refreshCap;
        $rows=$repo->core->query($sql)->fetchAll()?:[];
        foreach($rows as $r){
            $id=(int)$r['match_id'];$url=(string)($r['api_url']?:('https://api.chess.com/pub/match/'.$id));
            $f=fetchJson($url);
            if((int)$f['http']===200&&is_array($f['json'])){
                $stored=$repo->storeMatch($id,$f['json'],200);$refreshed[]=['match_id'=>$id,'status'=>$stored['status']??'','time_class'=>$stored['time_class']??'','points'=>$stored['points']??0];
            }else{
                $repo->markMatchHttp($id,(int)$f['http'],(string)$f['error']);$failed[]=['match_id'=>$id,'http'=>(int)$f['http']];
            }
            usleep(120000);
        }
    }
    printLine('Urgent current matches refreshed',count($refreshed));
    foreach($refreshed as $r)echo '  '.$r['match_id'].' -> '.$r['status'].' / '.($r['time_class']?:'(blank)').' / '.$r['points']." pts\n";
    if($failed){printLine('Urgent current fetch failures',count($failed));foreach($failed as $r)echo '  '.$r['match_id'].' -> HTTP '.$r['http']."\n";}
    $eligibility=$repo->backfillEligibilityFromCurrentFacts();
    printLine('Current eligible facts promoted',$eligibility['promoted']);
    printLine('Excluded/current facts zeroed',$eligibility['excluded_or_zeroed']);
    printLine('Excluded derived rows purged',$eligibility['derived_rows_purged']);

    echo "\n[6/6] Rebuilding Green analytics and running integrity checks...\n";
    $cycle=(int)($repo->state()['cycle_no']??0);$repo->rebuildAnalytics($cycle);
    $summary=$repo->greenSummary();$integrity=$summary['integrity']??[];$totals=$summary['totals']??[];
    $trusted=$repo->core->query("SELECT COUNT(*) rows_total,SUM(is_void=0) valid_finished,SUM(is_void=1) void_rows,COALESCE(SUM(CASE WHEN is_void=0 THEN competition_points ELSE 0 END),0) points FROM p2k_g_matches WHERE trusted_legacy=1")->fetch()?:[];
    $checks=[
        'trusted_rows'=>(int)($trusted['rows_total']??0)===GreenLegacyFacts::EXPECTED_ROWS,
        'trusted_valid'=>(int)($trusted['valid_finished']??0)===GreenLegacyFacts::EXPECTED_VALID_FINISHED,
        'trusted_void'=>(int)($trusted['void_rows']??0)===GreenLegacyFacts::EXPECTED_VOID,
        'trusted_points'=>(int)($trusted['points']??0)===GreenLegacyFacts::EXPECTED_POINTS,
        'no_non_daily_points'=>(int)($integrity['non_daily_point_rows']??0)===0,
        'no_unverified_points'=>(int)($integrity['unverified_point_rows']??0)===0,
        'no_ineligible_points'=>(int)($integrity['ineligible_point_rows']??0)===0,
        'no_excluded_member_events'=>(int)($integrity['excluded_point_events']??0)===0,
    ];
    printLine('Green Club Points now',$totals['club_points']??'n/a');
    printLine('Green known matches',$totals['known_matches']??'n/a');
    printLine('Trusted legacy locked rows',$integrity['trusted_legacy_rows']??0);
    foreach($checks as $name=>$ok)printLine('CHECK '.$name,$ok?'PASS':'FAIL');
    $all=!in_array(false,$checks,true);
    printLine('CONSOLIDATION INTEGRITY',$all?'PASS':'FAIL');
    if(!$all)throw new RuntimeException('Green consolidation integrity checks failed. Review output before resuming workers.');

    // Restore the user's routing choices. Cycle number, mode and stage were never reset.
    $repo->setControl([
        'worker_target'=>(string)($original['worker_target']??'blue'),
        'client_ingest_target'=>(string)($original['client_ingest_target']??'blue'),
        'force_mode'=>(string)($original['force_mode']??'auto'),
    ]);
    echo "\nRepair complete. Original Green routing restored; Cycle #".(int)($repo->state()['cycle_no']??0).' remains at stage '.(string)($repo->state()['stage']??'').".\n";
}catch(Throwable $e){
    try{$repo->setControl(['worker_target'=>'paused','client_ingest_target'=>'off']);}catch(Throwable $ignored){}
    fwrite(STDERR,"\nERROR: ".$e->getMessage()."\nGreen worker/client ingest remain PAUSED/OFF for safety.\n");
    exit(1);
}finally{
    try{$repo->core->query("SELECT RELEASE_LOCK('p2k_green_worker')");}catch(Throwable $ignored){}
}
