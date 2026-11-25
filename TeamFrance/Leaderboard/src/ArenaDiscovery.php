<?php
declare(strict_types=1);
namespace TeamFrance\Leaderboard;
require_once __DIR__.'/Refresh.php';
require_once __DIR__.'/ArenaIndexParser.php';
use RuntimeException;

final class ArenaDiscovery {
    public const MAX_PAGES=250;
    public function __construct(private Store $s){}
    public static function organizer(string $ref): string {
        $ref=trim($ref);
        if (str_contains($ref,'/')) {
            $u=parse_url($ref);
            if (!is_array($u)||strtolower($u['scheme']??'')!=='https'||!in_array(strtolower($u['host']??''),['www.chess.com','chess.com','api.chess.com'],true)||isset($u['port'])||isset($u['user'])||isset($u['pass'])) throw new RuntimeException('Enter a Chess.com organizer club URL or slug.');
            if (!preg_match('~^/(?:pub/club/|club/(?:live-tournaments/)?)([a-zA-Z0-9_-]{1,160})/?$~D',$u['path']??'',$m)) throw new RuntimeException('Invalid organizer club URL.');
            $ref=$m[1];
        }
        $ref=strtolower($ref);
        if (!preg_match('/^[a-z0-9_-]{1,160}$/D',$ref)) throw new RuntimeException('Invalid organizer club slug.');
        return $ref;
    }
    public static function classify(array $events,string $start,string $end,array $assigned=[]): array {
        $out=[];$unknown=0;$outside=0;
        foreach($events as $event){
            $date=(string)($event['event_date']??'');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))){$unknown++;continue;}
            if($date<$start||$date>$end||$date>gmdate('Y-m-d')){$outside++;continue;}
            try{[$id,$url]=eventRef('arena',(string)$event['arena_url']);}catch(\Throwable){$unknown++;continue;}
            if($id!==(string)$event['arena_id']){$unknown++;continue;}
            $name=trim((string)($event['name']??''));
            if($name==='')$name=trim(str_replace('-',' ',preg_replace('/(?:^|-)\d+$/','',(string)$event['arena_slug'])??''))?:'Arena #'.$id;
            $out[$id]=['id'=>$id,'url'=>$url,'name'=>$name,'date'=>$date,'date_precision'=>(string)($event['date_precision']??'unknown'),'assigned'=>$assigned[$id]??null];
        }
        return ['events'=>array_values($out),'unknown_dates'=>$unknown,'outside_range'=>$outside];
    }
    public function page(int $seasonId,string $organizer,int $page,string $start,string $end): array {
        $season=$this->s->one("SELECT * FROM seasons WHERE id=? AND kind='arena'",[$seasonId]);
        if(!$season||$season['start_date']===''||$season['end_date']==='')throw new RuntimeException('Set both start and end dates on the arena season before discovery.');
        if($season['start_date']!==$start||$season['end_date']!==$end)throw new RuntimeException('Season dates changed. Restart discovery.');
        if($page<1||$page>self::MAX_PAGES)throw new RuntimeException('Discovery page limit reached.');
        $club=self::organizer($organizer);
        // Only administrators can call this endpoint; use the same fixed public
        // Chess.com HTML index as Club Wars Live. No OAuth token is sent to HTML.
        $fp=fopen($this->s->dir.'/arena-discovery.lock','c+');
        if(!$fp)throw new RuntimeException('Arena discovery lock unavailable.');
        if(!flock($fp,LOCK_EX|LOCK_NB)){fclose($fp);return ['busy'=>true];}
        try {
            $last=(float)$this->s->meta('arena_discovery_last','0');
            $wait=1.0-(microtime(true)-$last);
            if($wait>0)usleep((int)(min(1.0,$wait)*1000000));
            $this->s->put('arena_discovery_last',(string)microtime(true));
            $url='https://www.chess.com/club/live-tournaments/'.rawurlencode($club).'?type=multi&page='.$page;
            $response=Transport::get($url,12);
            $effective=parse_url($response['url']);
            if(!is_array($effective)||!in_array(strtolower($effective['host']??''),['www.chess.com','chess.com'],true)||rtrim((string)($effective['path']??''),'/')!=='/club/live-tournaments/'.$club)throw new RuntimeException('Chess.com redirected away from the requested club arena index.');
            $parsed=ArenaIndexParser::parse($response['body'],$page);
            if(empty($parsed['events']))throw new RuntimeException('Chess.com returned no parseable arenas on page '.$page.'. Discovery incomplete; previously found arenas are retained.');
            $assigned=[];
            foreach($this->s->rows("SELECT e.event_id,s.name season,e.season_id FROM events e JOIN seasons s ON s.id=e.season_id WHERE e.kind='arena'") as $row)$assigned[$row['event_id']]=['season'=>$row['season'],'season_id'=>(int)$row['season_id']];
            return self::classify($parsed['events'],$start,$end,$assigned)+['page'=>$page,'scanned'=>count($parsed['events']),'has_next'=>(bool)$parsed['has_next'],'fingerprint'=>$parsed['page_fingerprint'],'club'=>$club,'limit'=>self::MAX_PAGES];
        }finally{flock($fp,LOCK_UN);fclose($fp);}
    }
}
