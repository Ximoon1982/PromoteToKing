<?php
declare(strict_types=1);

namespace P2K\Shared;

/**
 * Small fixed-window limiter backed by one protected JSON file.
 *
 * The previous observation limiter created one file per subject per minute. This
 * implementation keeps all active subjects in one locked state file and prunes
 * idle subjects on every mutation, so filesystem-object growth is O(1).
 */
final class BoundedRateLimiter
{
    public function __construct(
        private readonly string $stateFile,
        private readonly int $maxSubjects = 2048,
        private readonly int $subjectTtlSeconds = 7200,
    ) {
        FilesystemCache::ensureProtectedDirectory(dirname($this->stateFile));
    }

    /** @return array{allowed:bool,count:int,limit:int,window_seconds:int,retry_after_seconds:int,subjects:int} */
    public function consume(string $subject, int $limit, int $windowSeconds = 60, int $weight = 1): array
    {
        $limit=max(1,$limit);$windowSeconds=max(1,$windowSeconds);$weight=max(1,$weight);$now=time();
        $subjectHash=hash('sha256',$subject);$slot=(int)floor($now/$windowSeconds);
        $handle=@fopen($this->stateFile,'c+');
        if(!is_resource($handle))throw new \RuntimeException('Unable to open bounded rate-limit state.');
        try{
            if(!@flock($handle,LOCK_EX))throw new \RuntimeException('Unable to lock bounded rate-limit state.');
            rewind($handle);$raw=stream_get_contents($handle);$state=is_string($raw)&&trim($raw)!==''?json_decode($raw,true):null;
            if(!is_array($state))$state=['format'=>1,'subjects'=>[]];
            $subjects=is_array($state['subjects']??null)?$state['subjects']:[];$cut=$now-max($windowSeconds*2,$this->subjectTtlSeconds);
            foreach($subjects as $key=>$row){if(!is_array($row)||(int)($row['seen']??0)<$cut)unset($subjects[$key]);}
            $row=is_array($subjects[$subjectHash]??null)?$subjects[$subjectHash]:[];
            $count=(int)($row['slot']??-1)===$slot?(int)($row['count']??0):0;
            $allowed=$count+$weight<=$limit;
            if($allowed)$count+=$weight;
            $subjects[$subjectHash]=['slot'=>$slot,'count'=>$count,'seen'=>$now];
            if(count($subjects)>max(16,$this->maxSubjects)){
                uasort($subjects,static fn(array $a,array $b):int=>(int)($a['seen']??0)<=>(int)($b['seen']??0));
                while(count($subjects)>$this->maxSubjects)array_shift($subjects);
            }
            $state=['format'=>1,'updated_at'=>$now,'subjects'=>$subjects];
            $json=json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            ftruncate($handle,0);rewind($handle);if(fwrite($handle,$json)===false)throw new \RuntimeException('Unable to persist bounded rate-limit state.');fflush($handle);
            @chmod($this->stateFile,0600);
            $retry=max(1,(($slot+1)*$windowSeconds)-$now);
            return ['allowed'=>$allowed,'count'=>$count,'limit'=>$limit,'window_seconds'=>$windowSeconds,'retry_after_seconds'=>$allowed?0:$retry,'subjects'=>count($subjects)];
        }finally{@flock($handle,LOCK_UN);@fclose($handle);}
    }
}
