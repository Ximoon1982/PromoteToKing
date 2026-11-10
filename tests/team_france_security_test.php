<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/TeamFrance/MatchRecruitment/MatchPolicy.php';
$team = ['@id'=>'https://api.chess.com/pub/club/team-france'];
$other = ['@id'=>'https://api.chess.com/pub/club/another-club'];
$wrong = ['@id'=>'https://api.chess.com/pub/club/the-golden-phoenix'];
assert(TeamFranceRecruitmentPolicy::containsClub(['teams'=>['team1'=>$team,'team2'=>$other]]));
if(!TeamFranceRecruitmentPolicy::containsClub(['teams'=>['team1'=>$team,'team2'=>$other]]))throw new RuntimeException('Team France match rejected');
if(TeamFranceRecruitmentPolicy::containsClub(['teams'=>['team1'=>$wrong,'team2'=>$other]]))throw new RuntimeException('Cross-club match accepted');
if(TeamFranceRecruitmentPolicy::isClubReference('https://evil.example/pub/club/team-france'))throw new RuntimeException('Untrusted host allowed');
foreach(['TeamFrance/api/overview.php','TeamFrance/MatchRecruitment/api/index.php'] as $file){
 $src=file_get_contents(dirname(__DIR__).'/'.$file);
 if($file==='TeamFrance/api/overview.php'){
   foreach(['team_france_authorize()',"['registered']",'cache_ttl_seconds','api.chess.com/pub/club/team-france'] as $needle)if(!str_contains($src,$needle))throw new RuntimeException("Overview missing $needle");
 }else{
   foreach(['team_france_authorize()','Auth::enforceSameOrigin()','OAuthSession::batchForAuthorizedRequest','team-france/members','members-latest.json'] as $needle)if(!str_contains($src,$needle))throw new RuntimeException("Recruitment missing $needle");
   if(str_contains($src,'the-golden-phoenix'))throw new RuntimeException('TGP leakage');
 }
}
echo "PASS authentification, liste autorisée, confinement et règles de recrutement Team France\n";
