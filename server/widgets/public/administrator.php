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

if (($_GET['action'] ?? '') === 'matches') {
    try {
        $config=p2k_tp_config();
        $club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
        $tz=new DateTimeZone('Europe/Luxembourg');
        $start=(new DateTimeImmutable('today',$tz));
        $end=$start->modify('+7 days');
        $now=time();
        $days=[];
        for($i=0;$i<7;$i++) {
            $d=$start->modify('+' . $i . ' days');
            $days[$d->format('Y-m-d')]=['date'=>$d->format('Y-m-d'),'matches'=>0,'boards'=>0];
        }
        $sql="SELECT start_epoch,board_count FROM p2k_g_matches WHERE club_slug=?
            AND start_epoch>=? AND start_epoch<? AND
            (CASE WHEN status='unknown' THEN COALESCE(index_bucket,'unknown') ELSE status END)='registered'";
        $q=PublicReadDatabase::core()->prepare($sql);
        $q->execute([$club,max($now,$start->getTimestamp()),$end->getTimestamp()]);
        while($row=$q->fetch(PDO::FETCH_ASSOC)) {
            $date=(new DateTimeImmutable('@'.(int)$row['start_epoch']))->setTimezone($tz)->format('Y-m-d');
            if(isset($days[$date])) {
                $days[$date]['matches']++;
                $days[$date]['boards']+=max(0,(int)($row['board_count']??0));
            }
        }
        json_response(200,['ok'=>true,'source'=>'Green registered matches','timezone'=>'Europe/Luxembourg',
            'asOf'=>gmdate(DATE_ATOM),'days'=>array_values($days)]);
    } catch(Throwable $e) {
        error_log('P2K administrator widget match histogram: '.$e->getMessage());
        json_response(503,['ok'=>false,'error'=>'Histogram temporarily unavailable']);
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
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-administrator-v21510.css?v=1">
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
<section data-aw-view="matches" hidden><div class="aw-heading"><strong>Next seven days</strong><span class="aw-muted">Europe/Luxembourg</span></div><div class="aw-seg" role="group"><button type="button" data-aw-metric="matches" aria-pressed="true">Matches</button><button type="button" data-aw-metric="boards" aria-pressed="false">Boards</button></div><div id="awBars" class="aw-bars"></div><div id="awChartCaption" class="aw-muted"></div><button type="button" id="awRefreshMatches" class="aw-btn">Refresh</button></section>
<section data-aw-view="members" hidden><div class="aw-heading"><strong>Member chronology</strong><span class="aw-muted">10 / page</span></div><div id="awEvents">Loading…</div><div class="aw-pager"><button type="button" class="aw-btn" id="awEventPrev">Back</button><span id="awEventPage">1 / 1</span><button type="button" class="aw-btn" id="awEventNext">Next</button></div>
<div class="aw-heading"><strong>Member lookup</strong></div><form id="awMemberForm" class="aw-inline"><input id="awMemberName" maxlength="25" placeholder="Chess.com username" aria-label="Chess.com username" required><button type="submit" class="aw-btn aw-primary">Find</button></form><div id="awMemberResult"></div></section>
<section data-aw-view="showcase" hidden><div class="aw-heading"><strong>Upcoming arena discovery</strong></div><div class="aw-scan"><p class="aw-muted">Manual Chess.com scan · seven-day horizon · existing deduplication</p><button class="aw-btn aw-primary aw-full" type="button" id="awScan">Scan upcoming arenas</button><button class="aw-btn" type="button" id="awCancelScan" hidden>Cancel scan</button><div id="awScanStatus" class="aw-muted">Loading scan state…</div></div><div id="awScanResults"></div>
<details id="awOrganizers" class="aw-fold"><summary>Organizing clubs <span id="awClubCount"></span></summary><div id="awClubList"></div><form id="awClubForm" class="aw-stack"><input id="awClubId" placeholder="Numeric Chess.com club ID" inputmode="numeric" required><input id="awClubName" placeholder="Organizer name" required><button type="submit" class="aw-btn">Add organizer</button></form></details>
<div class="aw-seg" role="group"><button data-aw-sub="manage" aria-pressed="true">Manage</button><button data-aw-sub="add" aria-pressed="false">Add arena</button><button data-aw-sub="matches" aria-pressed="false">Match list</button></div>
<div data-aw-panel="manage"><div class="aw-heading"><strong>Configured arenas</strong><button class="aw-btn" id="awReloadShowcase" type="button">Reload</button></div><div id="awArenaList"></div></div>
<div data-aw-panel="add" hidden><div class="aw-heading"><strong id="awArenaFormTitle">Add arena</strong></div><form id="awArenaForm" class="aw-stack"><label>Name<input id="awArenaName" required></label><label>URL<input id="awArenaLink" type="url" required></label><a href="#" target="_blank" rel="noopener noreferrer" id="awOpenArena" hidden>Open arena on Chess.com ↗</a><label>Date<input id="awArenaDate" type="date" required></label><label>Start time<input id="awArenaTime" type="time" required></label><label>Time control<input id="awArenaTc" placeholder="3+2"></label><label>Duration<input id="awArenaDuration" placeholder="2h or 90m" required></label><button type="submit" class="aw-btn aw-primary">Save arena</button><button type="button" id="awArenaDiscard" class="aw-btn">Cancel</button></form></div>
<div data-aw-panel="matches" hidden><div class="aw-heading"><strong>Selected matches</strong></div><div id="awMatchList"></div></div>
<div id="awShowcaseFeedback" class="aw-muted" role="status"></div></section>
</div><footer class="p2kw-foot">Promote to King · 2.15.10</footer>
</section></main>
<script defer src="../../../assets/js/widgets/p2k-administrator-v21510.js?v=1"></script>
</body></html>
