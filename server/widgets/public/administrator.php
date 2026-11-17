<?php
declare(strict_types=1);
/**
 * Public, read-only P2K Club Operations widget.
 * Administrative Showcase discovery/configuration is intentionally not present.
 * Administration remains protected in its original P2K shell and APIs.
 */
require_once dirname(__DIR__,3).'/api/_common.php';
require_once dirname(__DIR__).'/src/WidgetConfigStore.php';
use P2K\Widgets\WidgetConfigStore;
use P2K\TeamPoints\Auth;

$preview=(string)($_GET['preview']??'')==='1';
if($preview) {
    try { Auth::requireAdmin(); }
    catch(Throwable $e) { http_response_code(403); exit('Administrator preview requires authentication.'); }
}
$store=new WidgetConfigStore(root_dir());
$published=(bool)($store->get('administrator')['enabled']??false);
if(!$published&&!$preview){ http_response_code(404);exit('Club Operations widget not published.'); }
if(isset($_GET['action'])){http_response_code(404);exit('Unknown widget display action.');}
$theme=(string)($_GET['theme']??'auto');
if(!in_array($theme,['auto','dark','light'],true))$theme='auto';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, no-cache, max-age=30');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'self' https://www.chess.com https://chess.com");
?>
<!doctype html>
<html lang="en" class="p2kw-theme-<?=htmlspecialchars($theme,ENT_QUOTES)?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<title>P2K Club Operations</title>
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-embed-v2153.css?v=2.15.10">
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-administrator-v21510.css?v=2.15.10">
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-operations-v21510.css?v=2.15.10-public-1">
</head>
<body data-widget="administrator">
<main class="p2kw-root"><section class="p2kw-panel">
<header class="p2kw-head"><h1>Club Operations</h1><span class="p2kw-status" id="awStatus">PUBLIC</span></header>
<nav class="aw-tabs" role="tablist" aria-label="Club operations views">
<button type="button" data-aw-tab="actions" aria-selected="true">Actions</button>
<button type="button" data-aw-tab="matches" aria-selected="false">Matches</button>
<button type="button" data-aw-tab="members" aria-selected="false">Members</button>
<button type="button" data-aw-tab="analysis" aria-selected="false">Analysis</button>
</nav>
<div class="p2kw-content" id="awScroll" aria-live="polite">
<section data-aw-view="actions"><div class="aw-heading"><strong>Club Action Queue</strong><button type="button" class="aw-btn" id="awRefreshActions">Refresh</button></div><div id="awActions">Loading…</div></section>
<section data-aw-view="matches" hidden><div class="aw-heading"><strong>Next seven days</strong><span class="aw-muted">Luxembourg time</span></div><div class="aw-seg" role="group"><button type="button" data-aw-metric="matches" aria-pressed="true">Matches</button><button type="button" data-aw-metric="boards" aria-pressed="false">Boards</button></div><div id="awBars" class="aw-bars"></div><div id="awChartCaption" class="aw-muted"></div><div id="awDayMatches"></div><button type="button" id="awRefreshMatches" class="aw-btn">Refresh</button></section>
<section data-aw-view="members" hidden>
<div id="awMembersIndex">
<div class="aw-heading"><strong>Member lookup</strong></div>
<form id="awMemberForm" class="aw-inline"><input id="awMemberName" maxlength="25" placeholder="Chess.com username" aria-label="Chess.com username" required><button type="submit" class="aw-btn aw-primary">Find</button></form>
<div class="aw-heading"><strong>Member chronology</strong><span class="aw-muted">10 / page</span></div>
<div id="awEvents">Loading…</div>
<div class="aw-pager"><button type="button" class="aw-btn" id="awEventPrev">Back</button><span id="awEventPage">1 / 1</span><button type="button" class="aw-btn" id="awEventNext">Next</button></div>
</div>
<div id="awMemberResult" hidden></div>
</section>
<section data-aw-view="analysis" hidden>
<div class="aw-heading"><strong>Match Analysis</strong></div>
<form id="awAnalysisForm" class="aw-inline"><input id="awAnalysisSearch" maxlength="150" placeholder="Match URL, ID or name" aria-label="Match URL ID or name" autocomplete="off" required><button class="aw-btn aw-primary" type="submit">Find</button></form>
<div id="awAnalysisSuggestions" class="aw-suggest" role="listbox" aria-label="Matching P2K daily matches"></div>
<div id="awAnalysisFeedback" class="aw-muted" role="status"></div>
<div id="awAnalysisResult" hidden>
<div class="aw-heading"><strong id="awAnalysisTitle"></strong></div>
<div id="awAnalysisMeta" class="aw-muted"></div>
<div class="aw-statgrid" id="awAnalysisMetrics"></div>
<div id="awAnalysisPrediction" class="aw-op-prediction"></div>
<div class="aw-heading"><strong>Cumulative rating strength</strong></div>
<p class="aw-muted">Registered players at or above rating</p>
<div id="awChartCumulative" class="aw-op-chart"></div>
<div class="aw-heading"><strong>Players by 100-Elo bracket</strong></div>
<div id="awChartHistogram" class="aw-op-chart"></div>
<div class="aw-heading"><strong>Lineup &amp; win probability history</strong></div>
<div id="awChartHistory" class="aw-op-chart"></div>
<p id="awAnalysisFreshness" class="aw-muted"></p>
<a id="awFullAnalysis" class="aw-btn aw-primary aw-full" target="_blank" rel="noopener noreferrer">Open full Match Analyzer ↗</a>
</div>
</section>
</div>
<footer class="p2kw-foot">Promote to King · 2.15.10</footer>
</section></main>
<script defer src="../../../assets/js/shared/recruitment-lineup-core.js?v=2.15.10-operations-1"></script>
<script defer src="../../../assets/js/shared/traffic-analytics.js?v=2.15.10-operations-1" data-p2k-traffic-analytics data-p2k-widget-collector></script>
<script defer src="../../../assets/js/widgets/p2k-operations-v21510.js?v=2.15.10-operations-1"></script>
</body></html>
