<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\AcamrClaimStore;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\RuntimeTelemetry;
use P2K\Shared\FilesystemCache;

/**
 * ACAMR work planner.
 *
 * This endpoint never accepts or writes canonical Chess.com facts. It only hands
 * a same-origin authenticated browser a small current-member refresh claim. The
 * browser later feeds raw observations to observe.php, where normal server-side
 * verification and Team Points workers remain authoritative.
 */
try {
    Http::method('POST');
    Auth::enforceSameOrigin();
    $body=Http::body();
    $actor=trim((string)($body['actor']??''));
    $mode=strtolower(trim((string)($body['mode']??'')));
    $cursor=max(0,(int)($body['cursor']??0));
    $clientId=trim((string)($body['client_id']??''));
    $sessionId=trim((string)($body['session_id']??''));
    $resultReport=is_array($body['result_report']??null)?$body['result_report']:null;
    if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$actor))throw new ApiException('A valid authenticated actor is required.',400,'INVALID_ACTOR');
    if(!in_array($mode,['simulated','oauth'],true))throw new ApiException('Unsupported authentication mode.',400,'INVALID_AUTH_MODE');
    $opaqueId=static fn(string $value):bool=>$value===''||preg_match('/^[A-Za-z0-9._:-]{8,160}$/',$value)===1;
    if(!$opaqueId($clientId)||!$opaqueId($sessionId))throw new ApiException('Invalid ACAMR telemetry identity.',400,'INVALID_TELEMETRY_ID');

    $config=p2k_tp_config();
    $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    // v2.10.6: the legacy ACAMR planner is retired from normal operation once
    // Green owns browser ingest. This prevents stale tabs from spending API budget
    // on Blue-only work after Green primary; Green Accelerator owns that lane.
    try {
        $greenConfigPath=dirname(__DIR__,2).'/team-points-green/src/GreenConfig.php';
        if(is_file($greenConfigPath)){
            require_once $greenConfigPath;
            $gcore=\P2K\Green\GreenConfig::core();
            $gq=$gcore->prepare('SELECT client_ingest_target,migration_phase FROM p2k_g_state WHERE club_slug=? LIMIT 1');
            $gq->execute([\P2K\Green\GreenConfig::clubSlug()]);$gs=$gq->fetch(PDO::FETCH_ASSOC)?:[];
            if((string)($gs['client_ingest_target']??'')==='green'){
                Http::json(['ok'=>true,'disabled'=>true,'reason'=>'green_primary_uses_green_accelerator','claims'=>[],'next_cursor'=>$cursor,'pulse_ms'=>60000,'migration_phase'=>(string)($gs['migration_phase']??'green_primary')]);
            }
        }
    } catch (Throwable $greenGuardFailure) {
        error_log('P2K ACAMR Green-cutover guard: '.$greenGuardFailure->getMessage());
    }
    $app=is_array($config['app']??null)?$config['app']:[];
    $claimTtl=max(300,min(3600,(int)($app['acamr_claim_ttl_seconds']??1200)));
    $basePulseMs=max(10000,min(120000,(int)($app['acamr_pulse_ms']??20000)));
    $baseScanBatch=max(12,min(200,(int)($app['acamr_scan_batch_size']??120)));
    $baseClaims=max(1,min(6,(int)($app['acamr_claims_per_pulse']??3)));
    // Real OAuth has its own adaptive server-side Bearer transport. Do not starve
    // that transport with the low-pulse anonymous ACAMR planner settings.
    $pulseMs=$mode==='oauth' ? max(1500,min($basePulseMs,(int)($app['acamr_oauth_pulse_ms']??5000))) : $basePulseMs;
    $claimsPerPulse=$mode==='oauth' ? max($baseClaims,min(96,(int)($app['acamr_oauth_claims_per_pulse']??48))) : $baseClaims;
    $scanBatch=$mode==='oauth' ? max($baseScanBatch,min(1200,(int)($app['acamr_oauth_scan_batch_size']??600))) : $baseScanBatch;

    $repo=new Repository(Database::core(),Database::analytics());
    if(!$repo->schemaInstalled())throw new ApiException('Team Points schema must be upgraded before ACAMR can run.',503,'SCHEMA_NOT_INSTALLED');

    $storage=is_array($config['storage']??null)?$config['storage']:[];
    $claimStore=new AcamrClaimStore($storage);
    // Producer-side cleanup is deliberately small and bounded; six-hour
    // housekeeping performs the full legacy drain.
    $claimStore->cleanup(100,100,max(3600,$claimTtl*4),8);
    $runtime=$claimStore->root();

    $claimMember=static fn(array $row):bool=>$claimStore->claimMember($row,$claimTtl,$actor,$mode);

    // Fair adaptive allocation: walk the roster from the browser cursor instead of
    // repeatedly querying the same global top-N cohort. Each selected member only
    // receives the tasks that are actually due.
    $selected=[];$nextCursor=$cursor;$claimConflicts=0;
    $rows=$repo->acamrCandidateMembers($club,$cursor,max($scanBatch,$claimsPerPulse*12));
    if($rows===[] && $cursor>0){$nextCursor=0;$rows=$repo->acamrCandidateMembers($club,0,max($scanBatch,$claimsPerPulse*12));}
    foreach($rows as $row){
        $nextCursor=max($nextCursor,(int)($row['member_id']??0));
        if(!$claimMember($row)){$claimConflicts++;continue;}
        $selected[]=$row;if(count($selected)>=$claimsPerPulse)break;
    }

    $rosterDue=false;
    $rosterPath=$runtime.'/roster-claim.json';
    $handle=@fopen($rosterPath,'c+');
    if($handle){
        try{
            if(flock($handle,LOCK_EX)){
                rewind($handle);$raw=stream_get_contents($handle);$previous=is_string($raw)&&$raw!==''?json_decode($raw,true):null;
                $last=is_array($previous)?(int)($previous['claimed_at']??0):0;
                if($last<=0||time()-$last>=1800){$rosterDue=true;ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode(['actor'=>$actor,'mode'=>$mode,'claimed_at'=>time()]));fflush($handle);}
            }
        } finally {@flock($handle,LOCK_UN);@fclose($handle);}
    }

    $issueClaimToken=static fn(?string $username,array $tasks):string=>$claimStore->issue($username,$tasks,$claimTtl,$actor,$mode);

    $claims=[];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$month=$now->format('Y/m');
    // Strong archive backpressure: exact canonical board repair belongs to the
    // server queue. ACAMR shares the same fixed-window archive budget as Continuous Refresh.
    $claimArchiveSlot=static fn():bool=>$claimStore->claimArchiveSlot(600,12);
    foreach($selected as $index=>$row){
        $username=(string)$row['username'];$encoded=rawurlencode($username);$tasks=[];
        if(!empty($row['matches_due']))$tasks[]=['kind'=>'matches','url'=>"https://api.chess.com/pub/player/{$encoded}/matches"];
        if(!empty($row['stats_due']))$tasks[]=['kind'=>'stats','url'=>"https://api.chess.com/pub/player/{$encoded}/stats"];
        if($tasks===[] && ((int)($row['incomplete_boards']??0)>0 || (int)($row['in_progress_boards']??0)>0) && $claimArchiveSlot()){
            $tasks[]=['kind'=>'archive','url'=>"https://api.chess.com/pub/player/{$encoded}/games/{$month}"];
        }
        if($rosterDue && $index===0){$tasks[]=['kind'=>'roster','url'=>'https://api.chess.com/pub/club/'.rawurlencode($club).'/members'];$rosterDue=false;}
        if($tasks===[])continue;
        $claims[]=['username'=>$username,'next_cursor'=>$nextCursor,'tasks'=>$tasks,'claim_token'=>$issueClaimToken($username,$tasks),'claimed_for_seconds'=>$claimTtl,'priority_score'=>(int)($row['priority_score']??0),'due'=>['matches'=>(bool)($row['matches_due']??false),'stats'=>(bool)($row['stats_due']??false),'incomplete_boards'=>(int)($row['incomplete_boards']??0)]];
    }
    if($rosterDue){$rosterTasks=[['kind'=>'roster','url'=>'https://api.chess.com/pub/club/'.rawurlencode($club).'/members']];$claims[]=['username'=>null,'next_cursor'=>$nextCursor,'tasks'=>$rosterTasks,'claim_token'=>$issueClaimToken(null,$rosterTasks),'claimed_for_seconds'=>$claimTtl,'priority_score'=>0,'due'=>['roster'=>true]];}
    $claim=$claims[0]??null;

    $taskKinds=['matches'=>0,'stats'=>0,'archive'=>0,'roster'=>0];
    foreach($claims as $c)foreach(is_array($c['tasks']??null)?$c['tasks']:[] as $task){$kind=strtolower((string)($task['kind']??''));if(array_key_exists($kind,$taskKinds))$taskKinds[$kind]++;}
    $memberHashes=[];foreach($claims as $c){$username=trim((string)($c['username']??''));if($username!=='')$memberHashes[]=substr(hash('sha256',strtolower($username)),0,16);}
    $telemetryReport=null;
    if(is_array($resultReport)){
        $reportId=trim((string)($resultReport['id']??''));$classes=is_array($resultReport['work_classes']??null)?$resultReport['work_classes']:[];
        if($opaqueId($reportId)&&$reportId!==''){
            $clean=[];foreach(['matches','stats','archive','roster'] as $kind){$row=is_array($classes[$kind]??null)?$classes[$kind]:[];$clean[$kind]=['fetched_ok'=>max(0,min(1000,(int)($row['fetched_ok']??0))),'fetch_failed'=>max(0,min(1000,(int)($row['fetch_failed']??0)))];}
            $telemetryReport=['id_hash'=>substr(hash('sha256',$reportId),0,24),'work_classes'=>$clean];
        }
    }
    RuntimeTelemetry::record('acamr_plan',[
        'actor_hash'=>substr(hash('sha256',strtolower($actor)),0,16),
        'client_hash'=>$clientId!==''?substr(hash('sha256',$clientId),0,24):'',
        'session_hash'=>$sessionId!==''?substr(hash('sha256',$sessionId),0,24):'',
        'member_hash'=>($memberHashes[0]??''),
        'member_hashes'=>$memberHashes,
        'mode'=>$mode,'claimed'=>count($claims)>0,'claims'=>count($claims),'claim_conflicts'=>$claimConflicts,
        'tasks'=>array_sum($taskKinds),'task_kinds'=>$taskKinds,'result_report'=>$telemetryReport,
        'priority_score'=>(int)($claim['priority_score']??0),'cursor'=>$nextCursor
    ]);

    Http::json([
        'ok'=>true,
        'claim'=>$claim,
        'claims'=>$claims,
        'next_cursor'=>$nextCursor,
        'policy'=>[
            'pulse_ms'=>$pulseMs,
            'claim_ttl_seconds'=>$claimTtl,
            'server_verification_required'=>true,
            'server_verification_required_for_canonical_facts'=>true,
            'claim_bound_observation_freshness'=>true,
            'club_points'=>true,
            'member_points'=>true,
            'tournaments'=>false,
            'match_registration'=>false,
        ],
    ]);
} catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch(Throwable $e){
    error_log('P2K ACAMR planner: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'ACAMR planning is temporarily unavailable.']],500);
}
