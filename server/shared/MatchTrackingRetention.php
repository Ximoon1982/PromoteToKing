<?php
declare(strict_types=1);

namespace P2K\Shared;

/** Tiered retention for legacy match-monitoring JSON snapshots. */
final class MatchTrackingRetention
{
    /** @return array{before:int,after:int,removed:int} */
    public static function pruneMatchDirectory(string $dir,array $config=[]):array
    {
        $files=glob(rtrim($dir,'/\\').'/*.json')?:[];sort($files,SORT_STRING);$before=count($files);
        if($before<=max(32,(int)($config['min_before_prune']??128)))return ['before'=>$before,'after'=>$before,'removed'=>0];
        $now=time();$recent=max(1,(int)($config['recent_days']??7))*86400;$dense=max($recent,(int)($config['dense_days']??30)*86400);$daily=max($dense,(int)($config['daily_days']??180)*86400);$hard=max(256,(int)($config['hard_cap']??1200));
        $keep=[];$bucketSeen=[];$previousStatus=null;$transitionFiles=[];
        foreach($files as $i=>$path){$mtime=(int)(@filemtime($path)?:0);if($mtime<=0)$mtime=self::timestampFromName(basename($path))?:$now;$age=max(0,$now-$mtime);
            if($age<=$recent){$keep[$path]=true;}
            else{$span=$age<=$dense?21600:($age<=$daily?86400:604800);$bucket=(int)floor($mtime/$span);$key=$span.':'.$bucket;if(!isset($bucketSeen[$key])){$bucketSeen[$key]=true;$keep[$path]=true;}}
            $raw=@file_get_contents($path);$row=is_string($raw)&&$raw!==''?json_decode($raw,true):null;$status=is_array($row)?strtolower((string)($row['match']['status']??'')):'';
            if($previousStatus!==null&&$status!==''&&$status!==$previousStatus){$keep[$path]=true;$transitionFiles[$path]=true;}if($status!=='')$previousStatus=$status;
        }
        if($files!==[]){$keep[$files[0]]=true;$keep[$files[count($files)-1]]=true;}
        $kept=array_keys($keep);sort($kept,SORT_STRING);
        if(count($kept)>$hard){$protected=[$files[0]=>true,$files[count($files)-1]=>true]+$transitionFiles;$drop=count($kept)-$hard;foreach($kept as $path){if($drop<=0)break;if(isset($protected[$path]))continue;unset($keep[$path]);$drop--;}}
        $removed=0;foreach($files as $path){if(isset($keep[$path]))continue;if(@unlink($path))$removed++;}
        return ['before'=>$before,'after'=>$before-$removed,'removed'=>$removed];
    }

    /** @return array{directories:int,removed:int,before:int,after:int} */
    public static function pruneRoot(string $root,array $config=[],int $directoryLimit=100):array
    {
        if(!is_dir($root))return ['directories'=>0,'removed'=>0,'before'=>0,'after'=>0];$dirs=glob(rtrim($root,'/\\').'/*',GLOB_ONLYDIR)?:[];sort($dirs,SORT_STRING);$dirs=array_slice($dirs,0,max(1,$directoryLimit));$out=['directories'=>0,'removed'=>0,'before'=>0,'after'=>0];
        foreach($dirs as $dir){$r=self::pruneMatchDirectory($dir,$config);$out['directories']++;$out['removed']+=$r['removed'];$out['before']+=$r['before'];$out['after']+=$r['after'];}
        return $out;
    }

    private static function timestampFromName(string $name):int
    {
        if(!preg_match('/^(\d{8}T\d{6})/',$name,$m))return 0;$dt=\DateTimeImmutable::createFromFormat('Ymd\\THis',$m[1],new \DateTimeZone('UTC'));return $dt?$dt->getTimestamp():0;
    }
}
