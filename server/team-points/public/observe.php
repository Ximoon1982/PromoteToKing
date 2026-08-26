<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\AcamrClaimStore;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\ObservationIngestor;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\RuntimeTelemetry;
use P2K\Shared\BoundedRateLimiter;
use P2K\Shared\FilesystemCache;

try {
    Http::method('POST'); Auth::enforceSameOrigin();
    $length=(int)($_SERVER['CONTENT_LENGTH']??0); if($length>2097152) throw new ApiException('Observation batch is too large.',413,'OBSERVATION_TOO_LARGE');
    $body=Http::body(); $observations=is_array($body['observations']??null)?$body['observations']:[];
    if($observations===[] || count($observations)>48) throw new ApiException('Provide between 1 and 48 observations.',400,'INVALID_OBSERVATIONS');
    $config=p2k_tp_config(); $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));

    // v2.10.6 Green cutover guard. Once browser ingest ownership is Green,
    // stale ACAMR/client-refresh tabs must not continue mutating Blue. The
    // Green Accelerator is the browser background writer in Green-primary.
    try {
        $greenConfigPath=dirname(__DIR__,2).'/team-points-green/src/GreenConfig.php';
        if(is_file($greenConfigPath)){
            require_once $greenConfigPath;
            $gcore=\P2K\Green\GreenConfig::core();
            $q=$gcore->prepare('SELECT client_ingest_target,migration_phase FROM p2k_g_state WHERE club_slug=? LIMIT 1');
            $q->execute([\P2K\Green\GreenConfig::clubSlug()]);$greenState=$q->fetch(PDO::FETCH_ASSOC)?:[];
            if((string)($greenState['client_ingest_target']??'')==='green'){
                Http::json(['ok'=>true,'accepted'=>0,'updated'=>0,'queued'=>0,'disabled'=>true,'reason'=>'green_primary_uses_green_accelerator','migration_phase'=>(string)($greenState['migration_phase']??'green_primary')]);
            }
        }
    } catch (Throwable $greenGuardFailure) {
        // Before Green owns browser ingest, Blue remains the rollback-safe path.
        error_log('P2K legacy browser Green-cutover guard: '.$greenGuardFailure->getMessage());
    }

    // Fixed-window limiter stored in one protected state file. The pre-v2.9.14
    // design emitted one rate file per IP/minute and could exhaust inode quotas.
    $storage=is_array($config['storage']??null)?$config['storage']:[];
    $runtime=FilesystemCache::runtimeRoot($storage).'/browser-observations';
    $limiter=new BoundedRateLimiter($runtime.'/rate-state.json',2048,7200);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$rate=$limiter->consume($ip,300,60);
    if(!$rate['allowed'])Http::json(['ok'=>true,'accepted'=>0,'deferred'=>true,'reason'=>'rate_limited','retry_after_seconds'=>$rate['retry_after_seconds']]);

    $claimStore=new AcamrClaimStore($storage);$claimStore->cleanup(25,25,86400,8);
    $verifyClaim=static fn(string $token,string $url,string $kind):array=>$claimStore->verify($token,$url,$kind);

    $repo=new Repository(Database::core(),Database::analytics());
    if (!$repo->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    if(!$repo->schemaInstalled())throw new ApiException('Team Points database is unavailable.',503,'SCHEMA_NOT_INSTALLED');
    $ingestor=new ObservationIngestor($repo,$club); $results=[];$accepted=0;$queued=0;$updated=0;
    $acamr=['observations'=>0,'accepted'=>0,'rejected'=>0,'claim_verified'=>0,'claim_unverified'=>0,'updated'=>0,'queued'=>0,'work_classes'=>[]];
    $classForType=static function(string $type,string $url=''):?string{
        $mapped=match($type){'player_matches'=>'matches','player_stats'=>'stats','player_games_archive'=>'archive','club_members'=>'roster',default=>null};
        if($mapped!==null)return $mapped;
        $path=strtolower((string)(parse_url($url,PHP_URL_PATH)?:''));
        if(preg_match('~/pub/player/[^/]+/matches/?$~',$path))return 'matches';
        if(preg_match('~/pub/player/[^/]+/stats/?$~',$path))return 'stats';
        if(preg_match('~/pub/player/[^/]+/games/20\d{2}/(?:0[1-9]|1[0-2])/?$~',$path))return 'archive';
        if(preg_match('~/pub/club/[^/]+/members/?$~',$path))return 'roster';
        return null;
    };
    foreach($observations as $observation){
        if(!is_array($observation))continue;
        $url=trim((string)($observation['url']??''));$payload=$observation['payload']??null;$source=strtolower(trim((string)($observation['source']??'browser')));
        if($url===''||!is_array($payload))continue;
        $claim=['verified'=>false,'reason'=>'not_acamr'];
        if(in_array($source,['acamr','client_refresh'],true))$claim=$verifyClaim((string)($observation['claimToken']??''),$url,(string)($observation['claimKind']??''));
        if(!in_array($source,['acamr','client_refresh'],true))$source='browser';
        $result=$ingestor->ingest($url,$payload,$source,['claim_verified'=>!empty($claim['verified']),'claim_reason'=>(string)($claim['reason']??''),'claim_username'=>(string)($claim['username']??'')]);$result['claim_verified']=!empty($claim['verified']);$results[]=$result;
        $wasAccepted=!empty($result['accepted']);$resultQueued=(int)($result['queued']??0);$resultUpdated=(int)($result['updated']??0);
        if($wasAccepted)$accepted++;
        $queued+=$resultQueued;$updated+=$resultUpdated;
        if($source==='acamr'){
            $acamr['observations']++;$acamr[$wasAccepted?'accepted':'rejected']++;$acamr[!empty($claim['verified'])?'claim_verified':'claim_unverified']++;$acamr['queued']+=$resultQueued;$acamr['updated']+=$resultUpdated;
            $class=$classForType((string)($result['type']??''),$url);
            if($class!==null){$row=$acamr['work_classes'][$class]??['observations'=>0,'accepted'=>0,'rejected'=>0,'queued'=>0];$row['observations']++;$row[$wasAccepted?'accepted':'rejected']++;$row['queued']+=$resultQueued;$acamr['work_classes'][$class]=$row;}
        }
    }
    if($acamr['observations']>0)RuntimeTelemetry::record('acamr_observation',$acamr);
    Http::json(['ok'=>true,'accepted'=>$accepted,'updated'=>$updated,'queued'=>$queued,'results'=>$results]);
} catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);} catch(Throwable $e){error_log('P2K browser observations: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Observation ingestion is temporarily unavailable.']],500);}
