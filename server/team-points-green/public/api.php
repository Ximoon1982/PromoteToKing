<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;
use P2K\Green\GreenComparison;
use P2K\Green\GreenLegacyFacts;
use P2K\Green\GreenCompatibility;
use P2K\Green\GreenAnalyticsBootstrap;
use P2K\TeamPoints\PublicReadDatabase;

try{
    GreenConfig::authorizeAdmin();$repo=GreenRepository::open();$cmp=new GreenComparison($repo);$action=strtolower(trim((string)($_GET['action']??'status')));
    $greenValidation=static function(?array $summary=null) use($repo,$cmp): array {
        $s=$summary??$repo->greenSummary();$st=$s['state'];$p=$s['progress'];$i=$s['integrity']??[];
        $checks=[
            'mode_quick'=>((string)($st['mode']??'')==='quick'),
            'seed_completed'=>!empty($st['seed_completed_at']),
            'no_unknown_matches'=>(int)($p['matches']['unknown']??0)===0,
            'no_pending_boards'=>(int)($p['boards']['pending']??0)===0,
            'current_profiles_complete'=>(int)($p['players']['profiles_pending']??0)===0,
            'initial_stats_complete'=>(int)($p['players']['initial_stats_pending']??0)===0,
            'index_seen'=>!empty($st['last_index_fetch']),
            'roster_seen'=>!empty($st['last_roster_fetch']),
            'no_non_daily_points'=>(int)($i['non_daily_point_rows']??0)===0,
            'no_unverified_points'=>(int)($i['unverified_point_rows']??0)===0,
            'no_ineligible_points'=>(int)($i['ineligible_point_rows']??0)===0,
            'no_excluded_member_events'=>(int)($i['excluded_point_events']??0)===0,
            'trusted_legacy_rows'=>(int)($i['trusted_legacy_rows']??0)===GreenLegacyFacts::EXPECTED_ROWS,
            'trusted_legacy_valid'=>(int)($i['trusted_legacy_valid']??0)===GreenLegacyFacts::EXPECTED_VALID_FINISHED,
            'trusted_legacy_void'=>(int)($i['trusted_legacy_void']??0)===GreenLegacyFacts::EXPECTED_VOID,
            'trusted_legacy_points'=>(int)($i['trusted_legacy_points']??0)===GreenLegacyFacts::EXPECTED_POINTS,
            'current_match_facts_within_gffl_slo'=>(int)($i['current_maintenance_over_slo']??0)===0,
        ];
        $comparison=null;$comparisonError=null;
        try{$comparison=$cmp->summary();}catch(Throwable $comparisonFailure){$comparisonError=$comparisonFailure->getMessage();}
        $checks['blue_comparison_available']=$comparisonError===null;
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'comparison'=>$comparison,'comparison_error'=>$comparisonError];
    };
    $adapterValidation=static function(?array $gabStatus=null,bool $runSmoke=false) use($repo): array {
        $state=$repo->state();$gab=$gabStatus??(new GreenAnalyticsBootstrap($repo))->status();$checks=['gab_ready'=>(string)($state['gab_status']??'')==='ready'];$smoke=null;
        if($checks['gab_ready']||$runSmoke){try{$smoke=(new GreenCompatibility($repo))->smokeTest();$checks['compatibility_smoke']=!empty($smoke['ready']);}catch(Throwable $e){$checks['compatibility_smoke']=false;$smoke=['ready'=>false,'error'=>$e->getMessage()];}}else{$checks['compatibility_smoke']=false;}
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'gab'=>$gab,'smoke'=>$smoke];
    };
    $technicalReadiness=static function() use($repo): array {
        $checks=['green_core_schema_17'=>false,'green_analytics_schema_9'=>false,'green_state_available'=>false];$errors=[];
        try{$checks['green_core_schema_17']=(int)$repo->core->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn()>=17;}catch(Throwable $e){$errors['green_core_schema_17']=$e->getMessage();}
        try{$checks['green_analytics_schema_9']=(int)$repo->analytics->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn()>=9;}catch(Throwable $e){$errors['green_analytics_schema_9']=$e->getMessage();}
        try{$checks['green_state_available']=!empty($repo->state());}catch(Throwable $e){$errors['green_state_available']=$e->getMessage();}
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'errors'=>$errors];
    };
    $cutoverStatus=static function(?array $summary=null,bool $runSmoke=false) use($greenValidation,$adapterValidation,$technicalReadiness): array {
        $validation=$greenValidation($summary);$adapter=$adapterValidation($summary['gab']??null,$runSmoke);$technical=$technicalReadiness();$warnings=[];
        foreach(['green'=>$validation['checks']??[],'adapter'=>$adapter['checks']??[],'read_parity'=>$adapter['smoke']['checks']??[]] as $group=>$checks){foreach($checks as $key=>$value)if($value!==true)$warnings[]=$group.':'.$key;}
        return ['allowed'=>(bool)$technical['ready'],'clean'=>(bool)$validation['ready']&&(bool)$adapter['ready'],'technical'=>$technical,'warning_count'=>count(array_unique($warnings)),'warnings'=>array_values(array_unique($warnings)),'validation'=>$validation,'adapter'=>$adapter];
    };
    if($action==='runtime-health'){
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')GreenConfig::json(['ok'=>false,'error'=>'Use GET for runtime health.'],405);
        $state=$repo->state();
        $invocations=$repo->recentInvocations(1);$last=$invocations[0]??null;
        PublicReadDatabase::reset();$effective=PublicReadDatabase::source();
        $lastAt=is_array($last)?((string)($last['completed_at']??$last['started_at']??'')):'';
        $lastEpoch=$lastAt!==''?strtotime($lastAt.' UTC'):false;$age=$lastEpoch===false?null:max(0,time()-$lastEpoch);
        $status=is_array($last)?strtolower((string)($last['status']??'unknown')):'unknown';
        $healthy=in_array($status,['running','success'],true)&&($age===null||$age<=180);
        GreenConfig::json(['ok'=>true,'effective_public_source'=>$effective,'state'=>[
            'cycle_no'=>(int)($state['cycle_no']??0),'mode'=>(string)($state['mode']??''),'stage'=>(string)($state['stage']??''),
            'last_worker_start'=>$state['last_worker_start']??null,'last_worker_finish'=>$state['last_worker_finish']??null,'last_worker_status'=>$state['last_worker_status']??null,
            'compat_analytics_rebuilt_at'=>$state['compat_analytics_rebuilt_at']??null,'migration_phase'=>$state['migration_phase']??null,
        ],'last_invocation'=>$last,'age_seconds'=>$age,'healthy'=>$healthy]);
    }
    if($action==='status'){
        // Migration control must remain available even if the Blue task-control read is
        // temporarily unavailable. Blue is informational on this endpoint; Green state
        // and routing are the authoritative controls during migration.
        $blueTasks=[];$blueTasksError=null;
        try{$blueTasks=$cmp->blueTaskState();}catch(Throwable $blueError){$blueTasksError=$blueError->getMessage();}
        $summary=$repo->greenSummary();$cutover=$cutoverStatus($summary,false);$validation=$cutover['validation'];$adapter=$cutover['adapter'];PublicReadDatabase::reset();$effective=PublicReadDatabase::source();
        $blueRollbackMaintained=$blueTasksError===null&&count($blueTasks)>=2;foreach($blueTasks as $blueTask)if((int)($blueTask['pause_requested']??1)!==0)$blueRollbackMaintained=false;
        $cutover['blue_rollback_maintained']=$blueRollbackMaintained;$cutover['blue_rollback_error']=$blueTasksError;
        GreenConfig::json(['ok'=>true,'release'=>'2.10.6','panel_build'=>'2.10.6.10','public_source'=>(string)($repo->state()['public_read_target']??'blue'),'effective_public_source'=>$effective,'green_public_adapter_ready'=>(bool)$adapter['ready'],'read_cutover_ready'=>(bool)$cutover['clean'],'read_cutover_allowed'=>(bool)$cutover['allowed'],'cutover'=>$cutover,'validation'=>$validation,'adapter'=>$adapter,'green'=>$summary,'blue_tasks'=>$blueTasks,'blue_tasks_error'=>$blueTasksError]);
    }
    if($action==='comparison')GreenConfig::json(['ok'=>true,'comparison'=>$cmp->summary()]);
    if($action==='member-events'){$limit=max(1,min(1000,(int)($_GET['limit']??250)));GreenConfig::json(['ok'=>true,'events'=>$repo->memberEvents($limit)]);}
    if($action==='member-lookup'){$username=trim((string)($_GET['username']??''));if($username==='')GreenConfig::json(['ok'=>false,'error'=>'A username is required.'],400);GreenConfig::json(['ok'=>true,'lookup'=>$repo->memberLookup($username)]);}
    if($action==='feed-plan'){$limit=max(1,min(96,(int)($_GET['limit']??48)));$owner=trim((string)($_GET['owner']??''));GreenConfig::json(['ok'=>true,'plan'=>$repo->feedPlan($limit,$owner)]);}
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')GreenConfig::json(['ok'=>false,'error'=>'Use POST for control actions.'],405);
    $body=GreenConfig::body();
    if($action==='advance-browser-stage'){
        GreenConfig::json(['ok'=>true]+$repo->advanceBrowserStageIfDrained());
    }
    if($action==='take-browser-claim-lane'){
        $owner=trim((string)($body['owner']??''));$released=$repo->takeBrowserClaimLane($owner);
        GreenConfig::json(['ok'=>true,'owner'=>$owner,'released_browser_claims'=>$released,'planner'=>$repo->seedMatchPlannerState($owner)]);
    }
    if($action==='release-browser-claims'){
        $owner=trim((string)($body['owner']??''));$released=$repo->releaseBrowserClaims($owner);
        GreenConfig::json(['ok'=>true,'owner'=>$owner,'released_browser_claims'=>$released]);
    }
    if($action==='switch-green-reads'){
        $cutover=$cutoverStatus(null,true);if(!$cutover['allowed'])GreenConfig::json(['ok'=>false,'error'=>'Green technical prerequisites are unavailable; public reads were not changed.','cutover'=>$cutover,'state'=>$repo->state()],409);
        $blueWarning=null;try{$cmp->setBluePaused(false);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}
        $state=$repo->setControl(['public_read_target'=>'green','migration_phase'=>'green_reads_both_writing','worker_target'=>'both','client_ingest_target'=>'both']);PublicReadDatabase::reset();
        GreenConfig::json(['ok'=>true,'effective_public_source'=>PublicReadDatabase::source(),'state'=>$state,'cutover'=>$cutover,'blue_warning'=>$blueWarning,'message'=>'Green public reads are active; Blue and Green maintenance remain enabled.']);
    }
    if($action==='make-green-primary'){
        $cutover=$cutoverStatus(null,true);if(!$cutover['allowed'])GreenConfig::json(['ok'=>false,'error'=>'Green technical prerequisites are unavailable; Green primary was not enabled.','cutover'=>$cutover,'state'=>$repo->state()],409);
        $state=$repo->setControl(['public_read_target'=>'green','migration_phase'=>'green_primary','worker_target'=>'green','client_ingest_target'=>'green']);PublicReadDatabase::reset();$effective=PublicReadDatabase::source();$blueWarning=null;
        try{$cmp->setBluePaused(true);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}
        GreenConfig::json(['ok'=>true,'effective_public_source'=>$effective,'state'=>$state,'cutover'=>$cutover,'blue_warning'=>$blueWarning,'message'=>'Green is primary. Blue data was not deleted and can still be selected for read rollback.']);
    }
    if($action==='rollback-blue-reads'){
        $blueWarning=null;try{$cmp->setBluePaused(false);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}
        $state=$repo->setControl(['public_read_target'=>'blue','migration_phase'=>'shadow_writing','worker_target'=>'both','client_ingest_target'=>'both']);PublicReadDatabase::reset();
        GreenConfig::json(['ok'=>true,'effective_public_source'=>PublicReadDatabase::source(),'state'=>$state,'blue_warning'=>$blueWarning,'message'=>'Public reads are back on Blue; both data paths remain maintained.']);
    }
    if($action==='set-migration-phase'){
        $phase=strtolower(trim((string)($body['phase']??'')));
        $allowed=['blue_primary','shadow_writing','green_validated','green_reads_both_writing','green_primary'];if(!in_array($phase,$allowed,true))throw new RuntimeException('Invalid migration phase.');
        $cutover=$cutoverStatus(null,true);if(in_array($phase,['green_reads_both_writing','green_primary'],true)&&!$cutover['allowed'])GreenConfig::json(['ok'=>false,'error'=>'Green technical prerequisites are unavailable; migration phase was not changed.','cutover'=>$cutover,'state'=>$repo->state()],409);
        $blueWarning=null;if(in_array($phase,['blue_primary','shadow_writing','green_validated','green_reads_both_writing'],true)){try{$cmp->setBluePaused(false);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}}
        if($phase==='blue_primary')$changes=['public_read_target'=>'blue','migration_phase'=>$phase,'worker_target'=>'blue','client_ingest_target'=>'blue'];
        elseif($phase==='shadow_writing'||$phase==='green_validated')$changes=['public_read_target'=>'blue','migration_phase'=>$phase,'worker_target'=>'both','client_ingest_target'=>'both'];
        elseif($phase==='green_reads_both_writing')$changes=['public_read_target'=>'green','migration_phase'=>$phase,'worker_target'=>'both','client_ingest_target'=>'both'];
        else $changes=['public_read_target'=>'green','migration_phase'=>$phase,'worker_target'=>'green','client_ingest_target'=>'green'];
        $state=$repo->setControl($changes);PublicReadDatabase::reset();$effective=PublicReadDatabase::source();
        if($phase==='green_primary'){try{$cmp->setBluePaused(true);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}}
        GreenConfig::json(['ok'=>true,'effective_public_source'=>$effective,'green_public_adapter_ready'=>(bool)$cutover['adapter']['ready'],'state'=>$state,'blue_warning'=>$blueWarning,'cutover'=>$cutover]);
    }
    if($action==='set-public-read-target'){
        $target=strtolower(trim((string)($body['target']??'blue')));if(!in_array($target,['blue','green'],true))throw new RuntimeException('Invalid public read target.');
        $cutover=$cutoverStatus(null,true);if($target==='green'&&!$cutover['allowed'])GreenConfig::json(['ok'=>false,'error'=>'Green technical prerequisites are unavailable; public source was not changed.','cutover'=>$cutover,'state'=>$repo->state()],409);
        if($target==='green'){$blueWarning=null;try{$cmp->setBluePaused(false);}catch(Throwable $blueFailure){$blueWarning=$blueFailure->getMessage();}$state=$repo->setControl(['public_read_target'=>'green','migration_phase'=>'green_reads_both_writing','worker_target'=>'both','client_ingest_target'=>'both']);}
        else{$state=$repo->setControl(['public_read_target'=>'blue']);$blueWarning=null;}
        PublicReadDatabase::reset();GreenConfig::json(['ok'=>true,'effective_public_source'=>PublicReadDatabase::source(),'state'=>$state,'cutover'=>$cutover,'blue_warning'=>$blueWarning]);
    }
    if($action==='set-worker-target'){
        $target=strtolower(trim((string)($body['target']??'')));if(!in_array($target,['blue','green','both','paused'],true))throw new RuntimeException('Invalid worker target.');
        // Explicit migration action only. Existing Blue CRON is never edited; its task flags are used.
        $cmp->setBluePaused(in_array($target,['green','paused'],true));
        $state=$repo->setControl(['worker_target'=>$target]);
        GreenConfig::json(['ok'=>true,'worker_target'=>$target,'state'=>$state,'blue_tasks'=>$cmp->blueTaskState()]);
    }
    if($action==='set-client-target'){$target=strtolower(trim((string)($body['target']??'')));$state=$repo->setControl(['client_ingest_target'=>$target]);GreenConfig::json(['ok'=>true,'state'=>$state]);}
    if($action==='set-force-mode'){$mode=strtolower(trim((string)($body['mode']??'auto')));if($mode==='deep'&&isset($body['from'],$body['to'])){$repo->configureDeep((int)$body['from'],(int)$body['to'],'manual_control');}$state=$repo->setControl(['force_mode'=>$mode]);GreenConfig::json(['ok'=>true,'state'=>$state]);}
    if($action==='start-seeding'){
        $state=$repo->state();if((int)$repo->progressSnapshot()['findings']<=0)throw new RuntimeException('Import findings.txt before starting Green Seeding.');
        if((string)$state['stage']==='not_started')$repo->stage('seed_index_roster');
        $target=(string)($body['worker_target']??'both');if(!in_array($target,['green','both'],true))$target='both';
        if($target==='both')$cmp->setBluePaused(false);else $cmp->setBluePaused(true);
        $repo->setControl(['worker_target'=>$target,'force_mode'=>'auto']);GreenConfig::json(['ok'=>true,'state'=>$repo->state()]);
    }
    if($action==='start-gab'){$restart=!empty($body['restart']);$gab=(new GreenAnalyticsBootstrap($repo))->start($restart);$repo->setControl(['worker_target'=>in_array((string)($repo->state()['worker_target']??''),['green','both'],true)?(string)$repo->state()['worker_target']:'both','client_ingest_target'=>'both']);GreenConfig::json(['ok'=>true,'gab'=>$gab,'state'=>$repo->state()]);}
    if($action==='run-gab-now'){$gab=(new GreenAnalyticsBootstrap($repo))->runSlice(max(1,min(20,(float)($body['seconds']??8))));GreenConfig::json(['ok'=>true,'result'=>$gab,'green'=>$repo->greenSummary()]);}
    if($action==='report-gab-failure'){$url=trim((string)($body['url']??''));$status=(int)($body['status']??0);$error=trim((string)($body['error']??'fetch failed'));if($url==='')throw new RuntimeException('GAB failure report requires a URL.');(new GreenAnalyticsBootstrap($repo))->markExternalFailure($url,$status,$error);GreenConfig::json(['ok'=>true,'gab'=>(new GreenAnalyticsBootstrap($repo))->status()]);}
    if($action==='start-heatmap-backfill'){$restart=!empty($body['restart']);GreenConfig::json(['ok'=>true,'heatmap_backfill'=>$repo->startHeatmapBackfill($restart),'green'=>$repo->greenSummary()]);}
    if($action==='report-heatmap-failure'){$url=trim((string)($body['url']??''));$status=(int)($body['status']??0);$error=trim((string)($body['error']??'fetch failed'));if($url==='')throw new RuntimeException('Heatmap backfill failure report requires a URL.');$repo->failHeatmapBackfill($url,$status,$error);GreenConfig::json(['ok'=>true,'heatmap_backfill'=>$repo->heatmapBackfillSnapshot()]);}
    if($action==='set-gffl'){$changes=[];if(array_key_exists('enabled',$body))$changes['gffl_enabled']=!empty($body['enabled'])?'1':'0';$state=$changes?$repo->setControl($changes):$repo->state();if(isset($body['target_seconds']))$state=$repo->setGfflTargetSeconds((int)$body['target_seconds']);GreenConfig::json(['ok'=>true,'state'=>$state,'gffl'=>$repo->gfflSnapshot()]);}
    if($action==='run-green-now'){$cfg=GreenConfig::load();$token=(string)($cfg['app']['cron_token']??'');$_GET['token']=$token;require_once dirname(__DIR__).'/src/GreenWorker.php';$worker=new \P2K\Green\GreenWorker($repo);GreenConfig::json($worker->run());}
    if($action==='validate-green'){
        $cutover=$cutoverStatus(null,true);GreenConfig::json(['ok'=>true,'ready'=>(bool)$cutover['clean'],'checks'=>$cutover['validation']['checks'],'comparison'=>$cutover['validation']['comparison'],'comparison_error'=>$cutover['validation']['comparison_error'],'validation'=>$cutover['validation'],'adapter'=>$cutover['adapter'],'cutover'=>$cutover,'read_cutover_ready'=>(bool)$cutover['clean'],'read_cutover_allowed'=>(bool)$cutover['allowed']]);
    }
    GreenConfig::json(['ok'=>false,'error'=>'Unknown migration action.'],404);
}catch(Throwable $e){GreenConfig::json(['ok'=>false,'error'=>$e->getMessage()],500);}
