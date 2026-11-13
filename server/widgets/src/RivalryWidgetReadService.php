<?php
declare(strict_types=1);
namespace P2K\Widgets;

use PDO;
use P2K\TeamPoints\Repository;

/** Read-only opponent directory, close-match search, and rivalry breakdowns. */
final class RivalryWidgetReadService
{
    public const PAGE_SIZE=25;
    public const SUGGESTIONS=8;

    public static function searchTerm(string $raw):string
    {
        $raw=trim($raw);
        if(strlen($raw)>250)throw new \InvalidArgumentException('Search is too long.');
        if(preg_match('~^(?:https?://)?(?:www\.)?chess\.com/club/([a-z0-9_-]{2,160})(?:[/?#].*)?$~i',$raw,$m)
          ||preg_match('~^https?://api\.chess\.com/pub/club/([a-z0-9_-]{2,160})(?:[/?#].*)?$~i',$raw,$m)
          ||preg_match('~^/?club/([a-z0-9_-]{2,160})/?$~i',$raw,$m)){
            return strtolower($m[1]);
        }
        if(preg_match('~^https?://~i',$raw))throw new \InvalidArgumentException('Enter a Chess.com club URL or a club name.');
        return substr(trim($raw),0,120);
    }

    public static function slug(string $raw):string
    {
        $term=strtolower(self::searchTerm($raw));
        if(!preg_match('/^[a-z0-9_-]{2,160}$/D',$term))throw new \InvalidArgumentException('Choose a club from the directory.');
        return $term;
    }

    private static function avatar(?string $url):string
    {
        $url=trim((string)$url);if($url==='')return '';
        $parts=parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        if(strtolower((string)($parts['scheme']??''))!=='https')return '';
        if(!in_array($host,['images.chesscomfiles.com','images.chess.com','www.chess.com','chess.com'],true))return '';
        return $url;
    }

    private static function enrich(PDO $core,string $club,array $rows):array
    {
        $slugs=array_values(array_unique(array_filter(array_map(
            static fn(array $r):string=>strtolower((string)($r['slug']??'')),$rows
        ))));
        if(!$slugs)return $rows;
        $q=$core->prepare('SELECT opponent_slug,icon_url,club_url FROM p2k_tp_opponents
            WHERE club_slug=? AND opponent_slug IN ('.implode(',',array_fill(0,count($slugs),'?')).')');
        $q->execute(array_merge([$club],$slugs));
        $profiles=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $p){
            $profiles[strtolower((string)$p['opponent_slug'])]=$p;
        }
        foreach($rows as &$row){
            $slug=strtolower((string)($row['slug']??''));
            $profile=$profiles[$slug]??[];
            $row['avatar']=self::avatar($profile['icon_url']??null);
            $row['url']=$row['url']??'';
            if(!$row['url']&&!empty($profile['club_url']))$row['url']=$profile['club_url'];
            if(!$row['url'])$row['url']='https://www.chess.com/club/'.rawurlencode($slug);
        }
        unset($row);
        return $rows;
    }

    public static function page(Repository $repo,PDO $core,string $club,int $page):array
    {
        $result=$repo->publicOpponentStats($club,[
            'page'=>max(1,$page),'page_size'=>self::PAGE_SIZE,
            'search'=>'','filter'=>'all','sort'=>'total','direction'=>'desc',
            'include_summary'=>true
        ]);
        return ['ok'=>true,'rows'=>self::enrich($core,$club,$result['rows']??[]),
            'summary'=>$result['summary']??[],
            'pagination'=>$result['pagination']??[]];
    }

    private static function normal(string $s):string
    {
        $s=strtolower(trim($s));
        $ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s):false;
        return trim(preg_replace('/[^a-z0-9]+/',' ',($ascii!==false?$ascii:$s))?:'');
    }

    public static function suggestions(PDO $core,PDO $analytics,string $club,string $raw):array
    {
        $needle=self::searchTerm($raw);
        if(strlen($needle)<2)return ['ok'=>true,'query'=>$raw,'results'=>[]];
        $query=self::normal($needle);
        if($query==='')return ['ok'=>true,'query'=>$raw,'results'=>[]];
        // Full directory is only a few thousand short metadata rows. Fetching it
        // here enables genuine typo-tolerant suggestions without dumping all
        // opponents to the visitor's browser.
        $q=$analytics->prepare('SELECT opponent_slug slug,display_name name,matches total
            FROM p2k_an_opponent_stats WHERE club_slug=?');
        $q->execute([$club]);
        $ranked=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $name=self::normal((string)($row['name']??''));
            $slug=self::normal((string)($row['slug']??''));
            $score=0;
            foreach([$name,$slug] as $candidate){
                if($candidate==='')continue;
                if($candidate===$query)$score=max($score,1000);
                elseif(str_starts_with($candidate,$query))$score=max($score,850);
                elseif(str_contains($candidate,' '.$query))$score=max($score,780);
                elseif(str_contains($candidate,$query))$score=max($score,690);
                else{
                    $distance=levenshtein(substr($candidate,0,100),substr($query,0,100));
                    foreach(array_slice(array_values(array_filter(explode(' ',$candidate))),0,12) as $part){
                        $distance=min($distance,levenshtein(substr($part,0,50),substr($query,0,50)));
                    }
                    $threshold=max(2,min(7,(int)ceil(strlen($query)*.34)));
                    if($distance<=$threshold)$score=max($score,520-$distance*45);
                }
            }
            if($score>0)$ranked[]=['slug'=>(string)$row['slug'],'name'=>(string)$row['name'],
                'total'=>(int)$row['total'],'score'=>$score];
        }
        usort($ranked,static fn(array $a,array $b):int=>
            $b['score']<=>$a['score']?:$b['total']<=>$a['total']?:strcmp($a['name'],$b['name']));
        $results=array_slice($ranked,0,self::SUGGESTIONS);
        foreach($results as &$result)unset($result['score']);unset($result);
        return ['ok'=>true,'query'=>$raw,'results'=>self::enrich($core,$club,$results)];
    }

    private static function emptySplit():array
    {
        return ['matches'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'covered'=>0];
    }

    public static function detail(PDO $core,PDO $analytics,string $club,string $raw):array
    {
        $slug=self::slug($raw);
        $a=$core->prepare('SELECT canonical_slug FROM p2k_tp_opponent_aliases
            WHERE club_slug=? AND alias_slug=? LIMIT 1');
        $a->execute([$club,$slug]);
        $resolved=$a->fetchColumn();
        if(is_string($resolved)&&$resolved!=='')$slug=strtolower($resolved);
        $q=$analytics->prepare('SELECT * FROM p2k_an_opponent_stats
            WHERE club_slug=? AND opponent_slug=? LIMIT 1');
        $q->execute([$club,$slug]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($r))throw new \InvalidArgumentException('This opponent is not in the recorded directory.');
        $base=[
            'slug'=>$slug,'name'=>(string)$r['display_name'],
            'url'=>(string)($r['club_url']??''),
            'total'=>(int)$r['matches'],'ongoing'=>(int)$r['ongoing'],
            'registered'=>(int)$r['registered'],'finished'=>(int)$r['finished'],
            'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses']
        ];
        $opponent=self::enrich($core,$club,[$base])[0];
        $aliases=[$slug];
        $q=$core->prepare('SELECT alias_slug FROM p2k_tp_opponent_aliases WHERE club_slug=? AND canonical_slug=?');
        $q->execute([$club,$slug]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $alias){
            $alias=strtolower((string)$alias);
            if(preg_match('/^[a-z0-9_-]{2,160}$/D',$alias))$aliases[]=$alias;
        }
        $aliases=array_values(array_unique($aliases));
        // The historical Green compatibility projection previously wrote
        // is_league=0 for every match. Until its existing materializations are
        // rebuilt, derive league classification from the canonical match-name
        // codes as well as the persisted flag. This is read-only and covers
        // historical records without a costly full regeneration.
        $leagueSql="CASE WHEN is_league=1 OR UPPER(COALESCE(match_name,'')) REGEXP '(^|[^A-Z0-9])(1WL|TCMAC|KOTML|TMCL|WKCL|PCL|CW)([^A-Z0-9]|$)' THEN 1 ELSE 0 END";
        $sql="SELECT {$leagueSql} league_category,COUNT(*) matches,
          COALESCE(SUM(result='win'),0) wins,
          COALESCE(SUM(result='draw'),0) draws,
          COALESCE(SUM(result='loss'),0) losses
          FROM p2k_an_match_facts WHERE club_slug=? AND is_void=0 AND status='finished'
          AND opponent_slug IN (".implode(',',array_fill(0,count($aliases),'?')).")
          GROUP BY league_category";
        $q=$analytics->prepare($sql);$q->execute(array_merge([$club],$aliases));
        $splits=['total'=>self::emptySplit(),'friendly'=>self::emptySplit(),'league'=>self::emptySplit()];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $key=(int)$row['league_category']===1?'league':'friendly';
            $stats=[
                'matches'=>(int)$row['matches'],'wins'=>(int)$row['wins'],
                'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],
                'covered'=>(int)$row['wins']+(int)$row['draws']+(int)$row['losses']
            ];
            $splits[$key]=$stats;
        }
        $splits['total']=['matches'=>(int)$r['finished'],
            'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],
            'covered'=>(int)$r['wins']+(int)$r['draws']+(int)$r['losses']];
        $categoriesCovered=$splits['friendly']['covered']+$splits['league']['covered'];
        return ['ok'=>true,'opponent'=>$opponent,'results'=>$splits,
            'coverage'=>[
                'missing'=>max(0,$splits['total']['matches']-$splits['total']['covered']),
                'category_gap'=>max(0,$splits['total']['covered']-$categoriesCovered)
            ]];
    }
}
