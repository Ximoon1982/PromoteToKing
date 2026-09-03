/* Existing Insights, achievement catalogue and unified profile behavior. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.insights = Object.freeze({create(context) {
const { state, byId, escapeHTML, number, setText, nativeLink, cellWithSub, statusChip, loadPublicCachedJSON, loadJSON, writeNavigationState, rankThumbnailAsset, originalRankAsset, adminEntryUsername, selectHallSubtab, showPublicPage, formatDateOnly, formatRelative, matchRulesLabel, matchTimeControlLabel, challengeProgress } = context;
  function insightAction(label, callback, className = "p2k-table-link-button") {
    const button = document.createElement("button");
    button.type = "button";
    button.className = className;
    button.textContent = label || "Open";
    button.addEventListener("click", event => { event.stopPropagation(); callback(); });
    return button;
  }
  const insightsModalStack = [];
  function showInsightsModal({ eyebrow = "Insights", title = "Details", subtitle = "", html = "", replace = false } = {}) {
    const modal = byId("insightsDetailModal"), body = byId("insightsDetailBody"); if (!modal || !body) return;
    if (!modal.hidden && !replace) { const card=modal.querySelector(".p2k-profile-modal-card"), nodes=Array.from(body.childNodes), stash=document.createDocumentFragment(); const previous={eyebrow:byId("insightsDetailEyebrow")?.textContent||"",title:byId("insightsDetailTitle")?.textContent||"",subtitle:byId("insightsDetailSubtitle")?.textContent||"",nodes,modalScroll:modal.scrollTop,cardScroll:card?.scrollTop||0,focus:document.activeElement instanceof HTMLElement&&modal.contains(document.activeElement)?document.activeElement:null,stash}; nodes.forEach(node=>stash.appendChild(node)); insightsModalStack.push(previous); }
    setText("insightsDetailEyebrow",eyebrow);setText("insightsDetailTitle",title);setText("insightsDetailSubtitle",subtitle);body.innerHTML=html;modal.hidden=false;modal.scrollTop=0;const card=modal.querySelector(".p2k-profile-modal-card");if(card)card.scrollTop=0;document.body.classList.add("dashboard-modal-open");requestAnimationFrame(()=>byId("insightsDetailClose")?.focus({preventScroll:true}));
  }
  function closeInsightsModal() {
    const modal=byId("insightsDetailModal"),body=byId("insightsDetailBody");if(!modal||!body)return;if(insightsModalStack.length){const previous=insightsModalStack.pop();setText("insightsDetailEyebrow",previous.eyebrow);setText("insightsDetailTitle",previous.title);setText("insightsDetailSubtitle",previous.subtitle);body.replaceChildren(...previous.nodes);modal.hidden=false;requestAnimationFrame(()=>{modal.scrollTop=previous.modalScroll||0;const card=modal.querySelector(".p2k-profile-modal-card");if(card)card.scrollTop=previous.cardScroll||0;if(previous.focus?.isConnected)previous.focus.focus({preventScroll:true});});return;}modal.hidden=true;body.replaceChildren();document.body.classList.remove("dashboard-modal-open");
  }
  function normalizeTournamentName(entry) {
    if (entry && typeof entry === "object") return String(entry.username || entry.name || entry.player || "").trim();
    return String(entry || "").trim();
  }
  function tournamentAchievements(archivePayload, username) {
    const key = String(username || "").trim().toLowerCase();
    const tournaments = Array.isArray(archivePayload?.archive?.tournaments)
      ? archivePayload.archive.tournaments
      : Array.isArray(archivePayload?.tournaments) ? archivePayload.tournaments : [];
    const medals = { gold: 0, silver: 0, bronze: 0, events: [] };
    for (const tournament of tournaments) {
      const podium = tournament?.podium || tournament?.medallists || tournament?.medalists || {};
      for (const medal of ["gold", "silver", "bronze"]) {
        const rawNames = podium?.[medal] ?? podium?.[medal === "gold" ? "first" : medal === "silver" ? "second" : "third"] ?? [];
        const names = (Array.isArray(rawNames) ? rawNames : [rawNames]).map(normalizeTournamentName).filter(Boolean);
        if (!names.some(name => name.toLowerCase() === key)) continue;
        medals[medal] += 1;
        medals.events.push({
          medal,
          name: tournament?.name || tournament?.title || tournament?.slug || "Tournament",
          date: tournament?.finishAt || tournament?.end_time || "",
          url: tournament?.url || (tournament?.slug ? `https://www.chess.com/tournament/${tournament.slug}` : "")
        });
      }
    }
    medals.events.sort((a, b) => String(b.date).localeCompare(String(a.date)));
    return medals;
  }
  function profileMetric(label, value, detail = "") {
    return `<article class="p2k-profile-metric"><span>${escapeHTML(label)}</span><strong>${escapeHTML(value)}</strong>${detail ? `<small>${escapeHTML(detail)}</small>` : ""}</article>`;
  }
  function tournamentAchievementKeys(medals) {
    const total = Number(medals?.gold || 0) + Number(medals?.silver || 0) + Number(medals?.bronze || 0);
    const keys = [];
    if (total >= 1) keys.push("tournament-first-medal");
    if (Number(medals?.gold || 0) >= 1) keys.push("tournament-first-gold");
    if (total >= 5) keys.push("tournament-medals-5");
    if (total >= 10) keys.push("tournament-medals-10");
    return keys;
  }
  function achievementCard(item, earned = false, options = {}) {
    const icon = String(item?.miniature || item?.icon || "p2k-logo.jpg");
    const count = Number(item?.earnedCurrentMemberCount ?? item?.earned_current_member_count ?? 0);
    const totalMembers = Number(item?.clubCurrentMemberCount ?? item?.club_current_member_count ?? 0);
    const share = totalMembers > 0 ? (count * 100 / totalMembers) : 0;
    const earnedAt = earned && item?.earned_at ? formatDateOnly(String(item.earned_at).includes("T") ? String(item.earned_at) : `${item.earned_at.replace(" ", "T")}Z`) : "";
    const datePending = earned && item?.earned_at_precision === "tournament-pending";
    const earnedApproximate=earned&&["mca-interpolated","mca-upload-fallback"].includes(String(item?.earned_at_precision||""));
    const earnedMeta=earned?(earnedAt?`Earned ${earnedAt}${earnedApproximate?" (approximative date)":""}`:datePending?"Earned · date pending tournament refresh":"Earned"):"";
    const ownershipMeta = !options.hideOwnership && count > 0 ? `${number(count)} club member${count === 1 ? "" : "s"}${totalMembers > 0 ? ` (${share.toFixed(1)}%)` : ""}` : "";
    const meta = [earnedMeta, ownershipMeta].filter(Boolean).join(" · ");
    const progress = !earned && options.progress && Number(options.progress.current) >= 0 && Number(options.progress.target) > 0
      ? `<div class="p2k-achievement-progress"><span>${escapeHTML(options.progress.progress_metric || "Progress")} · ${number(options.progress.current)} / ${number(options.progress.target)}</span><progress max="100" value="${Math.max(0,Math.min(100,Number(options.progress.progress_percent)||0))}"></progress></div>` : "";
    return `<button type="button" class="p2k-achievement-card${earned ? " is-earned" : ""}" data-achievement-key="${escapeHTML(item?.key || "")}" data-achievement-name="${escapeHTML(item?.label || item?.key || "")}"><img src="${escapeHTML(icon)}" alt="${escapeHTML(item?.label || "Achievement")}" loading="lazy" onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='assets/images/achievements/placeholders/generic.svg'}"><div><strong>${escapeHTML(item?.label || item?.key || "Achievement")}</strong><p>${escapeHTML(item?.description || "")}</p>${meta?`<small>${escapeHTML(meta)}</small>`:""}${progress}</div></button>`;
  }
  function achievementEarnedDetail(item) {
    const candidates = [item?.earned_at, item?.earned_on, item?.date, item?.achieved_at, item?.first_earned_at].filter(Boolean);
    const rawDate = candidates.length ? String(candidates[0]) : "";
    const normalizedDate = rawDate.includes("T") ? rawDate : rawDate.includes(" ") ? `${rawDate.replace(" ", "T")}Z` : rawDate ? `${rawDate}T00:00:00Z` : "";
    const approximateDate=["mca-interpolated","mca-upload-fallback"].includes(String(item?.earned_at_precision||""));
    const earnedDate=normalizedDate?`${formatDateOnly(normalizedDate)}${approximateDate?" (approximative date)":""}`:item?.earned_at_precision==="tournament-pending"?"Date pending tournament refresh":"Stored achievement records do not contain an exact award date";
    const context = item?.context || item?.source || item?.earned_via || item?.details || "The stored player statistics reached this achievement threshold.";
    return{earnedDate,context:typeof context==="string"?context:JSON.stringify(context)};
  }
  function achievementWebURL(raw) {
    const value=String(raw||"").trim();if(!value)return "";let url;try{url=new URL(value,window.location.href)}catch{return ""}
    if(url.hostname==="www.chess.com"||url.hostname==="chess.com")return url.href;if(url.hostname!=="api.chess.com")return url.href;
    let m=url.pathname.match(/^\/pub\/match\/(\d+)/i);if(m)return `https://www.chess.com/club/matches/${m[1]}`;m=url.pathname.match(/^\/pub\/tournament\/([^/]+)/i);if(m)return `https://www.chess.com/tournament/${encodeURIComponent(decodeURIComponent(m[1]))}`;return "";
  }
  let achievementDetailNav=null;
  function openAchievementDetail(item, earned = false, options = {}) {
    if (!item) return;
    const fullImage = String(item.icon || item.miniature || "p2k-logo.jpg");
    const criterion = item.criteria || item.criterion || item.description || "Achievement criterion unavailable.";
    const currentCount = Number(item.earnedCurrentMemberCount ?? item.earned_current_member_count ?? 0);
    const currentMembers = Number(item.clubCurrentMemberCount ?? item.club_current_member_count ?? 0);
    const share = currentMembers > 0 ? (currentCount * 100 / currentMembers) : 0;
    const ownership = currentCount > 0
      ? `${number(currentCount)} club member${currentCount === 1 ? "" : "s"}. ${currentMembers > 0 ? `${share.toFixed(1)}% of current club members.` : ""}`
      : `0 club members.${currentMembers > 0 ? " 0.0% of current club members." : ""}`;
    const earnedInfo = achievementEarnedDetail(item);
    const sourceName = String(item?.source_name || "").trim();
    const sourceURL = String(item?.source_url || "").trim();
    const safeSourceURL = achievementWebURL(sourceURL);
    const earnedRows = earned ? `<div><dt>Achievement date</dt><dd>${escapeHTML(earnedInfo.earnedDate)}</dd></div>${sourceName ? `<div><dt>Triggered by</dt><dd>${safeSourceURL ? `<a href="${escapeHTML(safeSourceURL)}" target="_blank" rel="noopener noreferrer">${escapeHTML(sourceName)}</a>` : escapeHTML(sourceName)}</dd></div>` : ""}` : "";
    const progress=options.progress&&!earned&&Number(options.progress.target)>0?`<div class="p2k-achievement-progress"><span>${escapeHTML(options.progress.progress_metric||"Progress")} · ${number(options.progress.current||0)} / ${number(options.progress.target||0)}</span><progress max="100" value="${Math.max(0,Math.min(100,Number(options.progress.progress_percent)||0))}"></progress></div>`:"";
    const nav=options.navIndex!=null&&achievementDetailNav?`<div class="p2k-achievement-detail-nav"><button type="button" data-achievement-prev ${options.navIndex<=0?"disabled":""}>← Previous</button><button type="button" data-achievement-next ${options.navIndex>=achievementDetailNav.items.length-1?"disabled":""}>Next →</button></div>`:"";
    const html = `${nav}<section class="p2k-achievement-detail"><img src="${escapeHTML(fullImage)}" alt="${escapeHTML(item.label || "Achievement")}" onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='assets/images/achievements/placeholders/generic.svg'}"><div><span class="p2k-achievement-detail-state ${earned ? "is-earned" : ""}">${earned ? "Earned" : "Not earned yet"}</span><h3>${escapeHTML(item.label || item.key || "Achievement")}</h3>${progress}<dl><div><dt>Criterion</dt><dd>${escapeHTML(criterion)}</dd></div>${earnedRows}<div><dt>Achieved by</dt><dd>${escapeHTML(ownership)}</dd></div></dl></div></section>`;
    showInsightsModal({replace:Boolean(options.replace),eyebrow:"Achievement details",title:item.label||item.key||"Achievement",subtitle:earned?"Player achievement record":"Achievement criterion",html});
    const body=byId("insightsDetailBody");body?.querySelector("[data-achievement-prev]")?.addEventListener("click",()=>openAchievementNav(options.navIndex-1,true));body?.querySelector("[data-achievement-next]")?.addEventListener("click",()=>openAchievementNav(options.navIndex+1,true));
  }
  function openAchievementNav(index,replace=false){const nav=achievementDetailNav;if(!nav||index<0||index>=nav.items.length)return;nav.index=index;const x=nav.items[index];openAchievementDetail(x.item,x.earned,{progress:x.progress,navIndex:index,replace})}
  function achievementDisplayBar(done, total, label = "Achievement progress") {
    const complete=Math.max(0,Math.min(Number(total)||0,Number(done)||0)),count=Math.max(0,Number(total)||0),pct=count?Math.max(0,Math.min(100,100*complete/count)):0;
    if(count>10){
      return `<span class="p2k-achievement-display-bar is-progressive"><span class="p2k-achievement-display-track" role="progressbar" aria-label="${escapeHTML(label)}" aria-valuemin="0" aria-valuemax="${count}" aria-valuenow="${complete}"><i style="width:${pct.toFixed(2)}%"></i></span></span>`;
    }
    return `<span class="p2k-achievement-display-bar is-segmented" role="progressbar" aria-label="${escapeHTML(label)}" aria-valuemin="0" aria-valuemax="${count}" aria-valuenow="${complete}"><span class="p2k-achievement-segments">${Array.from({length:count},(_,i)=>`<i class="${i<complete?'is-complete':''}"></i>`).join("")}</span></span>`;
  }
  function achievementCategoryLabel(item, category) {
    return String(item?.category_label || category || "Other").trim() || "Other";
  }
  function achievementFamilyLabel(item, family) {
    return String(item?.family_label || family || "Other").trim() || "Other";
  }
  async function openAchievementCatalog(username = "", focusKey = "") {
    const name=String(username||"").trim();showInsightsModal({eyebrow:"Achievements",title:name?`${name} · all achievements`:"All achievements",subtitle:"Loading the complete achievement catalogue…",html:'<p class="p2k-table-status">Loading achievement catalogue…</p>'});
    try {
      const requests=[loadPublicCachedJSON("server/team-points/public/achievements.php",{ttl:300000,credentials:"same-origin"})];
      if(name){const profileURL=new URL("server/team-points/public/player-profile.php",window.location.href);profileURL.searchParams.set("username",name);profileURL.searchParams.set("mode","modal");requests.push(loadPublicCachedJSON(profileURL.href,{ttl:120000,credentials:"same-origin"}));const progressURL=new URL("server/team-points/public/member-intelligence.php",window.location.href);progressURL.searchParams.set("username",name);requests.push(loadPublicCachedJSON(progressURL.href,{ttl:120000,credentials:"same-origin"}))}
      const results=await Promise.all(requests),catalogPayload=results[0],profilePayload=results[1],progressPayload=results[2],catalog=Array.isArray(catalogPayload?.catalog)?catalogPayload.catalog:[],earnedItems=Array.isArray(profilePayload?.player?.achievements)?profilePayload.player.achievements:[],progressRows=Array.isArray(progressPayload?.player?.member?.achievement_progress)?progressPayload.player.member.achievement_progress:[],progressByKey=new Map(progressRows.map(item=>[String(item.key||""),item])),metricEarned=new Set(progressRows.filter(item=>Number(item?.target)>0&&Number(item?.current)>=Number(item?.target)).map(item=>String(item.key||""))),earnedByKey=new Map(earnedItems.map(item=>[String(item.key||""),item])),earned=new Set([...earnedItems.map(item=>String(item.key||"")),...metricEarned]);metricEarned.forEach(k=>{if(!earnedByKey.has(k))earnedByKey.set(k,{key:k,level:"earned",earned_at:null,earned_at_precision:"metric-reconciled"})});const families=new Map();
      catalog.forEach(item=>{const family=String(item.family||"other"),category=String(item.category||"other");if(!families.has(family))families.set(family,{label:achievementFamilyLabel(item,family),groups:new Map(),items:[]});const f=families.get(family);f.items.push(item);if(!f.groups.has(category))f.groups.set(category,{label:achievementCategoryLabel(item,category),items:[]});f.groups.get(category).items.push(item)});
      const familyContainingFocus=focusKey?String(catalog.find(item=>String(item.key||"")===String(focusKey))?.family||""):"";
      const showCatalogueProgress=Boolean(name);
      const familiesHTML=[...families.entries()].map(([familyKey,family])=>{
        const familyAchieved=family.items.filter(item=>earned.has(item.key)).length,total=family.items.length;
        const groupsHTML=[...family.groups.entries()].map(([category,group])=>{const achievedCount=group.items.filter(item=>earned.has(item.key)).length,groupTotal=group.items.length;return `<section class="p2k-achievement-group-static" data-achievement-group="${escapeHTML(category)}"><header><div><h3>${escapeHTML(group.label)}</h3><span>${number(achievedCount)} / ${number(groupTotal)} achieved</span></div>${showCatalogueProgress?achievementDisplayBar(achievedCount,groupTotal,`${group.label} progress`):""}</header><div class="p2k-achievement-catalog">${group.items.map(item=>{const key=String(item.key||"");return achievementCard(earnedByKey.has(key)?{...item,...earnedByKey.get(key)}:item,earned.has(item.key),{hideOwnership:Boolean(name),progress:progressByKey.get(key)||null})}).join("")}</div></section>`}).join("");
        return `<details class="p2k-achievement-family" data-achievement-family="${escapeHTML(familyKey)}"${familyKey===familyContainingFocus?' open':''}><summary><span class="p2k-achievement-family-copy"><strong>${escapeHTML(family.label)}</strong><small>${number(familyAchieved)} / ${number(total)} achieved</small></span>${showCatalogueProgress?achievementDisplayBar(familyAchieved,total,`${family.label} progress`):""}<span class="p2k-achievement-family-chevron" aria-hidden="true"></span></summary><div class="p2k-achievement-family-body">${groupsHTML}</div></details>`;
      }).join("");
      const html=catalog.length?`<div class="p2k-achievement-catalog-search"><label><span>Search achievement names</span><input type="search" data-achievement-search autocomplete="off" placeholder="Type an achievement name…"></label><small data-achievement-search-count>${number(catalog.length)} achievements</small></div>${familiesHTML}`:'<p class="p2k-table-status">No achievement definitions are available.</p>';
      showInsightsModal({replace:true,eyebrow:"Achievement catalogue",title:name?`${name} · ${number(earned.size)} earned`:`${number(catalog.length)} achievements`,subtitle:"",html});
      const body=byId("insightsDetailBody"),catalogByKey=new Map(catalog.map(item=>{const key=String(item.key||"");return [key,earnedByKey.has(key)?{...item,...earnedByKey.get(key)}:item]}));achievementDetailNav={items:catalog.map(item=>{const key=String(item.key||"");return{item:catalogByKey.get(key),earned:earned.has(key),progress:progressByKey.get(key)||null}}),index:0};body?.querySelectorAll("[data-achievement-key]").forEach(button=>button.addEventListener("click",()=>{const key=String(button.dataset.achievementKey||""),i=achievementDetailNav.items.findIndex(x=>String(x.item.key||"")===key);openAchievementNav(i)}));const search=body?.querySelector("[data-achievement-search]"),searchCount=body?.querySelector("[data-achievement-search-count]");if(search){const applySearch=()=>{const q=String(search.value||"").trim().toLocaleLowerCase("en"),allCards=[...body.querySelectorAll("[data-achievement-key]")];let visible=0;allCards.forEach(card=>{const match=!q||String(card.dataset.achievementName||"").toLocaleLowerCase("en").includes(q);card.hidden=!match;if(match)visible++});body.querySelectorAll(".p2k-achievement-group-static").forEach(group=>{const any=[...group.querySelectorAll("[data-achievement-key]")].some(card=>!card.hidden);group.hidden=!any});body.querySelectorAll(".p2k-achievement-family").forEach(family=>{const any=[...family.querySelectorAll("[data-achievement-key]")].some(card=>!card.hidden);family.hidden=!any;if(q&&any)family.open=true});if(searchCount)searchCount.textContent=`${number(visible)} of ${number(allCards.length)} achievements`};search.addEventListener("input",applySearch)}if(focusKey){const target=body?.querySelector(`[data-achievement-key="${CSS.escape(String(focusKey))}"]`);if(target){target.closest(".p2k-achievement-family")?.setAttribute("open","");target.classList.add("is-focused-achievement");target.scrollIntoView({block:"center",behavior:"smooth"});target.focus({preventScroll:true});}}
    }catch(error){showInsightsModal({replace:true,eyebrow:"Achievements",title:name||"Achievement catalogue",subtitle:"Unable to load",html:`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p>`})}
  }
  async function openUnifiedPlayerProfile(username) {
    const name=String(username||"").trim();if(!name)return;showInsightsModal({eyebrow:"Player profile",title:name,subtitle:"Loading database profile…",html:'<p class="p2k-table-status">Loading unified player profile…</p>'});
    try {
      const profileURL=new URL("server/team-points/public/player-profile.php",window.location.href);profileURL.searchParams.set("username",name);profileURL.searchParams.set("mode","modal");const profilePayload=await loadPublicCachedJSON(profileURL.href,{ttl:120000,credentials:"same-origin"});if(profilePayload?.ok===false)throw new Error(profilePayload?.error?.message||"Player profile is unavailable.");
      const player=profilePayload.player||{},achievementTimestamp=item=>{const raw=item?.earned_at||item?.first_recorded_at||"";const ts=raw?Date.parse(String(raw).includes("T")?String(raw):String(raw).replace(" ","T")+"Z"):0;return Number.isFinite(ts)?ts:0},achievements=[...(Array.isArray(player.achievements)?player.achievements:[])].sort((a,b)=>achievementTimestamp(b)-achievementTimestamp(a)||String(a?.key||"").localeCompare(String(b?.key||""))),achievementTotal=Number(profilePayload?.meta?.achievement_total||profilePayload?.achievement_total||115),achievementCount=achievements.length,achievementPct=achievementTotal?100*achievementCount/achievementTotal:0,achievementPosition=Number(player.achievement_position||profilePayload?.meta?.achievement_position||0),monthly=Array.isArray(player.monthly)?player.monthly:[];let cumulativePoints=0;const progression=monthly.map(row=>{cumulativePoints+=Number(row.points)||0;return {...row,cumulative_points:cumulativePoints}});
      const monthlyHTML=monthly.length?`<div class="p2k-chart-heading"><div><p>Monthly Team Points are bars; cumulative Team Points are the line. Drag across the graph to zoom and double-click to reset.</p></div><button type="button" class="p2k-chart-reset" data-profile-progression-reset>Reset zoom</button></div><div id="playerTeamPointsProgression" class="p2k-native-chart p2k-profile-progression-chart"></div><div class="p2k-chart-legend"><span data-chart-color="blue">Monthly points</span><span data-chart-color="gold">Cumulative points</span></div>`:'<p class="p2k-table-status">No monthly activity is stored.</p>',achievementsHTML=achievements.length?achievements.slice(0,10).map(item=>achievementCard(item,true,{hideOwnership:true})).join(""):'<span class="p2k-table-status">No achievement threshold reached yet.</span>',avatar=String(player.avatar||""),dailyRanked=Boolean(player?.rank&&String(player?.rank?.key||player?.rank?.name||"").toLowerCase()!=="unranked"),dailyImage=dailyRanked?originalRankAsset(player?.rank?.framed_image||player?.rank?.image||""):"",liveRank=player?.live?.rank||null,liveRanked=Boolean(liveRank&&String(player?.live?.rank_name||liveRank?.name||liveRank?.key||"").toLowerCase()!=="unranked"),liveImage=liveRanked&&liveRank?.framed_image?`assets/images/live-ranks/${liveRank.framed_image}`:"";
      const profileToken=`profile-${Date.now()}-${Math.random().toString(36).slice(2)}`;const html=`<div class="p2k-profile-root" data-profile-root="${profileToken}"><section class="p2k-profile-overview"><div class="p2k-profile-identity"><div class="p2k-profile-avatar">${avatar?`<img src="${escapeHTML(avatar)}" alt="${escapeHTML(player.username||name)} avatar">`:"♔"}</div><div><h3>${escapeHTML(player.username||name)}</h3><p>${player.current_member?"Current Promote to King member":"Former or unconfirmed member"}</p>${player.profile_url?`<a href="${escapeHTML(player.profile_url)}" target="_blank" rel="noopener noreferrer">Open Chess.com profile</a>`:""}</div></div><div class="p2k-profile-rank-pair"><article class="${dailyImage?"":"is-unranked"}">${dailyImage?`<img src="${escapeHTML(dailyImage)}" alt="${escapeHTML(player?.rank?.name||"Daily rank")}">`:""}<span>Daily rank</span><strong>${escapeHTML(player?.rank?.name||"Unranked")}</strong></article><article class="${liveImage?"":"is-unranked"}">${liveImage?`<img src="${escapeHTML(liveImage)}" alt="${escapeHTML(player?.live?.rank_name||"Live rank")}">`:""}<span>Live rank</span><strong>${escapeHTML(player?.live?.rank_name||"Unranked")}</strong></article></div><div class="p2k-profile-metrics">${profileMetric("Team Points",number(player.points))}${profileMetric("Team matches",number(player.matches))}${profileMetric("Team games",number(player.games))}${profileMetric("W / D / L",`${number(player.wins)} / ${number(player.draws)} / ${number(player.losses)}`)}${profileMetric("Daily team position",player.team_position?`#${number(player.team_position)}`:"—")}${profileMetric("MCA points",player.live?number(player.live.points):"—",player.live?`${number(player.live.arenas)} arenas played`:"No MCA record")}${profileMetric("Live rank",player?.live?.rank_name||"Unranked",player?.live?.top3?`${number(player.live.top3)} podium finish${Number(player.live.top3)===1?"":"es"}`:"No MCA podium recorded")}${profileMetric("Achievements",`${number(achievementCount)} / ${number(achievementTotal)}`,`${achievementPct.toFixed(1)}% complete${achievementPosition?` · team position #${number(achievementPosition)}`:""}`)}<article class="p2k-profile-metric" data-tournament-medals><span>Tournament medals</span><strong>…</strong><small>Loading tournament history</small></article></div></section><section class="p2k-profile-section" data-profile-challenges><h3>Achievement challenges</h3><p class="p2k-table-status">Loading achievement challenges…</p></section><section class="p2k-profile-section"><div class="p2k-profile-section-title" data-profile-achievement-actions><h3>Achievements</h3><button type="button" class="dashboard-button" data-profile-achievements="${escapeHTML(player.username||name)}">View all achievements of ${escapeHTML(player.username||name)}</button></div><div class="p2k-achievement-catalog is-compact">${achievementsHTML}</div></section><section class="p2k-profile-section"><h3>Team Points progression</h3>${monthlyHTML}</section><section class="p2k-profile-section"><h3>Tournament achievements</h3><div class="p2k-profile-list" data-tournament-history><p class="p2k-table-status">Loading tournament history…</p></div></section></div>`;
      showInsightsModal({replace:true,eyebrow:"Unified player profile",title:player.username||name,subtitle:`${profilePayload?.meta?.source||"database"} · updated ${profilePayload?.meta?.last_database_update?formatRelative(profilePayload.meta.last_database_update):"from stored records"}`,html});
      const body=byId("insightsDetailBody"),profileRoot=body?.querySelector(`[data-profile-root="${profileToken}"]`),achievementByKey=new Map(achievements.map(item=>[String(item.key||""),item]));window.P2K_ACHIEVEMENT_DETAIL_CACHE=achievementByKey;profileRoot?.querySelectorAll("[data-achievement-key]").forEach(button=>button.addEventListener("click",()=>openAchievementDetail(achievementByKey.get(String(button.dataset.achievementKey||"")),true)));profileRoot?.querySelector("[data-profile-achievements]")?.addEventListener("click",()=>openAchievementCatalog(player.username||name));
      if(progression.length){renderNativeBarLine("playerTeamPointsProgression",progression,{xKey:"month",barKey:"points",lineKey:"cumulative_points",barLabel:"Monthly Team Points",lineLabel:"Cumulative Team Points"});profileRoot?.querySelector("[data-profile-progression-reset]")?.addEventListener("click",()=>profileRoot?.querySelector("#playerTeamPointsProgression")?._p2kResetZoom?.())}
      {const intelURL=new URL("server/team-points/public/member-intelligence.php",window.location.href);intelURL.searchParams.set("username",player.username||name);loadPublicCachedJSON(intelURL.href,{ttl:120000,credentials:"same-origin"}).then(ip=>{const host=profileRoot?.querySelector("[data-profile-challenges]"),m=ip?.player?.member;if(!host)return;const challenges=challengeProgress(m);const rows=challenges.length?`<div class="p2k-profile-challenges">${challenges.map(c=>{const criterion=String(c.criteria||c.description||"Achievement criterion unavailable.");return `<button type="button" class="p2k-challenge-row" data-challenge-achievement="${escapeHTML(c.key||'')}" title="${escapeHTML(criterion)}" aria-label="${escapeHTML(`${c.label||"Achievement"} — ${criterion}`)}"><span>${escapeHTML(c.label)}</span><progress max="100" value="${Number(c.progress_percent||0)}" aria-label="${escapeHTML(`${c.label||"Achievement"} progress`)}"></progress></button>`}).join("")}</div>`:`<p class="p2k-table-status">No supported achievement challenge is currently in progress.</p>`;host.innerHTML=`<h3>Achievement challenges</h3>${rows}`;host.querySelectorAll('[data-challenge-achievement]').forEach(button=>button.addEventListener('click',()=>openAchievementCatalog(player.username||name,String(button.dataset.challengeAchievement||''))));}).catch(()=>{const host=profileRoot?.querySelector("[data-profile-challenges]");if(host)host.innerHTML='<h3>Achievement challenges</h3><p class="p2k-table-status">Challenges temporarily unavailable.</p>'})}
      loadPublicCachedJSON(`server/tournaments/public/browse.php?view=player&username=${encodeURIComponent(player.username||name)}`,{ttl:300000,credentials:"same-origin"}).then(result=>{if(!profileRoot)return;const m=result?.player||{},events=Array.isArray(m.tournaments)?m.tournaments:[],total=Number(m.gold||0)+Number(m.silver||0)+Number(m.bronze||0),metric=profileRoot.querySelector("[data-tournament-medals]"),list=profileRoot.querySelector("[data-tournament-history]");if(metric)metric.innerHTML=`<span>Tournament medals</span><strong>${number(total)}</strong><small>${number(m.gold||0)} gold · ${number(m.silver||0)} silver · ${number(m.bronze||0)} bronze</small>`;if(list)list.innerHTML=events.length?events.slice(0,12).map(event=>`<a class="p2k-profile-list-row" href="${escapeHTML(event.webUrl||"#")}" ${event.webUrl?'target="_blank" rel="noopener noreferrer"':""}><span><strong>${escapeHTML(event.name||"Tournament")}</strong><small>${escapeHTML(event.date?formatDateOnly(event.date):event.period||"Date unavailable")}</small></span><b class="is-${escapeHTML(event.medal)}">${event.medal==='gold'?'🥇':event.medal==='silver'?'🥈':'🥉'} ${escapeHTML(event.medal)}</b></a>`).join(""):'<p class="p2k-table-status">No tournament medal is recorded.</p>'}).catch(()=>{const list=profileRoot?.querySelector("[data-tournament-history]"),metric=profileRoot?.querySelector("[data-tournament-medals]");if(list)list.innerHTML='<p class="p2k-table-status is-error">Tournament history is temporarily unavailable.</p>';if(metric)metric.innerHTML='<span>Tournament medals</span><strong>—</strong><small>History unavailable</small>'});
      if(window.P2K_API_CLIENT?.json){const chessProfileURL=`https://api.chess.com/pub/player/${encodeURIComponent(String(player.username||name).toLowerCase())}`;window.P2K_API_CLIENT.json(chessProfileURL,{attempts:2,cacheMode:"network-only",priority:-20}).then(fresh=>{const freshAvatar=String(fresh?.avatar||""),avatarHost=profileRoot?.querySelector(".p2k-profile-avatar");if(avatarHost&&freshAvatar)avatarHost.innerHTML=`<img src="${escapeHTML(freshAvatar)}" alt="${escapeHTML(fresh?.username||player.username||name)} avatar">`;const identity=profileRoot?.querySelector(".p2k-profile-identity > div:last-child"),freshURL=String(fresh?.url||"");if(identity&&freshURL&&!identity.querySelector("a"))identity.insertAdjacentHTML("beforeend",`<a href="${escapeHTML(freshURL)}" target="_blank" rel="noopener noreferrer">Open Chess.com profile</a>`)}).catch(()=>{})}
    }catch(error){showInsightsModal({replace:true,eyebrow:"Player profile",title:name,subtitle:"Unable to load",html:`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p>`})}
  }
  function membersTableColumns() { return []; }
  function loadFeatureScriptWithRetry(src, readiness, label) {
    const attempt = retry => new Promise((resolve, reject) => {
      if (readiness()) return resolve();
      const script = document.createElement("script");
      const url = new URL(src, window.location.href);
      if (retry) url.searchParams.set("hotfixRetry", String(Date.now()));
      script.src = url.href;
      script.defer = true;
      let settled = false;
      const finish = error => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        if (error) reject(error); else resolve();
      };
      const timer = window.setTimeout(() => finish(new Error(`${label} timed out while loading.`)), 8000);
      script.onload = () => readiness() ? finish() : finish(new Error(`${label} loaded without initializing.`));
      script.onerror = () => finish(new Error(`Unable to load ${label}.`));
      document.head.appendChild(script);
    });
    return attempt(false).catch(first => attempt(true).catch(second => {
      console.warn(`${label} failed after hotfix retry.`, first, second);
      throw second;
    }));
  }
  let dashboardInsightsModulePromise = null;
  function dashboardInsightsContext() {
    return { state, byId, number, escapeHTML, cellWithSub, insightAction, openUnifiedPlayerProfile, formatDateOnly, formatRelative, setText, loadJSON, profileMetric, showInsightsModal, matchRulesLabel, matchTimeControlLabel, statusChip, writeNavigationState };
  }
  function ensureDashboardInsightsModule() {
    if (window.P2K_DASHBOARD_INSIGHTS) return Promise.resolve(window.P2K_DASHBOARD_INSIGHTS);
    if (dashboardInsightsModulePromise) return dashboardInsightsModulePromise;
    dashboardInsightsModulePromise = loadFeatureScriptWithRetry(
      "assets/js/pages/dashboard-insights.js?v=2.10.6.24",
      () => typeof window.P2K_CREATE_DASHBOARD_INSIGHTS === "function",
      "Dashboard Insights module"
    ).then(() => {
      const factory = window.P2K_CREATE_DASHBOARD_INSIGHTS;
      window.P2K_DASHBOARD_INSIGHTS = factory(dashboardInsightsContext());
      return window.P2K_DASHBOARD_INSIGHTS;
    }).catch(error => { dashboardInsightsModulePromise = null; throw error; });
    return dashboardInsightsModulePromise;
  }
  async function loadMemberInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadMemberInsights(options); }
  async function loadMatchInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadMatchInsights(options); }
  async function loadOpponentInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadOpponentInsights(options); }
  async function loadArenaInsights(options = {}) { return (await ensureDashboardInsightsModule()).loadArenaInsights(options); }
  async function openMatchDetail(matchId, options = {}) { return (await ensureDashboardInsightsModule()).openMatchDetail(matchId, options); }
  function renderNativeBarLine(...args) { ensureDashboardInsightsModule().then(api => api.renderNativeBarLine(...args)).catch(error => console.warn(error)); }

return Object.freeze({ insightAction, showInsightsModal, closeInsightsModal, normalizeTournamentName, tournamentAchievements, profileMetric, tournamentAchievementKeys, achievementCard, achievementEarnedDetail, achievementWebURL, openAchievementDetail, openAchievementNav, achievementDisplayBar, achievementCategoryLabel, achievementFamilyLabel, openAchievementCatalog, openUnifiedPlayerProfile, membersTableColumns, loadFeatureScriptWithRetry, dashboardInsightsContext, ensureDashboardInsightsModule, loadMemberInsights, loadMatchInsights, loadOpponentInsights, loadArenaInsights, openMatchDetail, renderNativeBarLine, getAchievementNavigation: () => achievementDetailNav });
}});
})();
