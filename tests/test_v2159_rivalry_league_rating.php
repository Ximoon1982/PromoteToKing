<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/RivalryWidgetReadService.php';
use P2K\Widgets\RivalryWidgetReadService as Rival;
function mustV2159(bool $ok,string $case):void{if(!$ok)throw new RuntimeException('FAIL '.$case);}
foreach([
    'TCMAC Centurion S6 R2 P2K vs Team France','1WL U1800 P2K vs Vikings',
    'PCL GP Final','KOTML R5','TMCL U1600','WKCL Season','CW Round 5'
] as $name)mustV2159(Rival::isLeagueName($name),'league match: '.$name);
foreach([
    'Friendly 1 day match','Chess Friends v Team XYZ','No league match','ACW Team',
    'CWdoesnotmatch','NoTCMAC','Tournament friendlies'
] as $name)mustV2159(!Rival::isLeagueName($name),'friendly match: '.$name);
$root=dirname(__DIR__);
$compat=file_get_contents($root.'/server/team-points-green/src/GreenCompatibility.php');
$rival=file_get_contents($root.'/server/widgets/src/RivalryWidgetReadService.php');
$radar=file_get_contents($root.'/server/widgets/src/RecruitmentRadarGreenReadService.php');
$js=file_get_contents($root.'/assets/js/widgets/p2k-recruitment-radar-v2159.js');
mustV2159(str_contains($compat,'$isLeague=preg_match('),'Green compatibility no longer hardcodes league false');
mustV2159(str_contains($compat,'is_league=VALUES(is_league)'),'Green compatibility updates existing league flag');
mustV2159(str_contains($compat,"\$m['rules'],\$m['time_control'],\$isLeague,\$m['start_epoch']"),'League flag inserted from classification');
mustV2159(str_contains($rival,'GROUP BY league_category')&&str_contains($rival,"REGEXP"),'Rivalry categories use stored flag and historical name fallback');
mustV2159(str_contains($radar,"'minRating'=>\$m['min_rating']===null?0:"),'Unset Green minimum translates to 0');
mustV2159(str_contains($radar,"'minRatingKnown'=>true"),'Omitted minimum is always a known rating floor');
mustV2159(str_contains($js,"'U'+max"),'U1500 label supported');
mustV2159(!str_contains($js,'if(detail.minRatingKnown===false||detail.maxRatingKnown===false)return false;'),'Unknown minimum no longer excludes a user');
echo "PASS league classification historical/current, 0-floor U rating and personalized Radar gates\n";
