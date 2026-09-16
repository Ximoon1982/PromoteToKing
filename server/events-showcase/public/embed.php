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
<script src="../../../assets/js/shared/recruitment-lineup-core.js?v=p2k-2.12.2-src-79c27cc76eb1f256"></script><script src="../../../assets/js/shared/events-showcase-core.js?v=p2k-2.12.2-src-79c27cc76eb1f256"></script>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#100f0e;color:#eee;font-family:Arial,Helvetica,sans-serif}
.pm-admin{width:min(100%,1120px);margin:0 auto;padding:18px}.pm-admin h1{margin:0;color:#f6b73c;font-size:clamp(22px,4vw,28px)}.pm-subtitle{margin:5px 0 18px;color:#aaa198;font-size:12px;line-height:1.5}.pm-card{margin:0 0 14px;padding:16px;border:1px solid rgba(255,255,255,.09);border-radius:12px;background:linear-gradient(145deg,rgba(39,35,31,.96),rgba(25,23,21,.96));box-shadow:0 8px 22px rgba(0,0,0,.22)}.pm-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.pm-card-head h2{margin:0;color:#f0e4cf;font-size:18px}.pm-card-head>span,.pm-card-tools>span{color:#aaa198;font-size:11px}.pm-card-tools{display:flex;align-items:center;gap:9px;white-space:nowrap}.pm-searchbar{display:flex;gap:8px;margin-bottom:8px}.pm-searchbar input,.pm-integration input,.pm-integration textarea,.pm-arena-admin input{width:100%;min-height:38px;padding:8px 11px;border:1px solid rgba(255,255,255,.12);border-radius:9px;background:rgba(0,0,0,.2);color:#eef1f4;font:inherit;outline:none}.pm-searchbar input:focus,.pm-integration input:focus,.pm-integration textarea:focus,.pm-arena-admin input:focus{border-color:rgba(246,183,60,.55);box-shadow:0 0 0 2px rgba(246,183,60,.08)}.pm-integration textarea{min-height:76px;resize:vertical}.pm-remove,.pm-icon-button{display:inline-flex;min-height:30px;align-items:center;justify-content:center;padding:5px 9px;border:1px solid rgba(255,255,255,.16);border-radius:7px;background:rgba(255,255,255,.05);color:#e7e1d9;font-weight:800;cursor:pointer}.pm-remove{border-color:rgba(225,104,104,.4);background:rgba(151,45,45,.14);color:#f2abab}.pm-icon-button:disabled{opacity:.35;cursor:default}.pm-feedback{min-height:20px;margin:5px 0 13px;color:#aaa198;font-size:12px}.pm-feedback.success{color:#a9e7c5}.pm-feedback.warning{color:#f2d49b}.pm-feedback.error{color:#f2abab}.pm-search-row,.pm-selected-row{display:grid;align-items:center;gap:10px;border-top:1px solid rgba(255,255,255,.065);padding:10px 0}.pm-search-row:first-child,.pm-selected-row:first-child{border-top:0}.pm-search-row{grid-template-columns:minmax(0,1fr) auto}.pm-selected-row{grid-template-columns:24px 28px minmax(0,1fr) auto auto auto}.pm-selected-row.is-unavailable{opacity:.78}.pm-drag{cursor:grab;color:#8f9aa4;letter-spacing:-3px}.pm-order{font-weight:800;color:#8f9aa4}.pm-title-line{display:flex;align-items:center;gap:7px;min-width:0}.pm-title-line a{color:#ffd078;font-weight:800;text-decoration:none}.pm-title-line a:hover{text-decoration:underline}.pm-title-line strong{color:#f0e4cf}.pm-name{margin-top:3px;color:#d8dee4;font-size:13px}.pm-meta{margin-top:3px;color:#8f9aa4;font-size:11px;line-height:1.4}.pm-warning-text{color:#f2abab}.pm-badge{display:inline-flex;flex:0 0 auto;padding:3px 7px;border:1px solid rgba(79,168,91,.35);border-radius:999px;color:#91e09a;font-size:9px;font-weight:900;line-height:1.2;text-transform:uppercase;white-space:nowrap}.pm-badge.league{border-color:rgba(246,183,60,.35);color:#ffd078}.pm-badge.friendly{border-color:rgba(79,168,91,.35);color:#91e09a}.pm-badge.unavailable{border-color:rgba(225,104,104,.4);color:#f2abab}.pm-enabled{display:flex;align-items:center;gap:5px;white-space:nowrap;color:#c9c2b9;font-size:12px}.pm-enabled input{accent-color:#d98d18}.pm-urgent-toggle{display:flex;align-items:center;gap:5px;white-space:nowrap;color:#ffd078;font-size:12px}.pm-urgent-toggle input{accent-color:#d98d18}.pm-row-actions{display:flex;align-items:center;gap:5px}.pm-empty{padding:17px;border:1px dashed rgba(255,255,255,.11);border-radius:8px;color:#aaa198;text-align:center;font-size:11px}.pm-integration-grid{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:start;margin:8px 0}.pm-integration label{display:block;margin-top:10px;color:#8f9aa4;font-size:10px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}.pm-preview{width:100%;height:226px;margin-top:10px;border:1px solid rgba(255,255,255,.1);border-radius:10px;background:transparent}.pm-auth-status{margin:0 0 14px;padding:10px 12px;border:1px solid rgba(246,183,60,.2);border-radius:9px;background:rgba(246,183,60,.06);color:#d6c6aa;font-size:12px}.admin-access-pending .pm-auth-status{display:block}.pm-auth-status{display:none}.pm-arena-admin{display:block}.pm-arena-admin-actions{display:flex;justify-content:flex-end;margin-top:10px}.pm-arena-list-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:10px;border-top:1px solid rgba(255,255,255,.065);padding:10px 0}.pm-arena-list-row:first-child{border-top:0}.pm-arena-list-row.is-disabled{opacity:.72}.pm-arena-list-main{min-width:0}.pm-arena-list-main a{color:#ffd078;font-weight:800;text-decoration:none}.pm-arena-list-main a:hover{text-decoration:underline}.pm-arena-list-meta{margin-top:3px;color:#8f9aa4;font-size:11px;line-height:1.4}.pm-arena-modal{width:min(720px,calc(100vw - 28px));max-width:none;padding:0;border:1px solid rgba(246,183,60,.28);border-radius:12px;background:#211e1b;color:#eef1f4;box-shadow:0 20px 55px rgba(0,0,0,.55)}.pm-arena-modal::backdrop{background:rgba(0,0,0,.68)}.pm-arena-modal-form{padding:16px}.pm-arena-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.pm-arena-modal-head h3{margin:0;color:#f0e4cf;font-size:18px}.pm-arena-modal-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pm-arena-modal-field{min-width:0}.pm-arena-modal-field.wide{grid-column:1/-1}.pm-arena-modal-field label{display:block;margin:0 0 5px;color:#8f9aa4;font-size:10px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}.pm-arena-modal-field input{width:100%;min-height:38px;padding:8px 10px;border:1px solid rgba(255,255,255,.12);border-radius:8px;background:rgba(0,0,0,.2);color:#eef1f4;font:inherit;outline:none}.pm-arena-modal-field input:focus{border-color:rgba(246,183,60,.55);box-shadow:0 0 0 2px rgba(246,183,60,.08)}.pm-arena-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.pm-arena-modal-error{min-height:18px;margin-top:9px;color:#f2abab;font-size:11px}.pm-search-pager{display:flex;align-items:center;justify-content:center;gap:9px;margin-top:10px}.pm-search-pager span{color:#aaa198;font-size:11px}.pm-search-pager[hidden]{display:none!important}

html.pm-embed,html.pm-embed body{background:transparent!important;min-height:0!important}html.pm-embed{color-scheme:light dark;--pm-text:#eef1f4;--pm-muted:#aaa198;--pm-title:#f0e4cf;--pm-border:rgba(255,255,255,.12);--pm-row-bg:rgba(25,23,21,.72);--pm-chip-bg:rgba(255,255,255,.045);--pm-gold:#ffd078;--pm-green:#91e09a;--pm-danger:#ffaaa4;--pm-link:#ffd078;--pm-shadow:rgba(0,0,0,.12);--pm-row-hover:rgba(255,255,255,.055);--pm-selector-bg:rgba(12,10,9,.58);--pm-selector-active:rgba(255,255,255,.16);--pm-selector-border:rgba(246,183,60,.24);--pm-assistant-bg:rgba(255,255,255,.075);--pm-assistant-hover:rgba(255,255,255,.13);--pm-assistant-border:rgba(255,255,255,.14)}html.pm-theme-dark{color-scheme:dark}html.pm-theme-light{color-scheme:light;--pm-text:#1e252c;--pm-muted:#616a73;--pm-title:#302820;--pm-border:rgba(24,28,32,.16);--pm-row-bg:rgba(255,255,255,.76);--pm-chip-bg:rgba(20,25,30,.045);--pm-gold:#915700;--pm-green:#246f35;--pm-danger:#a33232;--pm-link:#7d4b00;--pm-shadow:rgba(0,0,0,.07);--pm-row-hover:rgba(0,0,0,.035);--pm-selector-bg:rgba(255,255,255,.62);--pm-selector-active:rgba(0,0,0,.10);--pm-selector-border:rgba(145,87,0,.24);--pm-assistant-bg:rgba(0,0,0,.045);--pm-assistant-hover:rgba(0,0,0,.085);--pm-assistant-border:rgba(0,0,0,.11)}@media(prefers-color-scheme:light){html.pm-theme-auto{color-scheme:light;--pm-text:#1e252c;--pm-muted:#616a73;--pm-title:#302820;--pm-border:rgba(24,28,32,.16);--pm-row-bg:rgba(255,255,255,.76);--pm-chip-bg:rgba(20,25,30,.045);--pm-gold:#915700;--pm-green:#246f35;--pm-danger:#a33232;--pm-link:#7d4b00;--pm-shadow:rgba(0,0,0,.07);--pm-row-hover:rgba(0,0,0,.035);--pm-selector-bg:rgba(255,255,255,.62);--pm-selector-active:rgba(0,0,0,.10);--pm-selector-border:rgba(145,87,0,.24);--pm-assistant-bg:rgba(0,0,0,.045);--pm-assistant-hover:rgba(0,0,0,.085);--pm-assistant-border:rgba(0,0,0,.11)}}
.pm-widget-body{min-height:0;background:transparent!important;color:var(--pm-text)}.pm-widget{width:min(100%,730px);margin:0 auto;padding:6px;color:var(--pm-text)}.pm-widget-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:5px;white-space:nowrap;overflow:hidden}.pm-widget-title{flex:1 1 auto;min-width:0;color:var(--pm-gold);font-size:16px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pm-widget-tabs.dashboard-player-mode-toggle{display:inline-flex!important;flex:0 0 auto!important;align-items:center!important;white-space:nowrap!important;padding:2px!important;border:1px solid var(--pm-selector-border)!important;border-radius:999px!important;background:var(--pm-selector-bg)!important;overflow:visible!important}.pm-widget-tab{min-width:48px!important;margin:0!important;padding:4px 9px!important;border:0!important;border-radius:999px!important;background:transparent!important;color:var(--pm-muted)!important;box-shadow:none!important;font:800 12px/1.2 inherit!important;letter-spacing:.03em!important;white-space:nowrap!important;cursor:pointer!important}.pm-widget-tab.is-active{background:linear-gradient(135deg,#d98d18,#f6b73c)!important;color:#17110a!important;box-shadow:0 2px 8px rgba(217,141,24,.24)!important}.pm-widget-tab:focus-visible{outline:2px solid #82c8ff!important;outline-offset:2px!important}.pm-widget-rows{display:block;border-top:1px solid var(--pm-border);border-bottom:1px solid var(--pm-border)}.pm-widget-separator{height:0;margin:0 5px;border:0;border-top:1px solid var(--pm-border)}.pm-widget-row.dashboard-recommendation{position:relative;display:grid;grid-template-columns:auto minmax(0,1fr) auto auto auto auto auto;align-items:center;gap:5px;min-height:26px;padding:2px 5px;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;text-decoration:none;white-space:nowrap;overflow:visible;cursor:pointer}.pm-widget-row:hover{background:var(--pm-row-hover)!important}.pm-widget-row:focus-visible{outline:2px solid var(--pm-gold);outline-offset:-2px}.pm-widget-row.is-admin-urgent{box-shadow:inset 3px 0 0 color-mix(in srgb,var(--pm-danger) 72%,transparent)!important}.pm-widget-row .dashboard-tag{padding:2px 5px;font-size:10.5px;line-height:1.05;max-width:64px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pm-widget-row .dashboard-tag.league{border-color:color-mix(in srgb,var(--pm-gold) 40%,transparent);color:var(--pm-gold)}.pm-widget-row.pm-league-48h .dashboard-tag.league{border-color:color-mix(in srgb,var(--pm-danger) 48%,transparent);background:color-mix(in srgb,var(--pm-danger) 10%,transparent);color:var(--pm-danger)}.pm-widget-row.pm-league-48h .pm-widget-start{color:var(--pm-danger)!important;font-weight:900}.pm-widget-row .dashboard-tag.friendly{max-width:none;border-color:color-mix(in srgb,var(--pm-green) 40%,transparent);color:var(--pm-green)}.pm-widget-opponent{min-width:0;overflow:hidden;text-overflow:ellipsis;color:var(--pm-text);font-size:13.5px;font-weight:800}.pm-widget-pin{display:inline-flex;align-items:center;padding:2px 4px;border:1px solid color-mix(in srgb,var(--pm-gold) 35%,transparent);border-radius:999px;color:var(--pm-gold);font-size:9px;font-weight:900;text-transform:uppercase}.pm-widget-start{color:var(--pm-muted);font-size:10.5px;font-weight:800}.pm-widget-start.soon{color:var(--pm-gold)}.pm-widget-start.urgent{color:var(--pm-danger);font-weight:900}.pm-widget-chip{display:inline-flex;align-items:center;gap:3px;min-width:0;padding:2px 4px;border:1px solid var(--pm-border);border-radius:999px;background:var(--pm-chip-bg);color:var(--pm-text);font-size:10.5px;font-weight:800;line-height:1.05;white-space:nowrap}.pm-widget-rating{font-variant-numeric:tabular-nums}.pm-widget-time{color:var(--pm-muted)}.pm-widget-recruits{position:relative;border-color:color-mix(in srgb,var(--pm-gold) 36%,transparent);color:var(--pm-gold);font-size:11px;font-weight:900}.pm-widget-recruits.attention{background:color-mix(in srgb,var(--pm-gold) 10%,transparent)}.pm-widget-recruits.urgent{border-color:color-mix(in srgb,var(--pm-danger) 48%,transparent);background:color-mix(in srgb,var(--pm-danger) 10%,transparent);color:var(--pm-danger)}.pm-widget-recruits.ready{border-color:color-mix(in srgb,var(--pm-green) 36%,transparent);color:var(--pm-green)}.pm-widget-row.pm-hide-time .pm-widget-time,.pm-widget-row.pm-hide-pin .pm-widget-pin,.pm-widget-row.pm-hide-start .pm-widget-start{display:none}.pm-widget-empty{padding:16px;color:var(--pm-muted);font-size:13px;text-align:center}.pm-widget-footer{margin-top:5px;text-align:center;font-size:12px}.pm-widget-footer a{display:flex;align-items:center;justify-content:center;gap:8px;min-height:29px;padding:4px 10px;border:1px solid color-mix(in srgb,var(--pm-gold) 48%,var(--pm-assistant-border));border-radius:8px;background:color-mix(in srgb,var(--pm-gold) 9%,var(--pm-assistant-bg));color:var(--pm-gold);font-size:13.5px;font-weight:850;letter-spacing:.005em;text-decoration:none;box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--pm-gold) 8%,transparent)}.pm-widget-footer a:hover{background:color-mix(in srgb,var(--pm-gold) 16%,var(--pm-assistant-hover));text-decoration:none}.pm-arena-links{display:flex;align-items:center;gap:5px;flex:0 0 auto}.pm-arena-links a{display:inline-flex;align-items:center;justify-content:center;min-height:23px;padding:4px 9px;border:1px solid color-mix(in srgb,var(--pm-gold) 48%,var(--pm-assistant-border));border-radius:8px;background:color-mix(in srgb,var(--pm-gold) 9%,var(--pm-assistant-bg));color:var(--pm-gold);font-size:11px;font-weight:850;line-height:1.1;text-decoration:none;white-space:nowrap;box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--pm-gold) 8%,transparent)}.pm-arena-links a:hover{background:color-mix(in srgb,var(--pm-gold) 16%,var(--pm-assistant-hover));color:var(--pm-gold);text-decoration:none}.pm-section-gap{height:7px}.pm-section-separator{height:0;margin:6px 5px;border:0;border-top:1px solid var(--pm-border)}.pm-section-separator[hidden]{display:none}.pm-arena-rows[hidden]{display:none}.pm-arena-row.dashboard-recommendation{grid-template-columns:auto minmax(0,1fr) auto auto auto}.pm-arena-status{display:inline-flex;align-items:center;padding:2px 6px;border:1px solid color-mix(in srgb,var(--pm-danger) 48%,transparent);border-radius:999px;background:color-mix(in srgb,var(--pm-danger) 10%,transparent);color:var(--pm-danger);font-size:10.5px;font-weight:900;line-height:1.05;white-space:nowrap}.pm-arena-status.upcoming{border-color:color-mix(in srgb,var(--pm-gold) 40%,transparent);background:transparent;color:var(--pm-gold)}.pm-arena-status.registration{border-color:color-mix(in srgb,var(--pm-green) 48%,transparent);background:color-mix(in srgb,var(--pm-green) 10%,transparent);color:var(--pm-green)}.pm-arena-name{min-width:0;overflow:hidden;text-overflow:ellipsis;color:var(--pm-text);font-size:13.5px;font-weight:800}.pm-arena-countdown{border-color:color-mix(in srgb,var(--pm-danger) 36%,transparent);color:var(--pm-danger);font-variant-numeric:tabular-nums}.pm-arena-countdown.registration{border-color:color-mix(in srgb,var(--pm-green) 36%,transparent);color:var(--pm-green)}.pm-arena-row.pm-hide-arena-time .pm-arena-time,.pm-arena-row.pm-hide-arena-duration .pm-arena-duration{display:none}
@media(max-width:480px){.pm-admin{padding:12px}.pm-selected-row{grid-template-columns:20px 24px 1fr}.pm-enabled,.pm-urgent-toggle,.pm-row-actions{grid-column:3}.pm-search-row{grid-template-columns:1fr}.pm-add{justify-self:start}.pm-integration-grid{grid-template-columns:1fr}.pm-card-head{align-items:flex-start}.pm-card-tools{align-items:flex-end;flex-direction:column;gap:4px}.pm-widget{padding:4px}.pm-widget-head{gap:4px;margin-bottom:4px}.pm-widget-title{font-size:clamp(11.5px,3vw,15px)}.pm-widget-tab{min-width:42px!important;padding:4px 5px!important;font-size:11px!important}.pm-widget-row.dashboard-recommendation{min-height:24px;gap:3px;padding:1px 4px}.pm-widget-row .dashboard-tag{padding:2px 4px;font-size:9.5px}.pm-widget-opponent{font-size:12px}.pm-widget-pin{font-size:8.5px;padding:2px 3px}.pm-widget-start,.pm-widget-chip{font-size:9.5px}.pm-widget-chip{padding:2px 3px}.pm-widget-recruits{font-size:10.5px}.pm-widget-footer{margin-top:4px;font-size:11px}.pm-widget-footer a{min-height:27px;font-size:12.5px}.pm-arena-links{gap:5px}.pm-arena-links a{font-size:10px}.pm-arena-status{font-size:9.5px;padding:2px 4px}.pm-arena-name{font-size:12px}.pm-section-gap{height:5px}.pm-arena-list-row{grid-template-columns:minmax(0,1fr) auto}.pm-arena-list-row .pm-enabled{grid-column:auto}.pm-arena-list-row .pm-row-actions{grid-column:1/-1;justify-content:flex-end}.pm-arena-modal-fields{grid-template-columns:1fr 1fr}}
@media(max-width:380px){.pm-widget-row.dashboard-recommendation{gap:2px;padding-left:3px;padding-right:3px}.pm-widget-row .dashboard-tag{font-size:9px}.pm-widget-opponent{font-size:11px}.pm-widget-start,.pm-widget-chip{font-size:9px}.pm-widget-chip{padding-left:2px;padding-right:2px}.pm-widget-recruits{font-size:10px}.pm-widget-title{font-size:11px}.pm-widget-tab{min-width:40px!important;font-size:10.5px!important;padding:4px!important}.pm-arena-links a{font-size:9.5px}.pm-arena-status{font-size:9px}.pm-arena-name{font-size:11px}.pm-arena-modal-fields{grid-template-columns:1fr}.pm-arena-modal-field.wide{grid-column:auto}}
</style></head><body class="pm-widget-body">
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
<script>
(() => {
  "use strict";
  const api = window.P2K_EVENTS_SHOWCASE_CORE;
  const rows = document.getElementById("pmWidgetRows");
  const arenaRows = document.getElementById("pmArenaRows");
  const arenaSeparator = document.getElementById("pmArenaDailySeparator");
  const arenaGap = document.getElementById("pmArenaDailyGap");
  let matches = []; let arenaConfig = []; let filter = "league"; let arenaTimer = 0; let arenaCleanupPending = false;
  async function loadState(){ const response=await fetch("api.php?action=state",{credentials:"same-origin",cache:"no-store"}); const payload=await response.json().catch(()=>({})); if(!response.ok||payload?.ok===false) throw new Error(payload?.error?.message||`HTTP ${response.status}`); return payload; }

  function chooseDefault() {
    if (matches.some(m => m.category === "league")) return "league";
    if (matches.some(m => m.category === "friendly")) return "friendly";
    return "league";
  }
  function resizeParent() {
    try { parent.postMessage({ type:"p2k-priority-matches-height", height:Math.ceil(document.documentElement.scrollHeight) }, "*"); } catch (_) {}
  }
  function recruitmentSeverity(count) {
    const n=Number(count);
    if (!Number.isFinite(n) || n <= 0) return "";
    if (n >= 8) return "urgent";
    if (n >= 4) return "attention";
    return "";
  }
  function fitRows() {
    document.querySelectorAll(".pm-widget-row").forEach(row => {
      if (row.classList.contains("pm-arena-row")) {
        row.classList.remove("pm-hide-arena-time","pm-hide-arena-duration");
        const cramped=()=>row.scrollWidth > row.clientWidth + 1;
        if (cramped()) row.classList.add("pm-hide-arena-time");
        if (cramped()) row.classList.add("pm-hide-arena-duration");
        return;
      }
      row.classList.remove("pm-hide-time","pm-hide-pin","pm-hide-start");
      const opponent=row.querySelector(".pm-widget-opponent");
      const cramped=()=>row.scrollWidth > row.clientWidth + 1 || (opponent && opponent.clientWidth < Math.min(92, row.clientWidth * .24));
      if (cramped()) row.classList.add("pm-hide-time");
      if (cramped()) row.classList.add("pm-hide-pin");
      if (cramped()) row.classList.add("pm-hide-start");
    });
  }
  function bindRows() {
    document.querySelectorAll(".pm-widget-row[data-href]").forEach(row => {
      if (row.dataset.pmBound === "1") return;
      row.dataset.pmBound = "1";
      const open=()=>window.open(row.dataset.href,"_blank","noopener,noreferrer");
      row.addEventListener("click",event=>{ if(event.target.closest("a,button")) return; open(); });
      row.addEventListener("keydown",event=>{ if(event.target!==row) return; if(event.key==="Enter"||event.key===" "){event.preventDefault();open();} });
    });
  }
  function formatCountdown(ms) {
    const total=Math.max(0,Math.ceil(ms/1000));
    const h=Math.floor(total/3600);
    const m=Math.floor((total%3600)/60);
    const sec=total%60;
    return `${h}h ${String(m).padStart(2,"0")}m ${String(sec).padStart(2,"0")}s`;
  }
  function formatStartsIn(ms) {
    return `Starts in ${Math.max(1,Math.ceil(ms/60000))} mn`;
  }
  function arenaTiming(arena, now=Date.now()) {
    const start=Date.parse(arena?.startAt || "");
    const durationMinutes=Number(arena?.durationMinutes);
    if (!Number.isFinite(start) || !Number.isFinite(durationMinutes) || durationMinutes <= 0) return null;
    const end=start + durationMinutes*60000;
    if (now >= end) return null;
    const phase = now < start-3600000 ? "upcoming" : now < start ? "registration" : "ongoing";
    return {start,end,phase};
  }
  function formatArenaStart(start) {
    const date=new Date(start);
    if (!Number.isFinite(date.getTime())) return "—";
    const now=new Date();
    const tomorrow=new Date(now); tomorrow.setDate(now.getDate()+1);
    const sameDay=(a,b)=>a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate();
    const time=new Intl.DateTimeFormat("en-GB",{hour:"2-digit",minute:"2-digit",hour12:false}).format(date);
    if (sameDay(date,now)) return `Today ${time}`;
    if (sameDay(date,tomorrow)) return `Tomorrow ${time}`;
    return new Intl.DateTimeFormat("en-GB",{day:"numeric",month:"short",hour:"2-digit",minute:"2-digit",hour12:false}).format(date).replace(",","");
  }
  function renderArenas(arenas=arenaConfig) {
    arenaConfig=Array.isArray(arenas)?arenas:[];
    const now=Date.now();
    const visible=arenaConfig
      .filter(arena => arena?.active === true && arena?.name && arena?.link)
      .map(arena => ({arena,timing:arenaTiming(arena,now)}))
      .filter(entry => entry.timing)
      .sort((a,b)=>a.timing.start-b.timing.start)
      .slice(0,2);
    arenaRows.hidden = visible.length === 0;
    arenaSeparator.hidden = visible.length !== 0;
    arenaGap.hidden = visible.length === 0;
    arenaRows.innerHTML = visible.map(({arena,timing}) => {
      const duration = arena.duration ? `<span class="pm-widget-chip pm-arena-duration">${api.escapeHTML(arena.duration)}</span>` : "";
      const time = arena.timeControl ? `<span class="pm-widget-chip pm-arena-time">${api.escapeHTML(arena.timeControl)}</span>` : "";
      const status = timing.phase === "upcoming"
        ? '<span class="pm-arena-status upcoming">Upcoming</span>'
        : timing.phase === "registration"
          ? '<span class="pm-arena-status registration">Registration</span>'
          : '<span class="pm-arena-status">On-going</span>';
      const countdown = timing.phase === "ongoing"
        ? `<span class="pm-widget-chip pm-arena-countdown" data-arena-countdown title="Time remaining">${api.escapeHTML(formatCountdown(timing.end-now))}</span>`
        : timing.phase === "registration"
          ? `<span class="pm-widget-chip pm-arena-countdown registration" data-arena-countdown title="Time until start">${api.escapeHTML(formatStartsIn(timing.start-now))}</span>`
          : `<span class="pm-widget-start pm-arena-start" title="${api.escapeHTML(api.formatStart(timing.start/1000))}">${api.escapeHTML(formatArenaStart(timing.start))}</span>`;
      return `<div class="dashboard-recommendation pm-widget-row pm-arena-row" role="link" tabindex="0" data-href="${api.escapeHTML(arena.link)}" data-arena-start="${timing.start}" data-arena-end="${timing.end}" data-arena-phase="${timing.phase}" title="${api.escapeHTML(arena.name)}">${status}<span class="pm-arena-name">${api.escapeHTML(arena.name)}</span>${duration}${time}${countdown}</div>`;
    }).join('<hr class="pm-widget-separator" aria-hidden="true">');
    bindRows();
    requestAnimationFrame(fitRows);
  }
  async function cleanupExpiredArenas() {
    if (arenaCleanupPending) return;
    arenaCleanupPending=true;
    try {
      const state=await loadState();
      arenaConfig=state.arenas || [];
      renderArenas(arenaConfig);
    } catch (_) {
      renderArenas(arenaConfig);
    } finally { arenaCleanupPending=false; }
  }
  function tickArenaClock() {
    const now=Date.now();
    let phaseChanged=false, expired=false;
    document.querySelectorAll(".pm-arena-row[data-arena-start]").forEach(row=>{
      const start=Number(row.dataset.arenaStart), end=Number(row.dataset.arenaEnd);
      if (now >= end) { expired=true; return; }
      if (row.dataset.arenaPhase === "upcoming" && now >= start-3600000) { phaseChanged=true; return; }
      if (row.dataset.arenaPhase === "registration" && now >= start) { phaseChanged=true; return; }
      const countdown=row.querySelector("[data-arena-countdown]");
      if (countdown) countdown.textContent=row.dataset.arenaPhase === "registration"
        ? formatStartsIn(start-now)
        : formatCountdown(end-now);
    });
    if (expired) { void cleanupExpiredArenas(); return; }
    if (phaseChanged) renderArenas(arenaConfig);
  }
  function startArenaClock() {
    if (arenaTimer) clearInterval(arenaTimer);
    arenaTimer=window.setInterval(tickArenaClock,1000);
  }

  function render() {
    document.querySelectorAll("[data-filter]").forEach(button => {
      const active=button.dataset.filter===filter;
      button.classList.toggle("is-active",active);
      button.setAttribute("aria-selected",active?"true":"false");
    });
    const visible = matches.filter(match => match.joinable !== false && match.category === filter).slice(0, 4);
    rows.innerHTML = visible.map(match => {
      const badge = match.category === "league" ? ((match.leagueAcronyms||[]).join("/") || "League") : "Friendly";
      const rating = match.ratingLabel || "Open";
      const urgency = api.startUrgency(match.startTime);
      const timeCompact = api.timePerMoveCompactLabel(match.timePerMove);
      const timeFull = api.timePerMoveLabel(match.timePerMove);
      const recruitment = match.recruitment;
      const count = Number.isFinite(Number(recruitment?.count)) ? Number(recruitment.count) : null;
      const ready = count === 0;
      const severity = recruitmentSeverity(count);
      const needText = count === null ? "Need ?" : ready ? "Need ✓" : `Need ${count}`;
      const opponent = match.opponentName || "Opponent";
      const startMs = Number(match.startTime) * 1000;
      const leagueWithin48h = match.category === "league" && Number.isFinite(startMs) && startMs >= Date.now() && startMs - Date.now() <= 48 * 3600000;
      const pin = match.urgent ? '<span class="pm-widget-pin" title="Manually marked urgent by a P2K administrator">Priority</span>' : '';
      const time = timeCompact ? `<span class="pm-widget-chip pm-widget-time" title="${api.escapeHTML(timeFull)}">${api.escapeHTML(timeCompact)}</span>` : '';
      return `<div class="dashboard-recommendation pm-widget-row${match.category==="league"?" is-priority":""}${leagueWithin48h?" pm-league-48h":""}${match.urgent?" is-admin-urgent":""}" role="link" tabindex="0" data-href="${api.escapeHTML(match.url)}" title="${api.escapeHTML(match.name)}"><span class="dashboard-tag ${match.category}">${api.escapeHTML(badge)}</span><span class="pm-widget-opponent">vs ${api.escapeHTML(opponent)}</span>${pin}<span class="pm-widget-start ${api.escapeHTML(urgency.level)}" title="${api.escapeHTML(urgency.title)}">${api.escapeHTML(urgency.label)}</span><span class="pm-widget-chip pm-widget-rating" title="Rating category: ${api.escapeHTML(rating)}">${api.escapeHTML(rating)}</span>${time}<span class="pm-widget-chip pm-widget-recruits${ready?" ready":""}${severity?` ${severity}`:""}">${api.escapeHTML(needText)}</span></div>`;
    }).join('<hr class="pm-widget-separator" aria-hidden="true">') || '<div class="pm-widget-empty">No open priority match in this category.</div>';
    bindRows();
    requestAnimationFrame(()=>{ fitRows(); resizeParent(); });
  }
  async function load() {
    rows.innerHTML = '<div class="pm-widget-empty">Loading priority matches…</div>';
    const [selection, catalog] = await Promise.all([loadState(), api.loadCatalog(false)]);
    arenaConfig=selection.arenas || [];
    renderArenas(arenaConfig);
    startArenaClock();
    const byId = new Map((catalog.matches || []).map(match => [String(match.matchId), match]));
    const selected = (selection.items || [])
      .filter(item => item.enabled !== false)
      .map(item => {
        const match=byId.get(String(item.matchId));
        return match ? { ...match, urgent:item.urgent === true } : null;
      })
      .filter(Boolean);
    const detailIds = new Set([
      ...selected.filter(match => match.category === "league").slice(0, 8),
      ...selected.filter(match => match.category === "friendly").slice(0, 8),
      ...selected.slice(0, 8)
    ].map(match => String(match.matchId)));
    const detailTargets = selected.filter(match => detailIds.has(String(match.matchId)));
    const enriched = await api.enrichMatches(detailTargets);
    const enrichedById = new Map(enriched.map(match => [String(match.matchId), match]));
    matches = selected.map(match => enrichedById.get(String(match.matchId)) || match).filter(match => match.joinable !== false);
    filter = chooseDefault();
    render();
  }
  document.querySelectorAll("[data-filter]").forEach(button => button.addEventListener("click", () => { filter=button.dataset.filter; render(); }));
  window.addEventListener("resize",()=>requestAnimationFrame(fitRows));
  window.addEventListener("message", event => { if (event.data?.type === "p2k-events-showcase-refresh") load().catch(() => {}); });
  load().catch(error => { console.error(error); rows.innerHTML='<div class="pm-widget-empty">Priority matches are temporarily unavailable.</div>'; resizeParent(); });
})();

</script></body></html>
