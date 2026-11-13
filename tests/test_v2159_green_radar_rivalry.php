<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/widgets/src/RecruitmentRadarGreenReadService.php';
require_once dirname(__DIR__).'/server/widgets/src/RivalryWidgetReadService.php';
use P2K\Widgets\RecruitmentRadarGreenReadService as Radar;
use P2K\Widgets\RivalryWidgetReadService as Rival;
function checkV2159(bool $yes,string $message):void{
    if(!$yes)throw new RuntimeException('FAIL: '.$message);
}
checkV2159(Radar::MAX_WINDOW_DAYS===7,'Radar Green read limited to upcoming 7-day horizon');
checkV2159(Radar::LEGACY_BATCH_MAX===4,'Legacy read constrained to four verified matches');
checkV2159(Radar::username('Ximoon')==='Ximoon','Valid username normalized');
try{Radar::username('https://example.org');throw new RuntimeException('Invalid username accepted');}
catch(InvalidArgumentException){}
checkV2159(Rival::searchTerm('https://www.chess.com/club/Knights-And-Kings')==='knights-and-kings','Human Chess.com URL accepted');
checkV2159(Rival::searchTerm('https://api.chess.com/pub/club/Knights-And-Kings')==='knights-and-kings','API Chess.com URL accepted');
checkV2159(Rival::searchTerm('club/Knights-And-Kings')==='knights-and-kings','Club path accepted');
checkV2159(Rival::slug('Knights-And-Kings')==='knights-and-kings','Slug accepted');
checkV2159(Rival::searchTerm('The Golden Phoenix')==='The Golden Phoenix','Club name accepted');
try{Rival::searchTerm('https://malicious.example.org/club/Knights-And-Kings');throw new RuntimeException('Off-domain URL accepted');}
catch(InvalidArgumentException){}
checkV2159(Rival::PAGE_SIZE===25,'All opponents paginated 25 at a time');
checkV2159(Rival::SUGGESTIONS>=5,'Several fuzzy club suggestions');

$root=dirname(__DIR__);
$green=file_get_contents($root.'/server/widgets/src/RecruitmentRadarGreenReadService.php');
$legacyRadar=file_get_contents($root.'/server/widgets/src/RecruitmentRadarReadService.php');
$api=file_get_contents($root.'/server/widgets/public/api.php');
$schema=file_get_contents($root.'/server/team-points-green/sql/core-schema.sql');
$ingest=file_get_contents($root.'/server/team-points-green/src/GreenRepository.php');
$rival=file_get_contents($root.'/server/widgets/src/RivalryWidgetReadService.php');
$js=file_get_contents($root.'/assets/js/widgets/p2k-recruitment-radar-v2159.js');
$embedding=file_get_contents($root.'/server/widgets/public/embed.php');
checkV2159(!str_contains($green,'file_get_contents(')&&!str_contains($green,'curl_'),'Green Radar service has zero external HTTP capability');
checkV2159(!str_contains($legacyRadar,'file_get_contents(')&&!str_contains($legacyRadar,'https://api.chess.com/pub/match/'),'Legacy compatibility has no external match API capability');
checkV2159(!str_contains($rival,'file_get_contents(')&&!str_contains($rival,'curl_'),'Rivalry service has zero external HTTP capability');
checkV2159(str_contains($api,'RecruitmentRadarGreenReadService::catalog')&&str_contains($api,'RecruitmentRadarGreenReadService::player'),'All public Radar reads use Green');
checkV2159(!str_contains($api,'RecruitmentRadarReadService::batch')&&!str_contains($api,'RecruitmentRadarReadService::player'),'Legacy network reader no longer routed');
checkV2159(!str_contains($js,'radar-batch')&&!str_contains($js,'api.chess.com'),'Radar browser has no per-match or Chess.com fetch');
checkV2159(str_contains($js,"url('radar-catalog')"),'One Green DB read for widget startup');
checkV2159(str_contains($js,'resolveWindow()'), 'Auto broaden respects effective period');
checkV2159(str_contains($api,'RivalryWidgetReadService::detail')&&str_contains($api,'RivalryWidgetReadService::suggestions'),'Rivalry directory/detail endpoints exist');
checkV2159(str_contains($rival,"is_league")&&str_contains($rival,'p2k_an_match_facts'),'Rivalry league/friendly breakdown built from stored match facts');
checkV2159(str_contains($rival,'icon_url'),'Rivalry avatar from stored opponent metadata');
foreach(['min_rating','min_team_players','max_team_players','registration_locked'] as $col){
    checkV2159(str_contains($schema,$col)&&str_contains($ingest,"'".$col."'"),'Green schema and worker preserve '.$col);
}
checkV2159(str_contains($embedding,'p2k-rivalry-v2159.js'),'Rivalry iframe renders standalone script');
echo "PASS: v2.15.9 zero-Chess.com Radar, Green registration fields, Rivalry fuzzy/URL search and stored results\n";
