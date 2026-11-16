<?php
declare(strict_types=1);
/**
 * P2K 2.15.10 Administrator widget: protected same-origin UI and seven-day Green chart.
 * This endpoint is never a public widget data source.
 */
require_once dirname(__DIR__, 3) . '/api/_common.php';

use P2K\TeamPoints\Auth;
use P2K\TeamPoints\PublicReadDatabase;

try {
    Auth::requireAdmin();
} catch (Throwable $e) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit('Administrator authentication required.');
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Frame-Options: SAMEORIGIN');

if (($_GET['action'] ?? '') === 'resolve-club') {
    $raw=trim((string)($_GET['input']??''));
    if ($raw==='' || strlen($raw)>200 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
        json_response(400,['ok'=>false,'error'=>'Enter a valid Chess.com club URL, slug or ID.']);
    }
    $value=$raw;
    if (str_contains($raw,'://')) {
        $parts=parse_url($raw);
        $host=strtolower((string)($parts['host']??''));
        $scheme=strtolower((string)($parts['scheme']??''));
        $path=(string)($parts['path']??'');
        if ($scheme!=='https' || !in_array($host,['chess.com','www.chess.com','api.chess.com'],true)
            || !preg_match('~^/(?:pub/)?club/([a-zA-Z0-9_-]+)/?$~D',$path,$matches)) {
            json_response(400,['ok'=>false,'error'=>'Use a Chess.com club profile URL.']);
        }
        $value=$matches[1];
    }
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$value)) {
        json_response(400,['ok'=>false,'error'=>'Invalid club slug or ID.']);
    }
    $numeric=ctype_digit($value);
    $url='https://api.chess.com/pub/club/'.rawurlencode(strtolower($value));
    $details=null;
    if(function_exists('curl_init')) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_USERAGENT=>'P2K-AdministratorWidget/2.15.10 (club profile resolution)'
        ]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http===200 && is_string($response) && strlen($response)<150000) {
            $candidate=json_decode($response,true);
            if(is_array($candidate))$details=$candidate;
        }
    }
    $id=$details['club_id']??null;
    $name=trim((string)($details['name']??''));
    if(is_numeric($id) && (int)$id>0 && $name!=='' && (!$numeric || (string)$id===$value)) {
        json_response(200,['ok'=>true,'clubId'=>(string)(int)$id,
            'name'=>function_exists('mb_substr')?mb_substr($name,0,80):substr($name,0,80),
            'resolved'=>true]);
    }
    if($numeric && preg_match('/^[1-9][0-9]{0,14}$/D',$value)) {
        json_response(200,['ok'=>true,'clubId'=>$value,'name'=>'Club #'.$value,
            'resolved'=>false,'message'=>'Club ID accepted; the official PubAPI did not return a verified name.']);
    }
    json_response(503,['ok'=>false,'error'=>'Club profile lookup failed; retry with a valid Chess.com slug or URL.']);
}

if (($_GET['action'] ?? '') === 'matches') {
    try {
        $config=p2k_tp_config();
        $club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
        $tz=new DateTimeZone('Europe/Luxembourg');
        $start=new DateTimeImmutable('today',$tz);
        $end=$start->modify('+7 days');
        $days=[];
        for($i=0;$i<7;$i++){
            $date=$start->modify('+'.$i.' days')->format('Y-m-d');
            $days[$date]=['date'=>$date,'matches'=>0,'boards'=>0,'items'=>[]];
        }
        // Same verified Green daily registration eligibility as the Showcase catalog.
        // Keep histogram totals independent of whether a roster has been fetched.
        $sql="SELECT match_id,start_epoch,board_count,name,opponent_name,web_url,
                min_team_players,max_team_players,min_rating,max_rating,registration_locked,last_verified_at
            FROM p2k_g_matches WHERE club_verified=1 AND verified_club_slug=? AND is_void=0
            AND COALESCE(NULLIF(time_class,''),NULLIF(index_time_class,''))='daily'
            AND start_epoch>=? AND start_epoch<?
            AND (CASE WHEN status='unknown' THEN COALESCE(index_bucket,'unknown') ELSE status END)='registered'
            ORDER BY start_epoch ASC,match_id ASC";
        $q=PublicReadDatabase::core()->prepare($sql);
        $q->execute([$club,max(time(),$start->getTimestamp()),$end->getTimestamp()]);
        $byId=[];$ids=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            $id=(int)($row['match_id']??0);
            if($id<=0)continue;
            $date=(new DateTimeImmutable('@'.(int)$row['start_epoch']))->setTimezone($tz)->format('Y-m-d');
            if(!isset($days[$date]))continue;
            $count=max(0,(int)($row['board_count']??0));
            $days[$date]['matches']++;
            $days[$date]['boards']+=$count;
            $url=trim((string)($row['web_url']??''));
            if(!preg_match('~^https://www\.chess\.com/club/matches/~i',$url))$url='https://www.chess.com/club/matches/'.$id;
            $item=[
                'matchId'=>$id,'name'=>(string)($row['name']?:'Match #'.$id),
                'opponent'=>(string)($row['opponent_name']?:'Opponent'),
                'startTime'=>(int)$row['start_epoch'],'url'=>$url,'boardCount'=>$count,
                'oursCount'=>0,'opponentCount'=>0,'oursEligible'=>0,'opponentEligible'=>0,
                'rosterComplete'=>false,'minimumPlayers'=>is_numeric($row['min_team_players'])?(int)$row['min_team_players']:null,
                'maximumPlayers'=>is_numeric($row['max_team_players'])?(int)$row['max_team_players']:null,
                'minRating'=>is_numeric($row['min_rating'])?(int)$row['min_rating']:0,
                'maxRating'=>is_numeric($row['max_rating'])?(int)$row['max_rating']:null,
                'locked'=>($row['registration_locked']??null)===null?null:(bool)$row['registration_locked'],
                'verified'=>($row['last_verified_at']??null)!==null,
            ];
            $byId[$id]=[$date,count($days[$date]['items'])];
            $days[$date]['items'][]=$item;
            $ids[]=$id;
        }
        foreach(array_chunk($ids,200) as $chunk){
            $roster=PublicReadDatabase::core()->prepare('SELECT match_id,is_p2k,start_rating FROM p2k_g_match_players WHERE match_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');
            $roster->execute($chunk);
            while($p=$roster->fetch(PDO::FETCH_ASSOC)){
                $id=(int)$p['match_id'];
                if(!isset($byId[$id]))continue;
                [$date,$index]=$byId[$id];
                $item=&$days[$date]['items'][$index];
                $ours=(int)$p['is_p2k']===1;
                if($ours)$item['oursCount']++;
                else $item['opponentCount']++;
                $rating=$p['start_rating']??null;
                if(is_numeric($rating) && (float)$rating>0 && (float)$rating>=$item['minRating']
                    && ($item['maxRating']===null || (float)$rating<=$item['maxRating'])){
                    if($ours)$item['oursEligible']++;
                    else $item['opponentEligible']++;
                }
                unset($item);
            }
        }
        foreach($days as &$day)foreach($day['items'] as &$item){
            $item['rosterComplete']=$item['oursCount']+$item['opponentCount']>0 || $item['boardCount']===0;
        }
        unset($day,$item);
        json_response(200,['ok'=>true,'source'=>'Green verified daily registration','timezone'=>'Europe/Luxembourg',
            'asOf'=>gmdate(DATE_ATOM),'days'=>array_values($days)]);
    }catch(Throwable $e){
        error_log('P2K Administrator histogram: '.$e->getMessage());
        json_response(503,['ok'=>false,'error'=>'Match schedule temporarily unavailable']);
    }
}

if(isset($_GET['action'])) {
    http_response_code(404);
    exit('Unknown action');
}
$theme=(string)($_GET['theme']??'auto');
if(!in_array($theme,['auto','dark','light'],true))$theme='auto';
?><!doctype html>
<html lang="en" class="p2kw-theme-<?=htmlspecialchars($theme,ENT_QUOTES)?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<title>P2K Administrator widget</title>
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-embed-v2153.css?v=p2k-21510">
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-administrator-v21510.css?v=2">
</head>
<body data-widget="administrator">
<main class="p2kw-root"><section class="p2kw-panel">
<header class="p2kw-head"><h1>Administrator</h1><span class="p2kw-status" id="awStatus">ADMIN ONLY</span></header>
<nav class="aw-tabs" role="tablist" aria-label="Administrator views">
<button type="button" data-aw-tab="actions" aria-selected="true">Actions</button>
<button type="button" data-aw-tab="matches" aria-selected="false">Matches</button>
<button type="button" data-aw-tab="members" aria-selected="false">Members</button>
<button type="button" data-aw-tab="showcase" aria-selected="false">Showcase</button>
</nav>
<div class="p2kw-content" id="awScroll" aria-live="polite">
<section data-aw-view="actions"><div class="aw-heading"><strong>Administrator Action Queue</strong><button type="button" class="aw-btn" id="awRefreshActions">Refresh</button></div><div id="awActions">Loading…</div><div id="awActionDetail" hidden></div></section>
<section data-aw-view="matches" hidden><div class="aw-heading"><strong>Next seven days</strong><span class="aw-muted">Europe/Luxembourg</span></div><div class="aw-seg" role="group"><button type="button" data-aw-metric="matches" aria-pressed="true">Matches</button><button type="button" data-aw-metric="boards" aria-pressed="false">Boards</button></div><div id="awBars" class="aw-bars"></div><div id="awChartCaption" class="aw-muted"></div><div id="awDayMatches"></div><button type="button" id="awRefreshMatches" class="aw-btn">Refresh</button></section>
<section data-aw-view="members" hidden><div id="awMembersIndex"><div class="aw-heading"><strong>Member chronology</strong><span class="aw-muted">10 / page</span></div><div id="awEvents">Loading…</div><div class="aw-pager"><button type="button" class="aw-btn" id="awEventPrev">Back</button><span id="awEventPage">1 / 1</span><button type="button" class="aw-btn" id="awEventNext">Next</button></div>
<div class="aw-heading"><strong>Member lookup</strong></div><form id="awMemberForm" class="aw-inline"><input id="awMemberName" maxlength="25" placeholder="Chess.com username" aria-label="Chess.com username" required><button type="submit" class="aw-btn aw-primary">Find</button></form></div><div id="awMemberResult" hidden></div></section>
<section data-aw-view="showcase" hidden><div class="aw-heading"><strong>Upcoming arena discovery</strong></div><div class="aw-scan"><p class="aw-muted">Manual Chess.com scan · seven-day horizon · existing deduplication</p><button class="aw-btn aw-primary aw-full" type="button" id="awScan">Scan upcoming arenas</button><button class="aw-btn" type="button" id="awCancelScan" hidden>Cancel scan</button><div id="awScanStatus" class="aw-muted">Loading scan state…</div></div><div id="awScanResults"></div>
<details id="awOrganizers" class="aw-fold"><summary>Organizing clubs <span id="awClubCount"></span></summary><div id="awClubList"></div><form id="awClubForm" class="aw-stack"><input id="awClubId" placeholder="Club URL, slug or numeric ID" required><button type="submit" class="aw-btn">Add organizer</button></form></details>
<div class="aw-seg" role="group"><button data-aw-sub="manage" aria-pressed="true">Manage</button><button data-aw-sub="add" aria-pressed="false">Add arena</button><button data-aw-sub="matches" aria-pressed="false">Match list</button></div>
<div data-aw-panel="manage"><div class="aw-heading"><strong>Configured arenas</strong><button class="aw-btn" id="awReloadShowcase" type="button">Reload</button></div><div id="awArenaList"></div></div>
<div data-aw-panel="add" hidden><div class="aw-heading"><strong id="awArenaFormTitle">Add arena</strong></div><form id="awArenaForm" class="aw-stack"><label>Name<input id="awArenaName" required></label><label>URL<input id="awArenaLink" type="url" required></label><a href="#" target="_blank" rel="noopener noreferrer" id="awOpenArena" hidden>Open arena on Chess.com ↗</a><label>Date<input id="awArenaDate" type="date" required></label><label>Start time<input id="awArenaTime" type="time" required></label><label>Time control<input id="awArenaTc" placeholder="3+2"></label><label>Duration<input id="awArenaDuration" placeholder="2h or 90m" required></label><button type="submit" class="aw-btn aw-primary">Save arena</button><button type="button" id="awArenaDiscard" class="aw-btn">Cancel</button></form></div>
<div data-aw-panel="matches" hidden><div class="aw-heading"><strong>Selected matches</strong></div><div id="awMatchList"></div></div>
<div id="awShowcaseFeedback" class="aw-muted" role="status"></div></section>
</div><footer class="p2kw-foot">Promote to King · 2.15.10</footer>
</section></main>
<script defer src="../../../assets/js/widgets/p2k-administrator-v21510.js?v=2"></script>
</body></html>
