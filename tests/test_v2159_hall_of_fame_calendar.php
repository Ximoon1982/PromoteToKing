<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/HallOfFameLeaderboardService.php';
use P2K\Widgets\HallOfFameLeaderboardService as H;
function checkHall(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);}
$cases=[
    ['2026-11-13T11:42:00+01:00','month','2026-11-01','2026-11-13'],
    ['2026-11-13T11:42:00+01:00','quarter','2026-10-01','2026-11-13'],
    ['2026-01-01T00:05:00+01:00','month','2025-12-01','2025-12-31'],
    ['2026-01-01T00:05:00+01:00','quarter','2025-10-01','2025-12-31'],
    ['2026-03-31T23:59:00Z','quarter','2026-01-01','2026-03-31'],
    ['2026-04-01T00:00:00Z','quarter','2026-04-01','2026-04-01'],
    ['2026-07-15T12:00:00Z','quarter','2026-07-01','2026-07-15'],
    ['2026-12-31T23:59:00Z','month','2026-12-01','2026-12-31'],
];
foreach($cases as [$date,$period,$start,$end]){
    $range=H::calendarRange($period,new DateTimeImmutable($date));
    checkHall($range['start']===$start&&$range['end']===$end,$period.' UTC date '.$date);
}
checkHall(H::calendarRange('all',new DateTimeImmutable('2026-11-13T10:42Z'))===['start'=>'','end'=>''],'All time has no artificial date window');
try{H::calendarRange('30d');throw new RuntimeException('Rolling window accepted unexpectedly');}catch(InvalidArgumentException){}
checkHall(H::PAGE_SIZE===25,'Exactly 25 items per page');
echo "PASS: Hall of Fame UTC calendar month and quarter, year rollovers and strict 25-row paging\n";
