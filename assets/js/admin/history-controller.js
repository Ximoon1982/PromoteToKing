/* Existing tracking graph and lineup-evolution behavior. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.history_controller = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, feedback, formatDateTime, adminModal, closeAdmin } = context;
  const historyModal = byId("historyModal");
  const snapshotModal = byId("snapshotChangesModal");
  let activeHistory = null;
  let activeSnapshotIndex = 0;

  function closeHistory() {
    snapshotModal.hidden = true;
    historyModal.hidden = true;
    activeHistory = null;
  }
  function closeSnapshotChanges() { snapshotModal.hidden = true; }
  byId("historyClose").addEventListener("click", closeHistory);
  historyModal.addEventListener("click", event => { if (event.target === historyModal) closeHistory(); });
  byId("snapshotChangesClose").addEventListener("click", closeSnapshotChanges);
  byId("snapshotChangesPrevious").addEventListener("click", () => openSnapshotChanges(Math.max(0, activeSnapshotIndex - 1)));
  byId("snapshotChangesNext").addEventListener("click", () => openSnapshotChanges(Math.min((activeHistory?.points?.length || 1) - 1, activeSnapshotIndex + 1)));
  snapshotModal.addEventListener("click", event => { if (event.target === snapshotModal) closeSnapshotChanges(); });

  function normalizeTeams(match) {
    const raw = match?.teams;
    const rows = Array.isArray(raw) ? raw : raw && typeof raw === "object" ? Object.values(raw) : [];
    return rows.filter(team => team && typeof team === "object").map((team, index) => ({
      raw: team,
      index,
      name: String(team.name || team.team_name || team.club_name || `Team ${index + 1}`),
      identity: String(team["@id"] || team.url || team.id || team.name || `team-${index}`),
      players: Array.isArray(team.players) ? team.players : []
    }));
  }

  function preferredTeamOrder(teams) {
    return [...teams].sort((left, right) => {
      const leftHome = /promote[- ]to[- ]king/i.test(`${left.identity} ${left.name}`);
      const rightHome = /promote[- ]to[- ]king/i.test(`${right.identity} ${right.name}`);
      return Number(rightHome) - Number(leftHome) || left.index - right.index;
    });
  }


  function lineupFingerprint(teams) {
    return teams.map(team => (Array.isArray(team?.players) ? team.players : [])
      .map(player => `${playerIdentity(player)}:${playerRating(player) ?? ""}`)
      .filter(value => !value.startsWith(":"))
      .sort()
      .join("|")).join("||");
  }

  function historySeries(snapshots) {
    const firstTeams = preferredTeamOrder(normalizeTeams(snapshots[0]?.match));
    const definitions = firstTeams.slice(0, 2).map((team, index) => ({
      identity: team.identity,
      fallbackIndex: team.index,
      name: team.name,
      key: index === 0 ? "home" : "away"
    }));
    while (definitions.length < 2) {
      const index = definitions.length;
      definitions.push({ identity: `team-${index}`, fallbackIndex: index, name: `Team ${index + 1}`, key: index === 0 ? "home" : "away" });
    }
    const allPoints = snapshots.map(snapshot => {
      const teams = normalizeTeams(snapshot.match);
      const resolved = definitions.map(definition => teams.find(team => team.identity === definition.identity) || teams[definition.fallbackIndex] || { name: definition.name, players: [], identity: definition.identity, raw: {} });
      return {
        timestamp: new Date(snapshot.trackedAt).getTime(),
        trackedAt: snapshot.trackedAt,
        snapshot,
        teams: resolved,
        home: resolved[0].players.length,
        away: resolved[1].players.length
      };
    });
    let previousFingerprint = null;
    const points = allPoints.filter(point => {
      const fingerprint = lineupFingerprint(point.teams);
      if (previousFingerprint !== null && fingerprint === previousFingerprint) return false;
      previousFingerprint = fingerprint;
      return true;
    });
    definitions.forEach((definition, index) => {
      definition.name = points.at(-1)?.teams[index]?.name || definition.name;
    });
    return { definitions, points, totalSnapshots: allPoints.length, omittedSnapshots: allPoints.length - points.length };
  }

  async function openHistory(identifier) {
    historyModal.hidden = false;
    byId("historyTitle").textContent = `Tracking graph — match ${identifier}`;
    byId("historySubtitle").textContent = "";
    byId("historyLegend").innerHTML = "";
    byId("historyChart").innerHTML = "";
    feedback("historyFeedback", "Loading…");
    try {
      const data = await fetchJSON(`api/match-history/?match=${encodeURIComponent(identifier)}`);
      const snapshots = data.snapshots || [];
      if (!snapshots.length) {
        drawHistory({ definitions: [], points: [] });
        feedback("historyFeedback", "No snapshots are available for this match.", "success");
        return;
      }
      activeHistory = { matchId: identifier, snapshots, ...historySeries(snapshots) };
      const matchName = snapshots.at(-1)?.match?.name || `Match ${identifier}`;
      byId("historyTitle").textContent = matchName;
      byId("historySubtitle").textContent = `Match ${identifier} · ${snapshots.length} archive snapshot(s) · ${activeHistory.points.length} lineup-change point(s)`;
      byId("historyLegend").innerHTML = activeHistory.definitions.map((definition, index) => `
        <span><i class="history-swatch team-${index + 1}"></i>${escapeHTML(definition.name)}</span>
      `).join("");
      drawHistory(activeHistory);
      feedback("historyFeedback", `${activeHistory.points.length} graph point(s) shown; ${activeHistory.omittedSnapshots || 0} consecutive record(s) with identical players and ratings omitted${data.truncated ? " (latest archive subset)" : ""}.`, "success");
    } catch (error) {
      feedback("historyFeedback", error.message, "error");
    }
  }

  function drawHistory(historyData) {
    const svg = byId("historyChart");
    const points = historyData.points || [];
    if (!points.length) {
      svg.innerHTML = '<text x="420" y="165" fill="#bdb5ab" text-anchor="middle">No snapshots available.</text>';
      return;
    }
    const width = 840;
    const height = 330;
    const left = 52;
    const right = 22;
    const top = 28;
    const bottom = 58;
    const maximum = Math.max(1, ...points.flatMap(point => [point.home, point.away]));
    const minimumTime = points[0].timestamp;
    const maximumTime = points.at(-1).timestamp || minimumTime + 1;
    const x = timestamp => left + (timestamp - minimumTime) / Math.max(1, maximumTime - minimumTime) * (width - left - right);
    const y = value => top + (1 - value / maximum) * (height - top - bottom);
    const path = key => points.map((point, index) => `${index ? "L" : "M"}${x(point.timestamp).toFixed(1)},${y(point[key]).toFixed(1)}`).join(" ");
    let content = "";
    for (let index = 0; index <= 4; index++) {
      const value = Math.round(maximum * index / 4);
      const vertical = y(value);
      content += `<line x1="${left}" y1="${vertical}" x2="${width - right}" y2="${vertical}" stroke="rgba(255,255,255,.1)"/>`;
      content += `<text x="${left - 8}" y="${vertical + 4}" fill="#aaa198" text-anchor="end" font-size="11">${value}</text>`;
    }
    const tickIndexes = [...new Set([0, Math.floor((points.length - 1) / 3), Math.floor((points.length - 1) * 2 / 3), points.length - 1])];
    tickIndexes.forEach(index => {
      const point = points[index];
      const label = new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", day: "2-digit", month: "short" }).format(new Date(point.timestamp));
      content += `<text x="${x(point.timestamp)}" y="${height - 18}" fill="#aaa198" text-anchor="middle" font-size="11">${escapeHTML(label)}</text>`;
    });
    content += `<path d="${path("home")}" fill="none" stroke="#91e09a" stroke-width="3"/>`;
    content += `<path d="${path("away")}" fill="none" stroke="#ff8b79" stroke-width="3"/>`;
    points.forEach((point, index) => {
      [["home", "#91e09a"], ["away", "#ff8b79"]].forEach(([key, color]) => {
        const teamName = key === "home" ? historyData.definitions[0].name : historyData.definitions[1].name;
        content += `<circle class="history-point" data-snapshot-index="${index}" cx="${x(point.timestamp)}" cy="${y(point[key])}" r="6" fill="${color}" stroke="#171513" stroke-width="3" tabindex="0" role="button" aria-label="${escapeHTML(teamName)}, ${point[key]} players. Open lineup evolution."/>`;
      });
    });
    svg.innerHTML = content;
    svg.querySelectorAll(".history-point").forEach(point => {
      const open = () => openSnapshotChanges(Number(point.dataset.snapshotIndex));
      point.addEventListener("click", open);
      point.addEventListener("keydown", event => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          open();
        }
      });
    });
  }

  function playerIdentity(player) {
    return String(player?.username || player?.name || player?.user || "").trim().toLowerCase();
  }

  function playerRating(player) {
    const value = Number(player?.rating ?? player?.elo ?? player?.score);
    return Number.isFinite(value) && value > 0 ? Math.round(value) : null;
  }

  function playerTimeoutRate(player) {
    const fields = ["timeout_percent", "timeout_percentage", "timeout_rate", "timeout"];
    for (const field of fields) {
      const value = Number(player?.[field]);
      if (!Number.isFinite(value) || value < 0) continue;
      return value;
    }
    return null;
  }

  function changedPlayers(currentPlayers, previousPlayers) {
    const current = new Map(currentPlayers.map(player => [playerIdentity(player), player]).filter(([identity]) => identity));
    const previous = new Map(previousPlayers.map(player => [playerIdentity(player), player]).filter(([identity]) => identity));
    const added = [...current].filter(([identity]) => !previous.has(identity)).map(([, player]) => player);
    const removed = [...previous].filter(([identity]) => !current.has(identity)).map(([, player]) => player);
    const ratingChanged = [...current].filter(([identity, player]) => previous.has(identity) && playerRating(player) !== playerRating(previous.get(identity)))
      .map(([identity, player]) => ({ player, previous: previous.get(identity) }));
    return { added, removed, ratingChanged };
  }

  function playerChangeRows(changes) {
    const rows = [
      ...changes.added.map(player => ({ player, sign: "+", className: "added" })),
      ...changes.removed.map(player => ({ player, sign: "−", className: "removed" })),
      ...changes.ratingChanged.map(change => ({ ...change, sign: "↕", className: "rating" }))
    ];
    if (!rows.length) return '<tr><td colspan="3" class="empty-change">No lineup change</td></tr>';
    return rows.map(row => {
      const username = playerIdentity(row.player) || "Unknown player";
      const rating = playerRating(row.player);
      const previousRating = row.previous ? playerRating(row.previous) : null;
      const ratingText = row.className === "rating" ? `${previousRating ?? "—"} → ${rating ?? "—"}` : rating ?? "—";
      const timeout = playerTimeoutRate(row.player);
      return `
        <tr class="player-change ${row.className}">
          <td><span class="change-sign">${row.sign}</span><a href="https://www.chess.com/member/${encodeURIComponent(username)}" target="_blank" rel="noopener">${escapeHTML(username)}</a></td>
          <td class="num">${ratingText}</td>
          <td class="num">${timeout === null ? "—" : `${Math.round(timeout * 10) / 10}%`}</td>
        </tr>
      `;
    }).join("");
  }

  function teamChangePanel(teamName, currentPlayers, previousPlayers, baseline) {
    const changes = baseline ? { added: [], removed: [], ratingChanged: [] } : changedPlayers(currentPlayers, previousPlayers);
    const balance = changes.added.length - changes.removed.length;
    return `
      <section class="snapshot-team-panel">
        <h3>${escapeHTML(teamName)}</h3>
        ${baseline ? '<div class="empty-change baseline-note">Initial archive: no previous snapshot is available for comparison.</div>' : `
          <div class="table-wrap player-change-wrap">
            <table class="table player-change-table">
              <thead><tr><th>Player</th><th class="num">Rating</th><th class="num">Timeout</th></tr></thead>
              <tbody>${playerChangeRows(changes)}</tbody>
            </table>
          </div>
        `}
        <div class="change-totals">
          <div><strong>Added</strong><span>+${changes.added.length}</span></div>
          <div><strong>Removed</strong><span>−${changes.removed.length}</span></div>
          <div><strong>Balance</strong><span>${balance > 0 ? "+" : ""}${balance}</span></div>
        </div>
      </section>
    `;
  }

  function openSnapshotChanges(index) {
    if (!activeHistory?.points?.[index]) return;
    const point = activeHistory.points[index];
    const previous = index > 0 ? activeHistory.points[index - 1] : null;
    activeSnapshotIndex = index;
    snapshotModal.hidden = false;
    byId("snapshotChangesTitle").textContent = "Lineup evolution";
    byId("snapshotChangesSubtitle").textContent = `${formatDateTime(point.trackedAt)} · Recording ${index + 1} of ${activeHistory.points.length}`;
    byId("snapshotChangesPrevious").disabled = index <= 0;
    byId("snapshotChangesNext").disabled = index >= activeHistory.points.length - 1;
    byId("snapshotChangesColumns").innerHTML = activeHistory.definitions.map((definition, teamIndex) => teamChangePanel(
      point.teams[teamIndex]?.name || definition.name,
      point.teams[teamIndex]?.players || [],
      previous?.teams[teamIndex]?.players || [],
      index === 0
    )).join("");
    requestAnimationFrame(() => {
      const bounds = snapshotModal.getBoundingClientRect();
      if (bounds.top < 0 || bounds.bottom > window.innerHeight) snapshotModal.scrollIntoView({ block: "nearest", behavior: "smooth" });
      byId("snapshotChangesClose").focus({ preventScroll: true });
    });
  }

  document.addEventListener("keydown", event => {
    if (event.key !== "Escape") return;
    if (!snapshotModal.hidden) closeSnapshotChanges();
    else if (!historyModal.hidden) closeHistory();
    else if (!adminModal.hidden) closeAdmin();
  });

return Object.freeze({ openHistory });
}
});
})();
