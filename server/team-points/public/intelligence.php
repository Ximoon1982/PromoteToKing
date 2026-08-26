<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ClubIntelligenceService;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\MiacService;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\RuntimeTelemetry;

try{
    Http::method('GET');
    Auth::requireAdmin();
    $c=p2k_tp_config();
    $club=strtolower((string)($c['app']['club_slug']??'promote-to-king'));
    $scope=strtolower(trim((string)($_GET['scope']??'overview')));
    // v2.9.22.5: traffic analytics are filesystem-backed. Do not make this
    // diagnostic wait for Core/Analytics connections or queue-heavy DB work.
    if($scope==='traffic'){
        $root=dirname(__DIR__,3);$days=max(1,min(366,(int)($_GET['days']??90)));
        $data=\P2K\Shared\TrafficAnalytics::report($root,$days);
        $data['diagnostics']=\P2K\Shared\TrafficAnalytics::diagnostics($root);
        $data['self_test']=\P2K\Shared\TrafficAnalytics::selfTest($root);
        Http::json(['ok'=>true,'scope'=>$scope,'server_utc'=>gmdate(DATE_ATOM),'data'=>$data]);
    }
    $r=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if(!$r->schemaInstalled())throw new ApiException('Team Points schema must be upgraded before Club Intelligence is available.',503,'SCHEMA_NOT_INSTALLED');
    $s=new ClubIntelligenceService(PublicReadDatabase::core(),PublicReadDatabase::analytics(),$club);

    $acamr=static function() use($s):array {
        $telemetry=RuntimeTelemetry::summary(7)['acamr']??[];
        $fresh=$s->freshnessCoverage();
        $coverage=is_array($fresh['coverage']??null)?$fresh['coverage']:[];
        $telemetry['freshness']=[
            'current_members'=>(int)($coverage['current_members']??0),
            'player_matches_due'=>(int)($coverage['player_matches_due']??0),
            'player_matches_never_checked'=>(int)($coverage['player_matches_never_checked']??0),
            'player_matches_fresh_percent'=>(float)($coverage['player_matches_fresh_percent']??0),
            'player_matches_operational_due'=>(int)($coverage['player_matches_operational_due']??0),
            'player_matches_operational_fresh_percent'=>(float)($coverage['player_matches_operational_fresh_percent']??0),
            'player_stats_due'=>(int)($coverage['player_stats_due']??0),
            'player_stats_never_checked'=>(int)($coverage['player_stats_never_checked']??0),
            'player_stats_fresh_percent'=>(float)($coverage['player_stats_fresh_percent']??0),
            'player_stats_operational_due'=>(int)($coverage['player_stats_operational_due']??0),
            'player_stats_operational_fresh_percent'=>(float)($coverage['player_stats_operational_fresh_percent']??0),
        ];
        $telemetry['warnings']=[];
        $plans30=(int)($telemetry['successful_plans_30m']??0);
        $classes=is_array($telemetry['work_classes']??null)?$telemetry['work_classes']:[];
        foreach([
            'matches'=>['due'=>(int)($coverage['player_matches_due']??0),'never'=>(int)($coverage['player_matches_never_checked']??0),'label'=>'Player matches'],
            'stats'=>['due'=>(int)($coverage['player_stats_due']??0),'never'=>(int)($coverage['player_stats_never_checked']??0),'label'=>'Player stats'],
        ] as $kind=>$state){
            $recent=is_array($classes[$kind]['recent_30m']??null)?$classes[$kind]['recent_30m']:[];
            $handed=(int)($recent['handed_out']??0);$ok=(int)($recent['browser_fetched_ok']??0);$failed=(int)($recent['browser_fetch_failed']??0);$accepted=(int)($recent['observations_accepted']??0);
            if($state['due']>0&&$plans30>=3&&$handed===0){
                $telemetry['warnings'][]=['code'=>'POSSIBLE_WORK_CLASS_STARVATION','class'=>$kind,'severity'=>'warning','message'=>$state['label'].' has due members but received no ACAMR tasks during at least three successful planning cycles in the last 30 minutes.'];
            }elseif($handed>=3&&$ok===0&&$failed>=3){
                $telemetry['warnings'][]=['code'=>'WORK_CLASS_FETCH_FAILURE','class'=>$kind,'severity'=>'warning','message'=>$state['label'].' tasks are being handed out but recent browser fetches are failing.'];
            }elseif($ok>=3&&$accepted===0){
                $telemetry['warnings'][]=['code'=>'WORK_CLASS_OBSERVATION_STALL','class'=>$kind,'severity'=>'warning','message'=>$state['label'].' browser fetches succeeded but no ACAMR observations were accepted in the last 30 minutes.'];
            }
        }
        return $telemetry;
    };

    $data=match($scope){
        'overview'=>$s->overview(),
        'depth'=>$s->teamDepthReport(),
        'activity'=>$s->memberActivity(),
        'freshness'=>$s->freshnessCoverage(),
        'anomalies'=>['rows'=>$s->anomalies(),'actions'=>$s->adminActions()],
        'actions'=>['rows'=>$s->adminActions()],
        'forecast'=>$s->forecasts(),
        'opponents'=>['rows'=>$s->opponentProfiles(50)],
        'balance'=>$s->opponentBalance(),
        'snapshots'=>['current'=>$s->captureDailySnapshot(),'rows'=>$s->snapshots(365),'comparison'=>$s->snapshotComparisons()],
        'performance'=>RuntimeTelemetry::summary(7),
        'acamr'=>$acamr(),
        'aliases'=>(new MiacService(PublicReadDatabase::core(),$club))->summary(max(1,min(500,(int)($_GET['limit']??500))),(string)($_GET['search']??'')),
        default=>throw new ApiException('Unknown intelligence scope.',400,'INVALID_SCOPE')
    };
    Http::json(['ok'=>true,'scope'=>$scope,'server_utc'=>gmdate(DATE_ATOM),'data'=>$data]);
}catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
}catch(Throwable $e){
    error_log('P2K intelligence: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Club Intelligence is temporarily unavailable.']],500);
}
