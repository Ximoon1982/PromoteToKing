<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/ScheduledTaskLogger.php';

use P2K\Shared\ScheduledTaskLogger;
use P2K\Shared\TaskRegistry;
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\CronLoop;
use P2K\TeamPoints\CronMaintenanceCoordinator;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\Worker;

$lane = defined('P2K_TP_CRON_LANE') ? (string)P2K_TP_CRON_LANE : 'club';
$lane = in_array($lane, ['club','player'], true) ? $lane : 'club';
$taskKey = defined('P2K_TP_TASK_KEY') ? (string)P2K_TP_TASK_KEY : 'team-points-club';
$cronStateKey = defined('P2K_TP_CRON_STATE_KEY') ? (string)P2K_TP_CRON_STATE_KEY : ('team-points-' . $lane . '-continuous');
$repository = null; $registry = null;
$runId = ScheduledTaskLogger::runId($taskKey);
$startedAt = gmdate('c'); $started = microtime(true); $authenticated = false;
try {
    $config = p2k_tp_config();
    Auth::requireCron(trim((string)($_SERVER['HTTP_X_P2K_CRON_TOKEN'] ?? ($_GET['token'] ?? '')))); $authenticated = true;
    $pdo = Database::core(); $analytics = Database::analytics();
    $repository = new Repository($pdo, $analytics);
    if (!$repository->schemaInstalled()) $repository->upgradeExistingSchema();
    if (!$repository->schemaInstalled()) throw new ApiException('The v2.8 Core/Analytics databases are not initialized or upgraded.',503,'SCHEMA_NOT_INSTALLED');
    $registry = new TaskRegistry($pdo);
    if ($registry->isPaused($taskKey)) Http::json(['ok'=>true,'status'=>'paused','lane'=>$lane,'processed_items'=>0,'message'=>ucfirst($lane).' Points is paused.','server_controlled'=>true]);
    $chainId=p2k_tp_uuid();
    $worker=new Worker($pdo,$repository,new ChessApi($repository),$lane);
    $loop=new CronLoop($repository,$worker,$lane);
    ignore_user_abort(true); @set_time_limit($loop->maxSeconds()+5);
    if(!$repository->acquireCronChain($cronStateKey,$chainId,$loop->leaseSeconds())) Http::json(['ok'=>true,'status'=>'scheduler_busy','lane'=>$lane,'processed_items'=>0,'message'=>'Another '.ucfirst($lane).' Points invocation owns the lease.','server_controlled'=>true]);
    if(!$registry->beginRun($taskKey,'cron',$runId)){
        $reason=$registry->lastBeginReason();
        $repository->finishCronInvocation($cronStateKey,$chainId,$loop->nextDelaySeconds(),$reason==='paused'?'paused':'scheduler_busy',$reason==='paused'?'Task paused.':'Task busy.',$loop->leaseSeconds());
        Http::json(['ok'=>true,'status'=>$reason==='paused'?'paused':'scheduler_busy','lane'=>$lane,'processed_items'=>0,'message'=>$reason==='paused'?'Task paused.':'Another execution is active.','server_controlled'=>true]);
    }
    // CMDI: canonical queue work owns the primary request budget and always runs first.
    // Optional maintenance is isolated into independent local deadline slices afterward.
    $requestDeadlineAt=$started+$loop->maxSeconds()-2.0;
    $workerBudget=max(5,min($loop->workerSeconds(),(int)floor($requestDeadlineAt-microtime(true)-4.0)));
    $result=$loop->execute($chainId,'cron',$workerBudget);
    $maintenance=(new CronMaintenanceCoordinator($pdo,$analytics,$repository,$config,$lane,$requestDeadlineAt))->run();
    $repository->finishCronInvocation($cronStateKey,$chainId,$loop->nextDelaySeconds(),(string)$result['status'],(string)$result['message'],$loop->leaseSeconds());
    $raw=(string)($result['status']??'partial');
    $final=($result['ok']??false)!==true?'failed':(in_array($raw,['completed','success'],true)?'success':($raw==='failed'?'failed':'partial'));
    $processed=(int)($result['processed_items']??0);
    $freshness=is_array($result['freshness']??null)?$result['freshness']:[];$lastWorker=is_array($result['last_worker_result']??null)?$result['last_worker_result']:[];
    $registry->finishRun($taskKey,$runId,$final,['processed'=>$processed,'updated'=>$processed,'failed'=>$final==='failed'?1:0],(string)($result['message']??'CRON segment completed.'),[
        'lane'=>$lane,'external_cron'=>true,'execution_window_seconds'=>$loop->maxSeconds(),'worker_segment_seconds'=>(int)($result['worker_segment_seconds']??0),'next_expected_seconds'=>$loop->nextDelaySeconds(),
        'worker_status'=>(string)($result['status']??'unknown'),'worker_processed_items'=>$processed,'worker_idle_reason'=>$lastWorker['idle_reason']??null,'worker_job_status'=>$lastWorker['job_status']??($lastWorker['job']['status']??null),
        'freshness_work_queued'=>(bool)($freshness[$lane==='club'?'club_index_queued':'roster_queued']??false),'freshness_age_seconds'=>$freshness[$lane==='club'?'club_index_age_seconds':'roster_age_seconds']??null,
        'maintenance'=>$maintenance
    ]);
    ScheduledTaskLogger::append(['taskType'=>'database-update','taskId'=>$taskKey,'runId'=>$runId,'source'=>'cron','status'=>$final,'startedAt'=>$startedAt,'endedAt'=>gmdate('c'),'processedItems'=>$processed,'updatedItems'=>$processed,'failedItems'=>$final==='failed'?1:0,'durationMs'=>(int)round((microtime(true)-$started)*1000),'message'=>(string)($result['message']??'CRON segment completed.'),'details'=>['lane'=>$lane,'serverControlled'=>true,'maintenance'=>$maintenance]]);
    Http::json($result+['lane'=>$lane,'server_controlled'=>true,'self_scheduling'=>false,'external_schedule_expected_seconds'=>$loop->nextDelaySeconds(),'maintenance'=>$maintenance]);
}catch(ApiException $exception){
    if($registry instanceof TaskRegistry){try{$registry->finishRun($taskKey,$runId,'failed',['failed'=>1],$exception->getMessage(),['lane'=>$lane,'errorCode'=>$exception->errorCode]);}catch(Throwable){}}
    Http::json(['ok'=>false,'lane'=>$lane,'error'=>['code'=>$exception->errorCode,'message'=>$exception->getMessage()]],$exception->httpStatus);
}catch(Throwable $exception){
    error_log('P2K '.ucfirst($lane).' Points cron: '.$exception);
    if($registry instanceof TaskRegistry){try{$registry->finishRun($taskKey,$runId,'failed',['failed'=>1],$exception->getMessage(),['lane'=>$lane,'exception'=>get_class($exception)]);}catch(Throwable){}}
    Http::json(['ok'=>false,'lane'=>$lane,'error'=>['code'=>'SERVER_ERROR','message'=>$exception->getMessage()]],500);
}
