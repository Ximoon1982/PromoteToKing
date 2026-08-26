/* Lazy integrated public Insights controller. Integrated in v2.8.8. */
(() => {
  "use strict";
  window.P2K_CREATE_DASHBOARD_INSIGHTS = function createDashboardInsights(ctx) {
    const { state, byId, number, escapeHTML, cellWithSub, insightAction, openUnifiedPlayerProfile, formatDateOnly, formatRelative, setText, loadJSON, profileMetric, showInsightsModal, matchRulesLabel, matchTimeControlLabel, statusChip, writeNavigationState } = ctx;
function membersTableColumns() {
    return [
      { key: "username", type: "text", render: row => cellWithSub(insightAction(row.username, () => openUnifiedPlayerProfile(row.username)), row.current_member ? "Current member" : "Former member") },
      { key: "activity_status", type: "text", render: row => { const value=String(row.activity_status||"unknown"),node=document.createElement("span");node.className=`p2k-member-activity-status is-${value}`;node.textContent=value.charAt(0).toUpperCase()+value.slice(1);return node; } },
      { key: "daily_rank", type: "text", render: row => row?.daily_rank?.name || "Unranked" },
      ...["points", "matches", "games", "wins", "draws", "losses"].map(key => ({ key, type: "number", align: "right", render: row => number(row[key]) })),
      { key: "result_coverage_percent", label:"Result coverage", type: "number", align:"right", render:row=>`${Number(row.result_coverage_percent??0).toFixed(1)}%` },
      { key: "win_rate", type: "number", align: "right", render: row => `${Number(row.win_rate || 0).toFixed(1)}%` },
      { key: "points_per_game", type: "number", align: "right", render: row => Number(row.points_per_game || 0).toFixed(3) },
      { key: "current_matches", type: "number", align: "right", render: row => number(row.current_matches) },
      { key: "live_points", type: "number", align: "right", render: row => number(row.live_points) },
      { key: "last_activity", type: "text", render: row => row.last_activity ? formatDateOnly(`${row.last_activity.replace(" ", "T")}Z`) : "—" }
    ];
  }

  function memberInsightsURL(tableState = state.membersTableState, { includeSummary = false, section = "all" } = {}) {
    const url = new URL("server/team-points/public/members-insights.php", window.location.href);
    url.searchParams.set("page", String(Math.max(1, Number(tableState?.page) || 1)));
    url.searchParams.set("page_size", "25");
    if (tableState?.query) url.searchParams.set("search", tableState.query);
    url.searchParams.set("filter", tableState?.filter || "current");
    const statuses=[...document.querySelectorAll('#membersActivityStatusFilter input[type="checkbox"]:checked')].map(input=>input.value).filter(Boolean);
    url.searchParams.set("activity_status", statuses.join(","));
    if (tableState?.sort) url.searchParams.set("sort", tableState.sort);
    url.searchParams.set("direction", tableState?.direction === "asc" ? "asc" : "desc");
    if (state.teamStart) url.searchParams.set("start", state.teamStart);
    if (state.teamEnd) url.searchParams.set("end", state.teamEnd);
    if (includeSummary) url.searchParams.set("include_summary", "1");
    if (section && section !== "all") url.searchParams.set("section", section);
    return url.href;
  }

  let insightsChartsPromise = null;
  function ensureInsightsCharts() {
    if (window.P2K_INSIGHTS_CHARTS) return Promise.resolve(window.P2K_INSIGHTS_CHARTS);
    if (insightsChartsPromise) return insightsChartsPromise;
    insightsChartsPromise = new Promise((resolve, reject) => {
      const finish = () => {
        try {
          const factory = window.P2K_CREATE_INSIGHTS_CHARTS;
          if (typeof factory !== "function") throw new Error("Insights chart module did not initialize.");
          window.P2K_INSIGHTS_CHARTS = factory({ byId, number, escapeHTML, openOpponentProfile });
          resolve(window.P2K_INSIGHTS_CHARTS);
        } catch (error) { reject(error); }
      };
      if (window.P2K_CREATE_INSIGHTS_CHARTS) return finish();
      const script = document.createElement("script");
      script.src = "assets/js/pages/dashboard-insights-charts.js?v=2.10.6.24";
      script.defer = true; script.onload = finish; script.onerror = () => reject(new Error("Unable to load Insights chart module."));
      document.head.appendChild(script);
    });
    return insightsChartsPromise;
  }
  function deferredChart(method, args) { ensureInsightsCharts().then(api => api?.[method]?.(...args)).catch(error => console.warn(error)); }
  function nativeChartEmpty(...args) { deferredChart("nativeChartEmpty", args); }
  function renderNativeBars(...args) { deferredChart("renderNativeBars", args); }
  function renderNativePie(...args) { deferredChart("renderNativePie", args); }
  function renderNativeStackedBars(...args) { deferredChart("renderNativeStackedBars", args); }
  function renderNativeLine(...args) { deferredChart("renderNativeLine", args); }
  function renderNativeBarLine(...args) { deferredChart("renderNativeBarLine", args); }
  function renderOpponentTopChart(...args) { deferredChart("renderOpponentTopChart", args); }

  function applyMemberSummaryPayload(payload) {
    const summary=payload?.summary||{}; setText("membersStatCurrent",number(summary.current_members));setText("membersStatActive",number(summary.active_members));setText("membersStatPlaying",number(summary.currently_playing));setText("membersDatabaseBadge",`Database · schema ${number(payload?.meta?.schema_version)}`);
    const activity=Array.isArray(payload?.analytics?.monthly_activity)?payload.analytics.monthly_activity.map(row=>({...row})):[],now=new Date(),currentMonth=`${now.getUTCFullYear()}-${String(now.getUTCMonth()+1).padStart(2,"0")}`,previousMonthDate=new Date(Date.UTC(now.getUTCFullYear(),now.getUTCMonth()-1,1)),previousMonth=`${previousMonthDate.getUTCFullYear()}-${String(previousMonthDate.getUTCMonth()+1).padStart(2,"0")}`,activityByMonth=new Map(activity.map(row=>[String(row.month),row]));for(let month=now.getUTCMonth()+2;month<=12;month+=1){const key=`${now.getUTCFullYear()}-${String(month).padStart(2,"0")}`;if(!activityByMonth.has(key))activity.push({month:key,active_members:null,games:null,points:null,future:true});}activity.sort((a,b)=>String(a.month).localeCompare(String(b.month)));renderNativeLine("membersActivityAnalytics",activity,{xKey:"month",series:[{key:"active_members",label:"Active members",color:"#4aa8d8",decimals:0}],futureBoundary:{key:previousMonth,fraction:0,label:`Past through ${previousMonth} · current month →`},tooltipExtra:row=>String(row.month)>=currentMonth?(row.future?"Future month":`Current month · incomplete<br>Finished games: <b>${number(row.games)}</b><br>Team Points: <b>${number(row.points)}</b>`):`Finished games: <b>${number(row.games)}</b><br>Team Points: <b>${number(row.points)}</b>`});
  }

  async function loadMemberInsights({ force = false } = {}) {
    if (state.membersLoaded && !force) return;
    const status = byId("membersTableStatus");
    if (status) { status.classList.remove("is-error"); status.textContent = "Loading member summary…"; }
    state.membersProgressiveStops?.forEach(stop => stop?.()); state.membersProgressiveStops = [];
    const activityStatusFilter=byId("membersActivityStatusFilter");
    if(activityStatusFilter&&!activityStatusFilter.dataset.bound){activityStatusFilter.dataset.bound="1";activityStatusFilter.addEventListener("change",event=>{const checked=activityStatusFilter.querySelectorAll('input[type="checkbox"]:checked');if(!checked.length){event.target.checked=true;return;}state.membersTableState={...state.membersTableState,page:1};writeNavigationState({replace:true});if(state.membersTable)state.membersTable.setState({page:1});else{state.membersTableProgressiveLoaded=false;}})}
    const loadTable = async () => {
      if (state.membersTableProgressiveLoaded) return; state.membersTableProgressiveLoaded = true;
      try {
        const payload = await loadJSON(memberInsightsURL(state.membersTableState, { section: "table" }), { credentials: "same-origin" });
        const totalRows = Number(payload.pagination?.total_rows || 0);
        if (!state.membersTable) {
          state.membersTable = new window.P2KDataTable({
            root: byId("membersDataTable"), columns: membersTableColumns(), rows: payload.rows || [], totalRows, pageSize: 25,
            searchInput: byId("membersTableSearch"), filterInput: byId("membersTableFilter"), countHost: byId("membersTableCount"), pagerHost: byId("membersTablePager"), state: state.membersTableState,
            remoteLoader: async tableState => { const remote = await loadJSON(memberInsightsURL(tableState,{section:"table"}),{credentials:"same-origin"}); return {rows:remote.rows||[],totalRows:Number(remote.pagination?.total_rows||0),pagination:remote.pagination}; },
            onRemoteState: event => { if(!status)return; status.classList.toggle("is-error",Boolean(event.error)); status.textContent=event.loading?"Loading matching database rows…":event.error?`Unable to load member rows: ${event.error.message||event.error}`:`Database updated · ${number(event.payload?.totalRows||0)} matching members`; },
            onStateChange: next => { state.membersTableState=next; writeNavigationState({replace:true}); }
          });
        } else state.membersTable.setRemoteData(payload.rows||[],totalRows);
        if(status)status.textContent=`Database table ready · ${number(totalRows)} matching members`;
      } catch(error){state.membersTableProgressiveLoaded=false;if(status){status.classList.add("is-error");status.textContent=`Unable to load member rows: ${error.message||error}`;}}
    };
    const cachedSummary=window.P2K_PROGRESSIVE?.snapshotGet?.("members-insights-summary",6*3600000);if(cachedSummary?.payload){applyMemberSummaryPayload(cachedSummary.payload);if(status)status.textContent="Cached member summary · refreshing…";}
    try {
      const payload = await loadJSON(memberInsightsURL(state.membersTableState,{section:"summary"}),{credentials:"same-origin"});
      if(payload?.ok===false)throw new Error(payload?.error?.message||"Member Insights are unavailable.");
      applyMemberSummaryPayload(payload);window.P2K_PROGRESSIVE?.snapshotSet?.("members-insights-summary",payload);
      state.membersLoaded=true;if(status)status.textContent="Member summary ready · more data loads as you scroll";
      const observe=window.P2K_PROGRESSIVE?.observe||((_,fn)=>{setTimeout(fn,0);return()=>{}});
      state.membersProgressiveStops.push(observe(byId("membersRankAnalytics"),async()=>{try{const ranks=await loadJSON(memberInsightsURL(state.membersTableState,{section:"ranks"}),{credentials:"same-origin"});const rows=Array.isArray(ranks.analytics?.rank_distribution)?ranks.analytics.rank_distribution:[];renderNativeBars("membersRankAnalytics",rows,{valueKey:"members",labelKey:"rank",color:"#f6b73c",horizontal:rows.length>8,showValues:true,detail:row=>`${number(row.members)} member${Number(row.members)===1?"":"s"}`});}catch(e){nativeChartEmpty(byId("membersRankAnalytics"),"Rank distribution is temporarily unavailable.");}},{rootMargin:"600px 0px"}));
      state.membersProgressiveStops.push(observe(byId("membersDataTable"),loadTable,{rootMargin:"600px 0px"}));
    } catch(error){if(status){status.classList.add("is-error");status.textContent=`Unable to load member insights: ${error.message||error}`;}}
  }

  function compactAnalyticsRows(rows, formatter) {
    return Array.isArray(rows) && rows.length ? rows.map(formatter).join("") : '<p class="p2k-table-status">No database data is available.</p>';
  }

  function renderDurationDistribution(rows, average, median) {
    ensureInsightsCharts().then(api => {
      api.renderNativeBars("matchesDurationDistribution", rows, { valueKey:"matches", labelKey:"label", color:"#a98ae8", showValues:true, detail:row=>`${number(row.matches)} valid finished matches` });
      const host=byId("matchesDurationDistribution"),svg=host?.querySelector("svg"); if(!svg||!Array.isArray(rows)||!rows.length)return;
      const bucketLabel=value=>{const v=Number(value);if(!Number.isFinite(v))return null;if(v<30)return "<30 d";if(v<60)return "30–59 d";if(v<90)return "60–89 d";if(v<120)return "90–119 d";if(v<180)return "120–179 d";if(v<270)return "180–269 d";if(v<365)return "270–364 d";return "365+ d";};
      const width=720,marginLeft=42,marginRight=12,innerWidth=width-marginLeft-marginRight,slot=innerWidth/rows.length,nativeSVG=api.nativeSVG;
      [[average,"Average","#f6b73c"],[median,"Median","#9fd8ff"]].forEach(([value,label,color],offset)=>{const wanted=bucketLabel(value),index=rows.findIndex(row=>String(row.label)===wanted);if(index<0)return;const x=marginLeft+slot*(index+.5);svg.appendChild(nativeSVG("line",{x1:x,y1:12,x2:x,y2:188,stroke:color,"stroke-width":2,"stroke-dasharray":"5 4"}));const text=nativeSVG("text",{x:x+(offset?5:-5),y:18+offset*15,"text-anchor":offset?"start":"end",fill:color,class:"p2k-chart-reference"});text.textContent=`${label} ${Number(value).toFixed(1)} d`;svg.appendChild(text);});
    }).catch(error => console.warn(error));
  }

  function renderMatchResults(analytics = {}) {
    const sizeRows = Array.isArray(analytics.results_by_size) ? analytics.results_by_size : [];
    renderNativeStackedBars("matchesSizeDistribution", sizeRows, { labelKey: "label", series: [
      { key: "wins", label: "Wins", color: "#5fbf72" },
      { key: "draws", label: "Draws", color: "#f6b73c" },
      { key: "losses", label: "Losses", color: "#e7685a" }
    ], showTotals: true });
    const sizes = byId("matchesSizeAnalytics");
    if (sizes) sizes.innerHTML = compactAnalyticsRows(sizeRows, row => `<div><span>${escapeHTML(row.label)}</span><strong>${number(row.matches)} matches · ${number(row.wins)}w / ${number(row.draws)}d / ${number(row.losses)}l</strong></div>`);
    const categoriesRows = Array.isArray(analytics.categories) ? analytics.categories : [];
    renderNativePie("matchesCategoryPie", categoriesRows, { valueKey: "matches", labelKey: "category" });
    const categories = byId("matchesCategoryAnalytics");
    if (categories) categories.innerHTML = compactAnalyticsRows(categoriesRows, row => `<div><span>${escapeHTML(row.category)}</span><strong>${number(row.wins)}w / ${number(row.draws)}d / ${number(row.losses)}l · ${number(row.club_points)} Club Points</strong></div>`);
  }

  function renderMatchDuration(analytics = {}, summary = {}) {
    const durationRows = Array.isArray(analytics.duration_trend) ? analytics.duration_trend : [];
    renderNativeLine("matchesDurationTrendChart", durationRows, { xKey: "month", series: [
      { key: "average_duration_days", label: "Average duration (days)", color: "#a98ae8", decimals: 1 }
    ], tooltipExtra: row => `Valid matches: <b>${number(row.matches)}</b><br>Range: <b>${Number(row.minimum_days || 0).toFixed(1)}–${Number(row.maximum_days || 0).toFixed(1)} days</b>` });
    const duration = byId("matchesDurationAnalytics");
    if (duration) duration.innerHTML = compactAnalyticsRows(durationRows.slice(-6), row => `<div><span>${escapeHTML(row.month)}</span><strong>${Number(row.average_duration_days || 0).toFixed(1)} days average · ${number(row.matches)} valid matches</strong></div>`);
    renderDurationDistribution(analytics.duration_distribution || [], summary.average_duration_days, summary.median_duration_days);
    document.querySelectorAll('[data-chart-reset="matchesDurationTrendChart"]').forEach(button => button.onclick = () => byId("matchesDurationTrendChart")?._p2kResetZoom?.());
  }

  function renderMatchDimensions(analytics = {}) {
    renderNativePie("matchesRulesDistribution", analytics.rules_distribution || [], { valueKey: "matches", labelKey: "label" });
    const timeRows = Array.isArray(analytics.time_control_distribution) ? analytics.time_control_distribution : [];
    renderNativeBars("matchesTimeControlDistribution", timeRows, { valueKey: "matches", labelKey: "label", color: "#48b8a8", horizontal: timeRows.length > 6, detail: row => `${number(row.matches)} stored matches` });
  }

  function renderMatchHighlights(analytics = {}) {
    const highlights = byId("matchesHighlights");
    const rows = [
      ["Largest", analytics.largest?.[0]], ["Longest valid", analytics.longest?.[0]], ["Longest ongoing", analytics.longest_ongoing?.[0]],
      ["Biggest win", analytics.biggest_wins?.[0]], ["Closest", analytics.closest?.[0]], ["Biggest loss", analytics.biggest_losses?.[0]]
    ].filter(([, row]) => row);
    if (highlights) highlights.innerHTML = rows.length ? rows.map(([label, row]) => `<button type="button" data-highlight-match="${Number(row.match_id) || 0}"><span>${escapeHTML(label)}</span><strong>${escapeHTML(row.name)}</strong></button>`).join("") : '<p class="p2k-table-status">No notable matches are stored.</p>';
    highlights?.querySelectorAll("[data-highlight-match]").forEach(button => button.addEventListener("click", () => openMatchDetail(Number(button.dataset.highlightMatch))));
  }

  function renderMatchAnalytics(analytics = {}, summary = {}) {
    renderMatchResults(analytics); renderMatchDuration(analytics, summary); renderMatchDimensions(analytics); renderMatchHighlights(analytics);
  }


  function chessComHumanMatchURL(value, matchId) {
    const raw = String(value || "").trim();
    const id = String(matchId || "").match(/\d+/)?.[0] || raw.match(/(?:\/match\/|\/matches\/)(\d+)/i)?.[1] || raw.match(/(\d+)(?:\/?(?:[?#].*)?)?$/)?.[1] || "";
    if (!id) return raw;
    return `https://www.chess.com/club/matches/${id}`;
  }

  async function openMatchDetail(matchId, options = {}) {
    if (!Number.isFinite(Number(matchId)) || Number(matchId) <= 0) return;
    showInsightsModal({ replace: options.replaceInitial === true, eyebrow: "Match Insights", title: `Match ${matchId}`, subtitle: "Loading database detail…", html: '<p class="p2k-table-status">Loading match detail…</p>' });
    try {
      const url = new URL("server/team-points/public/match-detail.php", window.location.href); url.searchParams.set("match_id", String(matchId));
      const payload = await loadJSON(url.href, { credentials: "same-origin" });
      const match = payload.match || {};
      const players = Array.isArray(match.players) ? match.players : [];
      const playerRows = players.length ? players.map(player => `<button type="button" class="p2k-profile-list-row" data-match-player="${escapeHTML(player.username)}"><span><strong>${escapeHTML(player.username)}</strong><small>${escapeHTML(player.wins)} wins · ${escapeHTML(player.draws)} draws · ${escapeHTML(player.losses)} losses</small></span><b>${escapeHTML(player.points)} pts</b></button>`).join("") : '<p class="p2k-table-status">No player rows are stored.</p>';
      const html = `<section class="p2k-profile-metrics">${profileMetric("Status", match.status || "unknown")}${profileMetric("Opponent", match?.opponent?.name || "Unknown")}${profileMetric("Boards", number(match.boards))}${profileMetric("Score", `${number(match.our_score)} – ${number(match.their_score)}`)}${profileMetric("Result", match.result || "—")}${profileMetric("Club Points", number(Math.round(Number(match.club_points) || 0)))}${profileMetric("Duration", match.duration_days === null ? "—" : `${Number(match.duration_days).toFixed(1)} d`)}${profileMetric("Type", matchRulesLabel(match.rules))}${profileMetric("Time control", matchTimeControlLabel(match.time_control))}${profileMetric("Category", match.is_league ? "League" : "Friendly")}${profileMetric("Players", number(players.length))}</section><section class="p2k-profile-section"><h3>Promote to King lineup</h3><div class="p2k-profile-list">${playerRows}</div></section><div class="p2k-profile-actions">${(match.url || match.match_id || matchId) ? `<a class="dashboard-button" href="${escapeHTML(chessComHumanMatchURL(match.url, match.match_id || matchId))}" target="_blank" rel="noopener noreferrer">Open on Chess.com</a>` : ""}<a class="dashboard-button" href="AnalyzeMatch.html?match=${encodeURIComponent(String(match.match_id || matchId))}" target="_blank" rel="noopener noreferrer">Detailed match analysis</a><button type="button" class="dashboard-button" data-open-opponent="${escapeHTML(match?.opponent?.slug || "")}">Opponent profile</button></div>`;
      showInsightsModal({ replace: true, eyebrow: "Database match profile", title: match.name || `Match ${matchId}`, subtitle: `${match.start_time ? formatDateOnly(`${match.start_time.replace(" ", "T")}Z`) : "Unscheduled"} · verified ${match.last_verified_at ? formatRelative(`${match.last_verified_at.replace(" ", "T")}Z`) : "from database"}`, html });
      byId("insightsDetailBody")?.querySelectorAll("[data-match-player]").forEach(button => button.addEventListener("click", () => openUnifiedPlayerProfile(button.dataset.matchPlayer)));
      byId("insightsDetailBody")?.querySelector("[data-open-opponent]")?.addEventListener("click", event => openOpponentProfile(event.currentTarget.dataset.openOpponent));
    } catch (error) { showInsightsModal({ replace: true, eyebrow: "Match Insights", title: `Match ${matchId}`, subtitle: "Unable to load", html: `<p class="p2k-table-status is-error">${escapeHTML(error.message || error)}</p>` }); }
  }

  async function copyPlainInsightText(text) {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const area = document.createElement("textarea");
    area.value = text; area.setAttribute("readonly", ""); area.style.position = "fixed"; area.style.opacity = "0";
    document.body.appendChild(area); area.select();
    if (!document.execCommand("copy")) throw new Error("Clipboard copy is unavailable.");
    area.remove();
  }

  async function openOpponentProfile(slug) {
    const opponentSlug = String(slug || "").trim();
    if (!opponentSlug) return;
    showInsightsModal({ eyebrow: "Opponent Insights", title: opponentSlug, subtitle: "Loading database profile…", html: '<p class="p2k-table-status">Loading opponent profile…</p>' });
    try {
      const url = new URL("server/team-points/public/opponent-profile.php", window.location.href); url.searchParams.set("slug", opponentSlug);
      const payload = await loadJSON(url.href, { credentials: "same-origin" });
      const opponent = payload.opponent || {}; const summary = opponent.summary || {}; const coverage=opponent.coverage||{};
      const playerSummary = opponent.player_summary || {}; const opponentPlayers = Array.isArray(opponent.players) ? opponent.players : [];
      const tags = Array.isArray(opponent.tags) ? opponent.tags : []; const matches = Array.isArray(opponent.matches) ? opponent.matches : [];
      const trend = Array.isArray(opponent.trend) ? opponent.trend : [];
      const timeControls = Array.isArray(opponent.time_controls) ? opponent.time_controls : [];
      const ratingBrackets = Array.isArray(opponent.max_rating_rates) ? opponent.max_rating_rates : (Array.isArray(opponent.rating_brackets) ? opponent.rating_brackets : []);
      const tagHTML = tags.length ? tags.map(tag => `<span class="p2k-achievement-chip">${escapeHTML(tag)}</span>`).join("") : '<span class="p2k-table-status">No classification tag yet.</span>';
      const rows = matches.slice(0, 30).map(match => `<button type="button" class="p2k-profile-list-row" data-opponent-match="${Number(match.match_id) || 0}"><span><strong>${escapeHTML(match.name)}</strong><small>${escapeHTML(match.status)} · ${escapeHTML(match.boards)} boards</small></span><b>${escapeHTML(match.result || "—")}</b></button>`).join("");
      const copyText = `Promote to King vs ${opponent.name || opponentSlug}: ${number(summary.wins)}w / ${number(summary.draws)}d / ${number(summary.losses)}l, ${number(summary.ongoing)} ongoing; ${number(playerSummary.unique_players)} opponent players observed.`;
      const opponentPlayerRows = opponentPlayers.slice(0, 75).map(player => `<div class="p2k-profile-list-row"><span><strong>${escapeHTML(player.username || "Unknown")}</strong><small>${number(player.appearances)} appearance${Number(player.appearances)===1?"":"s"} · ${player.average_rating==null?"rating —":`avg ${number(player.average_rating)}`} · last seen ${player.last_seen_at?formatDateOnly(`${String(player.last_seen_at).replace(" ","T")}Z`):"—"}</small></span><b>${number(player.wins)} / ${number(player.draws)} / ${number(player.losses)} W/D/L</b></div>`).join("");
      const html = `<div class="p2k-achievement-wall">${tagHTML}</div><section class="p2k-profile-metrics">${profileMetric("Matches", number(summary.total))}${profileMetric("W / D / L", `${number(summary.wins)} / ${number(summary.draws)} / ${number(summary.losses)}`)}${profileMetric("Result coverage", `${Number(coverage.result_coverage_percent??summary.result_coverage_percent??0).toFixed(1)}% · ${number(coverage.canonical_results??summary.result_covered)}/${number(coverage.finished_matches??summary.finished)}`)}${profileMetric("Win rate", `${Number(summary.win_rate || 0).toFixed(1)}%`)}${profileMetric("Score balance", `${Number(summary.balance || 0) > 0 ? "+" : ""}${number(summary.balance)}`)}${profileMetric("Average boards", number(summary.average_boards))}${profileMetric("Current / registration", `${number(summary.ongoing)} / ${number(summary.registered)}`)}${profileMetric("Opponent players", number(playerSummary.unique_players))}${profileMetric("Seen in last 12m", number(playerSummary.recent_players))}${profileMetric("Player appearances", number(playerSummary.appearances))}${profileMetric("Avg observed rating", playerSummary.average_rating==null?"—":number(playerSummary.average_rating))}</section>
      <section class="p2k-profile-section"><div class="p2k-profile-section-title"><h3>Results over time</h3><button type="button" class="p2k-chart-reset" data-opponent-trend-reset>Reset zoom</button></div><p>Finished matches by month. Drag across the chart to zoom; hover for exact W / D / L values.</p><div id="opponentResultsTrend" class="p2k-native-chart"></div><div class="p2k-chart-legend"><span data-chart-color="green">Wins</span><span data-chart-color="gold">Draws</span><span data-chart-color="red">Losses</span></div></section>
      <section class="p2k-opponent-breakdown-grid"><article class="p2k-profile-section"><h3>Win rate by time control</h3><div id="opponentTimeControlRates" class="p2k-native-chart"></div></article><article class="p2k-profile-section"><h3>Win rate by max rating</h3><div id="opponentRatingRates" class="p2k-native-chart"></div></article></section>
      <section class="p2k-profile-section"><h3>Opponent players</h3><p>Players observed in stored team-match lineups, ranked by appearances. W/D/L is Promote to King’s game record against that player.</p><div class="p2k-profile-list">${opponentPlayerRows || '<p class="p2k-table-status">Opponent-player identities will fill as match lineups are refreshed.</p>'}</div></section>
      <section class="p2k-profile-section"><h3>Match history</h3><p>${coverage.detail_list_complete===false?`Latest ${number(coverage.detail_matches_returned)} of ${number(coverage.total_matches)} stored matches loaded; latest 30 shown.`:`${number(coverage.detail_matches_returned??matches.length)} stored matches loaded; latest 30 shown.`}</p><div class="p2k-profile-list">${rows || '<p class="p2k-table-status">No matches are stored.</p>'}</div></section><div class="p2k-profile-actions">${opponent.url ? `<a class="dashboard-button" href="${escapeHTML(opponent.url)}" target="_blank" rel="noopener noreferrer">Open club</a>` : ""}${state.admin ? `<button type="button" class="dashboard-button p2k-copy-result" data-copy-opponent-result>Copy plain-text result</button>` : ""}</div>`;
      showInsightsModal({ replace: true, eyebrow: "Opponent intelligence", title: opponent.name || opponentSlug, subtitle: `${number(summary.total)} database matches · ${number(coverage.canonical_results??summary.result_covered)} canonical results · ${opponent.disabled ? "club disabled" : "club active or unchecked"}`, html });
      renderNativeStackedBars("opponentResultsTrend", trend, { labelKey: "month", series: [
        { key: "wins", label: "Wins", color: "#5fbf72" }, { key: "draws", label: "Draws", color: "#f6b73c" }, { key: "losses", label: "Losses", color: "#e7685a" }
      ] });
      renderNativeBars("opponentTimeControlRates", timeControls, { valueKey: "win_rate", labelKey: "label", horizontal: true, color: "#48b8a8", detail: row => `${Number(row.win_rate || 0).toFixed(1)}% win rate · ${number(row.wins)}w / ${number(row.draws)}d / ${number(row.losses)}l · ${number(row.finished)} finished` });
      renderNativeBars("opponentRatingRates", ratingBrackets, { valueKey: "win_rate", labelKey: "label", horizontal: true, color: "#a98ae8", detail: row => `${Number(row.win_rate || 0).toFixed(1)}% win rate · ${number(row.wins)}w / ${number(row.draws)}d / ${number(row.losses)}l · ${number(row.finished)} finished` });
      const modalBody = byId("insightsDetailBody");
      modalBody?.querySelector("[data-opponent-trend-reset]")?.addEventListener("click", () => byId("opponentResultsTrend")?._p2kResetZoom?.());
      modalBody?.querySelectorAll("[data-opponent-match]").forEach(button => button.addEventListener("click", () => openMatchDetail(Number(button.dataset.opponentMatch))));
      modalBody?.querySelector("[data-copy-opponent-result]")?.addEventListener("click", async event => {
        const button = event.currentTarget;
        try { await copyPlainInsightText(copyText); button.textContent = "Copied"; button.classList.add("is-copied"); }
        catch (copyError) { button.textContent = "Copy failed"; button.title = copyError.message || String(copyError); }
      });
    } catch (error) { showInsightsModal({ replace: true, eyebrow: "Opponent Insights", title: opponentSlug, subtitle: "Unable to load", html: `<p class="p2k-table-status is-error">${escapeHTML(error.message || error)}</p>` }); }
  }


  function renderMatchesTrend(rows) {
    const recent = Array.isArray(rows) ? rows.slice(-36) : [];
    renderNativeLine("matchesTrendChart", recent, { xKey: "month", series: [
      { key: "registered", label: "Registration", color: "#82c8ff", decimals: 0 },
      { key: "ongoing", label: "In progress", color: "#a98ae8", decimals: 0 },
      { key: "finished", label: "Finished", color: "#66d19e", decimals: 0 }
    ] });
    renderNativeLine("matchesAverageBoardsChart", recent.filter(row=>row.average_boards!=null), { xKey:"month", series:[{key:"average_boards",label:"Average boards / finished match",color:"#f6b73c",decimals:1}] });
    document.querySelectorAll('[data-chart-reset="matchesTrendChart"]').forEach(button => {
      button.onclick = () => byId("matchesTrendChart")?._p2kResetZoom?.();
    });
  }

  function matchesTableColumns() {
    return [
      { key: "name", type: "text", render: row => cellWithSub(insightAction(row.name || `Match ${row.match_id}`, () => openMatchDetail(row.match_id)), `#${row.match_id}`) },
      { key: "opponent_name", type: "text", render: row => insightAction(row.opponent_name || row.opponent_slug || "Unknown", () => openOpponentProfile(row.opponent_slug)) },
      { key: "status", type: "text", render: row => statusChip(row.status) },
      { key: "start_time", type: "text", render: row => row.start_time ? new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", dateStyle: "medium" }).format(new Date(`${row.start_time.replace(" ", "T")}Z`)) : "—" },
      { key: "boards", type: "number", align: "right", render: row => number(row.boards) },
      { key: "our_score", type: "number", align: "right", render: row => Number(row.our_score || 0).toFixed(1) },
      { key: "their_score", type: "number", align: "right", render: row => Number(row.their_score || 0).toFixed(1) },
      { key: "result", type: "text", render: row => row.result ? statusChip(row.result) : "—" },
      { key: "competition_points", type: "number", align: "right", render: row => number(row.competition_points) },
      { key: "duration_days", type: "number", align: "right", render: row => row.duration_days === null ? "—" : `${Number(row.duration_days).toFixed(1)} d` }
    ];
  }

  function matchInsightsURL(tableState = state.matchesTableState, { includeSummary = false, section = "all" } = {}) {
    const url = new URL("server/team-points/public/matches-insights.php", window.location.href);
    url.searchParams.set("page", String(Math.max(1, Number(tableState?.page) || 1)));
    url.searchParams.set("page_size", "25");
    if (tableState?.query) url.searchParams.set("search", tableState.query);
    if (tableState?.filter && tableState.filter !== "all") url.searchParams.set("filter", tableState.filter);
    if (tableState?.sort) url.searchParams.set("sort", tableState.sort);
    url.searchParams.set("direction", tableState?.direction === "asc" ? "asc" : "desc");
    url.searchParams.set("include_summary", includeSummary ? "1" : "0");
    if (section && section !== "all") url.searchParams.set("section", section);
    return url.href;
  }

  function applyMatchSummaryPayload(payload) { const summary=payload?.summary||{};state.matchesSummary=summary;setText("matchesStatTotal",number(summary.different_matches));setText("matchesStatRegistered",number(summary.registered));setText("matchesStatOngoing",number(summary.ongoing));setText("matchesStatFinished",number(summary.finished));setText("matchesStatResults",`${number(summary.wins)} / ${number(summary.draws)} / ${number(summary.losses)}`);setText("matchesStatPoints",number(summary.competition_points));setText("matchesStatBoards",number(summary.average_boards));setText("matchesStatDuration",summary.average_duration_days===null?"—":`${Number(summary.average_duration_days).toFixed(1)} d`);setText("matchesStatMedian",summary.median_duration_days===null?"—":`${Number(summary.median_duration_days).toFixed(1)} d`);setText("matchesStatPointsPerMatch",summary.club_points_per_finished_match===undefined?"—":Number(summary.club_points_per_finished_match).toFixed(1));renderMatchesTrend(payload?.trend||[]); }

  async function loadMatchInsights({ force = false } = {}) {
    if(state.matchesLoaded&&!force)return;const status=byId("matchesTableStatus");if(status){status.classList.remove("is-error");status.textContent="Loading match summary…";}state.matchesProgressiveStops?.forEach(stop=>stop?.());state.matchesProgressiveStops=[];
    const observe=window.P2K_PROGRESSIVE?.observe||((_,fn)=>{setTimeout(fn,0);return()=>{}});
    const section=async(name,target)=>{try{const payload=await loadJSON(matchInsightsURL(state.matchesTableState,{section:name}),{credentials:"same-origin"});const analytics=payload.analytics||{};if(name==="results")renderMatchResults(analytics);else if(name==="duration")renderMatchDuration(analytics,state.matchesSummary||{});else if(name==="dimensions")renderMatchDimensions(analytics);else if(name==="highlights")renderMatchHighlights(analytics);}catch(e){console.warn(`Match Insights ${name} unavailable`,e);if(target)nativeChartEmpty(byId(target),"This section is temporarily unavailable.");}};
    const loadTable=async()=>{if(state.matchesTableProgressiveLoaded)return;state.matchesTableProgressiveLoaded=true;try{const payload=await loadJSON(matchInsightsURL(state.matchesTableState,{section:"table"}),{credentials:"same-origin"});const totalRows=Number(payload.pagination?.total_rows||0);if(!state.matchesTable){state.matchesTable=new window.P2KDataTable({root:byId("matchesDataTable"),columns:matchesTableColumns(),rows:payload.rows||[],totalRows,pageSize:25,searchInput:byId("matchesTableSearch"),filterInput:byId("matchesTableFilter"),countHost:byId("matchesTableCount"),pagerHost:byId("matchesTablePager"),state:state.matchesTableState,remoteLoader:async tableState=>{const remote=await loadJSON(matchInsightsURL(tableState,{section:"table"}),{credentials:"same-origin"});return{rows:remote.rows||[],totalRows:Number(remote.pagination?.total_rows||0),pagination:remote.pagination};},onRemoteState:event=>{if(!status)return;status.classList.toggle("is-error",Boolean(event.error));status.textContent=event.loading?"Loading matching database rows…":event.error?`Unable to load match rows: ${event.error.message||event.error}`:`Database updated · ${number(event.payload?.totalRows||0)} matching matches`;},onStateChange:next=>{state.matchesTableState=next;writeNavigationState({replace:true});}});}else state.matchesTable.setRemoteData(payload.rows||[],totalRows);if(status)status.textContent=`Match table ready · ${number(totalRows)} rows`;}catch(e){state.matchesTableProgressiveLoaded=false;if(status){status.classList.add("is-error");status.textContent=`Unable to load match rows: ${e.message||e}`;}}};
    const cachedSummary=window.P2K_PROGRESSIVE?.snapshotGet?.("matches-insights-summary",6*3600000);if(cachedSummary?.payload){applyMatchSummaryPayload(cachedSummary.payload);if(status)status.textContent="Cached match summary · refreshing…";}
    try{const payload=await loadJSON(matchInsightsURL(state.matchesTableState,{section:"summary"}),{credentials:"same-origin"});if(payload?.ok===false)throw new Error(payload?.error?.message||"Match insights are unavailable.");applyMatchSummaryPayload(payload);window.P2K_PROGRESSIVE?.snapshotSet?.("matches-insights-summary",payload);state.matchesLoaded=true;if(status)status.textContent="Match summary ready · more data loads as you scroll";
      state.matchesProgressiveStops.push(observe(byId("matchesSizeAnalytics"),()=>section("results","matchesSizeDistribution"),{rootMargin:"600px 0px"}));
      state.matchesProgressiveStops.push(observe(byId("matchesDurationAnalytics"),()=>section("duration","matchesDurationTrendChart"),{rootMargin:"600px 0px"}));
      state.matchesProgressiveStops.push(observe(byId("matchesRulesDistribution"),()=>section("dimensions","matchesRulesDistribution"),{rootMargin:"600px 0px"}));
      state.matchesProgressiveStops.push(observe(byId("matchesHighlights"),()=>section("highlights"),{rootMargin:"600px 0px"}));
      state.matchesProgressiveStops.push(observe(byId("matchesDataTable"),loadTable,{rootMargin:"600px 0px"}));
    }catch(error){if(status){status.classList.add("is-error");status.textContent=`Unable to load match insights: ${error.message||error}`;}}
  }

  function arenaInsightsURL(tableState = state.arenasTableState, { section = "all", fileId = 0 } = {}) {
    const url = new URL("server/team-points/public/arenas-insights.php", window.location.href);
    if (section && section !== "all") url.searchParams.set("section", section);
    if (fileId) url.searchParams.set("file_id", String(fileId));
    if (section === "table" || section === "all") {
      url.searchParams.set("page", String(Math.max(1, Number(tableState?.page) || 1)));
      url.searchParams.set("page_size", "25");
      if (tableState?.query) url.searchParams.set("search", tableState.query);
      if (tableState?.sort) url.searchParams.set("sort", tableState.sort);
      url.searchParams.set("direction", tableState?.direction === "asc" ? "asc" : "desc");
    }
    return url.href;
  }

  function arenaDateLabel(row) {
    const date = String(row?.event_date || "");
    return row?.event_date_approximate ? `${date}≈` : date;
  }

  function renderArenaParticipation(rows) {
    const metric = state.arenasParticipationMetric === "share" ? "share" : "players";
    byId("arenasParticipationPlayers")?.classList.toggle("is-active", metric === "players");
    byId("arenasParticipationShare")?.classList.toggle("is-active", metric === "share");
    const mapped = rows.map(row => ({ ...row, chart_date: arenaDateLabel(row) }));
    renderNativeLine("arenasParticipationChart", mapped, metric === "share" ? {
      xKey: "chart_date", yMin: 0, yMax: 100, axisFormatter: value => `${Math.round(value)}%`,
      series: [{ key: "p2k_share_percent", label: "P2K share", color: "#4aa8d8", decimals: 1 }],
      tooltipExtra: row => `${escapeHTML(row.arena_name)}<br>Field: <b>${number(row.total_players)}</b> · P2K: <b>${number(row.p2k_players)}</b>`
    } : {
      xKey: "chart_date", series: [{ key: "p2k_players", label: "P2K players", color: "#4aa8d8", decimals: 0 }],
      tooltipExtra: row => `${escapeHTML(row.arena_name)}<br>Full field: <b>${number(row.total_players)}</b> · Share: <b>${row.p2k_share_percent == null ? "—" : Number(row.p2k_share_percent).toFixed(1) + "%"}</b>`
    });
  }

  function renderArenaTrend(rows) {
    const trend = Array.isArray(rows) ? rows.map(row => ({ ...row, chart_date: arenaDateLabel(row) })) : [];
    if (!trend.length) {
      ["arenasParticipationChart","arenasPlacementChart","arenasPercentileChart","arenasPointsChart","arenasResultsChart","arenasScoreChart"].forEach(id => nativeChartEmpty(byId(id), "No processed MCA Results data is available."));
      return;
    }
    renderArenaParticipation(trend);
    const ranks = trend.map(row => Number(row.best_rank)).filter(Number.isFinite), rankMax = Math.max(10, ...(ranks.length ? ranks : [10]));
    renderNativeLine("arenasPlacementChart", trend, {
      xKey: "chart_date", yMin: 1, yMax: rankMax, invertY: true, axisFormatter: value => `#${Math.max(1, Math.round(value))}`,
      series: [{ key: "best_rank", label: "Best finish", color: "#f6b73c", decimals: 0 }],
      tooltipExtra: row => `${escapeHTML(row.arena_name)}<br>Best: <b>${row.best_finisher ? escapeHTML(row.best_finisher) : "—"}</b> · Field: <b>${number(row.total_players)}</b><br>P2K: <b>${number(row.p2k_players)}</b> · Top 10: <b>${number(row.top10_count)}</b>`
    });
    renderNativeLine("arenasPercentileChart", trend, {
      xKey: "chart_date", yMin: 0, yMax: 100, axisFormatter: value => `${Math.round(value)}%`,
      series: [{ key: "best_percentile", label: "Best percentile", color: "#48b8a8", decimals: 1 }],
      tooltipExtra: row => `${escapeHTML(row.arena_name)}<br>Finish: <b>${row.best_rank ? `#${number(row.best_rank)}` : "—"}</b> of <b>${number(row.total_players)}</b>`
    });
    renderNativeBarLine("arenasPointsChart", trend, { xKey: "chart_date", barKey: "p2k_points", lineKey: "cumulative_points", barLabel: "Arena MCA points", lineLabel: "Cumulative MCA points" });
    const resultRows = trend.filter(row => row.games != null);
    renderNativeStackedBars("arenasResultsChart", resultRows, { labelKey: "chart_date", series: [
      { key: "wins", label: "Wins", color: "#5fbf72" }, { key: "draws", label: "Draws", color: "#f6b73c" }, { key: "losses", label: "Losses", color: "#e7685a" }
    ], showTotals: false });
    renderNativeLine("arenasScoreChart", resultRows, { xKey: "chart_date", yMin: 0, yMax: 100, axisFormatter: value => `${Math.round(value)}%`, series: [{ key: "score_percent", label: "Score", color: "#a98ae8", decimals: 1 }], tooltipExtra: row => `${escapeHTML(row.arena_name)}<br>W / D / L: <b>${number(row.wins)} / ${number(row.draws)} / ${number(row.losses)}</b>` });
    document.querySelectorAll('[data-chart-reset^="arenas"]').forEach(button => { const id = button.dataset.chartReset; button.onclick = () => byId(id)?._p2kResetZoom?.(); });
  }

  function arenaLeadersColumns() {
    return [
      { key: "username", type: "text", render: row => cellWithSub(insightAction(row.username, () => openUnifiedPlayerProfile(row.username)), row.current_member ? "Current member" : "Former member") },
      ...["arenas","wins","podiums","top10s"].map(key => ({ key, type: "number", align: "right", render: row => number(row[key]) })),
      { key: "points", type: "number", align: "right", render: row => Number(row.points || 0).toFixed(1).replace(/\.0$/, "") },
      ...["game_wins","draws","losses"].map(key => ({ key, type: "number", align: "right", render: row => row[key] == null ? "—" : number(row[key]) })),
      { key: "best_finish", type: "number", align: "right", render: row => row.best_finish ? `#${number(row.best_finish)}` : "—" },
      { key: "live_rank_name", type: "text", render: row => row.live_rank_name || "Unranked" }
    ];
  }

  function arenaTableColumns() {
    return [
      { key: "event_date", type: "text", render: row => cellWithSub(row.event_date || "—", row.event_date_approximate ? "Approximate" : "Known date") },
      { key: "arena_name", type: "text", render: row => cellWithSub(insightAction(row.arena_name || row.arena_slug, () => openArenaDetail(row.file_id)), row.best_finisher ? `Best: ${row.best_finisher}` : row.arena_slug) },
      ...["total_players","p2k_players"].map(key => ({ key, type: "number", align: "right", render: row => number(row[key]) })),
      { key: "p2k_share_percent", type: "number", align: "right", render: row => row.p2k_share_percent == null ? "—" : `${Number(row.p2k_share_percent).toFixed(1)}%` },
      { key: "best_rank", type: "number", render: row => row.best_rank ? cellWithSub(`#${number(row.best_rank)}`, row.best_finisher || "") : "—" },
      ...["top10_count","podium_count"].map(key => ({ key, type: "number", align: "right", render: row => number(row[key]) })),
      { key: "p2k_points", type: "number", align: "right", render: row => Number(row.p2k_points || 0).toFixed(1).replace(/\.0$/, "") },
      { key: "score_percent", type: "number", align: "right", render: row => row.score_percent == null ? "—" : `${Number(row.score_percent).toFixed(1)}%` }
    ];
  }

  function renderArenaRecords(records) {
    const host = byId("arenasRecords"); if (!host) return;
    const rows = Array.isArray(records) ? records : [];
    host.innerHTML = rows.length ? rows.map(row => {
      const subject = row.username ? `<button type="button" data-arena-record-player="${escapeHTML(row.username)}">${escapeHTML(row.username)}</button>` : row.file_id ? `<button type="button" data-arena-record-file="${Number(row.file_id)}">${escapeHTML(row.arena || "Arena")}</button>` : escapeHTML(row.arena || "—");
      const value = row.key === "points" || row.key === "arena_points" ? Number(row.value || 0).toFixed(1).replace(/\.0$/, "") : number(row.value);
      return `<div><span>${escapeHTML(row.label)}</span><strong>${subject} · ${value}</strong></div>`;
    }).join("") : '<p class="p2k-table-status">No arena records are available.</p>';
    host.querySelectorAll("[data-arena-record-player]").forEach(button => button.addEventListener("click", () => openUnifiedPlayerProfile(button.dataset.arenaRecordPlayer)));
    host.querySelectorAll("[data-arena-record-file]").forEach(button => button.addEventListener("click", () => openArenaDetail(Number(button.dataset.arenaRecordFile))));
  }

  async function openArenaDetail(fileId) {
    if (!Number.isFinite(Number(fileId)) || Number(fileId) <= 0) return;
    showInsightsModal({ eyebrow: "Arena Insights", title: "Arena", subtitle: "Loading MCA Results detail…", html: '<p class="p2k-table-status">Loading arena detail…</p>' });
    try {
      const payload = await loadJSON(arenaInsightsURL(state.arenasTableState, { section: "detail", fileId }), { credentials: "same-origin" });
      if (payload?.ok === false) throw new Error(payload?.error?.message || "Arena detail is unavailable.");
      const arena = payload.arena || {}, participants = Array.isArray(payload.participants) ? payload.participants : [];
      const rows = participants.map(row => `<tr><td>${escapeHTML(row.username)}</td><td class="is-right">${row.rank ? `#${number(row.rank)}` : "—"}</td><td class="is-right">${Number(row.points || 0).toFixed(1).replace(/\.0$/, "")}</td><td class="is-right">${row.games == null ? "—" : number(row.games)}</td><td class="is-right">${row.wins == null ? "—" : number(row.wins)}</td><td class="is-right">${row.draws == null ? "—" : number(row.draws)}</td><td class="is-right">${row.losses == null ? "—" : number(row.losses)}</td></tr>`).join("");
      const source = arena.event_url ? `<p><a class="p2k-table-link" href="${escapeHTML(arena.event_url)}" target="_blank" rel="noopener">Open source arena on Chess.com ↗</a></p>` : "";
      showInsightsModal({ eyebrow: "Arena Insights", title: arena.arena_name || arena.arena_slug || "Arena", subtitle: `${arena.event_date || "Unknown date"}${arena.event_date_approximate ? " · approximate date" : ""}`, html: `<div class="p2k-detail-kpis"><div><span>Field</span><strong>${number(arena.total_players)}</strong></div><div><span>P2K players</span><strong>${number(arena.p2k_players)}</strong></div><div><span>Best finish</span><strong>${arena.best_rank ? `#${number(arena.best_rank)}` : "—"}</strong></div><div><span>MCA points</span><strong>${Number(arena.p2k_points || 0).toFixed(1).replace(/\.0$/, "")}</strong></div><div><span>Top 10</span><strong>${number(arena.top10_count)}</strong></div><div><span>Podiums</span><strong>${number(arena.podium_count)}</strong></div></div>${source}<div class="p2k-table-wrap"><table class="p2k-table"><thead><tr><th>Player</th><th class="is-right">Rank</th><th class="is-right">Points</th><th class="is-right">Games</th><th class="is-right">W</th><th class="is-right">D</th><th class="is-right">L</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="p2k-table-empty">No canonical P2K participant rows are available.</td></tr>'}</tbody></table></div>` });
    } catch (error) { showInsightsModal({ eyebrow: "Arena Insights", title: "Arena unavailable", subtitle: "", html: `<p class="p2k-table-status is-error">${escapeHTML(error.message || error)}</p>` }); }
  }

  function applyArenaPayload(payload) {
    const summary = payload?.summary || {}, trend = Array.isArray(payload?.trend) ? payload.trend : [], leaders = Array.isArray(payload?.leaders) ? payload.leaders : [];
    setText("arenasStatPlayed", number(summary.arenas)); setText("arenasStatParticipations", number(summary.participations)); setText("arenasStatPlayers", number(summary.unique_players)); setText("arenasStatVictories", number(summary.victories)); setText("arenasStatPodiums", number(summary.podiums)); setText("arenasStatTop10", number(summary.top10_finishes)); setText("arenasStatBest", summary.best_finish ? `#${number(summary.best_finish)}` : "—"); setText("arenasStatAverage", Number(summary.average_p2k_players || 0).toFixed(1)); setText("arenasDatabaseBadge", `MCA Results · schema ${number(payload?.meta?.analytics_schema_version)}`);
    renderArenaTrend(trend); renderArenaRecords(payload?.records || []);
    if (!state.arenasLeadersTable) state.arenasLeadersTable = new window.P2KDataTable({ root: byId("arenasLeadersTable"), columns: arenaLeadersColumns(), rows: leaders, pageSize: 25, searchInput: byId("arenasLeadersSearch"), countHost: byId("arenasLeadersCount"), pagerHost: byId("arenasLeadersPager"), state: { sort: "points", direction: "desc", page: 1 } }); else state.arenasLeadersTable.setRows(leaders);
    setText("arenasLeadersStatus", `Canonical MCA leaders · ${number(leaders.length)} players`);
    const totalRows = Number(payload?.pagination?.total_rows || 0);
    if (!state.arenasTable) state.arenasTable = new window.P2KDataTable({ root: byId("arenasDataTable"), columns: arenaTableColumns(), rows: payload.rows || [], totalRows, pageSize: 25, searchInput: byId("arenasTableSearch"), countHost: byId("arenasTableCount"), pagerHost: byId("arenasTablePager"), state: state.arenasTableState, remoteLoader: async tableState => { const remote = await loadJSON(arenaInsightsURL(tableState, { section: "table" }), { credentials: "same-origin" }); return { rows: remote.rows || [], totalRows: Number(remote.pagination?.total_rows || 0), pagination: remote.pagination }; }, onRemoteState: event => { const status = byId("arenasTableStatus"); if (!status) return; status.classList.toggle("is-error", Boolean(event.error)); status.textContent = event.loading ? "Loading matching arena rows…" : event.error ? `Unable to load arena rows: ${event.error.message || event.error}` : `Arena archive ready · ${number(event.payload?.totalRows || 0)} matching arenas`; }, onStateChange: next => { state.arenasTableState = next; writeNavigationState({ replace: true }); } }); else state.arenasTable.setRemoteData(payload.rows || [], totalRows);
    setText("arenasTableStatus", `Arena archive ready · ${number(totalRows)} stored arenas`);
  }

  async function loadArenaInsights({ force = false } = {}) {
    if (state.arenasLoaded && !force) return;
    const status = byId("arenasTableStatus"); if (status) { status.classList.remove("is-error"); status.textContent = "Loading Arena Insights…"; }
    const playersButton = byId("arenasParticipationPlayers"), shareButton = byId("arenasParticipationShare");
    if (playersButton && !playersButton.dataset.bound) { playersButton.dataset.bound = "1"; playersButton.addEventListener("click", () => { state.arenasParticipationMetric = "players"; if (state.arenasTrend) renderArenaParticipation(state.arenasTrend); }); }
    if (shareButton && !shareButton.dataset.bound) { shareButton.dataset.bound = "1"; shareButton.addEventListener("click", () => { state.arenasParticipationMetric = "share"; if (state.arenasTrend) renderArenaParticipation(state.arenasTrend); }); }
    const cached = window.P2K_PROGRESSIVE?.snapshotGet?.("arenas-insights-v1", 6 * 3600000); if (cached?.payload) { state.arenasTrend = cached.payload.trend || []; applyArenaPayload(cached.payload); if (status) status.textContent = "Cached Arena Insights · refreshing…"; }
    try {
      const payload = await loadJSON(arenaInsightsURL(state.arenasTableState), { credentials: "same-origin" });
      if (payload?.ok === false) throw new Error(payload?.error?.message || "Arena Insights are unavailable.");
      state.arenasTrend = payload.trend || []; applyArenaPayload(payload); window.P2K_PROGRESSIVE?.snapshotSet?.("arenas-insights-v1", payload); state.arenasLoaded = true;
    } catch (error) { if (status) { status.classList.add("is-error"); status.textContent = `Unable to load Arena Insights: ${error.message || error}`; } }
  }

  function opponentsTableColumns() {
    return [
      { key: "name", type: "text", render: row => cellWithSub(insightAction(row.name || row.slug, () => openOpponentProfile(row.slug)), row.disabled ? "Disabled club" : row.slug) },
      ...["total", "ongoing", "registered", "finished", "wins", "draws", "losses", "our_points", "their_points"].map(key => ({ key, type: "number", align: "right", render: row => number(row[key]) })),
      { key: "balance", type: "number", align: "right", render: (row, td) => { const value = Number(row.balance) || 0; if (value > 0) td.classList.add("p2k-positive"); else if (value < 0) td.classList.add("p2k-negative"); return `${value > 0 ? "+" : ""}${number(value)}`; } },
      { key: "result_coverage_percent", label:"Result coverage", type: "number", align:"right", render:row=>`${Number(row.result_coverage_percent??0).toFixed(1)}%` },
      { key: "win_rate", type: "number", align: "right", render: row => `${Number(row.win_rate || 0).toFixed(1)}%` }
    ];
  }

  function opponentInsightsURL(tableState = state.opponentsTableState, { includeSummary = false, section = "all" } = {}) {
    const url = new URL("server/team-points/public/opponents.php", window.location.href);
    if (section === "balance") {
      url.searchParams.set("section", "balance");
      return url.href;
    }
    url.searchParams.set("page", String(Math.max(1, Number(tableState?.page) || 1)));
    url.searchParams.set("page_size", "25");
    if (tableState?.query) url.searchParams.set("search", tableState.query);
    if (tableState?.filter && tableState.filter !== "all") url.searchParams.set("filter", tableState.filter);
    if (tableState?.sort) url.searchParams.set("sort", tableState.sort);
    url.searchParams.set("direction", tableState?.direction === "asc" ? "asc" : "desc");
    url.searchParams.set("include_summary", includeSummary ? "1" : "0");
    if (section && section !== "all") url.searchParams.set("section", section);
    return url.href;
  }

  function applyOpponentSummaryPayload(payload) {
    const summary=payload?.summary||{}, rows=Array.isArray(payload?.top_opponents)?payload.top_opponents.map(row=>({...row})):[];
    setText("opponentsStatTotal",number(summary.different_opponents));setText("opponentsStatPlayed",number(summary.played_historically));setText("opponentsStatCurrent",number(summary.currently_playing));setText("opponentsStatRegistration",number(summary.in_registration));setText("opponentsStatFinished",number(summary.finished_matches));renderOpponentTopChart(rows);
    const slugs=[...new Set(rows.map(row=>String(row.slug||"").trim().toLowerCase()).filter(Boolean))].slice(0,15);if(!slugs.length)return;
    const url=new URL("server/team-points/public/opponent-icons.php",window.location.href);url.searchParams.set("slugs",slugs.join(","));
    loadJSON(url.href,{credentials:"same-origin"}).then(iconPayload=>{const profiles=iconPayload?.profiles||{};let changed=false;rows.forEach(row=>{const profile=profiles[String(row.slug||"").toLowerCase()];if(profile?.icon){row.icon=profile.icon;changed=true;}if(profile?.name&&!row.name)row.name=profile.name;});if(changed)renderOpponentTopChart(rows);}).catch(()=>{});
  }

  async function loadOpponentInsights({ force = false } = {}) {
    if(state.opponentsLoaded&&!force)return;const status=byId("opponentsTableStatus");if(status){status.classList.remove("is-error");status.textContent="Loading opponent summary…";}state.opponentsProgressiveStops?.forEach(stop=>stop?.());state.opponentsProgressiveStops=[];
    const loadBalance=async()=>{const host=byId("opponentsBalanceAnalyzer");if(!host)return;const cacheKey="opponents-balance-v4";const cached=window.P2K_PROGRESSIVE?.snapshotGet?.(cacheKey,5*60000);if(cached?.payload&&window.P2K_OPPONENT_BALANCE?.render){window.P2K_OPPONENT_BALANCE.render(host,cached.payload);}else host.innerHTML='<p class="p2k-table-status">Loading all-match opponent heatmaps…</p>';try{const payload=await loadJSON(opponentInsightsURL(state.opponentsTableState,{section:"balance"}),{credentials:"same-origin"});if(payload?.ok===false)throw new Error(payload?.error?.message||"Opponent balance heatmaps are unavailable.");if(!window.P2K_OPPONENT_BALANCE?.render)throw new Error("Opponent balance renderer did not initialize.");window.P2K_OPPONENT_BALANCE.render(host,payload);window.P2K_PROGRESSIVE?.snapshotSet?.(cacheKey,payload);}catch(error){if(!cached?.payload)host.innerHTML=`<p class="p2k-table-status is-error">Unable to load opponent heatmaps: ${escapeHTML(error.message||error)}</p>`;}};
    const loadTable=async()=>{if(state.opponentsTableProgressiveLoaded)return;state.opponentsTableProgressiveLoaded=true;try{const payload=await loadJSON(opponentInsightsURL(state.opponentsTableState,{section:"table"}),{credentials:"same-origin"});const totalRows=Number(payload.pagination?.total_rows||0);if(!state.opponentsTable){state.opponentsTable=new window.P2KDataTable({root:byId("opponentsDataTable"),columns:opponentsTableColumns(),rows:payload.rows||[],totalRows,pageSize:25,searchInput:byId("opponentsTableSearch"),filterInput:byId("opponentsTableFilter"),countHost:byId("opponentsTableCount"),pagerHost:byId("opponentsTablePager"),state:state.opponentsTableState,remoteLoader:async tableState=>{const remote=await loadJSON(opponentInsightsURL(tableState,{section:"table"}),{credentials:"same-origin"});return{rows:remote.rows||[],totalRows:Number(remote.pagination?.total_rows||0),pagination:remote.pagination};},onRemoteState:event=>{if(!status)return;status.classList.toggle("is-error",Boolean(event.error));status.textContent=event.loading?"Loading matching database rows…":event.error?`Unable to load opponent rows: ${event.error.message||event.error}`:`Database updated · ${number(event.payload?.totalRows||0)} matching opponents`;},onStateChange:next=>{state.opponentsTableState=next;writeNavigationState({replace:true});}});}else state.opponentsTable.setRemoteData(payload.rows||[],totalRows);if(status)status.textContent=`Opponent table ready · ${number(totalRows)} rows`;}catch(e){state.opponentsTableProgressiveLoaded=false;if(status){status.classList.add("is-error");status.textContent=`Unable to load opponent rows: ${e.message||e}`;}}};
    // Start the independent all-match heatmap request in parallel with the summary.
    // A session snapshot can paint immediately while the network refresh proceeds.
    void loadBalance();
    const cachedSummary=window.P2K_PROGRESSIVE?.snapshotGet?.("opponents-insights-summary",6*3600000);if(cachedSummary?.payload){applyOpponentSummaryPayload(cachedSummary.payload);if(status)status.textContent="Cached opponent summary · refreshing…";}
    try{const payload=await loadJSON(opponentInsightsURL(state.opponentsTableState,{section:"summary"}),{credentials:"same-origin"});if(payload?.ok===false)throw new Error(payload?.error?.message||"Opponent insights are unavailable.");applyOpponentSummaryPayload(payload);window.P2K_PROGRESSIVE?.snapshotSet?.("opponents-insights-summary",payload);state.opponentsLoaded=true;if(status)status.textContent="Opponent summary ready · heatmaps use all matches · table loads as you scroll";const observe=window.P2K_PROGRESSIVE?.observe||((_,fn)=>{setTimeout(fn,0);return()=>{}});state.opponentsProgressiveStops.push(observe(byId("opponentsDataTable"),loadTable,{rootMargin:"600px 0px"}));}catch(error){if(status){status.classList.add("is-error");status.textContent=`Unable to load opponent insights: ${error.message||error}`;}}
  }

    return Object.freeze({ loadMemberInsights, loadMatchInsights, loadArenaInsights, loadOpponentInsights, openMatchDetail, openArenaDetail, renderNativeBarLine });
  };
})();
