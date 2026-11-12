<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/TournamentWidgetReadService.php';
use P2K\Widgets\TournamentWidgetReadService as Pager;
function ensureWidget(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
}
$tournaments=[];
for($i=1;$i<=17;$i++){
    $day=str_pad((string)$i,2,'0',STR_PAD_LEFT);
    $tournaments[]=[
        'name'=>'Autumn Cup '.$day,
        'slug'=>'autumn-'.$day,
        'status'=>'finished',
        'period'=>'November 2026',
        'finishAt'=>'2026-11-'.$day,
        'webUrl'=>'https://www.chess.com/tournament/autumn-'.$day,
        'podium'=>['gold'=>['Alpha'], 'silver'=>['Bravo'], 'bronze'=>['Charlie']],
    ];
}
$tournaments[]=['name'=>'Autumn Registration','status'=>'registration','podium'=>[]];
$tournaments[]=['name'=>'Autumn In Progress','status'=>'in_progress','podium'=>[]];
$tournaments[]=['name'=>'December Preview','status'=>'upcoming','podium'=>[]];
$latest=Pager::read($tournaments);
ensureWidget(($latest['completed_pagination']['total']??0)===17,'All 17 finished tournaments are browsable');
ensureWidget(($latest['completed_pagination']['pages']??0)===17,'One finished tournament per page');
ensureWidget(($latest['completed']['name']??'')==='Autumn Cup 17','Newest finished tournament is default');
$seventh=Pager::read($tournaments,'',1,7);
ensureWidget(($seventh['completed']['name']??'')==='Autumn Cup 11','Seventh tournament reachable');
$last=Pager::read($tournaments,'',1,100000);
ensureWidget(($last['completed_pagination']['page']??0)===17,'Out of range page clamps to archive end');
ensureWidget(($last['completed']['name']??'')==='Autumn Cup 01','Oldest finished tournament reachable');
$search=Pager::read($tournaments,'Autumn',2,1);
ensureWidget(($search['search_kind']??'')==='tournament','Title search mode');
ensureWidget(($search['pagination']['total']??0)===19,'Search returns all statuses');
ensureWidget(count($search['matches'])===9,'Title search is paginated without truncation');
$player=Pager::read($tournaments,'alpha',2,12);
ensureWidget(($player['search_kind']??'')==='player','Player search takes precedence');
ensureWidget(($player['pagination']['total']??0)===17,'All 17 medal-winning tournaments found');
ensureWidget(count($player['matches'])===7,'Medalist results page two contains remaining seven');
ensureWidget(($player['matches'][0]['matched_medalists'][0]['medal']??'')==='gold','Medal type preserved');
ensureWidget(($player['completed_pagination']['page']??0)===12,'Completed browsing position retained independently');
$missing=Pager::read($tournaments,'NoSuchPlayer');
ensureWidget($missing['matches']===[],'No synthetic search results');
echo "PASS: all finished tournaments, arbitrary page navigation, current-status search, unbounded paginated medalist history\n";
