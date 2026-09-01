<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ClubIntelligenceService;
use P2K\TeamPoints\GreenFreshnessService;
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

    if($scope==='traffic'){
        $root=dirname(__DIR__,3);$days=max(1,min(366,(int)($_GET['days']??90)));
        $data=\P2K\Shared\TrafficAnalytics::report($root,$days);
        $data['diagnostics']=\P2K\Shared\TrafficAnalytics::diagnostics($root);
        $data['self_test']=\P2K\Shared\TrafficAnalytics::selfTest($root);
        Http::json(['ok'=>true,'scope'=>$scope,'server_utc'=>gmdate(DATE_ATOM),'data'=>$data]);
    }

    // v2.11.0: freshness is a native Green read. Never execute Blue-era
    // freshness SQL against the Green PDOs and infer production health from compatibility tables.
    $core=PublicReadDatabase::core();
    $analytics=PublicReadDatabase::analytics();
    $greenFreshness=new GreenFreshnessService($core,$analytics,$club);
    $greenReport=static function() use($greenFreshness):array {
        $report=$greenFreshness->report();
        // The compatibility queue headline in the canonical Admin shell now means
        // GFFL due debt specifically; do not expose an overlapping sum of board/GFFL/GQAC obligations.
        if(is_array($report['coverage']??null)){
            $report['coverage']['queue_pending']=(int)($report['green']['work']['gffl_due']??0);
        }
        return $report;
    };
    if($scope==='freshness'){
        Http::json(['ok'=>true,'scope'=>$scope,'server_utc'=>gmdate(DATE_ATOM),'data'=>$greenReport()]);
    }

    $r=new Repository($core,$analytics);
    if(!$r->schemaInstalled())throw new ApiException('Team Points schema must be upgraded before Club Intelligence is available.',503,'SCHEMA_NOT_INSTALLED');
    $s=new ClubIntelligenceService($core,$analytics,$club);

    $acamr=static function() use($greenReport):array {
        $telemetry=RuntimeTelemetry::summary(7)['acamr']??[];
        $fresh=$greenReport();
        $coverage=is_array($fresh['coverage']??null)?$fresh['coverage']:[];
        $green=is_array($fresh['green']??null)?$fresh['green']:[];
        $matches=is_array($green['matches']??null)?$green['matches']:[];
        // The legacy ACAMR planner is disabled when Green owns browser ingest. Do not
        // reinterpret Green current-match debt as the old per-player `matches` class.
        $telemetry['freshness']=[
            'current_members'=>(int)($coverage['current_members']??0),
            'player_stats_due'=>(int)($coverage['player_stats_due']??0),
            'player_stats_never_checked'=>(int)($coverage['player_stats_never_checked']??0),
            'player_stats_fresh_percent'=>(float)($coverage['player_stats_fresh_percent']??0),
            'current_matches_due'=>(int)($matches['current_due']??0),
            'current_matches_fresh_percent'=>(float)($matches['current_fresh_percent']??0),
            'source'=>'green_native_core',
        ];
        $telemetry['warnings']=[];
        $telemetry['planner_state']='retired_green_primary';
        $telemetry['legacy_work_class_warnings_applicable']=false;
        return $telemetry;
    };

    $data=match($scope){
        'overview'=>(static function() use($s,$greenReport):array {$overview=$s->overview();$overview['freshness']=$greenReport();return $overview;})(),
        'depth'=>$s->teamDepthReport(),
        'activity'=>$s->memberActivity(),
        'anomalies'=>['rows'=>$s->anomalies(),'actions'=>$s->adminActions()],
        'actions'=>['rows'=>$s->adminActions()],
        'forecast'=>$s->forecasts(),
        'opponents'=>['rows'=>$s->opponentProfiles(50)],
        'balance'=>$s->opponentBalance(),
        'snapshots'=>['current'=>$s->captureDailySnapshot(),'rows'=>$s->snapshots(365),'comparison'=>$s->snapshotComparisons()],
        'performance'=>RuntimeTelemetry::summary(7),
        'acamr'=>$acamr(),
        'aliases'=>(new MiacService($core,$club))->summary(max(1,min(500,(int)($_GET['limit']??500))),(string)($_GET['search']??'')),
        default=>throw new ApiException('Unknown intelligence scope.',400,'INVALID_SCOPE')
    };
    Http::json(['ok'=>true,'scope'=>$scope,'server_utc'=>gmdate(DATE_ATOM),'data'=>$data]);
}catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
}catch(Throwable $e){
    error_log('P2K intelligence: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Club Intelligence is temporarily unavailable.']],500);
}
