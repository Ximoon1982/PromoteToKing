/* Canonical Administration shell extracted without changing route or DOM semantics. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.adminShell = Object.freeze({
create(context) {
const { state, byId, escapeHTML, number, setText, applyOAuthContext, setIntegratedFrameActivity, ensureIntegratedFrame, writeNavigationState, tools } = context;
const ADMIN_DETAIL_DEFS = {
  competitions: {
    daily: { title:"Daily Matches", tabs:[
      {key:"upcoming",label:"Upcoming matches",src:"AnalyzeMatches.htm?embedded=1&release=2.10.6.14"},
      {key:"creation",label:"Match creation",src:"MatchCreationAnalyzer.htm?embedded=1&release=2.10.6.14"},
      {key:"challenge",label:"Challenge assistant",src:"ChallengeListAssistant.html?embedded=1&release=2.10.6.14"},
      {key:"analyzer",label:"Open analyzer",src:"AnalyzeMatch.html?embedded=1&release=2.10.6.14"}
    ]},
    mca: { title:"Multi Club Arenas", tabs:[
      {key:"source",label:"MCA source data",src:"TeamPointsAdmin.html?embedded=1&tab=live-ranks&release=2.10.6.14"}
    ]},
    tournaments: { title:"Tournaments", tabs:[{key:"management",label:"Tournament management",src:"TournamentManagement.html?embedded=1&release=2.10.6.23"}]}
  },
  members: {
    depth: { title:"Team Depth", tabs:[{key:"depth",label:"Team depth",src:"ClubIntelligence.html?embedded=1&tab=depth&release=2.10.6.14"}]},
    chronology: { title:"Member chronology", tabs:[{key:"chronology",label:"Chronology",src:"TeamPointsAdmin.html?embedded=1&tab=members&release=2.10.6.14"}]},
    aliases: { title:"Aliases & name changes", tabs:[{key:"aliases",label:"Aliases & name changes",src:"ClubIntelligence.html?embedded=1&tab=aliases&release=2.10.6.14"}]},
    recruitment: { title:"Recruitment", tabs:[{key:"recruitment",label:"Recruitment",src:"RecruitmentAdmin.html?view=admin&adminCategory=members&adminDetail=recruitment&embedded=1&release=2.11.0-r6"}]}
  },
  team: {
    team: { title:"Club intelligence", tabs:[
      {key:"overview",label:"Overview",src:"ClubIntelligence.html?embedded=1&tab=overview&release=2.10.6.14"},
      {key:"forecast",label:"Forecast",src:"ClubIntelligence.html?embedded=1&tab=forecast&release=2.10.6.14"},
      {key:"snapshots",label:"Snapshots",src:"ClubIntelligence.html?embedded=1&tab=snapshots&release=2.10.6.14"},
      {key:"anomalies",label:"Anomalies",src:"ClubIntelligence.html?embedded=1&tab=anomalies&release=2.10.6.14"}
    ]}
  },
  opponents: {
    opponents: { title:"Opponent intelligence", tabs:[
      {key:"intelligence",label:"Opponent intelligence",src:"ClubIntelligence.html?embedded=1&tab=opponents&release=2.10.6.14"},
      {key:"challenge",label:"Challenge assistant",src:"ChallengeListAssistant.html?embedded=1&release=2.10.6.14"}
    ]}
  },
  maintenance: {
    diagnostics: { title:"Diagnostics", tabs:[
      {key:"health",label:"Runtime diagnostics",src:"InsightsHealth.html?embedded=1&release=2.10.9"},
      {key:"reconciliation",label:"Data reconciliation",src:"DataReconciliation.html?embedded=1&release=2.10.6.14"}
    ]},
    tasks: { title:"Scheduled Task Control", tabs:[
      {key:"control",label:"Task Control",src:"TaskControl.html?embedded=1&tab=scheduled&release=2.10.6.16"},
      {key:"migration",label:"Production migration",src:"TeamPointsMigration.html?embedded=1&release=2.10.6.14"}
    ]},
    logs: { title:"Task logs", tabs:[{key:"logs",label:"Task logs",src:"TaskLogs.html?embedded=1&release=2.10.6.14"}]},
    storage: { title:"Storage & Capacity", tabs:[{key:"storage",label:"Storage & capacity",src:"TeamPointsAdmin.html?embedded=1&tab=storage&release=2.10.6.14"}]},
    performance: { title:"Performance", tabs:[{key:"performance",label:"Performance",src:"ClubIntelligence.html?embedded=1&tab=performance&release=2.10.6.14"}]},
    freshness: { title:"Freshness", tabs:[{key:"freshness",label:"Freshness",src:"ClubIntelligence.html?embedded=1&tab=freshness&release=2.10.6.14"}]},
    traffic: { title:"Traffic & visitors", tabs:[{key:"traffic",label:"Traffic & visitors",src:"ClubIntelligence.html?embedded=1&tab=traffic&release=2.10.6.14"}]}
  }
};
function adminDetailDefinition(category=state.category, detail=state.adminDetail) { return ADMIN_DETAIL_DEFS?.[category]?.[detail] || null; }
function adminShellHref(category, detail="", detailTab="", toolTab="") {
  const url=new URL("ui-v2.html",location.href);
  ["page","adminTool","adminContext","hall","insights","assistant","assistantFilter"].forEach(key=>url.searchParams.delete(key));
  url.searchParams.set("ui","v2");url.searchParams.set("view","admin");url.searchParams.set("adminCategory",category||"competitions");
  if(detail)url.searchParams.set("adminDetail",detail);else url.searchParams.delete("adminDetail");
  if(detailTab)url.searchParams.set("adminDetailTab",detailTab);else url.searchParams.delete("adminDetailTab");
  if(detail&&toolTab)url.searchParams.set("adminToolTab",toolTab);else url.searchParams.delete("adminToolTab");
  url.hash="";return url.href;
}
function adminShellCard({ key, category, detail=key, detailTab="", eyebrow, title, description, metrics = [], source = "Existing P2K data", links = [] }) {
const metricMarkup = metrics.map(metric => `<div class="dashboard-admin-shell-metric"><span>${escapeHTML(metric.label)}</span><strong id="${escapeHTML(metric.id)}">${escapeHTML(metric.value || "—")}</strong><small id="${escapeHTML(metric.noteId || `${metric.id}Note`)}">${escapeHTML(metric.note || "Loading…")}</small></div>`).join("");
const linkMarkup = links.map(link => `<a class="dashboard-button${link.secondary ? " is-secondary" : ""}" data-admin-shell-route="1" href="${escapeHTML(adminShellHref(category,detail,link.detailTab||link.tab||detailTab,link.toolTab||""))}">${escapeHTML(link.label)}</a>`).join("");
return `<article class="dashboard-admin-shell-card" data-admin-shell-card="${escapeHTML(key)}" data-admin-detail="${escapeHTML(detail)}" data-admin-detail-tab="${escapeHTML(detailTab)}" data-admin-detail-category="${escapeHTML(category)}" role="link" tabindex="0" aria-label="Open ${escapeHTML(title)}">
  <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">${escapeHTML(eyebrow)}</span><h3>${escapeHTML(title)}</h3></div><span class="dashboard-admin-shell-status is-loading" id="adminShellStatus_${escapeHTML(key)}">Loading</span></header>
  <p>${escapeHTML(description)}</p>
  <div class="dashboard-admin-shell-metrics">${metricMarkup}</div>
  <div class="dashboard-admin-shell-meta"><span><b>Freshness</b><em id="adminShellFresh_${escapeHTML(key)}">Checking…</em></span><span><b>Source</b><em id="adminShellSource_${escapeHTML(key)}">${escapeHTML(source)}</em></span></div>
  <footer class="dashboard-admin-shell-actions">${linkMarkup}</footer>
</article>`;
}
function adminMemberLookupCard(){
return `<article class="dashboard-admin-shell-card dashboard-admin-member-lookup-card" data-admin-member-lookup-card>
  <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">Members</span><h3>Member lookup</h3></div><span class="dashboard-admin-shell-status is-good">Ready</span></header>
  <p>Look up a current or former member by any known username. The result combines Green identity/lifecycle data with stored Daily, rating, achievement and MCA facts.</p>
  <form class="dashboard-admin-member-lookup-form" id="adminMemberLookupForm">
    <label for="adminMemberLookupInput">Chess.com username</label>
    <div><input id="adminMemberLookupInput" type="search" autocomplete="off" spellcheck="false" maxlength="80" placeholder="Enter current or former username"><button class="dashboard-button" type="submit">Look up</button></div>
  </form>
  <div class="dashboard-admin-member-lookup-status" id="adminMemberLookupStatus">Enter a username to display all known member information.</div>
  <div class="dashboard-admin-member-lookup-result" id="adminMemberLookupResult" hidden></div>
</article>`;
}
function adminMemberLookupUtc(value,epoch=false){if(value===null||value===undefined||value==='')return'—';const d=epoch?new Date(Number(value)*1000):new Date(`${String(value).replace(' ','T')}${/[zZ]|[+-]\d\d:?\d\d$/.test(String(value))?'':'Z'}`);if(!Number.isFinite(d.getTime()))return String(value);return new Intl.DateTimeFormat('en-GB',{timeZone:'UTC',dateStyle:'medium',timeStyle:'short'}).format(d)+' UTC'}
function adminMemberLookupEventLabel(type){return({discovered:'Discovered',joined:'Joined',left:'Left',name_changed:'Name changed',rejoined:'Rejoined'})[type]||String(type||'Event')}
function renderAdminMemberLookup(payload){
 const host=byId('adminMemberLookupResult'),status=byId('adminMemberLookupStatus');if(!host||!status)return;const d=payload?.lookup||payload||{};
 if(!d.found){host.hidden=true;host.replaceChildren();status.className='dashboard-admin-member-lookup-status is-warn';status.textContent=`No Green member identity is known for “${d.query||byId('adminMemberLookupInput')?.value||''}”.`;return}
 const m=d.member||{},a=d.activity||{},lc=d.lifecycle||{},counts=lc.counts||{},aliases=Array.isArray(d.aliases)?d.aliases:[],events=Array.isArray(lc.events)?lc.events:[],live=d.live||null;
 const totalGames=Number(a.games||0),wins=Number(a.wins||0),winRate=totalGames?100*wins/totalGames:0,lastActivity=a.last_activity_epoch?adminMemberLookupUtc(a.last_activity_epoch,true):'—';
 const membership=m.current_member?'Current member':'Former member';const account=String(m.account_status||'').trim();const statusText=account?`${membership} · account ${account}`:membership;
 const aliasHtml=aliases.length?aliases.map(x=>`<span class="dashboard-admin-member-alias${x.current?' is-current':''}">${escapeHTML(x.username||x.username_key||'')}</span>`).join(''):'<span class="dashboard-admin-member-alias is-current">'+escapeHTML(m.username||'')+'</span>';
 const lifecycleHtml=events.length?events.slice(0,16).map(e=>{const transition=e.event_type==='name_changed'?` · ${escapeHTML(e.previous_username||e.username||'—')} → ${escapeHTML(e.new_username||'—')}`:'';const profile=e.event_type==='left'&&e.profile_status?` · ${escapeHTML(e.profile_status)}`:'';return `<li><time>${escapeHTML(adminMemberLookupUtc(e.detected_at))}</time><strong>${escapeHTML(adminMemberLookupEventLabel(e.event_type))}</strong><span>${escapeHTML(e.username||e.new_username||m.username||'')}${transition}${profile}</span></li>`}).join(''):'<li><span>No lifecycle event has been recorded.</span></li>';
 const avatar=m.avatar_url?`<img src="${escapeHTML(m.avatar_url)}" alt="" loading="lazy">`:'';
 const liveSummary=live?`${Number(live.total_points||0).toLocaleString('en-GB',{maximumFractionDigits:1})} pts · ${number(live.arena_count||0)} arenas · best rank ${live.best_rank?`#${number(live.best_rank)}`:'—'}`:'No stored MCA summary';
 host.innerHTML=`<div class="dashboard-admin-member-profile-head">${avatar}<div><h4>${escapeHTML(m.username||d.query||'Member')}</h4><p>${escapeHTML(statusText)}</p><div class="dashboard-admin-member-aliases">${aliasHtml}</div></div><a class="dashboard-button is-secondary" href="https://www.chess.com/member/${encodeURIComponent(m.username||d.query||'')}" target="_blank" rel="noopener noreferrer">Chess.com profile</a></div>
 <div class="dashboard-admin-member-metrics">
   <div><span>Daily rating</span><strong>${m.daily_rating??'—'}</strong></div><div><span>Chess960</span><strong>${m.chess960_rating??'—'}</strong></div><div><span>Team Points</span><strong>${Number(a.points||0).toLocaleString('en-GB',{maximumFractionDigits:1})}</strong></div><div><span>Matches / games</span><strong>${number(a.matches||0)} / ${number(a.games||0)}</strong></div><div><span>W / D / L</span><strong>${number(a.wins||0)} / ${number(a.draws||0)} / ${number(a.losses||0)}</strong><small>${totalGames?winRate.toFixed(1)+'% wins':'—'}</small></div><div><span>Current load</span><strong>${number(a.registered_matches||0)} + ${number(a.in_progress_matches||0)}</strong><small>registered + ongoing</small></div><div><span>Achievements</span><strong>${number(d.achievements||0)}</strong></div><div><span>MCA</span><strong>${live?Number(live.total_points||0).toLocaleString('en-GB',{maximumFractionDigits:1}):'—'}</strong><small>${escapeHTML(liveSummary)}</small></div>
 </div>
 <div class="dashboard-admin-member-detail-grid"><section><h5>Membership lifecycle</h5><dl><dt>Current join</dt><dd>${escapeHTML(adminMemberLookupUtc(m.joined_epoch,true))}</dd><dt>First observed</dt><dd>${escapeHTML(adminMemberLookupUtc(lc.first_event?.detected_at||m.created_at))}</dd><dt>Rejoins</dt><dd>${number(counts.rejoined||0)}</dd><dt>Last departure</dt><dd>${escapeHTML(adminMemberLookupUtc(lc.last_left?.detected_at||m.left_at))}</dd><dt>Closure evidence</dt><dd>${lc.last_closure?escapeHTML(adminMemberLookupUtc(lc.last_closure.detected_at)):(account.toLowerCase()==='closed'?'Account reported closed':'—')}</dd></dl></section>
 <section><h5>Activity & freshness</h5><dl><dt>Last Daily activity</dt><dd>${escapeHTML(lastActivity)}</dd><dt>Last roster observation</dt><dd>${escapeHTML(adminMemberLookupUtc(m.last_seen_roster_at))}</dd><dt>Profile checked</dt><dd>${escapeHTML(adminMemberLookupUtc(m.profile_checked_at))}</dd><dt>Stats checked</dt><dd>${escapeHTML(adminMemberLookupUtc(m.stats_checked_at))}</dd><dt>Chess player ID</dt><dd>${m.chess_player_id??'—'}</dd></dl></section></div>
 <section class="dashboard-admin-member-history"><h5>Lifecycle chronology</h5><ol>${lifecycleHtml}</ol></section>`;
 host.hidden=false;status.className='dashboard-admin-member-lookup-status is-good';status.textContent=`Resolved “${d.query||m.username}” to ${m.username}${aliases.length>1?` · ${aliases.length} known names`:''}.`;
}
async function loadAdminMemberLookup(){const input=byId('adminMemberLookupInput'),status=byId('adminMemberLookupStatus'),host=byId('adminMemberLookupResult');const username=String(input?.value||'').trim().replace(/^@/,'');if(!username){if(status)status.textContent='Enter a username first.';input?.focus();return}if(status){status.className='dashboard-admin-member-lookup-status';status.textContent='Looking up Green member history…'}if(host)host.hidden=true;try{const url=new URL('server/team-points-green/public/api.php',location.href);url.searchParams.set('action','member-lookup');url.searchParams.set('username',username);renderAdminMemberLookup(await adminShellJSON(url.href))}catch(error){if(status){status.className='dashboard-admin-member-lookup-status is-bad';status.textContent=error.message||String(error)}}}

function adminPanelMarkup() {
const cards = {
competitions: [
  adminShellCard({key:"daily",category:"competitions",eyebrow:"Daily matches",title:"Daily Matches",description:"Operational view of registered, ongoing and finished Daily team matches.",metrics:[{label:"Registered",id:"adminShellDailyRegistered"},{label:"Ongoing",id:"adminShellDailyOngoing"},{label:"Club points",id:"adminShellDailyPoints"}],source:"Green Core",links:[{label:"Upcoming matches",tab:"upcoming"},{label:"Match creation",tab:"creation",secondary:true}]}),
  adminShellCard({key:"mca",category:"competitions",eyebrow:"Multi Club Arenas",title:"Multi Club Arenas",description:"Stored MCA source coverage and computed Live arena totals.",metrics:[{label:"Arenas",id:"adminShellMcaArenas"},{label:"Current players",id:"adminShellMcaPlayers"},{label:"Arena points",id:"adminShellMcaPoints"}],source:"Green Analytics · MCA results CSV",links:[{label:"MCA source data",tab:"source"},{label:"Task control",tab:"tasks",secondary:true}]}),
  adminShellCard({key:"tournaments",category:"competitions",eyebrow:"Tournament archive",title:"Tournaments",description:"Tournament archive coverage and maintenance state.",metrics:[{label:"Recorded",id:"adminShellTournamentCount"},{label:"Players ranked",id:"adminShellTournamentPlayers"},{label:"Years",id:"adminShellTournamentYears"}],source:"Tournament archive index",links:[{label:"Tournament management",tab:"management"}]})
],
members: [
  adminMemberLookupCard(),
  adminShellCard({key:"depth",category:"members",eyebrow:"Members",title:"Team Depth",description:"Current rating-band depth, activity and availability coverage.",metrics:[{label:"Current members",id:"adminShellDepthMembers"},{label:"Active ≤30d",id:"adminShellDepthActive"},{label:"Rating coverage",id:"adminShellDepthRatings"}],source:"Club Intelligence · Green Core/Analytics",links:[{label:"Open team depth",tab:"depth"}]}),
  adminShellCard({key:"chronology",category:"members",eyebrow:"Members",title:"Chronology",description:"Join, leave and name-change chronology from the authoritative Green member lifecycle.",metrics:[{label:"Observed members",id:"adminShellChronMembers"},{label:"Lifecycle events",id:"adminShellChronEvents"},{label:"Pending checks",id:"adminShellChronPending"}],source:"Green member lifecycle",links:[{label:"Open chronology",tab:"chronology"}]}),
  adminShellCard({key:"aliases",category:"members",eyebrow:"Members",title:"Aliases & name changes",description:"Canonical identity mappings, possible renames and review state.",metrics:[{label:"Known names",id:"adminShellAliasMappings"},{label:"Review queue",id:"adminShellAliasReview"},{label:"Confirmed",id:"adminShellAliasConfirmed"}],source:"MIAC identity graph",links:[{label:"Open aliases",tab:"aliases"}]}),
  adminShellCard({key:"recruitment",category:"members",eyebrow:"Members",title:"Recruitment",description:"Maintain the candidate pool and evaluate prospective members against Daily activity, reliability and membership criteria.",metrics:[{label:"Candidates",id:"adminRecruitmentCandidates"},{label:"Checked",id:"adminRecruitmentChecked"},{label:"Selected",id:"adminRecruitmentSelected"}],source:"Green Core + Chess.com OAuth",links:[{label:"Open Recruitment",tab:"recruitment"}]})
],
team: [adminShellCard({key:"team",category:"team",eyebrow:"Team",title:"Club intelligence",description:"Authoritative team health, Club Points, snapshots, forecast and current anomalies.",metrics:[{label:"Club points",id:"adminShellTeamPoints"},{label:"Current members",id:"adminShellTeamMembers"},{label:"Open anomalies",id:"adminShellTeamAnomalies"}],source:"Green Core · Club Intelligence",links:[{label:"Open club intelligence",tab:"overview"},{label:"Forecast",tab:"forecast",secondary:true}]})],
opponents: [adminShellCard({key:"opponents",category:"opponents",eyebrow:"Opponents",title:"Opponent intelligence",description:"Recurring opponents, historical outcomes and opponent maintenance intelligence.",metrics:[{label:"Profiles",id:"adminShellOpponentProfiles"},{label:"Recent opponents",id:"adminShellOpponentRecent"},{label:"Review needed",id:"adminShellOpponentReview"}],source:"Club Intelligence · Green match history",links:[{label:"Open opponent intelligence",tab:"intelligence"},{label:"Challenge assistant",tab:"challenge",secondary:true}]})],
maintenance: [
  adminShellCard({key:"diagnostics",category:"maintenance",eyebrow:"Maintenance",title:"Diagnostics",description:"Runtime and API health with direct access to diagnostic details.",metrics:[{label:"Open anomalies",id:"adminShellDiagAnomalies"},{label:"Failed queue",id:"adminShellDiagFailed"},{label:"Unresolved boards",id:"adminShellDiagBoards"}],source:"Club Intelligence · runtime diagnostics",links:[{label:"Open diagnostics",tab:"health"},{label:"Data reconciliation",tab:"reconciliation",secondary:true}]}),
  adminShellCard({key:"tasks",category:"maintenance",eyebrow:"Maintenance",title:"Scheduled Task Control",description:"CRON-compatible task state, including the Green accelerator control.",metrics:[{label:"Queue pending",id:"adminShellTaskPending"},{label:"Queue failed",id:"adminShellTaskFailed"},{label:"Core generation",id:"adminShellTaskGeneration"}],source:"Task registry · Green state",links:[{label:"Scheduled tasks",detailTab:"control",toolTab:"scheduled"},{label:"Green Team Points",detailTab:"control",toolTab:"green",secondary:true}]}),
  adminShellCard({key:"logs",category:"maintenance",eyebrow:"Maintenance",title:"Logs",description:"Execution history for match, database and tournament scheduled tasks.",metrics:[{label:"Recent endpoint events",id:"adminShellLogEvents"},{label:"Telemetry days",id:"adminShellLogDays"}],source:"Task logs · runtime telemetry",links:[{label:"Open task logs",tab:"logs"}]}),
  adminShellCard({key:"storage",category:"maintenance",eyebrow:"Maintenance",title:"Storage & Capacity",description:"Core, Analytics and filesystem capacity with projection tools.",metrics:[{label:"Core → Analytics lag",id:"adminShellStorageLag"},{label:"Finished boards verified",id:"adminShellStorageBoards"}],source:"Green Core/Analytics storage telemetry",links:[{label:"Storage & capacity",tab:"storage"}]}),
  adminShellCard({key:"performance",category:"maintenance",eyebrow:"Maintenance",title:"Performance",description:"Endpoint volume, errors and latency telemetry.",metrics:[{label:"Endpoint events",id:"adminShellPerfEvents"},{label:"ACAMR claims",id:"adminShellPerfClaims"},{label:"Warnings",id:"adminShellPerfWarnings"}],source:"Protected runtime telemetry",links:[{label:"Open performance",tab:"performance"}]}),
  adminShellCard({key:"freshness",category:"maintenance",eyebrow:"Maintenance",title:"Freshness",description:"Roster, ratings, player facts, boards and worker-queue freshness.",metrics:[{label:"Fresh ratings",id:"adminShellFreshRatings"},{label:"Player matches fresh",id:"adminShellFreshMatches"},{label:"Boards pending",id:"adminShellFreshBoards"}],source:"Club Intelligence freshness",links:[{label:"Open freshness",tab:"freshness"}]}),
  adminShellCard({key:"traffic",category:"maintenance",eyebrow:"Maintenance",title:"Traffic & visitors",description:"First-party cookieless page views, sessions and visitor estimates.",metrics:[{label:"Page views",id:"adminShellTrafficViews"},{label:"Sessions",id:"adminShellTrafficSessions"},{label:"Est. uniques",id:"adminShellTrafficUniques"}],source:"First-party traffic analytics",links:[{label:"Traffic / visitors",tab:"traffic"}]})
]};
return `<section aria-label="Administrator dashboard" class="dashboard-panel dashboard-admin-shell" id="adminDashboardPanel">
  <nav aria-label="Administrator sections" class="dashboard-page-tabs dashboard-admin-category-tabs" role="tablist">
    <button aria-pressed="true" data-admin-category="competitions" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M8 4h8v4a4 4 0 0 1-8 0V4Z"/><path d="M8 6H5v2a3 3 0 0 0 3 3M16 6h3v2a3 3 0 0 1-3 3M12 12v5M8.5 20h7M10 17h4"/></svg><span>Competitions</span></button>
    <button aria-pressed="false" data-admin-category="members" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 20v-1.5A4.5 4.5 0 0 1 8 14h2a4.5 4.5 0 0 1 4.5 4.5V20M14.5 15a4 4 0 0 1 6 3.5V20"/></svg><span>Members</span></button>
    <button aria-pressed="false" data-admin-category="team" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 7.7 7 10 4.2-2.3 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg><span>Team</span></button>
    <button aria-pressed="false" data-admin-category="opponents" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M7 4 20 17M17 4l3 3-13 13-3-3L17 4Z"/><path d="m4 7 3-3 13 13-3 3"/></svg><span>Opponents</span></button>
    <button aria-pressed="false" data-admin-category="maintenance" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><path d="M14.7 6.3a4 4 0 0 0-5 5L4 17l3 3 5.7-5.7a4 4 0 0 0 5-5l-2.4 2.4-3-3 2.4-2.4Z"/></svg><span>Maintenance</span></button>
    <button aria-pressed="false" data-admin-category="misc" type="button"><svg aria-hidden="true" class="dashboard-tab-icon" focusable="false" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg><span>Misc</span></button>
  </nav>
  <div class="dashboard-admin-shell-head-actions dashboard-admin-shell-menu-actions"><span class="dashboard-context-badge dashboard-context-admin">OAuth/local admin</span><button type="button" class="dashboard-button is-secondary" id="adminShellRefresh">Refresh live cards</button></div>
  <div class="dashboard-admin-shell-updated"><span id="adminShellOverallStatus">Loading live administration data…</span><small id="adminShellUpdated">Status · freshness · source are shown on every card.</small></div>
  ${Object.entries(cards).map(([key,list])=>`<section class="dashboard-admin-shell-panel" data-admin-shell-panel="${key}" ${key==="competitions"?"":"hidden"}><div class="dashboard-admin-shell-grid">${list.join("")}</div></section>`).join("")}
  <section class="dashboard-admin-shell-panel" data-admin-shell-panel="misc" hidden><div class="dashboard-admin-lost-found-head"><div><span class="dashboard-admin-shell-eyebrow">Misc</span><h3>Lost &amp; found tools</h3><p>Complete catalogue of existing administrator tools. Nothing was removed by the new shell.</p></div><strong>${tools.length} tools</strong></div><div class="dashboard-tool-grid dashboard-admin-lost-found-grid" id="adminToolGrid"></div></section>
  <section class="dashboard-admin-shell-detail" id="adminShellDetail" hidden>
    <div class="dashboard-admin-detail-heading"><div><button class="dashboard-button is-secondary" id="adminShellDetailBack" type="button">← Back</button><p class="dashboard-eyebrow" id="adminShellDetailBreadcrumb">Administration</p><h3 id="adminShellDetailTitle">Detail</h3></div></div>
    <nav class="dashboard-admin-detail-tabs" id="adminShellDetailTabs" aria-label="Administration detail sections"></nav>
    <div class="dashboard-admin-detail-frame-wrap" id="adminShellDetailFrameWrap"><iframe class="dashboard-integrated-frame dashboard-admin-detail-frame" id="adminShellDetailFrame" title="Administration detail"></iframe></div>
    <div class="dashboard-admin-detail-native" id="adminShellNativeDetailHost" hidden></div>
  </section>
</section>`;
}
function adminShellSet(id,value,note){const el=byId(id);if(el)el.textContent=value??"—";if(note!==undefined){const n=byId(`${id}Note`);if(n)n.textContent=note}}
function adminShellAge(value){if(!value)return"—";const t=Date.parse(value);if(!Number.isFinite(t))return String(value);const sec=Math.max(0,(Date.now()-t)/1000);if(sec<60)return"just now";if(sec<3600)return`${Math.round(sec/60)} min ago`;if(sec<86400)return`${(sec/3600).toFixed(1)} h ago`;return`${(sec/86400).toFixed(1)} d ago`}
function adminShellStatus(key,label,tone="good",freshness="—",source=null){const b=byId(`adminShellStatus_${key}`);if(b){b.textContent=label;b.className=`dashboard-admin-shell-status is-${tone}`;}const f=byId(`adminShellFresh_${key}`);if(f)f.textContent=freshness;if(source){const s=byId(`adminShellSource_${key}`);if(s)s.textContent=source}}
function adminShellNumber(value){const n=Number(value);return Number.isFinite(n)?number(n):"—"}
function adminShellPercent(value){const n=Number(value);return Number.isFinite(n)?`${n.toFixed(1)}%`:"—"}
function adminShellOpenDetail(category,detail,tab="",{updateHistory=true,replace=false,toolTab=""}={}){
  const def=adminDetailDefinition(category,detail);if(!def)return;
  state.category=category;state.adminDetail=detail;state.adminDetailTab=def.tabs.some(item=>item.key===tab)?tab:def.tabs[0].key;state.adminToolTab=String(toolTab||"").toLowerCase();
  adminShellActivate();if(updateHistory)writeNavigationState({replace});
}
function adminShellCloseDetail({updateHistory=true}={}){
  setIntegratedFrameActivity("");
  state.adminDetail="";state.adminDetailTab="";state.adminToolTab="";adminShellActivate();if(updateHistory)writeNavigationState();
}
function renderAdminShellDetail(){
  const detailHost=byId("adminShellDetail");if(!detailHost)return;
  const def=adminDetailDefinition();
  const frame=byId("adminShellDetailFrame");
  const frameWrap=byId("adminShellDetailFrameWrap")||frame?.parentElement;
  const nativeHost=byId("adminShellNativeDetailHost");
  if(!def){detailHost.hidden=true;setIntegratedFrameActivity("");if(frameWrap)frameWrap.hidden=false;if(frame)frame.hidden=false;if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}return;}
  const validTab=def.tabs.find(item=>item.key===state.adminDetailTab)||def.tabs[0];state.adminDetailTab=validTab.key;
  detailHost.hidden=false;setText("adminShellDetailTitle",def.title);setText("adminShellDetailBreadcrumb",`Administration · ${state.category.replace("maintenance","Maintenance")}`);
  const tabs=byId("adminShellDetailTabs");if(tabs){tabs.hidden=def.tabs.length<=1;tabs.innerHTML=def.tabs.map(item=>`<a class="dashboard-admin-detail-tab${item.key===validTab.key?" is-active":""}" data-admin-detail-tab="${escapeHTML(item.key)}" href="${escapeHTML(adminShellHref(state.category,state.adminDetail,item.key,item.key===validTab.key?state.adminToolTab:""))}" aria-current="${item.key===validTab.key?"page":"false"}">${escapeHTML(item.label)}</a>`).join("");}
  const nativeKey=String(validTab.nativeKey||"");
  const nativeMode=validTab.mode==="native"||nativeKey!=="";
  if(nativeMode){
    setIntegratedFrameActivity("");
    if(frameWrap)frameWrap.hidden=true;
    if(frame){frame.hidden=true;frame.removeAttribute("data-p2k-r2-stable");}
    if(nativeHost){nativeHost.hidden=false;nativeHost.dataset.nativeDetail=nativeKey||validTab.key;}
    return;
  }
  if(nativeHost){nativeHost.hidden=true;nativeHost.replaceChildren();nativeHost.removeAttribute("data-native-detail");}
  if(frameWrap)frameWrap.hidden=false;
  if(frame){
    frame.hidden=false;
    const targetUrl=applyOAuthContext(new URL(validTab.src,location.href));
    targetUrl.searchParams.set("embedded","1");
    targetUrl.searchParams.set("active","1");
    if(state.adminToolTab&&targetUrl.searchParams.has("tab"))targetUrl.searchParams.set("tab",state.adminToolTab);
    const target=targetUrl.href;
    if(frame.src!==target){
      setIntegratedFrameActivity("");
      frame.dataset.p2kLoaded="0";frame.dataset.p2kRetried="0";frame.style.height="";
      frame.title=`${def.title} — ${validTab.label}`;frame.src=target;
    }
    ensureIntegratedFrame("adminShellDetailFrame");
  }
}
function adminShellActivate(){
  const def=adminDetailDefinition();
  const detail=Boolean(def);
  document.querySelectorAll("[data-admin-shell-panel]").forEach(panel=>panel.hidden=detail||panel.dataset.adminShellPanel!==state.category);
  document.querySelectorAll("[data-admin-category]").forEach(button=>{const active=button.dataset.adminCategory===state.category;button.setAttribute("aria-pressed",String(active));button.classList.toggle("is-active",active);button.setAttribute("aria-selected",String(active));button.tabIndex=active?0:-1;});
  if(detail)renderAdminShellDetail();else if(byId("adminShellDetail"))byId("adminShellDetail").hidden=true;
  const activeTab=def?.tabs?.find(item=>item.key===state.adminDetailTab)||def?.tabs?.[0]||null;
  try{window.dispatchEvent(new CustomEvent("p2k-admin-shell-route",{detail:{category:state.category,detail:state.adminDetail,tab:state.adminDetailTab,nativeKey:activeTab?.nativeKey||""}}));}catch(_){ }
}
async function adminShellJSON(url){const response=await fetch(url,{credentials:"same-origin",cache:"no-store"});const payload=await response.json();if(!response.ok||payload?.ok===false)throw new Error(payload?.error?.message||`HTTP ${response.status}`);return payload}
async function loadAdminShellMetrics(force=false){
const host=byId("adminDashboardHost");if(!host||!byId("adminDashboardPanel")||!state.admin)return;if(state.adminShellLoading)return;if(!force&&state.adminShellLoadedAt&&Date.now()-state.adminShellLoadedAt<30000)return;state.adminShellLoading=true;setText("adminShellOverallStatus","Refreshing live administration data…");
const requests={
team:()=>window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team",{_fresh:Date.now()})||adminShellJSON("server/team-points/public/public.php?action=team"),
live:()=>adminShellJSON("server/team-points/public/public.php?action=live-team"),
tournaments:()=>adminShellJSON("server/tournaments/public/browse.php?view=summary"),
overview:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=overview"),
freshness:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=freshness"),
aliases:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=aliases&limit=500"),
opponents:()=>adminShellJSON("server/team-points/public/public.php?action=opponents&page=1&page_size=25&include_summary=1"),
chronology:()=>window.P2K_TEAM_POINTS_CLIENT?.endpointRequest?.("server/team-points-green/public/api.php",{action:"member-events",method:"GET",params:{limit:500},serverTrafficClass:"foreground"})||Promise.reject(new Error("Green member chronology client unavailable")),
performance:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=performance"),
traffic:()=>adminShellJSON("server/team-points/public/intelligence.php?scope=traffic&days=90")
};
const results=Object.fromEntries(await Promise.all(Object.entries(requests).map(async([key,fn])=>{try{return[key,{ok:true,payload:await fn()}]}catch(error){return[key,{ok:false,error}]}})));
const team=results.team.ok?(results.team.payload?.team||results.team.payload||{}):{};const live=results.live.ok?(results.live.payload?.team||results.live.payload||{}):{};const tour=results.tournaments.ok?results.tournaments.payload:{};const overview=results.overview.ok?(results.overview.payload?.data||{}):{};const fresh=results.freshness.ok?(results.freshness.payload?.data||{}):{};const aliases=results.aliases.ok?(results.aliases.payload?.data||{}):{};const opponents=results.opponents.ok?(results.opponents.payload||{}):{};const chronology=results.chronology.ok?(results.chronology.payload||{}):{};const perf=results.performance.ok?(results.performance.payload?.data||{}):{};const traffic=results.traffic.ok?(results.traffic.payload?.data||{}):{};
adminShellSet("adminShellDailyRegistered",adminShellNumber(team.registered_matches));adminShellSet("adminShellDailyOngoing",adminShellNumber(team.in_progress_matches??team.ongoing_matches));adminShellSet("adminShellDailyPoints",adminShellNumber(team.club_points));adminShellStatus("daily",results.team.ok?"Healthy":"Unavailable",results.team.ok?"good":"bad",adminShellAge(team.cache_updated_at),team.data_source==="green_native_core"?"Green Core · live":"Team Points database");
adminShellSet("adminShellMcaArenas",adminShellNumber(live.arenas));adminShellSet("adminShellMcaPlayers",adminShellNumber(live.current_players));adminShellSet("adminShellMcaPoints",adminShellNumber(live.aggregate_points));adminShellStatus("mca",results.live.ok?"Current":"Unavailable",results.live.ok?"good":"bad",results.live.ok?"live database read":"—","Green Analytics · MCA results CSV");
const ts=tour.summary||{};const years=Array.isArray(tour.years)?tour.years:[];adminShellSet("adminShellTournamentCount",adminShellNumber(ts.tournaments??ts.total_tournaments??ts.events));adminShellSet("adminShellTournamentPlayers",adminShellNumber(ts.players??ts.ranked_players??ts.medalists));adminShellSet("adminShellTournamentYears",adminShellNumber(years.length));adminShellStatus("tournaments",results.tournaments.ok?"Current":"Unavailable",results.tournaments.ok?"good":"bad",adminShellAge(tour.generated_at),tour.recovery_source?`Tournament index · ${tour.recovery_source}`:"Tournament archive index");
const coverage=overview.freshness?.coverage||fresh.coverage||{},activity=overview.activity_summary||{};adminShellSet("adminShellDepthMembers",adminShellNumber(coverage.current_members??team.current_members));adminShellSet("adminShellDepthActive",adminShellNumber(activity.active));adminShellSet("adminShellDepthRatings",adminShellPercent(coverage.rating_percent));adminShellStatus("depth",results.overview.ok?"Current":"Unavailable",results.overview.ok?"good":"bad",results.overview.ok?"live admin read":"—");
const lifecycleEvents=Array.isArray(chronology.events)?chronology.events:[];const pendingLifecycle=lifecycleEvents.filter(e=>e.event_type==="left"&&e.profile_status==="pending").length;adminShellSet("adminShellChronMembers",adminShellNumber(coverage.current_members??team.current_members));adminShellSet("adminShellChronEvents",adminShellNumber(lifecycleEvents.length));adminShellSet("adminShellChronPending",adminShellNumber(pendingLifecycle));adminShellStatus("chronology",results.chronology.ok?"Current":"Unavailable",results.chronology.ok?"good":"bad",results.chronology.ok?"live Green read":"—","Green member lifecycle");
const edges=Array.isArray(aliases.edges)?aliases.edges:[];const edgeCounts=aliases.edge_counts||{};const confirmed=Number(edgeCounts.confirmed||0);const review=Number(edgeCounts.candidate||0)+Number(edgeCounts.conflict||0);adminShellSet("adminShellAliasMappings",adminShellNumber(aliases.names));adminShellSet("adminShellAliasReview",adminShellNumber(review));adminShellSet("adminShellAliasConfirmed",adminShellNumber(confirmed));adminShellStatus("aliases",results.aliases.ok?"Current":"Unavailable",results.aliases.ok?"good":"bad",results.aliases.ok?"live admin read":"—");
adminShellSet("adminShellTeamPoints",adminShellNumber(team.club_points));adminShellSet("adminShellTeamMembers",adminShellNumber(team.current_members));adminShellSet("adminShellTeamAnomalies",adminShellNumber((overview.anomalies||[]).length));adminShellStatus("team",results.team.ok&&results.overview.ok?"Healthy":results.team.ok?"Partial":"Unavailable",results.team.ok&&results.overview.ok?"good":results.team.ok?"warn":"bad",adminShellAge(team.cache_updated_at));
const oppRows=Array.isArray(opponents.rows)?opponents.rows:[],oppSummary=opponents.summary||{};adminShellSet("adminShellOpponentProfiles",adminShellNumber(oppSummary.different_opponents??opponents.pagination?.total_rows));adminShellSet("adminShellOpponentRecent",adminShellNumber(oppSummary.currently_playing));adminShellSet("adminShellOpponentReview",adminShellNumber(oppRows.filter(x=>x.disabled||Number(x.result_missing||0)>0).length));adminShellStatus("opponents",results.opponents.ok?"Current":"Unavailable",results.opponents.ok?"good":"bad",results.opponents.ok?"live database read":"—","Green Analytics opponent projection");
const fc=fresh.coverage||{},fa=fresh.ages||{};adminShellSet("adminShellDiagAnomalies",adminShellNumber((overview.anomalies||[]).length));adminShellSet("adminShellDiagFailed",adminShellNumber(fc.queue_failed));adminShellSet("adminShellDiagBoards",adminShellNumber(fc.finished_boards_unresolved));adminShellStatus("diagnostics",results.freshness.ok?((Number(fc.queue_failed||0)+Number(fc.finished_boards_unresolved||0))?"Attention":"Healthy"):"Unavailable",results.freshness.ok?((Number(fc.queue_failed||0)+Number(fc.finished_boards_unresolved||0))?"warn":"good"):"bad",results.freshness.ok?"live admin read":"—");
adminShellSet("adminShellTaskPending",adminShellNumber(fc.queue_pending));adminShellSet("adminShellTaskFailed",adminShellNumber(fc.queue_failed));adminShellSet("adminShellTaskGeneration",adminShellNumber(fresh.core?.core_generation));adminShellStatus("tasks",results.freshness.ok?(Number(fc.queue_failed||0)?"Attention":"Ready"):"Unavailable",results.freshness.ok?(Number(fc.queue_failed||0)?"warn":"good"):"bad",adminShellAge(fresh.core?.members_last_observed_at));
adminShellSet("adminShellLogEvents",adminShellNumber(perf.events));adminShellSet("adminShellLogDays",adminShellNumber(perf.days));adminShellStatus("logs",results.performance.ok?"Current":"Unavailable",results.performance.ok?"good":"bad",results.performance.ok?"rolling telemetry":"—");
adminShellSet("adminShellStorageLag",(()=>{const n=Number(fa.core_analytics_lag_seconds);if(!Number.isFinite(n))return"—";if(n<60)return`${Math.round(n)} s`;if(n<3600)return`${Math.round(n/60)} min`;return`${(n/3600).toFixed(1)} h`})());adminShellSet("adminShellStorageBoards",adminShellPercent(fc.finished_board_complete_percent??fc.board_complete_percent));adminShellStatus("storage",results.freshness.ok?"Current":"Unavailable",results.freshness.ok?"good":"bad",results.freshness.ok?"live capacity inputs":"—");
adminShellSet("adminShellPerfEvents",adminShellNumber(perf.events));adminShellSet("adminShellPerfClaims",adminShellNumber(perf.acamr?.claims));adminShellSet("adminShellPerfWarnings",adminShellNumber((perf.acamr?.warnings||[]).length));adminShellStatus("performance",results.performance.ok?"Current":"Unavailable",results.performance.ok?"good":"bad",results.performance.ok?`${adminShellNumber(perf.days)} d rolling window`:"—");
adminShellSet("adminShellFreshRatings",adminShellPercent(fc.fresh_rating_percent));adminShellSet("adminShellFreshMatches",adminShellPercent(fc.player_matches_operational_fresh_percent??fc.player_matches_fresh_percent));adminShellSet("adminShellFreshBoards",adminShellNumber(fc.boards_pending));adminShellStatus("freshness",results.freshness.ok?((Number(fc.queue_failed||0)||Number(fc.boards_failed||0))?"Attention":"Current"):"Unavailable",results.freshness.ok?((Number(fc.queue_failed||0)||Number(fc.boards_failed||0))?"warn":"good"):"bad",adminShellAge(fresh.core?.members_last_observed_at));
const tr=traffic.summary||{};adminShellSet("adminShellTrafficViews",adminShellNumber(tr.pageviews));adminShellSet("adminShellTrafficSessions",adminShellNumber(tr.sessions));adminShellSet("adminShellTrafficUniques",adminShellNumber(tr.latest_daily_unique_visitors));adminShellStatus("traffic",results.traffic.ok?"Current":"Unavailable",results.traffic.ok?"good":"bad",adminShellAge(results.traffic.payload?.server_utc),"First-party cookieless analytics");
const okCount=Object.values(results).filter(x=>x.ok).length;state.adminShellLoadedAt=Date.now();setText("adminShellOverallStatus",okCount===Object.keys(results).length?"All live administration sources responded.":`${okCount} of ${Object.keys(results).length} live sources responded.`);setText("adminShellUpdated",`Updated ${new Date().toLocaleTimeString("en-GB", { timeZone: "UTC", hour12: false })} UTC · Status · freshness · source are shown on every card.`);state.adminShellLoading=false;
}

return Object.freeze({ adminDetailDefinition, adminShellHref, adminShellCard, adminMemberLookupCard, adminMemberLookupUtc, adminMemberLookupEventLabel, renderAdminMemberLookup, loadAdminMemberLookup, adminPanelMarkup, adminShellSet, adminShellAge, adminShellStatus, adminShellNumber, adminShellPercent, adminShellOpenDetail, adminShellCloseDetail, renderAdminShellDetail, adminShellActivate, adminShellJSON, loadAdminShellMetrics });
}
});
})();
