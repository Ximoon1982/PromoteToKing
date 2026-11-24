<?php
declare(strict_types=1);
/**
 * Team France / TGP v0.3.6 live PubAPI regressions.
 * The fixture is copied from the 2026-11-24 read-only GitHub Action response.
 * Pulls the pure parsing helpers from API source without loading its routes,
 * OAuth/session globals, or making any network requests.
 */
$club = $argv[1] ?? 'TeamFrance';
if (!in_array($club, ['TeamFrance', 'TGP'], true)) throw new RuntimeException('Invalid club');
$slug = $club === 'TeamFrance' ? 'team-france' : 'the-golden-phoenix';
$root = dirname(__DIR__) . '/' . $club . '/Widget';
$api = file_get_contents($root . '/api.php');
$client = file_get_contents($root . '/src/ChessApi.php');
if (!is_string($api) || !is_string($client)) throw new RuntimeException('Missing source');
function checkLive(bool $ok, string $what): void {
    if (!$ok) throw new RuntimeException($what);
}
function loadSourceFunction(string $source, string $name): void {
    $expression = '/^function\s+' . preg_quote($name, '/') . '\(.*?^\}/ms';
    checkLive((bool)preg_match($expression, $source, $parts), 'missing function ' . $name);
    eval($parts[0]); // trusted repository source under test, not user input
}
foreach (['match_reference_id','slug_key','row_match_id','club_slug_from_url','title_from_slug',
          'match_index_type','match_api_path','match_web_path','live_control_display',
          'match_teams','team_slug_from_team','validate_match',
          'registration_index_rows','club_daily_match_ids',
          'confirmed_started_daily_ids','club_daily_match_map'] as $name) {
    loadSourceFunction($api, $name);
}
checkLive(str_contains($client, "function liveMatch("), 'missing live API client');
checkLive(str_contains($client, "'https://api.chess.com/pub/match/live/'"), 'wrong live endpoint');
checkLive(str_contains($api, '$chess->liveMatch($id, true)'), 'admin resolver uses daily endpoint');
checkLive(str_contains($api, '$chess->liveMatch($id)'), 'public cards use daily endpoint');
checkLive(str_contains($api, '$chess->liveMatch((string)$id)'), 'new ID validation uses daily endpoint');
checkLive(match_reference_id('https://www.chess.com/play/match/31195524') === '31195524',
    'real user-facing Live URL did not resolve');
checkLive(match_api_path('31195524','live')==='https://api.chess.com/pub/match/live/31195524',
    'Live API URL builder');
checkLive(match_web_path('31195524',$slug,'live')==='https://www.chess.com/play/match/31195524',
    'Live website URL builder');

$live = [
    '@id'=>'https://api.chess.com/pub/match/live/31195524',
    'url'=>'https://www.chess.com/club/matches/live/31195524',
    'name'=>'GCWL 2026 R6 Bullet: France vs Ukraine',
    'start_time'=>1795975200,
    'status'=>'scheduled',
    'boards'=>0,
    'settings'=>[
        'rules'=>'chess','time_class'=>'lightning','time_control'=>'120',
        'time_increment'=>0,'min_team_players'=>1,'max_team_players'=>50000,
        'min_required_games'=>0,'autostart'=>false,
    ],
    'teams'=>[
        'team1'=>['@id'=>'https://api.chess.com/pub/club/'.$slug,'name'=>'Our club'],
        'team2'=>['@id'=>'https://api.chess.com/pub/club/chess-school-ukraine',
                  'name'=>'Chess School Ukraine'],
    ],
];
[$our,$opponent] = validate_match($live,$slug,'live');
checkLive($opponent['name']==='Chess School Ukraine','keyed team2 opponent');
try {
    validate_match($live,$slug,'daily');
    throw new RuntimeException('Live match falsely accepted as Daily');
} catch (InvalidArgumentException $e) {
    checkLive(str_contains($e->getMessage(),'Daily'),'wrong Daily rejection');
}
checkLive(live_control_display($live)==='2+0','120s/0 speed');
$blitz=$live; $blitz['settings']=['time_class'=>'blitz','time_control'=>300,'time_increment'=>2];
checkLive(live_control_display($blitz)==='5+2','blitz increment');
$rapid=$live; $rapid['settings']=['time_class'=>'standard','time_control'=>600,'time_increment'=>0];
checkLive(live_control_display($rapid)==='10+0','standard rapid speed');

$index=[
    'registered'=>[
        ['@id'=>'https://api.chess.com/pub/match/live/31195524',
         'name'=>$live['name'],'time_class'=>'lightning',
         'opponent'=>'https://api.chess.com/pub/club/chess-school-ukraine'],
        ['@id'=>'https://api.chess.com/pub/match/live/31195588',
         'name'=>'GCWL blitz','time_class'=>'blitz',
         'opponent'=>'https://api.chess.com/pub/club/team-spain-live'],
        ['@id'=>'https://api.chess.com/pub/match/live/31195620',
         'name'=>'GCWL rapid','time_class'=>'standard',
         'opponent'=>'https://api.chess.com/pub/club/team-spain-live'],
        ['@id'=>'https://api.chess.com/pub/match/20201111',
         'name'=>'Daily match','time_class'=>'daily'],
    ],
    'in_progress'=>[],
    'finished'=>[],
];
$liveRows=registration_index_rows($index,$slug,'live');
checkLive(count($liveRows)===3,'did not recognize all Live speed classes');
checkLive($liveRows[0]['matchId']==='31195524','Live ID missing');
checkLive($liveRows[0]['url']==='https://www.chess.com/play/match/31195524','bad chooser Live href');
checkLive(count(registration_index_rows($index,$slug,'daily'))===1,'Daily was misclassified');
checkLive(count(club_daily_match_ids($index,'live'))===3,'save validation does not see Live index IDs');
checkLive(count(club_daily_match_map($index,$slug,'live'))===3,'admin map loses Live index');
checkLive(!match_index_type(['time_class'=>'lightning'],'live'),
    'Speed alone without Live URL must not establish match type');
$liveStarted=$index;
$liveStarted['in_progress'][]=$liveStarted['registered'][1];
$liveStarted['registered']=[$liveStarted['registered'][0],$liveStarted['registered'][2]];
checkLive(confirmed_started_daily_ids($liveStarted,'live')===['31195588'],
    'Live start cleanup did not respect registered bucket');
$realIndex=['registered'=>[['@id'=>'https://api.chess.com/pub/match/20201111',
                            'time_class'=>'daily']]];
checkLive(registration_index_rows($realIndex,$slug,'live')===[],
    'Never fabricate scheduled Live matches missing from the public club index');
echo "PASS {$club}: real Live detail shape, 31195524 URL, speed classes, index and pruning\n";
