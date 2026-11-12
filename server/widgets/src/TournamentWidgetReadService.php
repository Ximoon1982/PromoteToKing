<?php
declare(strict_types=1);
namespace P2K\Widgets;

/**
 * Small, deterministic read projection of the canonical Tournament BrowseIndex.
 * Input podiums have already been filtered for excluded medalists by BrowseIndex.
 * Never writes or changes the source tournament archive.
 */
final class TournamentWidgetReadService
{
    private static function project(array $t): array
    {
        return [
            'name'=>(string)($t['name']??$t['title']??$t['slug']??'Tournament'),
            'slug'=>(string)($t['slug']??''),
            'status'=>(string)($t['status']??'unknown'),
            'period'=>(string)($t['period']??''),
            'finishAt'=>(string)($t['finishAt']??''),
            'webUrl'=>(string)($t['webUrl']??$t['url']??''),
            'podium'=>is_array($t['podium']??null)?$t['podium']:[],
        ];
    }

    private static function sortingDate(array $t): string
    {
        foreach (['finishAt','periodSort','period'] as $field) {
            $value=trim((string)($t[$field]??''));
            if ($value!=='') return $value;
        }
        return '';
    }

    public static function read(array $tournaments, string $search='', int $page=1, int $completedPage=1): array
    {
        $items=array_values(array_filter($tournaments,'is_array'));
        usort($items,static fn(array $a,array $b):int=>
            strnatcasecmp(self::sortingDate($b),self::sortingDate($a))
            ?:strnatcasecmp((string)($a['name']??''),(string)($b['name']??'')));
        $finished=array_values(array_filter($items,static fn(array $t):bool=>
            strtolower(trim((string)($t['status']??'')))==='finished'));

        // Completed browse is not artificially capped: every finished tournament
        // remains addressable, but only one record is transferred per navigation.
        $finishedCount=count($finished);
        $finishedPages=max(1,$finishedCount);
        $currentCompleted=min(max(1,$completedPage),$finishedPages);
        $completed=isset($finished[$currentCompleted-1])
            ?self::project($finished[$currentCompleted-1]):null;

        $titleMatches=[];$exactPlayers=[];$partialPlayers=[];
        if($search!=='')foreach($items as $t) {
            $title=(string)($t['name']??$t['title']??'');
            $slug=(string)($t['slug']??'');
            if(stripos($title,$search)!==false||stripos($slug,$search)!==false)$titleMatches[]=self::project($t);
            $exact=[];$partial=[];
            foreach(['gold','silver','bronze'] as $metal) {
                foreach(is_array($t['podium'][$metal]??null)?$t['podium'][$metal]:[] as $raw) {
                    $username=trim((string)(is_array($raw)?($raw['username']??$raw['name']??''):$raw));
                    if($username==='')continue;
                    $medalist=['username'=>$username,'medal'=>$metal];
                    if(strcasecmp($username,$search)===0)$exact[]=$medalist;
                    elseif(stripos($username,$search)!==false)$partial[]=$medalist;
                }
            }
            if($exact!==[]) {
                $row=self::project($t);$row['matched_medalists']=$exact;
                $exactPlayers[]=$row;
            }
            if($partial!==[]) {
                $row=self::project($t);$row['matched_medalists']=$partial;
                $partialPlayers[]=$row;
            }
        }

        // Prefer an exact medalist match over a coincidental tournament title;
        // otherwise show tournament titles before any partial player matches.
        $kind='tournament';$found=$titleMatches;
        if($exactPlayers!==[]) {
            $kind='player';$found=$exactPlayers;
        } elseif($titleMatches===[]&&$partialPlayers!==[]) {
            $kind='player';$found=$partialPlayers;
        }
        $size=10;
        $total=count($found);
        $pages=max(1,(int)ceil($total/$size));
        $current=min(max(1,$page),$pages);
        return [
            'ok'=>true,
            'completed'=>$completed,
            'completed_pagination'=>[
                'page'=>$currentCompleted,'pages'=>$finishedPages,
                'total'=>$finishedCount,'per_page'=>1
            ],
            'search_kind'=>$kind,
            'matches'=>array_slice($found,($current-1)*$size,$size),
            'pagination'=>[
                'page'=>$current,'pages'=>$pages,
                'total'=>$total,'per_page'=>$size
            ]
        ];
    }
}
