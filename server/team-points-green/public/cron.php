<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use P2K\Green\GreenConfig;
use P2K\Green\GreenWorker;
try{
    GreenConfig::authorizeCron();
    $minute=(int)gmdate('i');
    $worker=new GreenWorker(null,55,50,[
        'feeder_id'=>'gscf-main',
        'schedule_minute'=>$minute,
        'metric_source'=>'gscf',
    ]);
    $result=$worker->run();
    $result['gscf']=['feeder_id'=>'gscf-main','schedule_minute'=>$minute,'schedule'=>'every minute via 5 interlaced /5 CRON entries','soft_target_seconds'=>(int)($result['telemetry']['soft_target_seconds']??0),'hard_budget_seconds'=>(int)($result['telemetry']['hard_budget_seconds']??0)];
    GreenConfig::json($result);
}catch(Throwable $e){GreenConfig::json(['ok'=>false,'status'=>'error','error'=>$e->getMessage(),'gscf'=>['feeder_id'=>'gscf-main','schedule_minute'=>(int)gmdate('i')]],500);}
