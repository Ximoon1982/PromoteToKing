<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-cache, max-age=30');
$theme = strtolower(trim((string)($_GET['theme'] ?? 'auto')));
if (!in_array($theme, ['auto','light','dark'], true)) $theme='auto';
?>
<!doctype html><html lang="en" class="pm-embed pm-theme-<?= htmlspecialchars($theme, ENT_QUOTES) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">
<title>Promote to King · Events showcase</title>
<link rel="stylesheet" href="../../../assets/css/dashboard-v2.css">
<script src="../../../config/site-branding.js"></script><script src="../../../assets/js/site-config.js"></script>
<script src="../../../assets/js/shared/api-cache.js"></script><script src="../../../assets/js/shared/match-priority.js"></script><script src="../../../assets/js/shared/api-request-semantics.js"></script><script src="../../../assets/js/shared/api-oauth-context.js"></script><script src="../../../assets/js/shared/api-transport.js"></script><script src="../../../assets/js/shared/api-request-coordinator.js"></script><script src="../../../assets/js/shared/api-client.js"></script>
<script src="../../../assets/js/shared/recruitment-lineup-core.js?v=p2k-2.12.2-466b9201fc6a-6d9ec55951a1d3f9"></script><script src="../../../assets/js/shared/events-showcase-core.js?v=p2k-2.12.2-95b63403c1da-showcase-7502c4c0333ce8af"></script>
<link rel="stylesheet" href="../../../assets/css/events-showcase-line-v2122.css?v=p2k-2.12.2-2f5070cd07de-linecss-fcf8b18d7e42"></head><body class="pm-widget-body">
<section class="pm-widget" aria-label="Promote to King recruitment opportunities">
  <header class="pm-widget-head">
    <div class="pm-widget-title">⚔️ Join multi-club arenas ⚔️</div>
    <nav class="pm-arena-links" aria-label="Chess.com multi-club arenas">
      <a href="https://www.chess.com/clubs/currentevents/promote-to-king?type=multi-club-arena" target="_blank" rel="noopener noreferrer">On-going</a>
      <a href="https://www.chess.com/clubs/upcomingevents/promote-to-king?type=multi-club-arena" target="_blank" rel="noopener noreferrer">Upcoming</a>
    </nav>
  </header>
  <div id="pmArenaRows" class="pm-widget-rows pm-arena-rows" hidden></div>
  <hr id="pmArenaDailySeparator" class="pm-section-separator" aria-hidden="true" hidden>
  <div id="pmArenaDailyGap" class="pm-section-gap" aria-hidden="true"></div>
  <header class="pm-widget-head">
    <div class="pm-widget-title">⚔️ Join daily matches ⚔️</div>
    <div class="dashboard-player-mode-toggle pm-widget-tabs" role="tablist" aria-label="Match type">
      <button class="pm-widget-tab" type="button" role="tab" data-filter="league">League</button>
      <button class="pm-widget-tab" type="button" role="tab" data-filter="friendly">Friendly</button>
    </div>
  </header>
  <div id="pmWidgetRows" class="pm-widget-rows" aria-live="polite"><div class="pm-widget-empty">Loading priority matches…</div></div>
  <div class="pm-widget-footer"><a href="https://www.promotetoking.org/" target="_blank" rel="noopener noreferrer">Open P2K match selection assistant</a></div>
</section>
<script src="../../../assets/js/events-showcase-line-v2122.js?v=p2k-2.12.2-95b63403c1da-showcase-7502c4c0333ce8af"></script></body></html>
