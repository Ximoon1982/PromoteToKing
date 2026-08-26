<?php
declare(strict_types=1);
namespace P2K\TeamPoints;
use P2K\Shared\FilesystemCache;

final class RuntimeTelemetry
{
    private static array $responseMeta=[];

    public static function noteResponse(int $bytes,string $cachePolicy='no-store',bool $notModified=false):void
    {
        self::$responseMeta=['response_bytes'=>max(0,$bytes),'cache_policy'=>substr($cachePolicy,0,48),'not_modified'=>$notModified];
    }

    public static function record(string $type,array $data=[]):void
    {
        try{$c=\p2k_tp_config();$root=FilesystemCache::runtimeRoot(is_array($c['storage']??null)?$c['storage']:[]).'/telemetry';FilesystemCache::ensureProtectedDirectory($root);$row=['ts'=>gmdate(DATE_ATOM),'epoch'=>time(),'type'=>substr($type,0,80)]+$data;@file_put_contents($root.'/'.gmdate('Y-m-d').'.jsonl',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);}catch(\Throwable){}
    }

    public static function recordRequest(float $startedAt):void
    {
        $uri=(string)($_SERVER['REQUEST_URI']??'');if($uri===''||!str_contains($uri,'/server/team-points/public/'))return;
        self::record('endpoint',['endpoint'=>(string)(parse_url($uri,PHP_URL_PATH)?:$uri),'method'=>(string)($_SERVER['REQUEST_METHOD']??'GET'),'status'=>http_response_code(),'duration_ms'=>(int)round((microtime(true)-$startedAt)*1000),'memory_peak_bytes'=>memory_get_peak_usage(true)]+self::$responseMeta);
    }

    public static function recent(int $days=7,int $max=25000):array
    {
        $rows=[];try{$c=\p2k_tp_config();$root=FilesystemCache::runtimeRoot(is_array($c['storage']??null)?$c['storage']:[]).'/telemetry';for($i=0;$i<max(1,min(30,$days));$i++){$path=$root.'/'.gmdate('Y-m-d',time()-$i*86400).'.jsonl';if(!is_file($path))continue;$fh=@fopen($path,'rb');if(!$fh)continue;while(($line=fgets($fh))!==false){$row=json_decode($line,true);if(is_array($row))$rows[]=$row;if(count($rows)>=$max)break 2;}fclose($fh);}}catch(\Throwable){}return $rows;
    }


    public static function chessApiThroughput(int $minutes=10):array
    {
        $minutes=max(1,min(60,$minutes));$now=time();$start=$now-$minutes*60;$perSecond=[];
        foreach(self::recent(2,50000) as $r){
            if(($r['type']??'')!=='chess_api_batch')continue;$epoch=(int)($r['epoch']??0);if($epoch<$start-120)continue;
            $counts=is_array($r['completion_counts']??null)?$r['completion_counts']:[];
            if($counts){foreach($counts as $sec=>$count){$sec=(int)$sec;if($sec<$start||$sec>$now+5)continue;$perSecond[$sec]=($perSecond[$sec]??0)+max(0,(int)$count);}}
            else{
                $calls=max(0,(int)($r['calls']??0));if($calls>0&&$epoch>=$start&&$epoch<=$now)$perSecond[$epoch]=($perSecond[$epoch]??0)+$calls;
            }
        }
        $rows=[];$firstMinute=(int)(floor($start/60)*60);$lastMinute=(int)(floor($now/60)*60);
        for($minute=$firstMinute;$minute<=$lastMinute;$minute+=60){$total=0;$peak=0;$covered=0;for($sec=$minute;$sec<$minute+60;$sec++){if($sec<$start||$sec>$now)continue;$covered++;$n=(int)($perSecond[$sec]??0);$total+=$n;$peak=max($peak,$n);}if($covered<=0)$covered=60;$rows[]=['minute'=>gmdate('Y-m-d\TH:i:00\Z',$minute),'average_rps'=>round($total/$covered,2),'peak_rps'=>$peak,'calls'=>$total];}
        return $rows;
    }

    private static function emptyWorkClass():array
    {
        return [
            'handed_out'=>0,'browser_fetched_ok'=>0,'browser_fetch_failed'=>0,
            'observations_accepted'=>0,'observations_rejected'=>0,'authoritative_queued'=>0,
            'recent_30m'=>[
                'handed_out'=>0,'browser_fetched_ok'=>0,'browser_fetch_failed'=>0,
                'observations_accepted'=>0,'observations_rejected'=>0,'authoritative_queued'=>0,
            ],
        ];
    }

    public static function summary(int $days=7):array
    {
        $rows=self::recent($days);$by=[];$now=time();
        $classes=['matches'=>self::emptyWorkClass(),'stats'=>self::emptyWorkClass(),'archive'=>self::emptyWorkClass(),'roster'=>self::emptyWorkClass()];
        $acamr=[
            'window_days'=>$days,'active_window_minutes'=>30,
            'plans'=>0,'plans_30m'=>0,'successful_plans'=>0,'successful_plans_30m'=>0,'claims'=>0,'claims_30m'=>0,'empty_plans'=>0,
            'observations'=>0,'observations_30m'=>0,'claim_verified_observations'=>0,'claim_unverified_observations'=>0,'queued'=>0,'queued_30m'=>0,'tasks'=>0,'tasks_30m'=>0,'claim_conflicts'=>0,
            'distinct_clients'=>0,'active_clients_30m'=>0,'distinct_browsing_sessions'=>0,'active_browsing_sessions_30m'=>0,
            'distinct_actors'=>0,'active_actors_30m'=>0,'distinct_members_claimed'=>0,'work_classes'=>[],
        ];
        $clients=[];$activeClients=[];$sessions=[];$activeSessions=[];$actors=[];$activeActors=[];$members=[];$seenReports=[];
        foreach($rows as $r){
            $epoch=(int)($r['epoch']??0);$recent30=$epoch>0&&$now-$epoch<=1800;
            if(($r['type']??'')==='endpoint'){
                $k=(string)($r['endpoint']??'unknown');$b=$by[$k]??['calls'=>0,'errors'=>0,'durations'=>[],'bytes'=>[],'not_modified'=>0,'memory_peak_bytes'=>0];$b['calls']++;if((int)($r['status']??200)>=400)$b['errors']++;$b['durations'][]=(int)($r['duration_ms']??0);if(isset($r['response_bytes']))$b['bytes'][]=(int)$r['response_bytes'];if(!empty($r['not_modified'])||(int)($r['status']??0)===304)$b['not_modified']++;$b['memory_peak_bytes']=max($b['memory_peak_bytes'],(int)($r['memory_peak_bytes']??0));$by[$k]=$b;
            }
            if(($r['type']??'')==='acamr_plan'){
                $acamr['plans']++;if($recent30)$acamr['plans_30m']++;
                $claims=max(0,(int)($r['claims']??(!empty($r['claimed'])?1:0)));
                if($claims>0){$acamr['successful_plans']++;$acamr['claims']+=$claims;if($recent30){$acamr['successful_plans_30m']++;$acamr['claims_30m']+=$claims;}}else{$acamr['empty_plans']++;}
                $acamr['claim_conflicts']+=max(0,(int)($r['claim_conflicts']??0));
                $tasks=max(0,(int)($r['tasks']??0));$acamr['tasks']+=$tasks;if($recent30)$acamr['tasks_30m']+=$tasks;
                foreach(['actor_hash'=>'actors','client_hash'=>'clients','session_hash'=>'sessions'] as $field=>$bucket){
                    $value=(string)($r[$field]??'');if($value==='')continue;
                    if($bucket==='actors'){$actors[$value]=true;if($recent30)$activeActors[$value]=true;}
                    elseif($bucket==='clients'){$clients[$value]=true;if($recent30)$activeClients[$value]=true;}
                    else{$sessions[$value]=true;if($recent30)$activeSessions[$value]=true;}
                }
                $memberHashes=is_array($r['member_hashes']??null)?$r['member_hashes']:[];
                if($memberHashes===[]&&trim((string)($r['member_hash']??''))!=='')$memberHashes=[(string)$r['member_hash']];
                foreach($memberHashes as $member){$member=trim((string)$member);if($member!=='')$members[$member]=true;}
                $taskKinds=is_array($r['task_kinds']??null)?$r['task_kinds']:[];
                foreach($classes as $kind=>&$class){$n=max(0,(int)($taskKinds[$kind]??0));$class['handed_out']+=$n;if($recent30)$class['recent_30m']['handed_out']+=$n;}unset($class);
                $report=is_array($r['result_report']??null)?$r['result_report']:null;
                $reportId=is_array($report)?trim((string)($report['id_hash']??'')):'';
                if($reportId!==''&&!isset($seenReports[$reportId])){
                    $seenReports[$reportId]=true;$reportedClasses=is_array($report['work_classes']??null)?$report['work_classes']:[];
                    foreach($classes as $kind=>&$class){$row=is_array($reportedClasses[$kind]??null)?$reportedClasses[$kind]:[];$ok=max(0,(int)($row['fetched_ok']??0));$failed=max(0,(int)($row['fetch_failed']??0));$class['browser_fetched_ok']+=$ok;$class['browser_fetch_failed']+=$failed;if($recent30){$class['recent_30m']['browser_fetched_ok']+=$ok;$class['recent_30m']['browser_fetch_failed']+=$failed;}}unset($class);
                }
            }
            if(($r['type']??'')==='acamr_observation'){
                $accepted=max(0,(int)($r['accepted']??0));$queued=max(0,(int)($r['queued']??0));
                $acamr['claim_verified_observations']+=max(0,(int)($r['claim_verified']??0));$acamr['claim_unverified_observations']+=max(0,(int)($r['claim_unverified']??0));
                $acamr['observations']+=$accepted;$acamr['queued']+=$queued;if($recent30){$acamr['observations_30m']+=$accepted;$acamr['queued_30m']+=$queued;}
                $work=is_array($r['work_classes']??null)?$r['work_classes']:[];
                foreach($classes as $kind=>&$class){$row=is_array($work[$kind]??null)?$work[$kind]:[];$a=max(0,(int)($row['accepted']??0));$rej=max(0,(int)($row['rejected']??0));$q=max(0,(int)($row['queued']??0));$class['observations_accepted']+=$a;$class['observations_rejected']+=$rej;$class['authoritative_queued']+=$q;if($recent30){$class['recent_30m']['observations_accepted']+=$a;$class['recent_30m']['observations_rejected']+=$rej;$class['recent_30m']['authoritative_queued']+=$q;}}unset($class);
            }
        }
        $end=[];foreach($by as $endpoint=>$b){sort($b['durations']);sort($b['bytes']);$n=count($b['durations']);$bn=count($b['bytes']);$pct=static fn(array $values,float $p):int=>count($values)?(int)$values[min(count($values)-1,(int)floor((count($values)-1)*$p))]:0;$end[]=['endpoint'=>$endpoint,'calls'=>$b['calls'],'errors'=>$b['errors'],'p50_ms'=>$pct($b['durations'],.5),'p95_ms'=>$pct($b['durations'],.95),'max_ms'=>$n?max($b['durations']):0,'avg_response_bytes'=>$bn?(int)round(array_sum($b['bytes'])/$bn):0,'p95_response_bytes'=>$pct($b['bytes'],.95),'not_modified'=>$b['not_modified'],'not_modified_rate'=>$b['calls']?round(100*$b['not_modified']/$b['calls'],1):0,'memory_peak_bytes'=>$b['memory_peak_bytes']];}
        usort($end,static fn($a,$b)=>$b['p95_ms']<=>$a['p95_ms']?:$b['calls']<=>$a['calls']);
        $acamr['distinct_clients']=count($clients);$acamr['active_clients_30m']=count($activeClients);
        $acamr['distinct_browsing_sessions']=count($sessions);$acamr['active_browsing_sessions_30m']=count($activeSessions);
        $acamr['distinct_actors']=count($actors);$acamr['active_actors_30m']=count($activeActors);$acamr['distinct_members_claimed']=count($members);
        // Backward-compatible aliases. Old telemetry has actor hashes but no client/session hashes.
        $acamr['distinct_sessions']=$acamr['distinct_browsing_sessions']?:$acamr['distinct_actors'];
        $acamr['active_sessions_30m']=$acamr['active_browsing_sessions_30m']?:$acamr['active_actors_30m'];
        $acamr['claim_rate']=$acamr['plans']?round(100*$acamr['successful_plans']/$acamr['plans'],1):0;
        $acamr['claims_per_plan']=$acamr['plans']?round($acamr['claims']/$acamr['plans'],2):0;
        $acamr['queue_yield']=$acamr['observations']?round($acamr['queued']/$acamr['observations'],2):0;
        $acamr['work_classes']=$classes;
        $acamr['identity_semantics']=[
            'client'=>'Random ACAMR client ID stored in browser localStorage; persists until site storage is cleared. Only its protected hash is stored server-side.',
            'browsing_session'=>'Random ACAMR session ID stored in browser sessionStorage; survives reloads in that browsing session and is discarded with the session. Only its protected hash is stored server-side.',
            'actor'=>'Authenticated Chess.com/simulated username, stored only as a protected hash in ACAMR telemetry.',
            'summary_window_days'=>$days,'active_window_minutes'=>30,
        ];
        return ['days'=>$days,'events'=>count($rows),'endpoints'=>$end,'acamr'=>$acamr,'limitations'=>['db_query_time'=>'Direct PDO query timing is not instrumented per endpoint; total endpoint duration is measured.']];
    }
}
