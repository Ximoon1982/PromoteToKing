<?php
declare(strict_types=1);

namespace P2K\Shared;

final class MatchAssistantUsage
{
    public static function report(string $root,int $days=90,int $maxEntries=2000): array
    {
        $days=max(1,min(366,$days));
        $maxEntries=max(1,min(5000,$maxEntries));
        $tz=new \DateTimeZone('UTC');
        $to=new \DateTimeImmutable('today',$tz);
        $from=$to->modify('-'.($days-1).' days');
        $daily=[];
        for($date=$from;$date<=$to;$date=$date->modify('+1 day')){
            $key=$date->format('Y-m-d');
            $daily[$key]=['date'=>$key,'analyses'=>0,'matchesFound'=>0,'users'=>[]];
        }
        $entries=[];$users=[];$invalid=0;
        $dir=rtrim($root,'/').'/logs/match-assistant';
        for($date=$from;$date<=$to;$date=$date->modify('+1 day')){
            $day=$date->format('Y-m-d');$path=$dir.'/'.$day.'.jsonl';
            if(!is_file($path))continue;
            $handle=@fopen($path,'rb');if(!$handle){$invalid++;continue;}
            while(($line=fgets($handle))!==false){
                if(trim($line)==='')continue;
                $record=json_decode($line,true);if(!is_array($record)){$invalid++;continue;}
                $username=strtolower(trim((string)($record['username']??'')));
                $count=filter_var($record['matchesFound']??null,FILTER_VALIDATE_INT);
                $timestamp=(string)($record['timestamp']??'');
                if($username===''||strlen($username)>128||$count===false||$count<0||$timestamp===''){$invalid++;continue;}
                $entries[]=['timestamp'=>$timestamp,'username'=>$username,'matchesFound'=>(int)$count];
                $daily[$day]['analyses']++;$daily[$day]['matchesFound']+=(int)$count;$daily[$day]['users'][$username]=true;
                if(!isset($users[$username]))$users[$username]=['username'=>$username,'analyses'=>0,'matchesFound'=>0,'lastSearchAt'=>''];
                $users[$username]['analyses']++;$users[$username]['matchesFound']+=(int)$count;
                if(strcmp($timestamp,(string)$users[$username]['lastSearchAt'])>0)$users[$username]['lastSearchAt']=$timestamp;
            }
            fclose($handle);
        }
        usort($entries,static fn(array $a,array $b):int=>strcmp((string)$b['timestamp'],(string)$a['timestamp']));
        $dailyRows=array_values(array_map(static fn(array $row):array=>['date'=>$row['date'],'analyses'=>$row['analyses'],'matchesFound'=>$row['matchesFound'],'distinctUsers'=>count($row['users'])],$daily));
        usort($dailyRows,static fn(array $a,array $b):int=>strcmp((string)$b['date'],(string)$a['date']));
        $userRows=array_values($users);
        usort($userRows,static fn(array $a,array $b):int=>$b['analyses']<=>$a['analyses']?:$b['matchesFound']<=>$a['matchesFound']?:strcmp((string)$b['lastSearchAt'],(string)$a['lastSearchAt'])?:strcmp((string)$a['username'],(string)$b['username']));
        $analyses=count($entries);$matchesFound=array_sum(array_column($entries,'matchesFound'));
        return [
            'range'=>['from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'days'=>$days],
            'summary'=>['analyses'=>$analyses,'matchesFound'=>$matchesFound,'distinctUsers'=>count($users),'averageMatchesFound'=>$analyses>0?$matchesFound/$analyses:0.0],
            'daily'=>$dailyRows,'users'=>$userRows,'entries'=>array_slice($entries,0,$maxEntries),
            'truncated'=>count($entries)>$maxEntries,'invalidLines'=>$invalid,'source'=>'shared_match_assistant_log',
            'coverage_note'=>'Includes completed Match Assistant analyses from UI v1 and UI v2. Synchronized/reused analyses intentionally skipped by the shared logger are not counted.',
        ];
    }
}
