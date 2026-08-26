<?php
declare(strict_types=1);
namespace P2K\Tournaments;

final class BrowseIndex
{
    private string $archivePath;
    private string $cachePath;
    public function __construct()
    {
        $this->archivePath=\p2k_tournament_archive_path();
        $this->cachePath=\p2k_tournament_cache_dir().'/browse-index-v1.json';
    }
    public function signature(): string
    {
        // Include every archive source TournamentService may recover from.  Older
        // standalone releases could leave an empty primary while a healthy backup
        // remained authoritative; primary-only ETags would then stay stale forever.
        $candidates=[$this->archivePath,$this->archivePath.'.bak'];
        $backups=glob(dirname($this->archivePath).'/backups/archive-*.json')?:[];
        rsort($backups,SORT_STRING);
        $parts=[];
        foreach(array_merge($candidates,$backups) as $path){
            if(!is_file($path)) continue;
            $parts[]=basename($path).':'.(int)@filemtime($path).':'.(int)@filesize($path);
        }
        return substr(hash('sha256',implode('|',$parts)),0,20);
    }
    public function requestEtag(array $query): string
    {
        ksort($query);return '"'.hash('sha256','p2k-tournament-browse-v1|'.$this->signature().'|'.json_encode($query,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'"';
    }
    public function index(): array
    {
        $sig=$this->signature();
        if(is_file($this->cachePath)){
            $cached=json_decode((string)@file_get_contents($this->cachePath),true);
            if(is_array($cached)&&($cached['signature']??'')===$sig&&is_array($cached['index']??null))return $cached['index'];
        }
        $archive=(new TournamentService())->archive();$index=$this->build($archive);$dir=dirname($this->cachePath);if(!is_dir($dir))@mkdir($dir,0775,true);$tmp=$this->cachePath.'.tmp.'.bin2hex(random_bytes(4));@file_put_contents($tmp,json_encode(['signature'=>$sig,'builtAt'=>\p2k_tournament_now(),'index'=>$index],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));@rename($tmp,$this->cachePath);return $index;
    }
    private function build(array $archive): array
    {
        $tournaments=is_array($archive['tournaments']??null)?$archive['tournaments']:[];$excluded=[];foreach(is_array($archive['excludedMedalists']??null)?$archive['excludedMedalists']:[] as $raw){$name=strtolower(trim((string)(is_array($raw)?($raw['username']??$raw['name']??''):$raw)));if($name!=='')$excluded[$name]=true;}
        $names=static fn($v):array=>array_values(array_filter(array_map(static fn($x):string=>trim((string)(is_array($x)?($x['username']??$x['name']??''):$x)),is_array($v)?$v:[])));
        $ranking=[];$players=[];$years=[];$summary=['total'=>count($tournaments),'finished'=>0,'in_progress'=>0,'registration'=>0,'medalists'=>0,'excluded'=>count($excluded)];
        foreach($tournaments as $idx=>$t){$status=strtolower((string)($t['status']??''));if($status==='finished')$summary['finished']++;elseif(str_contains($status,'progress'))$summary['in_progress']++;elseif(str_contains($status,'registration'))$summary['registration']++;$period=(string)($t['period']??$t['periodSort']??'');if(preg_match('/(20\d{2})/',$period,$m))$years[$m[1]]=true;
            $participantKeys=[];foreach(['players','participants','members'] as $field)foreach($names($t[$field]??[]) as $name){$k=strtolower($name);$participantKeys[$k]=$name;}
            foreach($participantKeys as $k=>$name){if(!isset($players[$k]))$players[$k]=['name'=>$name,'gold'=>0,'silver'=>0,'bronze'=>0,'podiums'=>0,'participations'=>[],'tournaments'=>[]];$players[$k]['participations'][]=$idx;}
            foreach(['gold','silver','bronze'] as $medal){foreach($names($t['podium'][$medal]??[]) as $name){$k=strtolower($name);if(isset($excluded[$k]))continue;if(!isset($ranking[$k]))$ranking[$k]=['name'=>$name,'gold'=>0,'silver'=>0,'bronze'=>0,'tournaments'=>[]];$event=['name'=>(string)($t['name']??$t['title']??$t['slug']??'Tournament'),'period'=>$period,'webUrl'=>(string)($t['webUrl']??$t['url']??''),'medal'=>$medal,'date'=>(string)($t['finishAt']??'')];$ranking[$k][$medal]++;$ranking[$k]['tournaments'][]=$event;if(!isset($players[$k]))$players[$k]=['name'=>$name,'gold'=>0,'silver'=>0,'bronze'=>0,'podiums'=>0,'participations'=>[],'tournaments'=>[]];$players[$k][$medal]++;$players[$k]['podiums']++;$players[$k]['tournaments'][]=$event;}}
        }
        $ranking=array_values($ranking);foreach($ranking as &$r){$r['podiums']=$r['gold']+$r['silver']+$r['bronze'];$r['tournamentCount']=count($r['tournaments']);}unset($r);usort($ranking,static fn($a,$b)=>$b['gold']<=>$a['gold']?:$b['silver']<=>$a['silver']?:$b['bronze']<=>$a['bronze']?:strcasecmp($a['name'],$b['name']));foreach($ranking as $i=>&$r)$r['rank']=$i+1;unset($r);$summary['medalists']=count($ranking);
        $rankingMap=[];foreach($ranking as $r)$rankingMap[strtolower((string)$r['name'])]=$r;
        foreach($players as $k=>&$row){$rank=$rankingMap[$k]??null;if($rank){$row=array_merge($row,$rank);}$row['tournamentCount']=count($row['tournaments']);$row['participationCount']=count(array_unique($row['participations']));}unset($row);
        return ['generatedAt'=>$archive['generatedAt']??null,'recoverySource'=>$archive['recoverySource']??null,'summary'=>$summary,'years'=>array_values(array_reverse(array_keys($years))),'ranking'=>$ranking,'ranking_map'=>$rankingMap,'players'=>$players,'tournaments'=>$tournaments];
    }
}
