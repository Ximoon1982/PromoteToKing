<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/RecruitmentRadarReadService.php';
use P2K\Widgets\RecruitmentRadarReadService as Radar;
function checkRadar(bool $yes,string $reason):void{if(!$yes)throw new RuntimeException('FAIL '.$reason);}
$sheet=[
    'settings'=>[
        'rules'=>'chess960','time_control'=>'1/259200','min_rating'=>1400,'max_rating'=>1800,
        'min_team_players'=>20,'max_team_players'=>50
    ],
    'teams'=>[
        ['@id'=>'https://api.chess.com/pub/club/promote-to-king',
         'players'=>[
            ['username'=>'Ximoon','rating'=>1500],
            ['username'=>'Another','rating'=>1430],
            ['username'=>'Chess960Guest','rating'=>1900]
         ]],
        ['@id'=>'https://api.chess.com/pub/club/opponent-club',
         'players'=>[
            ['username'=>'OpponentA','rating'=>1650],
            ['username'=>'OpponentB','rating'=>1700]
         ]]
    ]
];
$parsed=Radar::parseMatch($sheet,'promote-to-king',2011536,'2 days');
checkRadar($parsed['verified']===true,'Real match with valid home/opponent teams');
checkRadar($parsed['rules']==='chess960','Chess960 rules are preserved');
checkRadar($parsed['timeControl']==='1/259200','Live time control overrides Green fallback');
checkRadar($parsed['minRating']===1400&&$parsed['maxRating']===1800,'Rating bounds from sheet');
checkRadar($parsed['minimumPlayers']===20&&$parsed['maximumPlayers']===50,'Lineup size bounds from sheet');
checkRadar($parsed['oursCount']===3&&$parsed['opponentCount']===2,'Original roster sizes');
checkRadar($parsed['oursRatings']===[1500.0,1430.0,1900.0],'Raw ratings passed to the canonical lineup model');
$anonymous=Radar::publicMatch($parsed);
checkRadar(!isset($anonymous['_registeredNames']),'Public payload never leaks cached roster usernames');
checkRadar($anonymous['alreadyRegistered']===false,'Anonymous visitor not marked as registered');
checkRadar(Radar::publicMatch($parsed,'XIMOON')['alreadyRegistered']===true,'Case-insensitive registered-player exclusion');
checkRadar(Radar::publicMatch($parsed,'NewPlayer')['alreadyRegistered']===false,'Unregistered member is not excluded');
$unknown=Radar::parseMatch(['settings'=>[],'teams'=>[]],'promote-to-king',42);
checkRadar($unknown['verified']===false,'Unknown rosters never classified as verified');
$stats=['chess_daily'=>['last'=>['rating'=>1550]],'chess960_daily'=>['last'=>['rating'=>1880]]];
$player=Radar::playerRatings($stats,'PlayerName');
checkRadar($player['classical']===1550&&$player['chess960']===1880,'Both Daily rating categories preserved');
$partial=Radar::playerRatings(['chess_daily'=>['record'=>[]]],'PlayerName');
checkRadar($partial['classical']===null&&$partial['chess960']===null,'Missing rating remains null, never a guessed zero');
try{Radar::username('https://bad.invalid');throw new RuntimeException('Unsafe username accepted');}catch(InvalidArgumentException){}
checkRadar(Radar::BATCH_MAX===4,'Request count bounded to four matches');
echo "PASS Radar sheet verification, rating/time control, closed roster privacy, username registration and missing data\n";
