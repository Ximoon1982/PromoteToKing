/* Existing authenticated personal dashboard and activity behavior. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.personalHome = Object.freeze({create(context) {
const { state, byId, setText, number, escapeHTML, viewed, adminEntryUsername, showPublicPage, selectHallSubtab, openAchievementCatalog, loadPublicCachedJSON, writeNavigationState, verifyAdmin, loadRecommendations, renderLiveRanksNative, ranks, unrankedRank, rankThumbnailAsset, originalRankAsset } = context;
  function currentSession() { return window.P2K_AUTH?.getSession?.() || null; }
  function memberRecord(payload, username) {
    const key = adminEntryUsername(username);
    if (!key || !payload || typeof payload !== "object") return null;
    for (const bucket of ["all_time", "monthly", "weekly"]) {
      for (const member of Array.isArray(payload[bucket]) ? payload[bucket] : []) {
        if (adminEntryUsername(member?.username || member?.name || member) === key) return member;
      }
    }
    return null;
  }
  function renderPersonalJoinDate() {
    const host = byId("personalJoinDate");
    if (!host) return;
    const joined = state.playerPoints?.joined_at || state.playerPoints?.first_seen_at || null;
    if (joined) {
      const date = new Date(String(joined).replace(" ", "T") + (String(joined).includes("Z") ? "" : "Z"));
      host.textContent = Number.isNaN(date.getTime()) ? "Join date unavailable" : `Member since ${date.toLocaleDateString("en-GB",{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})}`;
      return;
    }
    const record = memberRecord(state.membersPayload, viewed());
    const epoch = Number(record?.joined || record?.joined_at || 0);
    host.textContent = epoch > 0 ? `Member since ${new Date(epoch * 1000).toLocaleDateString("en-GB",{timeZone:"UTC",year:"numeric",month:"short",day:"numeric"})}` : "Join date unavailable";
  }
  function rankForPoints(points) {
    const numeric = Math.max(0, Number(points) || 0);
    return ranks.reduce((selected, rank) => numeric >= rank.minimum ? rank : selected, null) || unrankedRank;
  }
  const nullableMetric = value => value === null || value === undefined || value === "" ? "—" : number(value);
  const challengeProgress=m=>Array.isArray(m?.challenges)&&m.challenges.length?m.challenges:Array.isArray(m?.achievement_progress)?m.achievement_progress:[];
  function setPersonalRankImage(source, fallback, alt, title) {
    const image = byId("personalRankImage");
    if (!image) return;
    image.onerror = () => {
      image.onerror = null;
      image.src = fallback || "assets/images/p2k-logo.jpg";
    };
    image.src = source || fallback || "assets/images/p2k-logo.jpg";
    image.alt = alt;
    image.title = title;
  }
  const bindHome=(h,n)=>{h.hidden=false;h.querySelector("[data-home-achievements-view]")?.addEventListener("click",()=>{showPublicPage("hall",{hallSubtab:"achievements"});selectHallSubtab("achievements")});h.querySelectorAll("[data-home-challenge]").forEach(b=>b.addEventListener("click",()=>openAchievementCatalog(n,String(b.dataset.homeChallenge||""))))};
  let personalizedHomeRequest="", personalizedHomePromise=null;
  async function loadPersonalizedHome(username) {
    const name=String(username||"").trim(),host=byId("personalizedHome");if(!name||!host)return;if(personalizedHomeRequest===name&&personalizedHomePromise)return personalizedHomePromise;if(personalizedHomeRequest===name&&!host.hidden)return;personalizedHomeRequest=name;
    const request=(async()=>{try{const url=new URL("server/team-points/public/member-intelligence.php",window.location.href);url.searchParams.set("username",name);const payload=await loadPublicCachedJSON(url.href,{ttl:120000,credentials:"same-origin"}),member=payload?.player?.member;if(!member||payload?.player?.found===false){host.hidden=true;return;}const challenges=challengeProgress(member);host.innerHTML=`<div class="dashboard-achievement-challenges"><div class="dashboard-achievement-heading"><strong>Achievements</strong><button type="button" data-home-achievements-view>View →</button></div>${challenges.length?challenges.slice(0,3).map(c=>{const criterion=String(c.criteria||c.description||"Achievement criterion unavailable.");return `<button type="button" class="p2k-challenge-row" data-home-challenge="${escapeHTML(c.key||'')}" title="${escapeHTML(criterion)}" aria-label="${escapeHTML(`${c.label||"Achievement"} — ${criterion}`)}"><span>${escapeHTML(c.label)}</span><progress max="100" value="${Number(c.progress_percent||0)}" aria-label="${escapeHTML(`${c.label||"Achievement"} progress`)}"></progress></button>`}).join(""):`<p class="p2k-table-status">No supported achievement challenge is currently in progress.</p>`}</div>`;bindHome(host,name);}catch(_){host.innerHTML=`<div class="dashboard-achievement-challenges"><div class="dashboard-achievement-heading"><strong>Achievements</strong><button type="button" data-home-achievements-view>View →</button></div><p class="p2k-table-status">Challenges temporarily unavailable.</p></div>`;bindHome(host,name);}})();personalizedHomePromise=request;try{return await request;}finally{if(personalizedHomePromise===request)personalizedHomePromise=null;}
  }
  function renderDailyPersonalCard(payload = state.playerPoints || {}) {
    const points = Number(payload?.points || 0);
    const localRank = rankForPoints(points);
    const rank = payload?.rank && typeof payload.rank === "object" ? { ...localRank, ...payload.rank } : localRank;
    setText("personalPointsLabel", "Team Points");
    const info = byId("personalPointsInfo");
    if (info) info.dataset.p2kInfoMessage = "Team Points are earned from finished team games: one point for a win and half a point for a draw.";
    setText("personalTeamPoints", number(points));
    setText("personalStat1Label", "Wins");
    setText("personalStat2Label", "Draws");
    setText("personalStat3Label", "Losses");
    setText("personalWins", number(payload?.wins || 0));
    setText("personalDraws", number(payload?.draws || 0));
    setText("personalLosses", number(payload?.losses || 0));
    const teamPosition = Number(payload?.team_position);
    const positionLabel = Number.isFinite(teamPosition) && teamPosition > 0 ? `Team #${number(teamPosition)}` : "Team position unavailable";
    setText("personalRankName", `${rank.name} · ${positionLabel} · ${number(payload?.matches || 0)} matches`);
    const source = rankThumbnailAsset(rank.image, 320);
    setPersonalRankImage(source, originalRankAsset(rank.image), `${rank.name} team rank`, `${rank.name} — ${number(points)} Team Points · ${positionLabel}`);
    const hallButton = byId("openHallOfFame");
    if (hallButton) hallButton.textContent = "View Daily Ranks →";
    byId("personalDailyActivity").hidden = false;
    byId("personalLiveActivity").hidden = true;
    if (byId("explorePlayerGames")) byId("explorePlayerGames").hidden = false;
    setText("hallPersonalPosition", Number.isFinite(teamPosition) && teamPosition > 0 ? `#${number(teamPosition)} · ${rank.name}` : "—");
    if(viewed())void loadPersonalizedHome(viewed());
  }
  function renderLivePersonalCard(payload = state.playerLive || {}) {
    const available = payload?.available === true;
    const points = Number(payload?.points || 0);
    const rank = payload?.rank && typeof payload.rank === "object" ? payload.rank : null;
    setText("personalPointsLabel", "Live points");
    const info = byId("personalPointsInfo");
    if (info) info.dataset.p2kInfoMessage = "Live points are arena scores aggregated from Multi-Club Arenas (MCAs).";
    setText("personalTeamPoints", number(points));
    setText("personalStat1Label", "Best rank");
    setText("personalStat2Label", "Top 3");
    setText("personalStat3Label", "Top 10");
    setText("personalWins", nullableMetric(payload?.best_rank));
    setText("personalDraws", number(payload?.top3_count || 0));
    setText("personalLosses", number(payload?.top10_count || 0));
    const teamPosition = Number(payload?.team_position);
    const positionLabel = Number.isFinite(teamPosition) && teamPosition > 0 ? `Team #${number(teamPosition)}` : "Team position unavailable";
    const rankName = rank?.name || payload?.rank_name || "Unranked";
    setText("personalRankName", available ? `${rankName} · ${positionLabel} · ${number(payload?.arenas || 0)} arenas` : "No Live arena record in the current MCA data");
    if (rank?.icon) {
      const icon = String(rank.icon);
      const source = `assets/images/ranks/thumbs/320/${icon.replace(/\.png$/i, ".webp")}`;
      setPersonalRankImage(source, `assets/images/ranks/${icon}`, `${rankName} Live rank`, `${rankName} — ${number(points)} Live points · ${positionLabel}`);
    } else {
      setPersonalRankImage("assets/images/p2k-logo.jpg", "assets/images/p2k-logo.jpg", "No Live rank", "No Live arena rank is available yet");
    }
    setText("personalLiveBestScore", nullableMetric(payload?.best_score));
    setText("personalLiveTotalWins", nullableMetric(payload?.wins));
    setText("personalLiveMaxWins", nullableMetric(payload?.max_wins_single_arena));
    setText("personalLiveBestStreak", nullableMetric(payload?.best_streak));
    const hallButton = byId("openHallOfFame");
    if (hallButton) hallButton.textContent = "View Live Ranks →";
    byId("personalDailyActivity").hidden = true;
    byId("personalLiveActivity").hidden = false;
    if (byId("explorePlayerGames")) byId("explorePlayerGames").hidden = true;
    if(viewed())void loadPersonalizedHome(viewed());
  }
  function shortArenaName(value) {
    return String(value || "").replace(/\.csv$/i, "").replaceAll("-", " ").trim() || "Arena not available";
  }
  function renderLiveTeamData(payload = state.liveTeamData || {}) {
    setText("teamLiveArenas", number(payload?.arenas || 0));
    setText("teamLiveCurrentPlayers", number(payload?.current_players || 0));
    setText("teamLiveMostParticipants", number(payload?.most_participants || 0));
    setText("teamLiveMostParticipantsArena", shortArenaName(payload?.most_participants_arena));
    setText("teamLiveMostPoints", nullableMetric(payload?.most_points));
    setText("teamLiveMostPointsArena", shortArenaName(payload?.most_points_arena));
    setText("teamLiveFirstPlaces", number(payload?.first_places || 0));
    setText("teamLiveSecondPlaces", number(payload?.second_places || 0));
    setText("teamLiveThirdPlaces", number(payload?.third_places || 0));
    setText("teamLiveAggregatePoints", nullableMetric(payload?.aggregate_points));
  }
  function renderTeamMode() {
    const live = state.personalMode === "live";
    const daily = byId("teamDailyContent");
    const livePanel = byId("teamLiveContent");
    if (daily) daily.hidden = live;
    if (livePanel) livePanel.hidden = !live;
    setText("teamStatsTitle", live ? "Promote to King · Live arenas" : "Promote to King");
    if (live) renderLiveTeamData();
  }
  function renderPersonalCard() {
    document.querySelectorAll("[data-player-mode]").forEach(button => {
      const active = button.dataset.playerMode === state.personalMode;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
    if (state.personalMode === "live") renderLivePersonalCard();
    else renderDailyPersonalCard();
  }
  function selectPersonalMode(mode, { updateHistory = true } = {}) {
    state.personalMode = mode === "live" ? "live" : "daily";
    renderPersonalCard();
    renderTeamMode();
    if (updateHistory) writeNavigationState({ replace: true });
  }
  function renderPlayerPoints(payload) {
    const points = Number(payload?.points || 0);
    const localRank = rankForPoints(points);
    const rank = payload?.rank && typeof payload.rank === "object" ? { ...localRank, ...payload.rank } : localRank;
    state.playerPoints = { ...payload, points, rank };
    if (state.personalMode === "daily") renderPersonalCard();
  }
  function renderLivePlayer(payload) {
    state.playerLive = payload && typeof payload === "object" ? payload : { available: false, points: 0 };
    if (state.personalMode === "live") renderPersonalCard();
  }
  function playerMatchKey(entry) {
    const direct = String(entry?.["@id"] || entry?.url || entry?.match || "").trim();
    if (direct) return direct.toLowerCase();
    const board = String(entry?.board || "").trim();
    return board ? board.replace(/\/\d+\/?$/, "").toLowerCase() : "";
  }
  function uniquePlayerMatches(entries) {
    const seen = new Set();
    return (Array.isArray(entries) ? entries : []).filter(entry => {
      if (!entry || typeof entry !== "object") return false;
      const club = String(entry.club || "").replace(/\/$/, "").toLowerCase();
      if (club !== clubProfileAPI.toLowerCase()) return false;
      const key = playerMatchKey(entry);
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }
  function renderPlayerActivity(matchesPayload, pointsPayload = state.playerPoints || {}) {
    const lists = {
      registered: uniquePlayerMatches(matchesPayload?.registered || matchesPayload?.registration),
      ongoing: uniquePlayerMatches(matchesPayload?.in_progress || matchesPayload?.ongoing),
      finished: uniquePlayerMatches(matchesPayload?.finished)
    };
    const currentGames = lists.ongoing.length * 2;
    const playedGames = Number(pointsPayload?.games || 0);
    const playedMatches = Number(pointsPayload?.matches || 0);
    state.playerActivity = { lists, currentGames, playedGames, playedMatches };
    setText("personalCurrentGames", `${number(currentGames)} games`);
    setText("personalCurrentMatches", `in ${number(lists.ongoing.length)} matches`);
    setText("personalRegisteredMatches", `${number(lists.registered.length)} matches`);
    setText("personalPlayedGames", `${number(playedGames)} games`);
    setText("personalPlayedMatches", `in ${number(playedMatches)} matches`);
  }
  function playerMatchResult(entry) {
    const results = entry?.results && typeof entry.results === "object" ? entry.results : {};
    const white = String(results.played_as_white || "").trim();
    const black = String(results.played_as_black || "").trim();
    return [white && `White: ${white}`, black && `Black: ${black}`].filter(Boolean).join(" · ") || "Two team games";
  }
  function playerMatchTitle(entry) {
    return String(entry?.name || entry?.title || "Team match");
  }
  function renderPlayerGamesSection(host, titleText, entries, emptyText) {
    const section = document.createElement("section");
    section.className = "dashboard-player-games-section";
    const heading = document.createElement("h3");
    heading.textContent = `${titleText} (${number(entries.length)})`;
    section.appendChild(heading);
    if (!entries.length) {
      const empty = document.createElement("p");
      empty.className = "dashboard-player-games-empty";
      empty.textContent = emptyText;
      section.appendChild(empty);
    } else {
      entries.forEach(entry => {
        const row = document.createElement("article");
        row.className = "dashboard-player-game-row";
        const copy = document.createElement("div");
        const title = document.createElement("strong");
        title.textContent = playerMatchTitle(entry);
        const meta = document.createElement("small");
        meta.textContent = `${formatDateOnly(entry?.start_time || entry?.end_time)} · ${playerMatchResult(entry)}`;
        copy.append(title, meta);
        const url = matchURL(entry);
        if (url) {
          const link = document.createElement("a");
          link.className = "dashboard-button dashboard-button-muted";
          link.href = url;
          link.target = "_blank";
          link.rel = "noopener noreferrer";
          link.textContent = "Open";
          row.append(copy, link);
        } else row.append(copy);
        section.appendChild(row);
      });
    }
    host.appendChild(section);
  }
  function openPlayerGames() {
    const activity = state.playerActivity || { lists: { registered: [], ongoing: [], finished: [] }, currentGames: 0, playedGames: 0, playedMatches: 0 };
    const username = viewed() || "Player";
    setText("playerGamesTitle", `${username}'s team games`);
    setText("playerGamesSummary", `${number(activity.currentGames)} current games in ${number(activity.lists.ongoing.length)} matches · registered to ${number(activity.lists.registered.length)} matches · ${number(activity.playedGames)} games played in ${number(activity.playedMatches)} matches.`);
    const host = byId("playerGamesList");
    host.replaceChildren();
    renderPlayerGamesSection(host, "Registered", activity.lists.registered, "No current team-match registrations were found.");
    renderPlayerGamesSection(host, "Ongoing", activity.lists.ongoing, "No team matches are currently in progress.");
    renderPlayerGamesSection(host, "Recent finished", activity.lists.finished, "No recent finished team matches were returned by Chess.com.");
    byId("playerGamesModal").hidden = false;
    document.body.classList.add("dashboard-modal-open");
  }
  function closePlayerGames() {
    byId("playerGamesModal").hidden = true;
    document.body.classList.remove("dashboard-modal-open");
  }
  async function applySession(session) {
    state.session = session || null;
    const loggedIn = Boolean(state.session?.username);
    if (state.liveRanksPayload) renderLiveRanksNative(state.liveRanksPayload);
    if (loggedIn) renderPersonalCard();
    byId("personalGuest").hidden = loggedIn;
    byId("personalMember").hidden = !loggedIn;
    byId("personalModeToggle").hidden = !loggedIn;
    setText("personalStatsTitle", loggedIn ? viewed() : "Member");
    setText("personalStatusBadge", loggedIn ? "Loading" : (window.P2K_AUTH?.enabled ? "Login required" : "OAuth disabled"));
    renderPersonalJoinDate();
    if (!loggedIn) {
      setText("personalGuestMessage", "Use the login button in the header to load authenticated player information.");
    } else {
      loadPersonalData(viewed());
    }
    verifyAdmin(window.P2K_AUTH?.getDisplaySession?.() || state.session).catch(error => console.warn("Unable to verify administrator state.", error));
    if (loggedIn) (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(() => (window.P2K_PROGRESSIVE?.lowPriority || ((run)=>Promise.resolve().then(run)))(() => loadRecommendations()));
    else loadRecommendations();
  }
  async function loadPersonalData(username) {
    const normalized = String(username || "").trim().toLowerCase();
    if (!normalized) return;
    const snapshotKey = `dashboard-player-${normalized}`;
    const cached = window.P2K_PROGRESSIVE?.snapshotGet?.(snapshotKey, 6 * 3600000);
    if (cached?.payload?.points) {
      renderPlayerPoints(cached.payload.points);
      renderPersonalCard();
      setText("personalStatusBadge", "Cached · refreshing");
    }
    let pointsPayload = {};
    try {
      const pointsResult = await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("player", { username: normalized }) || Promise.reject(new Error("Team Points client unavailable")));
      if (adminEntryUsername(viewed()) !== normalized) return;
      pointsPayload = pointsResult?.player || {};
      renderPlayerPoints(pointsPayload);
      renderPersonalCard();
      setText("personalStatusBadge", "Database linked");
      window.P2K_PROGRESSIVE?.snapshotSet?.(snapshotKey, { points: pointsPayload });
    } catch (error) {
      console.warn("Unable to load personal Team Points data.", error);
      if (!cached?.payload?.points) state.playerPoints = { available: false, points: 0, rank: unrankedRank };
      renderPersonalCard();
    }
    const secondWave = async () => {
      const low = window.P2K_PROGRESSIVE?.lowPriority || ((run) => Promise.resolve().then(run));
      const greenLists = pointsPayload?.data_source === "green_native_core" && pointsPayload?.match_lists && typeof pointsPayload.match_lists === "object" ? pointsPayload.match_lists : null;
      const [matchesResult, liveResult] = await Promise.allSettled([
        greenLists ? Promise.resolve(greenLists) : low(() => loadJSON(`https://api.chess.com/pub/player/${encodeURIComponent(normalized)}/matches`)),
        low(() => loadJSON(`server/team-points/public/public.php?action=live-player&username=${encodeURIComponent(normalized)}&_fresh=${Date.now()}`, { credentials: "same-origin", cache: "no-store" }))
      ]);
      if (adminEntryUsername(viewed()) !== normalized) return;
      if (liveResult.status === "fulfilled") renderLivePlayer(liveResult.value?.player || {});
      else renderLivePlayer({ available: false, points: 0 });
      if (matchesResult.status === "fulfilled") renderPlayerActivity(matchesResult.value, pointsPayload);
      else renderPlayerActivity({}, pointsPayload);
      renderPersonalCard();
    };
    (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(secondWave);
  }

return Object.freeze({ currentSession, memberRecord, renderPersonalJoinDate, rankForPoints, setPersonalRankImage, loadPersonalizedHome, renderDailyPersonalCard, renderLivePersonalCard, shortArenaName, renderLiveTeamData, renderTeamMode, renderPersonalCard, selectPersonalMode, renderPlayerPoints, renderLivePlayer, playerMatchKey, uniquePlayerMatches, renderPlayerActivity, playerMatchResult, playerMatchTitle, renderPlayerGamesSection, openPlayerGames, closePlayerGames, applySession, loadPersonalData });
}});
})();
