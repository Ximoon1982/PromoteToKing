<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\FilesystemCache;
use P2K\Shared\FilesystemRetention;
use PDO;

/** Bounded-retention cleanup. Safe to call from every CRON; it self-throttles. */
final class Housekeeping
{
    private ?float $deadlineAt = null;

    public function __construct(private PDO $core, private ?PDO $analytics = null, private ?array $config = null) {}

    public function runIfDue(int $minimumSeconds = 21600, ?float $deadlineAt = null): array
    {
        $this->deadlineAt=$deadlineAt;
        if(!$this->canContinue(0.5))return ['ran'=>false,'deferred'=>true,'reason'=>'cmdi_deadline'];
        $storage=($this->config ?? \p2k_tp_config())['storage']??[];
        $root=FilesystemCache::runtimeRoot(is_array($storage)?$storage:[]);
        FilesystemCache::ensureProtectedDirectory($root);
        $marker=$root.'/housekeeping-state.json';$last=0;
        if(is_file($marker)){try{$payload=json_decode((string)@file_get_contents($marker),true);$last=(int)($payload['completed_epoch']??0);}catch(\Throwable){}}
        if($last>0&&time()-$last<max(300,$minimumSeconds))return ['ran'=>false,'last_epoch'=>$last];
        $result=$this->run($deadlineAt);
        if(!empty($result['deadline_reached']))return ['ran'=>true,'partial'=>true]+$result;
        @file_put_contents($marker,json_encode(['completed_epoch'=>time(),'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
        return ['ran'=>true]+$result;
    }

    public function run(?float $deadlineAt = null): array
    {
        if($deadlineAt!==null)$this->deadlineAt=$deadlineAt;
        $cfg=$this->config ?? \p2k_tp_config();$storage=is_array($cfg['storage']??null)?$cfg['storage']:[];
        $jobDays=max(1,(int)($storage['job_retention_days']??14));$failedDays=max($jobDays,(int)($storage['failed_job_retention_days']??30));$logDays=max(1,(int)($storage['log_retention_days']??30));
        $deleted=[];
        foreach([
            ['job_logs',"DELETE FROM p2k_tp_job_logs WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$logDays} DAY)"],
            ['worker_runs',"DELETE FROM p2k_tp_worker_runs WHERE started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$logDays} DAY)"],
            ['task_logs',"DELETE FROM p2k_control_task_logs WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$logDays} DAY)"],
            ['task_runs',"DELETE FROM p2k_control_task_runs WHERE started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$logDays} DAY) AND status IN ('success','partial','failed','cancelled')"],
            ['job_items_done',"DELETE FROM p2k_tp_job_items WHERE status IN ('done','skipped') AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$jobDays} DAY)"],
            ['job_items_failed',"DELETE FROM p2k_tp_job_items WHERE status='failed' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$failedDays} DAY)"],
            ['repair_history',"DELETE FROM p2k_tp_consistency_repairs WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)"],
        ] as [$key,$sql]){if(!$this->canContinue(0.35))return ['deleted'=>$deleted,'deadline_reached'=>true,'stopped_before'=>$key];try{$n=$this->core->exec($sql);$deleted[$key]=(int)$n;}catch(\Throwable $e){$deleted[$key]='error: '.$e->getMessage();}}
        // Jobs are only removed when none of their queue items remain.
        if(!$this->canContinue(0.35))return ['deleted'=>$deleted,'deadline_reached'=>true,'stopped_before'=>'jobs'];
        try{$n=$this->core->exec("DELETE j FROM p2k_tp_jobs j LEFT JOIN p2k_tp_job_items i ON i.job_id=j.id WHERE i.id IS NULL AND j.status IN ('completed','failed','cancelled') AND j.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$jobDays} DAY)");$deleted['jobs']=(int)$n;}catch(\Throwable $e){$deleted['jobs']='error: '.$e->getMessage();}
        if(!$this->canContinue(0.5))return ['deleted'=>$deleted,'deadline_reached'=>true,'stopped_before'=>'queue_deduplication'];
        $dedupe=['ran'=>false];
        try{
            $repo=new Repository($this->core,$this->analytics);
            if($repo->schemaVersion()>=13){$result=$repo->compactOutstandingQueue(null,1000000);$dedupe=['ran'=>(int)($result['legacy_before']??0)>0]+$result;}
        }catch(\Throwable $e){$dedupe=['ran'=>false,'error'=>$e->getMessage()];}
        if(!$this->canContinue(0.75))return ['deleted'=>$deleted,'queue_deduplication'=>$dedupe,'deadline_reached'=>true,'stopped_before'=>'cache'];
        $cache=(new FilesystemCache($storage))->purge(50000);
        if(!$this->canContinue(0.5))return ['deleted'=>$deleted,'queue_deduplication'=>$dedupe,'cache'=>$cache,'deadline_reached'=>true,'stopped_before'=>'public_response_cache'];
        $publicCache=(new ResponseCache($storage))->purge(14*86400,(int)($storage['public_response_cache_max_bytes']??134217728),(int)($storage['public_response_cache_max_entries']??2000));
        if(!$this->canContinue(0.75))return ['deleted'=>$deleted,'queue_deduplication'=>$dedupe,'cache'=>$cache,'public_response_cache'=>$publicCache,'deadline_reached'=>true,'stopped_before'=>'filesystem_logs'];
        $logs=$this->purgeOldFiles(FilesystemCache::resolveLogsDir($storage),$logDays);
        $runtime=FilesystemCache::runtimeRoot($storage);
        if(!$this->canContinue(1.0))return ['deleted'=>$deleted,'queue_deduplication'=>$dedupe,'cache'=>$cache,'public_response_cache'=>$publicCache,'filesystem_logs'=>$logs,'deadline_reached'=>true,'stopped_before'=>'retention_tail'];
        $legacyObservationRates=$this->purgePattern($runtime.'/browser-observations','rate-*.txt',20000);
        try{$claims=(new AcamrClaimStore($storage))->cleanup(50000,50000,(int)($storage['acamr_member_lease_retention_seconds']??86400),512);}catch(\Throwable $e){$claims=['error'=>$e->getMessage()];}
        try{$matchRetention=\P2K\Shared\MatchTrackingRetention::pruneRoot(dirname(__DIR__,3).'/data/match-tracking/matches',[
            'recent_days'=>(int)($storage['match_tracking_recent_days']??7),'dense_days'=>(int)($storage['match_tracking_dense_days']??30),
            'daily_days'=>(int)($storage['match_tracking_daily_days']??180),'hard_cap'=>(int)($storage['match_tracking_max_snapshots_per_match']??1200),
        ],200);}catch(\Throwable $e){$matchRetention=['error'=>$e->getMessage()];}
        try{$telemetryRetention=FilesystemRetention::pruneFiles($runtime.'/telemetry','*.jsonl',(int)($storage['telemetry_retention_days']??30),(int)($storage['telemetry_max_files']??35));}catch(\Throwable $e){$telemetryRetention=['error'=>$e->getMessage()];}
        try{$reconciliationRetention=FilesystemRetention::pruneDirectories($runtime.'/reconciliation',(int)($storage['reconciliation_retention_days']??365),(int)($storage['reconciliation_max_batches']??250));}catch(\Throwable $e){$reconciliationRetention=['error'=>$e->getMessage()];}
        try{$intelligenceRetention=FilesystemRetention::pruneFiles($runtime.'/intelligence/snapshots','*.json',(int)($storage['intelligence_snapshot_retention_days']??730),(int)($storage['intelligence_snapshot_max_files']??730));}catch(\Throwable $e){$intelligenceRetention=['error'=>$e->getMessage()];}
        $siteRoot=dirname(__DIR__,3);
        try{$scheduledTaskFileRetention=FilesystemRetention::pruneFiles($siteRoot.'/logs/scheduled-tasks','*.jsonl',(int)($storage['scheduled_task_file_retention_days']??30),(int)($storage['scheduled_task_file_max_files']??35));}catch(\Throwable $e){$scheduledTaskFileRetention=['error'=>$e->getMessage()];}
        try{$trafficRetention=FilesystemRetention::pruneFiles($siteRoot.'/data/traffic/aggregates','*.json',(int)($storage['traffic_analytics_retention_days']??400),(int)($storage['traffic_analytics_max_files']??400));}catch(\Throwable $e){$trafficRetention=['error'=>$e->getMessage()];}
        try{$freshInitNonceRetention=FilesystemRetention::pruneFiles($runtime.'/fresh-init/nonces','[a-f0-9]*',(int)($storage['fresh_init_nonce_retention_days']??2),(int)($storage['fresh_init_nonce_max_files']??500));}catch(\Throwable $e){$freshInitNonceRetention=['error'=>$e->getMessage()];}
        try{$freshInitCoverageRetention=FilesystemRetention::pruneDirectories($runtime.'/fresh-init/coverage',(int)($storage['fresh_init_coverage_retention_days']??90),(int)($storage['fresh_init_coverage_max_runs']??20));}catch(\Throwable $e){$freshInitCoverageRetention=['error'=>$e->getMessage()];}
        try{$tournamentBackupRetention=FilesystemRetention::pruneFiles($siteRoot.'/data/tournaments/backups','archive-*.json',(int)($storage['tournament_backup_retention_days']??3650),(int)($storage['tournament_backup_max_files']??100));}catch(\Throwable $e){$tournamentBackupRetention=['error'=>$e->getMessage()];}
        return ['deleted'=>$deleted,'queue_deduplication'=>$dedupe,'cache'=>$cache,'public_response_cache'=>$publicCache,'filesystem_logs'=>$logs,'legacy_observation_rate_files'=>$legacyObservationRates,'acamr_claims'=>$claims,'match_tracking_retention'=>$matchRetention,'telemetry_retention'=>$telemetryRetention,'reconciliation_retention'=>$reconciliationRetention,'intelligence_snapshot_retention'=>$intelligenceRetention,'scheduled_task_file_retention'=>$scheduledTaskFileRetention,'traffic_analytics_retention'=>$trafficRetention,'fresh_init_nonce_retention'=>$freshInitNonceRetention,'fresh_init_coverage_retention'=>$freshInitCoverageRetention,'tournament_backup_retention'=>$tournamentBackupRetention];
    }

    private function purgeOldFiles(string $dir,int $days): array
    {
        if(!is_dir($dir))return ['removed_files'=>0,'removed_bytes'=>0];$cut=time()-$days*86400;$files=0;$bytes=0;
        try{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if(!$this->canContinue(0.1))break;if($f->isFile()&&$f->getMTime()<$cut){$size=$f->getSize();if(@unlink($f->getPathname())){$files++;$bytes+=$size;}}elseif($f->isDir())@rmdir($f->getPathname());}}catch(\Throwable){}
        return ['removed_files'=>$files,'removed_bytes'=>$bytes];
    }
    private function purgePattern(string $dir,string $pattern,int $limit):array
    {
        if(!is_dir($dir))return ['removed_files'=>0,'remaining_hint'=>0];$removed=0;$seen=0;foreach(glob(rtrim($dir,'/\\').'/'.$pattern)?:[] as $path){if(!$this->canContinue(0.1))break;$seen++;if($removed>=max(1,$limit))continue;if(is_file($path)&&@unlink($path))$removed++;}return ['removed_files'=>$removed,'remaining_hint'=>max(0,$seen-$removed)];
    }

    private function canContinue(float $reserveSeconds = 0.0): bool
    {
        return $this->deadlineAt===null || microtime(true)+max(0.0,$reserveSeconds)<$this->deadlineAt;
    }

}
