<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

final class StorageMetricsService
{
    private array $config;
    private ?float $deadlineAt = null;

    public function __construct(private ?PDO $core = null, private ?PDO $analytics = null, ?array $config = null)
    {
        $this->config = $config ?? \p2k_tp_config();
    }

    public function snapshot(bool $persist = true, ?float $deadlineAt = null): array
    {
        $this->deadlineAt=$deadlineAt;
        $core = $this->databaseMetric('core', $this->core);
        $analytics = $this->databaseMetric('analytics', $this->analytics);
        $storage = $this->filesystemMetrics();
        $measured = gmdate('Y-m-d H:i:s');
        $sample = [
            'sample_date'=>gmdate('Y-m-d'),'core_bytes'=>$core['bytes'],'analytics_bytes'=>$analytics['bytes'],
            'cache_bytes'=>$storage['cache']['bytes'],'logs_bytes'=>$storage['logs']['bytes'],'archive_bytes'=>$storage['archive']['bytes'],
            'other_runtime_bytes'=>$storage['other_runtime']['bytes'],'measured_at'=>$measured,
            'core_quota_bytes'=>$core['quota_bytes'],'analytics_quota_bytes'=>$analytics['quota_bytes'],'filesystem_quota_bytes'=>$storage['quota_bytes'],
        ];
        if ($persist && $analytics['available'] && $this->analytics instanceof PDO) $this->persistSample($sample);
        $history = $this->hasTime(0.35) && $analytics['available'] && $this->analytics instanceof PDO ? $this->history() : [];
        $daily = $this->hasTime(0.35) && $analytics['available'] && $this->analytics instanceof PDO ? $this->dailyHistory(180) : [];
        // Capacity forecasts use weekly roll-ups once at least two ISO weeks exist.
        // Daily samples remain the explicitly labelled bootstrap during the first week.
        $projectionRows = count($history) >= 2 ? $history : $daily;
        $projectionBasis = count($history) >= 2 ? 'weekly' : 'daily_bootstrap';
        return [
            'architecture'=>'hybrid-core-analytics-filesystem','measured_at'=>str_replace(' ','T',$measured).'Z',
            'databases'=>['core'=>$core,'analytics'=>$analytics],
            'filesystem'=>$storage,'weekly_history'=>$history,
            'projection'=>[
                'basis'=>$projectionBasis,
                'core'=>$this->projection($projectionRows,'core_bytes',(int)$core['quota_bytes'],(int)($core['bytes']??0),$projectionBasis),
                'analytics'=>$this->projection($projectionRows,'analytics_bytes',(int)$analytics['quota_bytes'],(int)($analytics['bytes']??0),$projectionBasis),
                'filesystem'=>$this->projection($projectionRows,'filesystem_bytes',(int)$storage['quota_bytes'],(int)$storage['total_bytes'],$projectionBasis),
            ],
            'threshold_ratio'=>(float)($this->config['storage']['storage_warning_ratio']??0.80),
        ];
    }

    private function databaseMetric(string $role, ?PDO $pdo): array
    {
        $quota=Database::quotaBytes($role);
        try {
            $pdo ??= Database::named($role);
            if ($role==='core') $this->core=$pdo; else $this->analytics=$pdo;
            $q=$pdo->query("SELECT COALESCE(SUM(data_length+index_length),0) bytes,COUNT(*) tables_count FROM information_schema.tables WHERE table_schema=DATABASE()");
            $row=$q->fetch(PDO::FETCH_ASSOC)?:[];
            $bytes=(int)($row['bytes']??0);$ratio=$quota>0?$bytes/$quota:null;
            return ['available'=>true,'database'=>Database::databaseName($role),'bytes'=>$bytes,'quota_bytes'=>$quota,'ratio'=>$ratio,'percent'=>$ratio===null?null:round(100*$ratio,2),'status'=>$ratio!==null&&$ratio>=($this->config['storage']['storage_warning_ratio']??0.80)?'red':'green','tables'=>(int)($row['tables_count']??0),'error'=>null];
        } catch (\Throwable $e) {
            return ['available'=>false,'database'=>Database::databaseName($role),'bytes'=>null,'quota_bytes'=>$quota,'ratio'=>null,'percent'=>null,'status'=>'unknown','tables'=>null,'error'=>$e->getMessage()];
        }
    }

    private function filesystemMetrics(): array
    {
        $storage=is_array($this->config['storage']??null)?$this->config['storage']:[];
        $root=rtrim((string)($storage['runtime_dir']??''),'/\\');
        if($root==='')$root=dirname(__DIR__,3).'/data/runtime-v280';
        $cache=(string)($storage['cache_dir']??'');if($cache==='')$cache=$root.'/cache/chesscom';
        $logs=(string)($storage['logs_dir']??'');if($logs==='')$logs=$root.'/logs';
        $archive=(string)($storage['archive_dir']??'');if($archive==='')$archive=dirname(__DIR__,3).'/data/archive-v280';
        $cacheStat=$this->dirStats($cache);$logStat=$this->dirStats($logs);$archiveStat=$this->dirStats($archive);$rootStat=$this->dirStats($root);
        $other=max(0,$rootStat['bytes']-$cacheStat['bytes']-$logStat['bytes']);
        $warning=(float)($storage['storage_warning_ratio']??0.80);
        $cacheQuota=max(1,(int)($storage['cache_max_bytes']??536870912));$cacheRatio=$cacheStat['bytes']/$cacheQuota;
        $cacheMaxEntries=max(1000,(int)($storage['cache_max_entries']??30000));$cacheEntryRatio=$cacheStat['files']/$cacheMaxEntries;
        $cacheMetric=['path'=>$cache]+$cacheStat+['quota_bytes'=>$cacheQuota,'ratio'=>$cacheRatio,'percent'=>round(100*$cacheRatio,2),'max_entries'=>$cacheMaxEntries,'entry_ratio'=>$cacheEntryRatio,'entry_percent'=>round(100*$cacheEntryRatio,2),'status'=>max($cacheRatio,$cacheEntryRatio)>=$warning?'red':'green'];
        $quota=max(0,(int)($storage['filesystem_quota_bytes']??0));$total=$rootStat['bytes']+$archiveStat['bytes'];$ratio=$quota>0?$total/$quota:null;
        $siteRoot=dirname(__DIR__,3);$objects=$this->siteObjectStats($siteRoot,$root);$maxFiles=max(0,(int)($storage['filesystem_max_files']??0));$fileWarning=(float)($storage['filesystem_file_warning_ratio']??$warning);$fileRatio=$maxFiles>0?$objects['files']/$maxFiles:null;
        return ['runtime_root'=>$root,'cache'=>$cacheMetric,'logs'=>['path'=>$logs]+$logStat,'archive'=>['path'=>$archive]+$archiveStat,
            'other_runtime'=>['bytes'=>$other,'files'=>max(0,$rootStat['files']-$cacheStat['files']-$logStat['files'])],'total_bytes'=>$total,'quota_bytes'=>$quota,'ratio'=>$ratio,'percent'=>$ratio===null?null:round(100*$ratio,2),
            'status'=>$ratio===null?'unbounded':($ratio>=$warning?'red':'green'),'objects'=>['site_root'=>$siteRoot]+$objects+['max_files'=>$maxFiles,'file_ratio'=>$fileRatio,'file_percent'=>$fileRatio===null?null:round(100*$fileRatio,2),'status'=>$fileRatio===null?'unbounded':($fileRatio>=$fileWarning?'red':'green')]];
    }

    private function dirStats(string $path): array
    {
        if(!is_dir($path))return ['bytes'=>0,'files'=>0,'directories'=>0,'objects'=>0];$bytes=0;$files=0;$directories=1;
        try{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($it as $entry){if(!$this->hasTime(0.1))break;if($entry->isLink())continue;if($entry->isDir()){$directories++;continue;}if(!$entry->isFile())continue;$bytes+=$entry->getSize();$files++;}}catch(\Throwable){}
        return ['bytes'=>$bytes,'files'=>$files,'directories'=>$directories,'objects'=>$files+$directories];
    }

    private function siteObjectStats(string $siteRoot,string $runtimeRoot):array
    {
        $cacheFile=rtrim($runtimeRoot,'/\\').'/.filesystem-object-stats.json';$now=time();if(is_file($cacheFile)&&(int)(@filemtime($cacheFile)?:0)>=$now-900){$row=json_decode((string)@file_get_contents($cacheFile),true);if(is_array($row)&&isset($row['files'],$row['directories']))return $row;}
        if(!$this->hasTime(0.4))return ['files'=>0,'directories'=>0,'objects'=>0,'generated_at'=>gmdate('c'),'partial'=>true];
        $stat=$this->dirStats($siteRoot);$row=['files'=>(int)$stat['files'],'directories'=>(int)$stat['directories'],'objects'=>(int)$stat['objects'],'generated_at'=>gmdate('c')];@file_put_contents($cacheFile,json_encode($row,JSON_UNESCAPED_SLASHES),LOCK_EX);return $row;
    }

    private function persistSample(array $s): void
    {
        try{$q=$this->analytics->prepare("INSERT INTO p2k_an_storage_samples(sample_date,core_bytes,analytics_bytes,cache_bytes,logs_bytes,archive_bytes,other_runtime_bytes,measured_at,core_quota_bytes,analytics_quota_bytes,filesystem_quota_bytes)
            VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE core_bytes=VALUES(core_bytes),analytics_bytes=VALUES(analytics_bytes),cache_bytes=VALUES(cache_bytes),logs_bytes=VALUES(logs_bytes),archive_bytes=VALUES(archive_bytes),other_runtime_bytes=VALUES(other_runtime_bytes),measured_at=VALUES(measured_at),core_quota_bytes=VALUES(core_quota_bytes),analytics_quota_bytes=VALUES(analytics_quota_bytes),filesystem_quota_bytes=VALUES(filesystem_quota_bytes)");
            $q->execute(array_values($s));}catch(\Throwable){}
    }

    private function dailyHistory(int $days): array
    {
        try{$q=$this->analytics->prepare("SELECT sample_date,core_bytes,analytics_bytes,(COALESCE(cache_bytes,0)+COALESCE(logs_bytes,0)+COALESCE(archive_bytes,0)+COALESCE(other_runtime_bytes,0)) filesystem_bytes FROM p2k_an_storage_samples WHERE sample_date>=DATE_SUB(UTC_DATE(),INTERVAL ? DAY) ORDER BY sample_date");$q->bindValue(1,max(2,$days),PDO::PARAM_INT);$q->execute();return $q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(\Throwable){return [];}
    }

    private function history(): array
    {
        $months=max(12,min(240,(int)($this->config['storage']['storage_history_months']??120)));
        try{$q=$this->analytics->prepare("SELECT s.sample_date,s.core_bytes,s.analytics_bytes,s.cache_bytes,s.logs_bytes,s.archive_bytes,s.other_runtime_bytes,DATE_FORMAT(s.sample_date,'%x-W%v') iso_week FROM p2k_an_storage_samples s JOIN (SELECT DATE_FORMAT(sample_date,'%x-W%v') yw,MAX(sample_date) d FROM p2k_an_storage_samples WHERE sample_date>=DATE_SUB(UTC_DATE(),INTERVAL ? MONTH) GROUP BY yw) x ON x.d=s.sample_date ORDER BY s.sample_date");$q->bindValue(1,$months,PDO::PARAM_INT);$q->execute();$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$rows[]=['week'=>(string)$r['iso_week'],'date'=>(string)$r['sample_date'],'core_bytes'=>(int)$r['core_bytes'],'analytics_bytes'=>(int)$r['analytics_bytes'],'filesystem_bytes'=>(int)$r['cache_bytes']+(int)$r['logs_bytes']+(int)$r['archive_bytes']+(int)$r['other_runtime_bytes']];return $rows;}catch(\Throwable){return [];}
    }

    private function projection(array $rows,string $key,int $quota,int $current,string $basis='weekly'): array
    {
        if($quota<=0)return ['available'=>false,'reason'=>'quota_not_configured','basis'=>$basis,'growth_bytes_per_day'=>null,'reaches_80_at'=>null,'reaches_100_at'=>null];
        $points=[];foreach($rows as $r){if(!isset($r[$key])||$r[$key]===null)continue;$date=(string)($r['sample_date']??$r['date']??'');$t=strtotime($date.' UTC');if($t===false)continue;$points[]=[$t/86400.0,(float)$r[$key]];}
        if(count($points)<2)return ['available'=>false,'reason'=>'collecting_baseline','basis'=>$basis,'samples'=>count($points),'growth_bytes_per_day'=>null,'reaches_80_at'=>$current>=.8*$quota?gmdate('Y-m-d'):null,'reaches_100_at'=>$current>=$quota?gmdate('Y-m-d'):null];
        $n=count($points);$sx=$sy=$sxx=$sxy=0.0;foreach($points as [$x,$y]){$sx+=$x;$sy+=$y;$sxx+=$x*$x;$sxy+=$x*$y;}$den=$n*$sxx-$sx*$sx;$slope=abs($den)<1e-9?0.0:($n*$sxy-$sx*$sy)/$den;$slope=max(0.0,$slope);
        $dateFor=function(float $ratio)use($quota,$current,$slope):?string{$target=$quota*$ratio;if($current>=$target)return gmdate('Y-m-d');if($slope<=0)return null;$days=($target-$current)/$slope;if($days>36500)return null;return gmdate('Y-m-d',time()+(int)ceil($days)*86400);};
        return ['available'=>$slope>0,'reason'=>$slope>0?null:'non_growing_baseline','basis'=>$basis,'samples'=>$n,'growth_bytes_per_day'=>(int)round($slope),'growth_bytes_per_week'=>(int)round($slope*7),'reaches_80_at'=>$dateFor(.8),'reaches_100_at'=>$dateFor(1.0)];
    }
    private function hasTime(float $reserveSeconds = 0.0): bool
    {
        return $this->deadlineAt===null || microtime(true)+max(0.0,$reserveSeconds)<$this->deadlineAt;
    }

}
