<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use P2K\Shared\FilesystemCache;

/** Small filesystem cache for public materialized read models. */
final class ResponseCache
{
    private string $root;
    private int $maxEntries;
    private int $maxBytes;

    public function __construct(array $storage = [])
    {
        $this->root=FilesystemCache::runtimeRoot($storage).'/public-response-cache';
        $this->maxEntries=max(100,(int)($storage['public_response_cache_max_entries']??2000));
        $this->maxBytes=max(1048576,(int)($storage['public_response_cache_max_bytes']??134217728));
        FilesystemCache::ensureProtectedDirectory($this->root);
    }

    /** @return array{payload:array,etag:string,cached:bool,generated_epoch:int,stale?:bool} */
    public function remember(string $key,int $ttlSeconds,callable $builder,int $staleSeconds=300):array
    {
        $ttlSeconds=max(1,min(2592000,$ttlSeconds));$staleSeconds=max(0,min(15552000,$staleSeconds));
        $path=$this->pathFor($key);$now=time();$stored=$this->read($path);
        if($this->fresh($stored,$now))return $this->result($stored,true,false,$now);

        $lockPath=$path.'.lock';$lock=@fopen($lockPath,'c');$owns=false;
        if(is_resource($lock))$owns=@flock($lock,LOCK_EX|LOCK_NB);
        if(!$owns){
            if($this->staleUsable($stored,$now))return $this->result($stored,true,true,$now);
            // Briefly allow the winning request to populate the entry, then reuse it.
            for($i=0;$i<10;$i++){
                usleep(50000);$candidate=$this->read($path);
                if($this->fresh($candidate,time())){if(is_resource($lock))fclose($lock);return $this->result($candidate,true,false,time());}
            }
            if(is_resource($lock))$owns=@flock($lock,LOCK_EX);
        }

        try{
            // Another request may have filled it while we waited for the lock.
            $candidate=$this->read($path);$now=time();
            if($this->fresh($candidate,$now))return $this->result($candidate,true,false,$now);
            try{
                $payload=$builder();if(!is_array($payload))throw new \RuntimeException('Public response cache builders must return an array.');
                $etag=$this->etag($payload);$record=['format'=>2,'key_hash'=>hash('sha256',$key),'generated_epoch'=>$now,'expires_epoch'=>$now+$ttlSeconds,'stale_until_epoch'=>$now+$ttlSeconds+$staleSeconds,'etag'=>$etag,'payload'=>$payload];
                $this->write($path,$record);
                if(random_int(1,20)===1)$this->purge(14*86400,$this->maxBytes,$this->maxEntries);
                return ['payload'=>$payload,'etag'=>$etag,'cached'=>false,'generated_epoch'=>$now,'stale'=>false];
            }catch(\Throwable $exception){
                $fallback=is_array($candidate)?$candidate:$stored;
                if($this->staleUsable($fallback,$now))return $this->result($fallback,true,true,$now);
                throw $exception;
            }
        }finally{
            if(is_resource($lock)){if($owns)@flock($lock,LOCK_UN);fclose($lock);}
            if(is_file($lockPath)&&filesize($lockPath)===0)@unlink($lockPath);
        }
    }

    /** Remove expired/old entries and bound cache disk usage without decoding payloads. */
    public function purge(int $maxAgeSeconds=1209600,int $maxBytes=134217728,int $maxEntries=2000):array
    {
        $now=time();$entries=[];$deleted=0;$bytes=0;
        foreach(glob($this->root.'/*.json.gz')?:[] as $file){
            if(!is_file($file))continue;$mtime=(int)(@filemtime($file)?:0);$size=(int)(@filesize($file)?:0);
            if($mtime>0&&$mtime<$now-max(3600,$maxAgeSeconds)){@unlink($file);$deleted++;continue;}
            $entries[]=['file'=>$file,'mtime'=>$mtime,'size'=>$size];$bytes+=$size;
        }
        $maxEntries=max(100,$maxEntries);$remaining=count($entries);
        if($bytes>$maxBytes||$remaining>$maxEntries){usort($entries,static fn(array $a,array $b):int=>$a['mtime']<=>$b['mtime']);foreach($entries as $entry){if($bytes<=$maxBytes&&$remaining<=$maxEntries)break;if(@unlink($entry['file'])){$deleted++;$bytes-=$entry['size'];$remaining--;}}}
        foreach(glob($this->root.'/*.lock')?:[] as $lock)if((int)(@filemtime($lock)?:0)<$now-3600)@unlink($lock);
        return ['deleted'=>$deleted,'bytes'=>max(0,$bytes),'entries'=>max(0,$remaining),'max_entries'=>$maxEntries];
    }

    private function fresh(?array $stored,int $now):bool{return is_array($stored)&&(int)($stored['expires_epoch']??0)>=$now&&is_array($stored['payload']??null);}
    private function staleUsable(?array $stored,int $now):bool{return is_array($stored)&&(int)($stored['stale_until_epoch']??0)>=$now&&is_array($stored['payload']??null);}
    private function result(array $stored,bool $cached,bool $stale,int $now):array{return ['payload'=>$stored['payload'],'etag'=>(string)($stored['etag']??$this->etag($stored['payload'])),'cached'=>$cached,'generated_epoch'=>(int)($stored['generated_epoch']??$now),'stale'=>$stale];}
    private function pathFor(string $key):string{return $this->root.'/'.hash('sha256',$key).'.json.gz';}
    private function read(string $path):?array{if(!is_file($path))return null;$raw=@file_get_contents($path);if($raw===false||$raw==='')return null;$json=@gzdecode($raw);if($json===false)return null;$decoded=json_decode($json,true);return is_array($decoded)?$decoded:null;}
    private function write(string $path,array $record):void{$json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$compressed=gzencode($json,5);if($compressed===false)throw new \RuntimeException('Unable to compress public response cache entry.');$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$compressed,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Unable to write public response cache entry.');}}
    private function etag(array $payload):string{return '"'.hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'"';}
}
