/* Existing team summary, match totals and readiness-gauge behavior. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.teamSummary = Object.freeze({
create(context) {
const { state, byId, setText, number, loadJSON, loadPublicCachedJSON, renderTeamMode, renderPersonalJoinDate, renderLiveTeamData } = context;
  function matchLists(payload) {
    return {
      registered: Array.isArray(payload?.registered) ? payload.registered : Array.isArray(payload?.registration) ? payload.registration : [],
      ongoing: Array.isArray(payload?.in_progress) ? payload.in_progress : Array.isArray(payload?.ongoing) ? payload.ongoing : [],
      finished: Array.isArray(payload?.finished) ? payload.finished : []
    };
  }
  function matchBoardCount(match) {
    const direct = [match?.boards, match?.board_count, match?.num_boards, match?.boardCount]
      .map(Number).find(value => Number.isFinite(value) && value >= 0);
    if (direct !== undefined) return direct;
    const teams = Array.isArray(match?.teams) ? match.teams : Object.values(match?.teams || {});
    const playerCounts = teams.map(team => Array.isArray(team?.players) ? team.players.length : 0).filter(Boolean);
    if (playerCounts.length) return Math.min(...playerCounts);
    const configured = Number(match?.settings?.max_team_players ?? match?.settings?.max_players ?? match?.max_players);
    return Number.isFinite(configured) && configured >= 0 ? configured : 0;
  }
  function matchListTotals(list) {
    const boards = (Array.isArray(list) ? list : []).reduce((sum, match) => sum + matchBoardCount(match), 0);
    return { boards, games: boards * 2 };
  }
  function authoritativeMatchListTotals(list) {
    return window.P2K_DASHBOARD_MATCH_BOARD_HYDRATION?.totals(list) || { boards: 0, games: 0, unresolved: Array.isArray(list) ? list.length : 0 };
  }
  async function hydrateDashboardMatchBoards(lists) {
    const dmbhf = window.P2K_DASHBOARD_MATCH_BOARD_HYDRATION;
    if (!dmbhf?.hydrate) throw new Error("Dashboard Match Board Hydration module is unavailable.");
    return dmbhf.hydrate(lists, { client: window.P2K_API_CLIENT, loadJSON });
  }
  function setMatchMetric(status, list, options = {}) {
    const ids = {
      registered: ["teamOpenRegistrations", "teamRegisteredBoards"],
      ongoing: ["teamActiveMatches", "teamOngoingBoards"],
      finished: ["teamFinishedMatches", "teamFinishedBoards"]
    }[status];
    if (!ids) return;
    const matches = Array.isArray(list) ? list : [];
    setText(ids[0], number(matches.length));
    if (options.loadingBoards) {
      setText(ids[1], "Loading authoritative board totals…");
      return;
    }
    const totals = status === "finished" ? matchListTotals(matches) : authoritativeMatchListTotals(matches);
    const suffix = totals.unresolved > 0 ? "+" : "";
    setText(ids[1], `${number(totals.boards)}${suffix} boards · ${number(totals.games)}${suffix} games${totals.unresolved > 0 ? ` · ${number(totals.unresolved)} unresolved` : ""}`);
  }
  async function loadTeamData() {
    setText("teamStatusBadge", "Loading Green database");
    const data = { members: null, profile: null, matches: null, database: null, lists: { registered: [], ongoing: [], finished: [] } };
    const cached = window.P2K_PROGRESSIVE?.snapshotGet?.("dashboard-team", 6 * 3600000);
    try {
      const databaseResult = await (window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("team", { _fresh: Date.now() }) || Promise.reject(new Error("Team Points client unavailable")));
      data.database = databaseResult?.team || {};
      data.members = Number(data.database.current_members ?? 0) || null;
      setText("teamMembers", number(data.members));
      setText("teamClubPoints", number(data.database.club_points));
      setText("teamClubPointsNote", "5 × boards won · 2 × boards drawn");
      if (data.database.finished_matches_available) {
        setText("teamFinishedMatches", number(data.database.finished_matches));
        setText("teamFinishedBoards", `${number(data.database.finished_boards)} boards · ${number(data.database.finished_games)} games`);
      }
      setText("teamOpenRegistrations", number(data.database.registered_matches ?? 0));
      setText("teamRegisteredBoards", `${number(data.database.registered_boards ?? 0)} boards · ${number(data.database.registered_games ?? 0)} games`);
      setText("teamActiveMatches", number(data.database.in_progress_matches ?? data.database.ongoing_matches ?? 0));
      setText("teamOngoingBoards", `${number(data.database.in_progress_boards ?? data.database.ongoing_boards ?? 0)} boards · ${number(data.database.in_progress_games ?? data.database.ongoing_games ?? 0)} games`);
      state.teamData = data;
      renderTeamMode();
      setText("teamStatusBadge", data.database.data_source === "green_native_core" ? "GREEN · live database" : "Database ready");
      window.P2K_PROGRESSIVE?.snapshotSet?.("dashboard-team", { database: data.database, members: data.members });
    } catch (error) {
      console.warn("Green Dashboard database refresh failed.", error);
      if (cached?.payload?.database) {
        data.database = cached.payload.database; data.members = Number(data.database.current_members ?? cached.payload.members ?? 0) || null;
        setText("teamMembers", number(data.members)); setText("teamClubPoints", number(data.database.club_points));
        setText("teamStatusBadge", "Database unavailable · cached fallback");
      } else {
        setText("teamClubPoints", "—"); setText("teamClubPointsNote", "Database unavailable"); setText("teamStatusBadge", "Database unavailable");
      }
    }
    const secondWave = () => {
      renderPersonalJoinDate();
      Promise.all([
        window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("dashboard-matches", { status: "registered", limit: 1500, _fresh: Date.now() }),
        window.P2K_TEAM_POINTS_CLIENT?.publicRequest?.("dashboard-matches", { status: "in_progress", limit: 2000, _fresh: Date.now() })
      ]).then(([registeredPayload, ongoingPayload]) => {
        data.lists.registered = Array.isArray(registeredPayload?.matches?.rows) ? registeredPayload.matches.rows : [];
        data.lists.ongoing = Array.isArray(ongoingPayload?.matches?.rows) ? ongoingPayload.matches.rows : [];
        setMatchMetric("registered", data.lists.registered);
        setMatchMetric("ongoing", data.lists.ongoing);
        setText("adminRegistrationCount", number(data.lists.registered.length));
        setText("adminOngoingCount", number(data.lists.ongoing.length));
        state.teamData = data; renderTeamMode();
      }).catch(error => console.warn("Green Dashboard match-list refresh failed.", error));
      loadJSON("server/team-points/public/public.php?action=live-team", { credentials: "same-origin", cache: "no-store" }).then(payload => {
        state.liveTeamData = payload?.team || payload || {}; renderLiveTeamData();
      }).catch(error => { console.warn("Live team refresh failed.", error); state.liveTeamData = {}; renderLiveTeamData(); });
    };
    (window.P2K_PROGRESSIVE?.afterFirstPaint || (fn => setTimeout(fn, 0)))(secondWave);
    (window.P2K_PROGRESSIVE?.afterIdle || (fn => setTimeout(fn, 1200)))(() => {
      if (window.P2K_PROGRESSIVE?.canPrefetch && !window.P2K_PROGRESSIVE.canPrefetch()) return;
      loadPublicCachedJSON("server/team-points/public/achievement-players.php?page=1&page_size=12&filter=current", { ttl: 120000, credentials: "same-origin" }).catch(() => {});
      loadPublicCachedJSON("server/team-points/public/team-insights.php?section=summary", { ttl: 120000, credentials: "same-origin" }).catch(() => {});
    });
  }
  function renderGauge(valueId, gaugeId, noteId, value, note) {
    const numeric = Number(value);
    const available = value !== null && value !== undefined && String(value).trim() !== "" && Number.isFinite(numeric);
    const bounded = available ? Math.max(0, Math.min(100, numeric)) : 0;
    const statusLabel = !available ? "—" : bounded >= 80 ? "Ready" : bounded >= 50 ? "Progressing" : "Needs support";
    setText(valueId, statusLabel);
    const gauge = byId(gaugeId);
    gauge.style.width = `${bounded}%`;
    const card = gauge.closest(".dashboard-gauge-card");
    if (card) {
      card.classList.remove("status-low", "status-medium", "status-good");
      if (available) card.classList.add(bounded >= 80 ? "status-good" : bounded >= 50 ? "status-medium" : "status-low");
    }
    setText(noteId, note);
  }
  function renderTeamIndicators(indicators) {
    if (!indicators || typeof indicators !== "object") {
      renderGauge("teamLineupReadinessValue", "teamLineupReadinessGauge", "teamLineupReadinessNote", null, "Log in to calculate from Match Assistant data");
      renderGauge("teamRegistrationTargetsValue", "teamRegistrationTargetsGauge", "teamRegistrationTargetsNote", null, "Log in to calculate from Match Assistant data");
      setText("teamStartingSoon", "—");
      setText("teamPriorityCalls", "—");
      return;
    }
    renderGauge(
      "teamLineupReadinessValue", "teamLineupReadinessGauge", "teamLineupReadinessNote",
      indicators.lineupReadiness,
      `${number(indicators.leagueMatches || 0)} priority league registration match${Number(indicators.leagueMatches) === 1 ? "" : "es"}`
    );
    renderGauge(
      "teamRegistrationTargetsValue", "teamRegistrationTargetsGauge", "teamRegistrationTargetsNote",
      indicators.registrationTargets,
      `${number(indicators.registrationMatches || 0)} open Daily registration match${Number(indicators.registrationMatches) === 1 ? "" : "es"}`
    );
    setText("teamStartingSoon", number(indicators.startingWithinSevenDays));
    setText("teamPriorityCalls", number(indicators.priorityCalls));
  }

return Object.freeze({ matchLists, matchBoardCount, matchListTotals, authoritativeMatchListTotals, hydrateDashboardMatchBoards, setMatchMetric, loadTeamData, renderGauge, renderTeamIndicators });
}
});
})();
