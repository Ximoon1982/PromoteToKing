<?php
declare(strict_types=1);
namespace P2K\Widgets;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use P2K\TeamPoints\MemberInsightsTableService;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;

/** Canonical Green Daily/MCA read adapter for the compact Hall of Fame widget. */
final class HallOfFameLeaderboardService
{
    public const PAGE_SIZE=25;

    /** All boundaries are UTC calendar boundaries, never rolling 30/90-day periods. */
    public static function calendarRange(string $period, ?DateTimeImmutable $today=null): array
    {
        if(!in_array($period,['all','month','quarter'],true)) {
            throw new \InvalidArgumentException('Unknown Hall of Fame leaderboard period.');
        }
        $now=($today??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $end=$now->format('Y-m-d');
        if($period==='all')return ['start'=>'','end'=>''];
        if($period==='month')return ['start'=>$now->format('Y-m-01'),'end'=>$end];
        $quarterMonth=1+3*intdiv((int)$now->format('n')-1,3);
        return ['start'=>sprintf('%04d-%02d-01',(int)$now->format('Y'),$quarterMonth),'end'=>$end];
    }

    public static function daily(Repository $repository,ResponseCache $cache,string $generation,string $club,string $period,int $page):array
    {
        $range=self::calendarRange($period);
        $service=new MemberInsightsTableService($repository,$cache,$generation);
        $result=$service->table($club,[
            'page'=>max(1,$page),'page_size'=>self::PAGE_SIZE,'search'=>'',
            'filter'=>'current','sort'=>'points','direction'=>'desc',
            'start'=>$range['start'],'end'=>$range['end'],
            'include_position_comparison'=>false,
        ],true);
        $pagination=is_array($result['pagination']??null)?$result['pagination']:[];
        $currentPage=max(1,(int)($pagination['page']??1));
        $out=[];
        foreach(is_array($result['rows']??null)?$result['rows']:[] as $i=>$row){
            $username=trim((string)($row['username']??''));
            if($username==='')continue;
            $out[]=[
                'username'=>$username,
                'points'=>(float)($row['points']??0),
                'rank'=>($currentPage-1)*self::PAGE_SIZE+$i+1,
            ];
        }
        return [
            'ok'=>true,'kind'=>'daily','period'=>$period,'range'=>$range,'rows'=>$out,
            'pagination'=>[
                'page'=>$currentPage,'page_size'=>self::PAGE_SIZE,
                'total_rows'=>(int)($pagination['total_rows']??0),
                'total_pages'=>max(1,(int)($pagination['total_pages']??1)),
            ],
        ];
    }

    public static function arena(PDO $analytics,string $club,int $page):array
    {
        // This is the same current-member MCA totals table and stable tie ordering
        // used by Player Spotlight. A LIMIT/OFFSET read avoids reconstructing Arena
        // Results CSV history merely to display a leaderboard page.
        $count=$analytics->prepare('SELECT COUNT(*) FROM p2k_lr_players WHERE club_slug=? AND current_member=1');
        $count->execute([$club]);
        $total=max(0,(int)$count->fetchColumn());
        $pages=max(1,(int)ceil($total/self::PAGE_SIZE));
        $current=min(max(1,$page),$pages);
        $offset=($current-1)*self::PAGE_SIZE;
        $query=$analytics->prepare('SELECT username,total_points FROM p2k_lr_players WHERE club_slug=? AND current_member=1 ORDER BY total_points DESC,username_key ASC LIMIT '.self::PAGE_SIZE.' OFFSET '.$offset);
        $query->execute([$club]);
        $out=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC)?:[] as $i=>$row){
            $out[]=['username'=>(string)$row['username'],'points'=>(float)$row['total_points'],'rank'=>$offset+$i+1];
        }
        return [
            'ok'=>true,'kind'=>'arena','period'=>'all','range'=>['start'=>'','end'=>''],
            'rows'=>$out,
            'pagination'=>['page'=>$current,'page_size'=>self::PAGE_SIZE,'total_rows'=>$total,'total_pages'=>$pages],
        ];
    }
}
