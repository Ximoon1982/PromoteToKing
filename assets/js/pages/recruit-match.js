/* Match Recruitment Assistant page logic — stored-rating recruitment model v2. */
(async () => {
  "use strict";
  if (window.P2K_ADMIN_ACCESS_READY && !(await window.P2K_ADMIN_ACCESS_READY)) return;

  const REQUEST_ATTEMPTS = window.P2K_SITE_CONFIG?.api?.defaultAttempts || 3;
  const SIGNIFICANT_WIN_PROBABILITY = 0.65;
  const MEMBER_GROUPS = ["weekly", "monthly", "all_time"];

  const root = document.getElementById("p2kUpcomingAnalyzer");
  const matchForm = document.getElementById("p2kMatchForm");
  const matchInput = document.getElementById("p2kMatchReference");
  const loadButton = document.getElementById("p2kLoadButton");
  const settingsPanel = document.getElementById("p2kSettingsPanel");
  const recruitmentForm = document.getElementById("p2kRecruitmentForm");
  const teamSelector = document.getElementById("p2kTeamSelector");
  const teamChoices = document.getElementById("p2kTeamChoices");
  const scanButton = document.getElementById("p2kScanButton");
  const statusBox = document.getElementById("p2kStatus");
  const statusText = document.getElementById("p2kStatusText");
  const progressTrack = document.getElementById("p2kProgressTrack");
  const progressBar = document.getElementById("p2kProgressBar");
  const processingActions = document.getElementById("p2kProcessingActions");
  const cancelButton = document.getElementById("p2kCancelButton");
  const resultsBox = document.getElementById("p2kResults");
  const selectedTeamLogo = document.getElementById("p2kSelectedTeamLogo");

  if (!root || !matchForm || !recruitmentForm) return;

  const state = {
    rawMatch: null,
    matchId: null,
    selectedTeamKey: null,
    preferredTeamReference: "",
    lockTeamSelection: false,
    embedded: false,
    autorun: false,
    activeController: null,
    clubProfiles: new Map(),
    logoRequestToken: 0
  };

  class CancellationError extends Error {
    constructor(message = "The operation was cancelled.") { super(message); this.name = "CancellationError"; }
  }

  function escapeHTML(value) {
    return String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }
  function setBusy(isBusy) {
    loadButton.disabled = isBusy; scanButton.disabled = isBusy;
    processingActions.style.display = isBusy ? "flex" : "none";
  }
  function setStatus(message, type = "working", progress = null) {
    statusBox.style.display = "block";
    statusBox.classList.toggle("p2k-error", type === "error");
    statusBox.classList.toggle("p2k-success", type === "success");
    statusText.textContent = message;
    if (progress === null) { progressTrack.style.display = "none"; progressBar.style.width = "0%"; }
    else { progressTrack.style.display = "block"; progressBar.style.width = `${Math.max(0, Math.min(100, progress))}%`; }
  }
  function parseBoolean(value, fallback = false) {
    if (value === null || value === undefined || value === "") return fallback;
    return ["1", "true", "yes", "on", "modal", "internal", "embedded"].includes(String(value).trim().toLowerCase());
  }
  function parseMatchReference(input) {
    const value = String(input || "").trim();
    if (!value) throw new Error("Enter a match number or URL.");
    if (/^\d+$/.test(value)) return value;
    let decoded = value; try { decoded = decodeURIComponent(value); } catch (_) {}
    try {
      const url = new URL(decoded, window.location.href);
      const nested = url.searchParams.get("match") || url.searchParams.get("id");
      if (nested) return parseMatchReference(nested);
      const numbers = url.pathname.match(/\d+/g); if (numbers?.length) return numbers[numbers.length - 1];
    } catch (_) {}
    const numbers = decoded.match(/\d+/g); if (numbers?.length) return numbers[numbers.length - 1];
    throw new Error("No numeric Chess.com match ID was found in that value.");
  }
  function parseClubReference(input) {
    let value = String(input || "").trim(); if (!value) return "";
    try { value = decodeURIComponent(value); } catch (_) {}
    try {
      const url = new URL(value, window.location.href), parts = url.pathname.split("/").filter(Boolean);
      const clubIndex = parts.findIndex(part => part.toLowerCase() === "club");
      if (clubIndex >= 0 && parts[clubIndex + 1]) return parts[clubIndex + 1].toLowerCase();
      const pubIndex = parts.findIndex((part, i) => part.toLowerCase() === "pub" && parts[i + 1]?.toLowerCase() === "club");
      if (pubIndex >= 0 && parts[pubIndex + 2]) return parts[pubIndex + 2].toLowerCase();
    } catch (_) {}
    return value.replace(/^@+/, "").replace(/^\/+|\/+$/g, "").split(/[?#]/, 1)[0].toLowerCase();
  }
  function slugify(value) {
    return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  }
  function teamEntries(match = state.rawMatch) { return Object.entries(match?.teams || {}).filter(([, team]) => team && typeof team === "object"); }
  function teamName(team, fallback = "Team") { return String(team?.name || team?.club?.name || fallback || "Team"); }
  function teamClubSlug(team) { return parseClubReference(team?.["@id"]) || parseClubReference(team?.url) || slugify(teamName(team)); }
  function teamKeyForReference(match, reference) {
    const raw = String(reference || "").trim(); if (!raw) return null; const entries = teamEntries(match);
    const exact = entries.find(([key]) => key.toLowerCase() === raw.toLowerCase()); if (exact) return exact[0];
    const requestedSlug = parseClubReference(raw), requestedName = slugify(raw);
    return entries.find(([, team]) => teamClubSlug(team) === requestedSlug || slugify(teamName(team)) === requestedSlug || slugify(teamName(team)) === requestedName)?.[0] || null;
  }
  function selectedTeamEntry() { return teamEntries().find(([key]) => key === state.selectedTeamKey) || null; }
  function opponentTeamEntry() { return teamEntries().find(([key]) => key !== state.selectedTeamKey) || null; }
  function normalizeRules(value) { return String(value || "chess").toLowerCase().replace(/[\s_-]+/g, "").includes("960") ? "chess960" : "chess"; }
  function rulesLabel(rules) { return rules === "chess960" ? "Chess960 Daily" : "Classical Daily"; }
  function numericSetting(value, fallback) { const n = Number(value); return value !== "" && value != null && Number.isFinite(n) ? n : fallback; }
  function matchSettings() {
    const s = state.rawMatch?.settings || {};
    return {
      rules: normalizeRules(s.rules),
      minimumRating: numericSetting(s.min_rating, 0),
      maximumRating: numericSetting(s.max_rating, Number.POSITIVE_INFINITY),
      minimumRequiredGames: Math.max(0, Math.floor(numericSetting(s.min_required_games, 0))),
      minimumPlayers: Math.max(0, Math.floor(numericSetting(s.min_team_players, 0))),
      maximumPlayers: (() => { const n = numericSetting(s.max_team_players, Number.POSITIVE_INFINITY); return Number.isFinite(n) && n > 0 ? Math.floor(n) : Number.POSITIVE_INFINITY; })()
    };
  }
  function formatRatingRange(settings) {
    const min = settings.minimumRating, max = settings.maximumRating;
    if (min <= 0 && !Number.isFinite(max)) return "Open"; if (min <= 0) return `up to ${Math.floor(max)}`;
    if (!Number.isFinite(max)) return `${Math.floor(min)} and above`; return `${Math.floor(min)}–${Math.floor(max)}`;
  }
  function playerCount(team) { return Array.isArray(team?.players) ? team.players.length : 0; }
  function registeredUsernameSet() {
    return new Set(teamEntries().flatMap(([, team]) => Array.isArray(team?.players) ? team.players : []).map(p => String(p?.username || "").trim().toLowerCase()).filter(Boolean));
  }
  function ratedRegisteredPlayers(team, settings) {
    return (Array.isArray(team?.players) ? team.players : []).map(player => ({ username: String(player?.username || "").trim(), rating: Number(player?.rating) }))
      .filter(p => p.username && Number.isFinite(p.rating) && p.rating >= settings.minimumRating && p.rating <= settings.maximumRating)
      .sort((a, b) => b.rating - a.rating || a.username.localeCompare(b.username));
  }
  function flattenClubMembers(data) {
    const byUser = new Map();
    MEMBER_GROUPS.forEach(group => (Array.isArray(data?.[group]) ? data[group] : []).forEach(member => {
      const username = String(member?.username || "").trim(), key = username.toLowerCase();
      if (key && !byUser.has(key)) byUser.set(key, { username, normalizedUsername: key });
    }));
    return [...byUser.values()];
  }
  async function loadJSON(url, signal = null, options = {}) {
    if (signal?.aborted) throw new CancellationError();
    if (!window.P2K_API_CLIENT) throw new Error("P2K_API_CLIENT is not loaded.");
    try { return await window.P2K_API_CLIENT.json(url, { signal, attempts: REQUEST_ATTEMPTS, cacheMode: options.networkOnly ? "network-only" : "default" }); }
    catch (error) { if (signal?.aborted || error?.category === "cancelled") throw new CancellationError(); throw error; }
  }
  async function loadSameOriginJSON(url, signal) {
    const response = await fetch(url, { signal, credentials: "same-origin", cache: "no-store" });
    const data = await response.json();
    if (!response.ok || data?.ok === false) throw new Error(data?.error?.message || `HTTP ${response.status}`);
    return data;
  }

  function eloExpectedScore(r1, r2) { return 1 / (1 + Math.pow(10, (r2 - r1) / 400)); }
  function matchOutcomeProbabilities(expectedScores) {
    let distribution = [1];
    expectedScores.forEach(e => { for (let trial = 0; trial < 4; trial += 1) { const next = new Array(distribution.length + 1).fill(0); distribution.forEach((p, u) => { next[u] += p * (1 - e); next[u + 1] += p * e; }); distribution = next; } });
    const threshold = expectedScores.length * 2; let win = 0, draw = 0, loss = 0;
    distribution.forEach((p, u) => { if (u > threshold) win += p; else if (u < threshold) loss += p; else draw += p; });
    return { p2kWin: win, draw, opponentWin: loss };
  }
  function compareLineups(ourRatings, opponentRatings, maxPlayers) {
    const ours = Number.isFinite(maxPlayers) ? ourRatings.slice(0, maxPlayers) : [...ourRatings];
    const theirs = Number.isFinite(maxPlayers) ? opponentRatings.slice(0, maxPlayers) : [...opponentRatings];
    const boardCount = Math.min(ours.length, theirs.length); let oursAhead = 0, theirsAhead = 0, tied = 0, delta = 0; const scores = [];
    for (let i = 0; i < boardCount; i += 1) { const d = ours[i] - theirs[i]; delta += d; if (d > 0) oursAhead += 1; else if (d < 0) theirsAhead += 1; else tied += 1; scores.push(eloExpectedScore(ours[i], theirs[i])); }
    const probabilities = boardCount ? matchOutcomeProbabilities(scores) : { p2kWin: 0, draw: 1, opponentWin: 0 };
    return { boardCount, p2kAdvantageBoards: oursAhead, opponentAdvantageBoards: theirsAhead, tiedBoards: tied, netBoardAdvantage: oursAhead - theirsAhead, averageRatingDifference: boardCount ? delta / boardCount : 0, p2kWinProbability: probabilities.p2kWin, drawProbability: probabilities.draw, opponentWinProbability: probabilities.opponentWin };
  }
  function significantTarget(targetBoardCount) {
    return { net: Math.min(targetBoardCount, Math.max(2, Math.ceil(targetBoardCount * 0.20))), ahead: Math.ceil(targetBoardCount * 0.60), win: SIGNIFICANT_WIN_PROBABILITY };
  }
  function isSignificant(comparison, targetBoardCount) {
    if (!comparison || comparison.boardCount < targetBoardCount || targetBoardCount <= 0) return false;
    const t = significantTarget(targetBoardCount);
    return comparison.netBoardAdvantage >= t.net && comparison.p2kAdvantageBoards >= t.ahead && comparison.p2kWinProbability >= t.win;
  }
  function projectedOpponentRatings(opponentRatings, settings, targetBoardCount) {
    const current = [...opponentRatings].sort((a, b) => b - a);
    if (current.length >= targetBoardCount) return current;
    const boundedDefault = Math.max(settings.minimumRating, Math.min(Number.isFinite(settings.maximumRating) ? settings.maximumRating : 1200, 1200));
    const average = current.length ? current.reduce((s, n) => s + n, 0) / current.length : boundedDefault;
    const median = current.length ? current[Math.floor(current.length / 2)] : boundedDefault;
    const assumed = Math.max(settings.minimumRating, Math.min(Number.isFinite(settings.maximumRating) ? settings.maximumRating : 3000, Math.ceil(Math.max(average, median) / 25) * 25));
    return [...current, ...Array.from({ length: targetBoardCount - current.length }, () => assumed)].sort((a, b) => b - a);
  }
  function recruitmentPlan(selectedRatings, opponentRatings, eligiblePlayers, settings) {
    const cappedMinimum = Number.isFinite(settings.maximumPlayers) ? Math.min(settings.maximumPlayers, settings.minimumPlayers) : settings.minimumPlayers;
    const opponentCapacity = Number.isFinite(settings.maximumPlayers) ? Math.min(settings.maximumPlayers, opponentRatings.length) : opponentRatings.length;
    const targetBoardCount = Number.isFinite(settings.maximumPlayers) ? Math.min(settings.maximumPlayers, Math.max(cappedMinimum, opponentCapacity)) : Math.max(cappedMinimum, opponentCapacity);
    if (targetBoardCount <= 0) return { targetBoardCount: 0, needed: null, reason: "The opponent has no rated registered lineup yet." };
    const projectedOpponent = projectedOpponentRatings(opponentRatings, settings, targetBoardCount);
    const currentRatings = [...selectedRatings].sort((a, b) => b - a);
    const current = compareLineups(currentRatings, projectedOpponent, settings.maximumPlayers);
    if (isSignificant(current, targetBoardCount)) return { targetBoardCount, needed: 0, recruits: [], current, projected: current, projectedOpponent, target: significantTarget(targetBoardCount) };
    const maxSlots = Number.isFinite(settings.maximumPlayers) ? Math.max(0, settings.maximumPlayers - currentRatings.length) : eligiblePlayers.length;
    const usable = eligiblePlayers.slice(0, maxSlots); let projected = current;
    for (let n = 1; n <= usable.length; n += 1) {
      const recruitSlice = usable.slice(0, n), ratings = [...currentRatings, ...recruitSlice.map(p => p.rating)].sort((a, b) => b - a);
      projected = compareLineups(ratings, projectedOpponent, settings.maximumPlayers);
      if (isSignificant(projected, targetBoardCount)) return { targetBoardCount, needed: n, recruits: recruitSlice, current, projected, projectedOpponent, target: significantTarget(targetBoardCount) };
    }
    return { targetBoardCount, needed: null, recruits: usable, current, projected, projectedOpponent, target: significantTarget(targetBoardCount), reason: usable.length ? "Even recruiting every currently eligible top-rated member does not reach the significant-advantage target." : "No eligible stored-rated member is available to improve the lineup." };
  }

  function renderTeamChoices() {
    const entries = teamEntries(); if (teamSelector) teamSelector.hidden = state.lockTeamSelection;
    teamChoices.innerHTML = entries.map(([key, team], i) => `<label for="p2kRecruitTeam_${i}"><input${key === state.selectedTeamKey ? " checked" : ""} id="p2kRecruitTeam_${i}" name="p2kRecruitTeam" type="radio" value="${escapeHTML(key)}"/>${escapeHTML(teamName(team, key))}</label>`).join("");
    teamChoices.querySelectorAll('input[name="p2kRecruitTeam"]').forEach(input => input.addEventListener("change", () => { if (!input.checked) return; state.selectedTeamKey = input.value; updateSelectedTeamLogo(); renderLoadedMatchIntro(); }));
  }
  function hideSelectedTeamLogo() { if (!selectedTeamLogo) return; selectedTeamLogo.hidden = true; selectedTeamLogo.removeAttribute("src"); selectedTeamLogo.alt = ""; selectedTeamLogo.title = ""; }
  async function updateSelectedTeamLogo() {
    const token = ++state.logoRequestToken; hideSelectedTeamLogo(); const entry = selectedTeamEntry(); if (!entry) return; const slug = teamClubSlug(entry[1]); if (!slug) return;
    let profile = state.clubProfiles.get(slug); if (profile === undefined) { try { profile = await loadJSON(`https://api.chess.com/pub/club/${encodeURIComponent(slug)}`); } catch (_) { profile = null; } state.clubProfiles.set(slug, profile); }
    if (token !== state.logoRequestToken) return; const icon = String(profile?.icon || "").trim(); if (!icon) return;
    selectedTeamLogo.onerror = hideSelectedTeamLogo; selectedTeamLogo.src = icon; selectedTeamLogo.alt = `${teamName(entry[1], entry[0])} club logo`; selectedTeamLogo.title = teamName(entry[1], entry[0]); selectedTeamLogo.hidden = false;
  }
  function renderLoadedMatchIntro() {
    const selected = selectedTeamEntry(), opponent = opponentTeamEntry(); if (!selected || !opponent) return; const settings = matchSettings();
    const url = String(state.rawMatch?.url || `https://www.chess.com/club/matches/${state.matchId}`);
    resultsBox.innerHTML = `<div class="p2k-match-summary"><div class="p2k-match-summary-title"><a href="${escapeHTML(url)}" rel="noopener noreferrer" target="_blank">${escapeHTML(state.rawMatch?.name || `Match ${state.matchId}`)}</a></div><div class="p2k-summary-grid"><div class="p2k-summary-item"><strong>Recruiting team:</strong> ${escapeHTML(teamName(selected[1], selected[0]))}</div><div class="p2k-summary-item"><strong>Opponent:</strong> ${escapeHTML(teamName(opponent[1], opponent[0]))}</div><div class="p2k-summary-item"><strong>Rating category:</strong> ${escapeHTML(rulesLabel(settings.rules))}</div><div class="p2k-summary-item"><strong>Allowed rating range:</strong> ${escapeHTML(formatRatingRange(settings))}</div><div class="p2k-summary-item"><strong>Team players:</strong> ${settings.minimumPlayers || 0}${Number.isFinite(settings.maximumPlayers) ? `–${settings.maximumPlayers}` : "+"}</div><div class="p2k-summary-item"><strong>Registered:</strong> ${playerCount(selected[1])} vs ${playerCount(opponent[1])}</div></div></div><div class="p2k-recruit-intro"><strong>Stored-rating recruitment.</strong> The scan uses the stored ${escapeHTML(rulesLabel(settings.rules))} rating for current P2K members, excludes unrated/out-of-range/already-registered players, excludes anyone below the opponent's lowest rated registration, removes opponent-club members, then recruits the strongest remaining players first until the significant-advantage target is reached.</div>`;
  }
  function percent(value) { return `${Math.round(100 * Number(value || 0))}%`; }
  function formatSnapshot(value) { if (!value) return "—"; const d = new Date(String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z")); return Number.isNaN(d.getTime()) ? escapeHTML(value) : d.toLocaleDateString("en-GB",{timeZone:"UTC"}); }
  function rejectionItems(c) {
    return [["Already registered", c.alreadyRegistered], ["Unrated", c.unrated], ["Outside match range", c.outsideRange], ["Below lowest opponent", c.belowOpponent], ["Member of opponent club", c.opponentClub]].map(([l,n]) => `<div class="p2k-rejection-item"><strong>${n}</strong> ${escapeHTML(l)}</div>`).join("");
  }
  function recruitmentConfidence(result) {
    const players=result.eligiblePlayers||[], plan=result.plan||{}, selected=plan.recruits||[], now=Date.now();
    const fresh=players.length?players.filter(p=>{const d=p.ratingUpdatedAt?new Date(String(p.ratingUpdatedAt).replace(" ","T")+"Z"):null;return d&&!Number.isNaN(d.getTime())&&(now-d.getTime())<=60*86400000;}).length/players.length:0;
    const opponentCoverage=Math.min(1,(result.opponentRatedCount||0)/Math.max(1,result.opponentRegisteredCount||0));
    const availability=selected.length?selected.reduce((a,p)=>a+Math.min(1,Number(p.availabilityScore||0)/100),0)/selected.length:(players.length?players.slice(0,Math.min(10,players.length)).reduce((a,p)=>a+Math.min(1,Number(p.availabilityScore||0)/100),0)/Math.min(10,players.length):0);
    const lineup=Math.min(1,Number(plan.projected?.boardCount||plan.current?.boardCount||0)/Math.max(1,Number(plan.targetBoardCount||1)));
    const score=Math.round(100*(.30*fresh+.30*opponentCoverage+.25*availability+.15*lineup));
    return {score,label:score>=80?"High":score>=60?"Medium":"Low",drivers:[`Fresh stored ratings ${Math.round(fresh*100)}%`,`Opponent rated-lineup coverage ${Math.round(opponentCoverage*100)}%`,`Recommended-player availability ${Math.round(availability*100)}%`,`Projected board coverage ${Math.round(lineup*100)}%`]};
  }

  function renderResult(result) {
    const { selectedName, opponentName, settings, eligiblePlayers, counters, lowestOpponentRating, poolSummary, plan } = result;
    const needed = plan.needed, confidence=recruitmentConfidence(result);
    const selectedSet = new Set((plan.recruits || []).map(p => p.normalizedUsername));
    const rows = eligiblePlayers.map((p, index) => `<tr class="${selectedSet.has(p.normalizedUsername) ? "p2k-recommended-recruit" : ""}"><td>${index + 1}</td><td><a class="p2k-player-link" href="https://www.chess.com/member/${encodeURIComponent(p.username)}" target="_blank" rel="noopener noreferrer">${escapeHTML(p.username)}</a> <button type="button" class="p2k-profile-inline" data-unified-profile="${escapeHTML(p.username)}">Profile</button>${selectedSet.has(p.normalizedUsername) ? '<span class="p2k-top-recruit">Recruit</span>' : ""}</td><td>${Math.round(p.rating)}</td><td>${Math.round(p.availabilityScore||0)} / 100${p.overloaded?" · overloaded":""}<br><small>${escapeHTML(p.activityClass||"unknown")} · ${Number(p.currentLoad||0)} boards</small></td><td>${formatSnapshot(p.ratingUpdatedAt)}${p.ratingVerified?"":"<br><small>browser-observed · pending server audit</small>"}</td></tr>`).join("");
    const target = plan.target || significantTarget(Math.max(1, plan.targetBoardCount || 1));
    const projection = plan.projected || plan.current || {};
    const needText = needed === 0 ? "0" : needed === null ? "Not reachable" : String(needed);
    const tone = needed === null ? "p2k-bad" : "p2k-good";
    resultsBox.innerHTML = `
      <div class="p2k-result-cards">
        <div class="p2k-result-card"><div class="p2k-result-card-value">${counters.totalMembers}</div><div class="p2k-result-card-label">Current P2K members</div></div>
        <div class="p2k-result-card"><div class="p2k-result-card-value">${poolSummary.rated || 0}</div><div class="p2k-result-card-label">Stored ${settings.rules === "chess960" ? "960" : "classical"} ratings</div></div>
        <div class="p2k-result-card p2k-good"><div class="p2k-result-card-value">${eligiblePlayers.length}</div><div class="p2k-result-card-label">Eligible top recruits</div></div>
        <div class="p2k-result-card ${tone}"><div class="p2k-result-card-value">${needText}</div><div class="p2k-result-card-label">Players needed for significant advantage</div></div>
        <div class="p2k-result-card"><div class="p2k-result-card-value">${confidence.score}%</div><div class="p2k-result-card-label">${confidence.label} recommendation confidence</div></div>
      </div>
      <div class="p2k-recruit-summary">
        <strong>${escapeHTML(selectedName)}</strong> vs <strong>${escapeHTML(opponentName)}</strong> — ${escapeHTML(rulesLabel(settings.rules))}. Match range ${escapeHTML(formatRatingRange(settings))}; effective recruitment floor <strong>${lowestOpponentRating == null ? Math.max(0, settings.minimumRating) : lowestOpponentRating}</strong>${Number.isFinite(settings.maximumRating) ? `, ceiling ${Math.floor(settings.maximumRating)}` : ""}.
        <div class="p2k-rejection-grid">${rejectionItems(counters)}</div>
      </div>
      <div class="p2k-recruit-plan ${needed === null ? "p2k-plan-warning" : "p2k-plan-good"}">
        <strong>${needed === 0 ? "Significant advantage is already reached." : needed === null ? "Significant advantage is not reachable with the current eligible pool." : `Recruit the top ${needed} eligible player${needed === 1 ? "" : "s"}.`}</strong>
        <span>${escapeHTML(plan.reason || `Target across ${plan.targetBoardCount} projected boards: at least +${target.net} net boards, ${target.ahead} boards ahead, and ${Math.round(target.win * 100)}% projected win probability.`)}</span>
        ${projection.boardCount ? `<small>Projected result after recommendation: ${projection.p2kAdvantageBoards} boards ahead, ${projection.opponentAdvantageBoards} behind, ${projection.tiedBoards} tied; net ${projection.netBoardAdvantage >= 0 ? "+" : ""}${projection.netBoardAdvantage}; average rating delta ${projection.averageRatingDifference >= 0 ? "+" : ""}${Math.round(projection.averageRatingDifference)}; win probability ${percent(projection.p2kWinProbability)}.</small>` : ""}
        <small><strong>Confidence:</strong> ${confidence.drivers.map(escapeHTML).join(" · ")}</small>
      </div>
      <div class="p2k-recruit-table-wrap"><table class="p2k-recruit-table"><thead><tr><th>#</th><th>Player</th><th>Stored rating</th><th>Availability</th><th>Rating snapshot</th></tr></thead><tbody>${rows || '<tr><td colspan="5">No eligible stored-rated member was found.</td></tr>'}</tbody></table></div>`;
    resultsBox.querySelectorAll("[data-unified-profile]").forEach(button=>button.addEventListener("click",()=>{const username=button.dataset.unifiedProfile||"";if(window.parent!==window)window.parent.postMessage({type:"p2k-open-player-profile",username},window.location.origin);else window.open(`https://www.chess.com/member/${encodeURIComponent(username)}`,"_blank","noopener");}));
  }

  async function loadMatch(reference) {
    if (state.activeController) return; const controller = new AbortController(); state.activeController = controller; setBusy(true); setStatus("Loading match…", "working", 5); resultsBox.innerHTML = "";
    try {
      const matchId = parseMatchReference(reference), match = await loadJSON(`https://api.chess.com/pub/match/${encodeURIComponent(matchId)}`, controller.signal, { networkOnly: true });
      const entries = teamEntries(match); if (entries.length !== 2) throw new Error("The match endpoint did not return exactly two teams.");
      state.rawMatch = match; state.matchId = matchId; const preferred = teamKeyForReference(match, state.preferredTeamReference); state.selectedTeamKey = preferred || entries[0][0];
      if (state.lockTeamSelection && !preferred) throw new Error("The requested recruiting club is not one of the teams in this match.");
      matchInput.value = matchId; settingsPanel.hidden = false; renderTeamChoices(); renderLoadedMatchIntro(); void updateSelectedTeamLogo();
      setStatus("Match loaded. Start the stored-rating recruitment analysis.", "success", null);
      if (state.autorun) { state.autorun = false; await scanEligiblePlayers(); }
    } catch (error) {
      if (!(error instanceof CancellationError)) { console.error(error); setStatus(error.message || String(error), "error", null); resultsBox.innerHTML = '<div class="p2k-warning">The match could not be loaded.</div>'; }
    } finally { if (state.activeController === controller) state.activeController = null; setBusy(false); }
  }

  async function scanEligiblePlayers() {
    if (state.activeController) return;
    const selected = selectedTeamEntry(), opponent = opponentTeamEntry(); if (!selected || !opponent) { setStatus("Select a team before starting the scan.", "error", null); return; }
    const configuredSlug = String(window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king").toLowerCase();
    const selectedSlug = teamClubSlug(selected[1]), opponentSlug = teamClubSlug(opponent[1]);
    if (selectedSlug !== configuredSlug) { setStatus("Stored member ratings are available only when recruiting for Promote to King.", "error", null); return; }
    if (!opponentSlug) { setStatus("The opponent club could not be identified.", "error", null); return; }
    const settings = matchSettings(), controller = new AbortController(); state.activeController = controller; setBusy(true);
    try {
      setStatus(`Loading stored ${rulesLabel(settings.rules)} ratings…`, "working", 10);
      const pool = await loadSameOriginJSON(`server/team-points/public/recruitment-pool.php?rules=${encodeURIComponent(settings.rules)}`, controller.signal);
      if (controller.signal.aborted) throw new CancellationError();
      setStatus("Loading opponent club membership…", "working", 30);
      const opponentMembersData = await loadJSON(`https://api.chess.com/pub/club/${encodeURIComponent(opponentSlug)}/members`, controller.signal, { networkOnly: true });
      const opponentMemberSet = new Set(flattenClubMembers(opponentMembersData).map(m => m.normalizedUsername));
      setStatus("Applying match and opponent filters…", "working", 55);
      const opponentRatings = ratedRegisteredPlayers(opponent[1], settings).map(p => p.rating);
      const selectedRatings = ratedRegisteredPlayers(selected[1], settings).map(p => p.rating);
      const lowestOpponentRating = opponentRatings.length ? Math.min(...opponentRatings) : null;
      const effectiveFloor = Math.max(settings.minimumRating, lowestOpponentRating ?? settings.minimumRating);
      const registered = registeredUsernameSet();
      const counters = { totalMembers: (pool.rows || []).length, alreadyRegistered: 0, unrated: 0, outsideRange: 0, belowOpponent: 0, opponentClub: 0 };
      const eligiblePlayers = [];
      for (const row of pool.rows || []) {
        const username = String(row.username || "").trim(), key = String(row.username_key || username).trim().toLowerCase(), rating = Number(row.rating);
        if (!key) continue;
        if (registered.has(key)) { counters.alreadyRegistered += 1; continue; }
        if (!Number.isFinite(rating) || rating <= 0) { counters.unrated += 1; continue; }
        if (rating < settings.minimumRating || rating > settings.maximumRating) { counters.outsideRange += 1; continue; }
        if (lowestOpponentRating !== null && rating < lowestOpponentRating) { counters.belowOpponent += 1; continue; }
        if (opponentMemberSet.has(key)) { counters.opponentClub += 1; continue; }
        eligiblePlayers.push({ username, normalizedUsername: key, rating, ratingUpdatedAt: row.rating_updated_at || null, ratingSource:String(row.rating_source||"server_verified"), ratingVerified:row.rating_verified!==false, availabilityScore:Number(row.availability_score||0), activityClass:String(row.activity_class||"unknown"), currentLoad:Number(row.current_load||0), overloaded:Number(row.current_load||0)>=8, lastActivity:row.last_activity||null });
      }
      eligiblePlayers.sort((a, b) => b.rating - a.rating || b.availabilityScore - a.availabilityScore || a.username.localeCompare(b.username));
      setStatus("Computing top-player significant-advantage plan…", "working", 82);
      const plan = recruitmentPlan(selectedRatings, opponentRatings, eligiblePlayers, settings);
      const result = { selectedName: teamName(selected[1], selected[0]), opponentName: teamName(opponent[1], opponent[0]), settings, eligiblePlayers, counters, lowestOpponentRating, effectiveFloor, poolSummary: pool.summary || {}, plan, opponentRatedCount:opponentRatings.length, opponentRegisteredCount:playerCount(opponent[1]) };
      renderResult(result); setStatus(`Analysis complete: ${eligiblePlayers.length} eligible stored-rated member${eligiblePlayers.length === 1 ? "" : "s"}; ${plan.needed === null ? "significant advantage not currently reachable" : `${plan.needed} recruit${plan.needed === 1 ? "" : "s"} needed`}.`, plan.needed === null ? "working" : "success", 100);
    } catch (error) {
      if (error instanceof CancellationError || error?.category === "cancelled") setStatus("Recruitment analysis cancelled.", "working", null);
      else { console.error(error); setStatus(window.P2K_API_CLIENT?.userMessage?.(error) || error.message || String(error), "error", null); }
    } finally { if (state.activeController === controller) state.activeController = null; setBusy(false); }
  }

  matchForm.addEventListener("submit", event => { event.preventDefault(); void loadMatch(matchInput.value); });
  recruitmentForm.addEventListener("submit", event => { event.preventDefault(); void scanEligiblePlayers(); });
  cancelButton.addEventListener("click", () => state.activeController?.abort());

  const params = new URLSearchParams(window.location.search);
  state.preferredTeamReference = params.get("team") || ""; state.lockTeamSelection = parseBoolean(params.get("lockTeam"), false); state.embedded = parseBoolean(params.get("embedded"), false); state.autorun = parseBoolean(params.get("autorun"), false);
  const initialMatch = params.get("match") || params.get("id") || "";
  if (state.embedded) { document.body.classList.add("p2k-recruit-embedded"); if (initialMatch) document.body.classList.add("p2k-recruit-parameterized"); }
  if (initialMatch) { matchInput.value = initialMatch; void loadMatch(initialMatch); }
})();
