<?php
declare(strict_types=1);
require_once __DIR__.'/../TGP/MatchRecruitment/MatchPolicy.php';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$team=['@id'=>'https://api.chess.com/pub/club/the-golden-phoenix'];
$other=['@id'=>'https://api.chess.com/pub/club/team-france'];
check(TgpMatchPolicy::containsClub(['teams'=>[$team,$other]]),'TGP match rejected');
check(TgpMatchPolicy::containsClub(['teams'=>['team1'=>$other,'team2'=>$team]]),'Associative teams rejected');
check(!TgpMatchPolicy::containsClub(['teams'=>[$other,$other]]),'Non-TGP match accepted');
check(!TgpMatchPolicy::containsClub(['teams'=>[['@id'=>'http://api.chess.com/pub/club/the-golden-phoenix'],$other]]),'Insecure reference accepted');
echo "TGP recruitment club-policy checks passed\n";
