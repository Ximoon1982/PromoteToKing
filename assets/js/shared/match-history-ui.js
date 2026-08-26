/* Shared tracked-match history chart with clickable archive-change details. */
(() => {
  "use strict";

  const HOME_COLOR = "#91e09a";
  const OPPONENT_COLOR = "#ff8b79";
  const PROBABILITY_COLOR = "#9fd8ff";
  const MINIMUM_COLOR = "#ffd078";
  const hostsInFlight = new WeakSet();
  const detailsPanels = new WeakMap();
  let openDetailsPanel = null;
  let detailsPanelSequence = 0;

  function escapeHTML(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function escapeAttribute(value) {
    return escapeHTML(value).replace(/`/g, "&#096;");
  }

  function matchId(value) {
    const match = String(value || "").match(/(\d+)(?:\/?(?:[?#].*)?)?$/);
    return match ? match[1] : "";
  }

  function placeholderHTML(reference) {
    const id = matchId(reference);
    if (!id) return "";
    return `<div class="p2k-match-history" data-p2k-match-history="${escapeAttribute(id)}" hidden></div>`;
  }

  function formatTimestamp(value, compact = false) {
    const date = new Date(String(value || ""));
    if (!Number.isFinite(date.getTime())) return String(value || "");
    return new Intl.DateTimeFormat("en-GB", compact
      ? { timeZone: "UTC", day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit", hour12: false }
      : { timeZone: "UTC", year: "numeric", month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false }
    ).format(date) + " UTC";
  }

  function clamp(value, minimum, maximum) {
    return Math.max(minimum, Math.min(maximum, value));
  }

  function normalizeTeams(match) {
    const raw = match?.teams;
    const rows = Array.isArray(raw) ? raw : raw && typeof raw === "object" ? Object.values(raw) : [];
    return rows.filter(team => team && typeof team === "object").map((team, index) => ({
      index,
      name: String(team.name || team.team_name || team.club_name || `Team ${index + 1}`),
      identity: String(team["@id"] || team.url || team.id || team.name || `team-${index}`),
      players: Array.isArray(team.players) ? team.players : []
    }));
  }

  function preferredTeams(teams) {
    return [...teams].sort((left, right) => {
      const leftHome = /promote[- ]to[- ]king/i.test(`${left.identity} ${left.name}`);
      const rightHome = /promote[- ]to[- ]king/i.test(`${right.identity} ${right.name}`);
      return Number(rightHome) - Number(leftHome) || left.index - right.index;
    });
  }

  function buildTeamDefinitions(snapshots) {
    const first = preferredTeams(normalizeTeams(snapshots[0]?.match));
    const definitions = first.slice(0, 2).map((team, index) => ({
      identity: team.identity,
      fallbackIndex: team.index,
      name: team.name,
      key: index === 0 ? "p2kCount" : "opponentCount"
    }));
    while (definitions.length < 2) {
      const index = definitions.length;
      definitions.push({ identity: `team-${index}`, fallbackIndex: index, name: index === 0 ? "Promote to King" : "Opponent", key: index === 0 ? "p2kCount" : "opponentCount" });
    }
    return definitions;
  }

  function resolveTeams(match, definitions) {
    const teams = normalizeTeams(match);
    return definitions.map(definition => teams.find(team => team.identity === definition.identity)
      || teams[definition.fallbackIndex]
      || { name: definition.name, identity: definition.identity, players: [] });
  }

  function lineupFingerprint(teams) {
    return teams.map(team => (Array.isArray(team?.players) ? team.players : [])
      .map(player => `${playerIdentity(player)}:${playerRating(player) ?? ""}`)
      .filter(value => !value.startsWith(":"))
      .sort()
      .join("|")).join("||");
  }

  function omitUnchangedLineups(points) {
    if (!Array.isArray(points) || points.length <= 2) return Array.isArray(points) ? points.slice() : [];
    const kept = [];
    let start = 0;
    const fingerprintAt = index => lineupFingerprint(points[index]?.teams);
    for (let index = 1; index <= points.length; index += 1) {
      const sameRun = index < points.length && fingerprintAt(index) === fingerprintAt(start) && points[index]?.isLive !== true;
      if (sameRun) continue;
      kept.push(points[start]);
      const last = index - 1;
      if (last > start) kept.push(points[last]);
      start = index;
    }
    return kept.filter((point, index, all) => index === 0 || point !== all[index - 1]);
  }

  function pointTimestamp(point, fallback = 0) {
    const value = Date.parse(String(point?.trackedAt || ""));
    return Number.isFinite(value) ? value : fallback;
  }

  function axisTimestamp(value, span) {
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return "";
    const options = span > 7 * 86400000
      ? { day: "2-digit", month: "short" }
      : { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit", hour12: false };
    return new Intl.DateTimeFormat("en-GB", { ...options, timeZone: "UTC" }).format(date);
  }

  function linePath(rows, xFor, yFor, key) {
    return rows.map((row, index) => `${index === 0 ? "M" : "L"}${xFor(row.point, index).toFixed(2)},${yFor(row.point[key]).toFixed(2)}`).join(" ");
  }

  function chartHTML(allPoints, options = {}) {
    if (!Array.isArray(allPoints) || allPoints.length === 0) return "";
    const large = options.large === true;
    const rangeStart = clamp(Number(options.start ?? 0), 0, allPoints.length - 1);
    const rangeEnd = clamp(Number(options.end ?? (allPoints.length - 1)), rangeStart, allPoints.length - 1);
    const rows = allPoints.slice(rangeStart, rangeEnd + 1).map((point, offset) => ({ point, sourceIndex: rangeStart + offset }));
    const points = rows.map(row => row.point);
    const homeName = String(allPoints.at(-1).teams[0]?.name || allPoints.at(-1).p2kName || "Promote to King");
    const opponentName = String(allPoints.at(-1).teams[1]?.name || allPoints.at(-1).opponentName || "Opponent");
    const width = large ? 1120 : 760;
    const height = large ? 470 : 350;
    const left = large ? 64 : 52;
    const right = large ? 64 : 54;
    const top = large ? 26 : 20;
    const bottom = large ? 82 : 68;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maxPlayers = Math.max(1, ...points.flatMap(point => [point.p2kCount, point.opponentCount, point.minPlayers || 0]));
    const step = Math.max(1, Math.ceil(maxPlayers / 5));
    const playerAxisMax = Math.max(step, Math.ceil(maxPlayers / step) * step);
    const rawTimes = rows.map((row, index) => pointTimestamp(row.point, index));
    let timeMin = Math.min(...rawTimes), timeMax = Math.max(...rawTimes);
    if (!Number.isFinite(timeMin) || !Number.isFinite(timeMax)) { timeMin = 0; timeMax = Math.max(1, rows.length - 1); }
    if (timeMax <= timeMin) timeMax = timeMin + 1;
    const timeSpan = timeMax - timeMin;
    const xFor = (point, localIndex) => {
      const time = pointTimestamp(point, timeMin + localIndex);
      return left + ((time - timeMin) / timeSpan) * plotWidth;
    };
    const yPlayers = value => top + plotHeight - (clamp(Number(value) || 0, 0, playerAxisMax) / playerAxisMax) * plotHeight;
    const yProbability = value => top + plotHeight - clamp(Number(value) || 0, 0, 1) * plotHeight;

    let grid = "";
    for (let value = 0; value <= playerAxisMax; value += step) {
      const y = yPlayers(value);
      grid += `<line x1="${left}" y1="${y.toFixed(2)}" x2="${width - right}" y2="${y.toFixed(2)}" stroke="rgba(255,255,255,.08)" stroke-width="1" />`;
      grid += `<text x="${left - 7}" y="${(y + 4).toFixed(2)}" text-anchor="end" fill="#bfb7ae" font-size="${large ? 12 : 10}">${value}</text>`;
    }
    [0, 25, 50, 75, 100].forEach(value => {
      const y = yProbability(value / 100);
      grid += `<text x="${width - right + 7}" y="${(y + 4).toFixed(2)}" text-anchor="start" fill="#9fcfe7" font-size="${large ? 12 : 10}">${value}%</text>`;
    });
    const tickCount = Math.min(6, Math.max(2, rows.length));
    for (let index = 0; index < tickCount; index += 1) {
      const time = timeMin + timeSpan * index / Math.max(1, tickCount - 1);
      const x = left + plotWidth * index / Math.max(1, tickCount - 1);
      grid += `<line x1="${x.toFixed(2)}" y1="${top}" x2="${x.toFixed(2)}" y2="${top + plotHeight}" stroke="rgba(255,255,255,.035)" stroke-width="1" />`;
      grid += `<text x="${x.toFixed(2)}" y="${height - 33}" text-anchor="${index === 0 ? "start" : index === tickCount - 1 ? "end" : "middle"}" fill="#aaa198" font-size="${large ? 11 : 9}">${escapeHTML(axisTimestamp(time, timeSpan))}</text>`;
    }

    const homePath = linePath(rows, xFor, yPlayers, "p2kCount");
    const opponentPath = linePath(rows, xFor, yPlayers, "opponentCount");
    const probabilityPath = linePath(rows, xFor, yProbability, "p2kWinProbability");
    const minimumValues = points.map(point => Number(point.minPlayers) || 0);
    const minimumIsStable = minimumValues.every(value => value === minimumValues[0]);
    const minimumPath = minimumIsStable
      ? `<line x1="${left}" y1="${yPlayers(minimumValues[0]).toFixed(2)}" x2="${width - right}" y2="${yPlayers(minimumValues[0]).toFixed(2)}" stroke="${MINIMUM_COLOR}" stroke-width="1.2" stroke-dasharray="7 5" />`
      : `<path d="${linePath(rows, xFor, yPlayers, "minPlayers")}" fill="none" stroke="${MINIMUM_COLOR}" stroke-width="1.2" stroke-dasharray="7 5" />`;

    const pointGroups = rows.map((row, localIndex) => {
      const point = row.point;
      const x = xFor(point, localIndex);
      const previousX = localIndex === 0 ? left : (xFor(rows[localIndex - 1].point, localIndex - 1) + x) / 2;
      const nextX = localIndex === rows.length - 1 ? width - right : (x + xFor(rows[localIndex + 1].point, localIndex + 1)) / 2;
      const tooltip = [
        point.isLive ? "Current live lineup" : formatTimestamp(point.trackedAt),
        `${homeName}: ${point.p2kCount} registered`,
        `${opponentName}: ${point.opponentCount} registered`,
        `Minimum required: ${point.minPlayers || 0}`,
        `P2K win probability: ${(point.p2kWinProbability * 100).toFixed(1)}%`,
        "Click for lineup evolution"
      ].join(" | ");
      return `<g>
        <circle cx="${x.toFixed(2)}" cy="${yPlayers(point.p2kCount).toFixed(2)}" r="${point.isLive ? 4.6 : 3.5}" fill="${HOME_COLOR}" stroke="${point.isLive ? "#fff" : "#171513"}" stroke-width="1.2" />
        <circle cx="${x.toFixed(2)}" cy="${yPlayers(point.opponentCount).toFixed(2)}" r="${point.isLive ? 4.6 : 3.5}" fill="${OPPONENT_COLOR}" stroke="${point.isLive ? "#fff" : "#171513"}" stroke-width="1.2" />
        <circle cx="${x.toFixed(2)}" cy="${yProbability(point.p2kWinProbability).toFixed(2)}" r="${point.isLive ? 4.2 : 3}" fill="${PROBABILITY_COLOR}" stroke="${point.isLive ? "#fff" : "#171513"}" stroke-width="1.2" />
        <rect class="p2k-history-hit" data-p2k-history-index="${row.sourceIndex}" x="${previousX.toFixed(2)}" y="${top}" width="${Math.max(1, nextX - previousX).toFixed(2)}" height="${plotHeight}" fill="transparent" tabindex="0" role="button" aria-label="${escapeAttribute(point.isLive ? "Current live lineup" : formatTimestamp(point.trackedAt))}. Open lineup evolution."><title>${escapeAttribute(tooltip)}</title></rect>
      </g>`;
    }).join("");

    const heading = large ? "" : `<div class="p2k-chart-heading"><div class="p2k-chart-title">Lineup evolution and win-probability history<button type="button" class="p2k-info-mark p2k-info-button" aria-expanded="false" aria-controls="p2kSharedInfoPopover" aria-label="Recruitment tracking graph information" data-p2k-info-message="Stored UTC snapshots are plotted on a real time axis and the final point is refreshed from the current live Chess.com match sheet. The green/red lines show registrations and the blue line shows modeled P2K win probability.">i</button></div><div class="p2k-history-chart-actions"><button class="p2k-chart-expand p2k-history-expand" type="button" data-p2k-history-expand>View larger</button></div></div>`;
    return `${heading}
      <div class="p2k-chart-wrap p2k-history-chart-wrap">
        <div class="p2k-chart-scroll">
          <svg class="p2k-chart-svg p2k-history-chart" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" role="img" aria-label="Registration history for ${escapeAttribute(homeName)} and ${escapeAttribute(opponentName)}, minimum required players, and P2K win probability">
            ${grid}
            <line x1="${left}" y1="${top + plotHeight}" x2="${width - right}" y2="${top + plotHeight}" stroke="rgba(255,255,255,.22)" stroke-width="1" />
            ${minimumPath}
            <path d="${homePath}" fill="none" stroke="${HOME_COLOR}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" />
            <path d="${opponentPath}" fill="none" stroke="${OPPONENT_COLOR}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" />
            <path d="${probabilityPath}" fill="none" stroke="${PROBABILITY_COLOR}" stroke-width="2" stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round" />
            ${pointGroups}
          </svg>
        </div>
        <div class="p2k-chart-legend">
          <span><i class="p2k-chart-swatch" style="background:${HOME_COLOR}"></i>${escapeHTML(homeName)} registrations</span>
          <span><i class="p2k-chart-swatch" style="background:${OPPONENT_COLOR}"></i>${escapeHTML(opponentName)} registrations</span>
          <span><i class="p2k-chart-swatch p2k-history-minimum-swatch"></i>Minimum required</span>
          <span><i class="p2k-chart-swatch p2k-history-probability-swatch"></i>P2K win probability</span>
          <span><i class="p2k-chart-swatch p2k-history-live-swatch"></i>Current live lineup</span>
        </div>
        <div class="p2k-chart-note">Real UTC timescale · final point is the current live lineup · hover for exact values · click a point for changes.</div>
        <div class="p2k-history-details-slot" aria-live="polite"></div>
      </div>`;
  }

  function playerIdentity(player) {
    return String(player?.username || player?.name || player?.user || "").trim().toLowerCase();
  }

  function playerRating(player) {
    const value = Number(player?.rating ?? player?.elo ?? player?.score);
    return Number.isFinite(value) && value > 0 ? Math.round(value) : null;
  }

  function timeoutRate(player) {
    for (const field of ["timeout_percent", "timeout_percentage", "timeout_rate", "timeout"]) {
      const value = Number(player?.[field]);
      if (!Number.isFinite(value) || value < 0) continue;
      return value;
    }
    return null;
  }

  function changedPlayers(currentPlayers, previousPlayers) {
    const current = new Map(currentPlayers.map(player => [playerIdentity(player), player]).filter(([identity]) => identity));
    const previous = new Map(previousPlayers.map(player => [playerIdentity(player), player]).filter(([identity]) => identity));
    return {
      added: [...current].filter(([identity]) => !previous.has(identity)).map(([, player]) => player),
      removed: [...previous].filter(([identity]) => !current.has(identity)).map(([, player]) => player),
      ratingChanged: [...current].filter(([identity, player]) => previous.has(identity) && playerRating(player) !== playerRating(previous.get(identity)))
        .map(([identity, player]) => ({ player, previous: previous.get(identity) }))
    };
  }

  function playerRows(changes) {
    const rows = [
      ...changes.added.map(player => ({ player, sign: "+", type: "added" })),
      ...changes.removed.map(player => ({ player, sign: "−", type: "removed" })),
      ...changes.ratingChanged.map(change => ({ ...change, sign: "↕", type: "rating" }))
    ];
    if (!rows.length) return '<tr><td class="p2k-history-empty" colspan="3">No lineup change</td></tr>';
    return rows.map(row => {
      const username = playerIdentity(row.player) || "unknown-player";
      const rating = playerRating(row.player);
      const previousRating = row.previous ? playerRating(row.previous) : null;
      const ratingText = row.type === "rating" ? `${previousRating ?? "—"} → ${rating ?? "—"}` : rating ?? "—";
      const timeout = timeoutRate(row.player);
      return `<tr class="p2k-history-player-${row.type}">
        <td><span class="p2k-history-sign">${row.sign}</span><a href="https://www.chess.com/member/${encodeURIComponent(username)}" target="_blank" rel="noopener noreferrer">${escapeHTML(username)}</a></td>
        <td>${ratingText}</td>
        <td>${timeout === null ? "—" : `${Math.round(timeout * 10) / 10}%`}</td>
      </tr>`;
    }).join("");
  }

  function teamPanel(team, previousTeam, baseline) {
    const changes = baseline ? { added: [], removed: [], ratingChanged: [] } : changedPlayers(team?.players || [], previousTeam?.players || []);
    const balance = changes.added.length - changes.removed.length;
    return `<section class="p2k-history-team-panel">
      <h3>${escapeHTML(team?.name || "Team")}</h3>
      ${baseline ? '<div class="p2k-history-baseline">Initial archive: no previous snapshot is available for comparison.</div>' : `<div class="p2k-history-table-wrap"><table class="p2k-history-change-table"><thead><tr><th>Player</th><th>Rating</th><th>Timeout</th></tr></thead><tbody>${playerRows(changes)}</tbody></table></div>`}
      <div class="p2k-history-totals">
        <div><strong>Added</strong><span>+${changes.added.length}</span></div>
        <div><strong>Removed</strong><span>−${changes.removed.length}</span></div>
        <div><strong>Balance</strong><span>${balance > 0 ? "+" : ""}${balance}</span></div>
      </div>
    </section>`;
  }

  function formatPlayerPlainText(player, sign) {
    const username = playerIdentity(player) || "unknown-player";
    const rating = playerRating(player);
    const timeout = timeoutRate(player);
    return `${sign} ${username} | Rating: ${rating ?? "—"} | Timeout: ${timeout === null ? "—" : `${Math.round(timeout * 10) / 10}%`}`;
  }

  function teamPlainText(team, previousTeam, baseline) {
    if (baseline) {
      return `${team?.name || "Team"}\nInitial archive: no previous snapshot is available for comparison.\nAdded: 0 | Removed: 0 | Balance: 0`;
    }
    const changes = changedPlayers(team?.players || [], previousTeam?.players || []);
    const rows = [
      ...changes.added.map(player => formatPlayerPlainText(player, "+")),
      ...changes.removed.map(player => formatPlayerPlainText(player, "−")),
      ...changes.ratingChanged.map(change => `↕ ${playerIdentity(change.player)} | Rating: ${playerRating(change.previous) ?? "—"} → ${playerRating(change.player) ?? "—"}`)
    ];
    const balance = changes.added.length - changes.removed.length;
    return [
      team?.name || "Team",
      ...(rows.length ? rows : ["No lineup change"]),
      `Added: ${changes.added.length} | Removed: ${changes.removed.length} | Rating changes: ${changes.ratingChanged.length} | Balance: ${balance > 0 ? "+" : ""}${balance}`
    ].join("\n");
  }

  function changesPlainText(point, previous, index, total) {
    return [
      "Lineup evolution",
      `${formatTimestamp(point.trackedAt)} | Recording ${index + 1} of ${total}`,
      "",
      ...point.teams.slice(0, 2).flatMap((team, teamIndex) => [
        teamPlainText(team, previous?.teams?.[teamIndex], index === 0),
        ""
      ])
    ].join("\n").trim();
  }

  async function copyPlainText(text) {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand("copy");
    textarea.remove();
    if (!copied) throw new Error("Copy is unavailable.");
  }

  function closeDetails(panel = openDetailsPanel) {
    if (!panel) return;
    panel.hidden = true;
    const host = panel.closest(".p2k-match-history");
    host?.querySelectorAll("[data-p2k-history-index][aria-expanded='true']").forEach(target => target.setAttribute("aria-expanded", "false"));
    if (openDetailsPanel === panel) openDetailsPanel = null;
  }

  function ensureDetailsPanel(host) {
    let panel = detailsPanels.get(host);
    if (panel) return panel;
    const slot = host.querySelector(".p2k-history-details-slot") || host.appendChild(document.createElement("div"));
    slot.classList.add("p2k-history-details-slot");
    panel = document.createElement("div");
    panel.className = "p2k-history-details-backdrop";
    panel.hidden = true;
    const titleId = `p2kHistoryDetailsTitle${++detailsPanelSequence}`;
    panel.innerHTML = `<div class="p2k-history-details-dialog" role="dialog" aria-modal="false" aria-labelledby="${titleId}">
      <div class="p2k-history-details-header">
        <div><h2 id="${titleId}" class="p2k-history-details-title">Lineup evolution</h2><p class="p2k-history-details-subtitle"></p></div>
        <div class="p2k-history-details-actions">
          <button type="button" class="p2k-history-details-nav" data-p2k-history-previous title="Show the previous recording">Previous</button>
          <button type="button" class="p2k-history-details-nav" data-p2k-history-next title="Show the next recording">Next</button>
          <button type="button" class="p2k-history-details-copy" title="Copy the displayed changes as plain text">Copy text</button>
          <button type="button" class="p2k-history-details-close">Close</button>
        </div>
      </div>
      <div class="p2k-history-details-columns"></div>
      <p class="p2k-history-details-note">Ratings and timeout rates are taken from the selected archived match sheet.</p>
    </div>`;
    slot.appendChild(panel);
    panel.querySelector(".p2k-history-details-close").addEventListener("click", () => closeDetails(panel));
    panel.querySelector("[data-p2k-history-previous]").addEventListener("click", () => {
      const points = panel._p2kPoints || [];
      openChanges(host, points, Math.max(0, Number(panel.dataset.index || 0) - 1), null);
    });
    panel.querySelector("[data-p2k-history-next]").addEventListener("click", () => {
      const points = panel._p2kPoints || [];
      openChanges(host, points, Math.min(points.length - 1, Number(panel.dataset.index || 0) + 1), null);
    });
    panel.querySelector(".p2k-history-details-copy").addEventListener("click", async event => {
      const button = event.currentTarget;
      const text = String(panel.dataset.plainText || "");
      if (!text) return;
      const original = button.textContent;
      try {
        await copyPlainText(text);
        button.textContent = "Copied";
        button.classList.add("p2k-history-copy-success");
      } catch (_) {
        button.textContent = "Copy failed";
        button.classList.add("p2k-history-copy-error");
      }
      window.setTimeout(() => {
        button.textContent = original;
        button.classList.remove("p2k-history-copy-success", "p2k-history-copy-error");
      }, 1700);
    });
    detailsPanels.set(host, panel);
    return panel;
  }

  function openChanges(host, points, index, trigger) {
    const point = points[index];
    if (!point) return;
    const previous = index > 0 ? points[index - 1] : null;
    const panel = ensureDetailsPanel(host);
    if (openDetailsPanel && openDetailsPanel !== panel) closeDetails(openDetailsPanel);
    panel.querySelector(".p2k-history-details-title").textContent = "Lineup evolution";
    panel.querySelector(".p2k-history-details-subtitle").textContent = `${point.isLive ? "Current live lineup" : formatTimestamp(point.trackedAt)} · Recording ${index + 1} of ${points.length}`;
    const probability = 100 * Number(point.p2kWinProbability || 0);
    const previousProbability = previous ? 100 * Number(previous.p2kWinProbability || 0) : null;
    const delta = previousProbability === null ? null : probability - previousProbability;
    const winRate = `<section class="p2k-history-winrate"><span>Modeled P2K win probability</span><strong>${probability.toFixed(1)}%</strong><small>${point.isLive ? "Current live model" : "Archived model"}${delta === null ? " · baseline" : ` · ${delta >= 0 ? "+" : ""}${delta.toFixed(1)} percentage points vs previous timeslot`}</small></section>`;
    panel.querySelector(".p2k-history-details-columns").innerHTML = winRate + point.teams.slice(0, 2).map((team, teamIndex) => teamPanel(team, previous?.teams?.[teamIndex], index === 0)).join("");
    panel.dataset.plainText = `${changesPlainText(point, previous, index, points.length)}\n\nModeled P2K win probability: ${probability.toFixed(1)}%${delta === null ? "" : ` (${delta >= 0 ? "+" : ""}${delta.toFixed(1)} pp vs previous)`}`;
    panel.dataset.index = String(index);
    panel._p2kPoints = points;
    panel.querySelector("[data-p2k-history-previous]").disabled = index <= 0;
    panel.querySelector("[data-p2k-history-next]").disabled = index >= points.length - 1;
    host.querySelectorAll("[data-p2k-history-index]").forEach(target => target.setAttribute("aria-expanded", "false"));
    trigger?.setAttribute("aria-expanded", "true");
    panel.hidden = false;
    openDetailsPanel = panel;
    requestAnimationFrame(() => {
      panel.querySelector(".p2k-history-details-close")?.focus({ preventScroll: true });
    });
  }


  function renderHistoryHost(host, points, { large = false } = {}) {
    host.innerHTML = chartHTML(points, { large, start: 0, end: points.length - 1 });
    bindPointActions(host, points, { large });
  }

  function openLargerChart(host, points, trigger) {
    const modal = window.P2K_UPCOMING_CHART_MODAL;
    if (!modal?.open || !Array.isArray(points) || !points.length) return;
    const homeName = String(points.at(-1)?.teams?.[0]?.name || points.at(-1)?.p2kName || "Promote to King");
    const opponentName = String(points.at(-1)?.teams?.[1]?.name || points.at(-1)?.opponentName || "Opponent");
    modal.open({
      titleHTML: `Lineup evolution — ${escapeHTML(homeName)} vs ${escapeHTML(opponentName)}<button type="button" class="p2k-info-mark p2k-info-button" aria-expanded="false" aria-controls="p2kSharedInfoPopover" aria-label="Recruitment tracking graph information" data-p2k-info-message="The enlarged graph uses a real UTC timescale and ends with the current live lineup. Hover or tap for values and select a timeslot for player changes.">i</button>`,
      bodyHTML: `<div class="p2k-match-history p2k-history-large-host"></div>`,
      trigger,
      afterRender: body => {
        const largeHost = body.querySelector(".p2k-history-large-host");
        if (largeHost) renderHistoryHost(largeHost, points, { large: true });
      }
    });
  }

  function bindPointActions(host, points, { large = false } = {}) {
    host.querySelector("[data-p2k-history-expand]")?.addEventListener("click", event => openLargerChart(host, points, event.currentTarget));
    host.querySelectorAll("[data-p2k-history-index]").forEach(target => {
      target.setAttribute("aria-expanded", "false");
      const open = () => openChanges(host, points, Number(target.dataset.p2kHistoryIndex), target);
      target.addEventListener("click", open);
      target.addEventListener("keydown", event => {
        if (event.key === "Enter" || event.key === " ") { event.preventDefault(); open(); }
      });
    });
  }

  async function hydrateHost(host) {
    if (!host || host.dataset.p2kHistoryLoaded === "true" || hostsInFlight.has(host)) return;
    const id = matchId(host.dataset.p2kMatchHistory);
    const summarize = window.P2K_MATCH_HISTORY_SUMMARIZER;
    if (!id || typeof summarize !== "function") return;
    hostsInFlight.add(host);
    try {
      const endpoint = window.P2K_SITE_CONFIG?.serverStorage?.matchHistoryEndpoint || "api/match-history/";
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set("match", id);
      let snapshots = [];
      try {
        const response = await fetch(url.href, { cache: "no-store", credentials: "same-origin" });
        if (response.ok) {
          const payload = await response.json();
          snapshots = Array.isArray(payload?.snapshots) ? payload.snapshots : [];
        }
      } catch (_) { /* Stored tracking history is optional. */ }

      let liveMatch = null;
      try {
        liveMatch = await window.P2K_API_CLIENT?.json?.(`https://api.chess.com/pub/match/${id}`, { cacheMode: "network-only", attempts: 2 });
      } catch (_) { /* The archived graph remains usable when live refresh is unavailable. */ }
      const definitionSource = snapshots.length ? snapshots : liveMatch ? [{ match: liveMatch }] : [];
      if (!definitionSource.length) return;
      const definitions = buildTeamDefinitions(definitionSource);
      const points = [];
      const addPoint = (match, trackedAt, isLive = false) => {
        try {
          const summary = summarize(match);
          if (!summary || !Number.isFinite(Number(summary.p2kWinProbability))) return;
          const teams = resolveTeams(match, definitions);
          points.push({
            trackedAt: String(trackedAt || ""), isLive,
            teams,
            p2kName: String(summary.p2kName || teams[0]?.name || "Promote to King"),
            opponentName: String(summary.opponentName || teams[1]?.name || "Opponent"),
            p2kCount: teams[0]?.players?.length ?? Math.max(0, Number(summary.p2kCount) || 0),
            opponentCount: teams[1]?.players?.length ?? Math.max(0, Number(summary.opponentCount) || 0),
            minPlayers: Math.max(0, Number(summary.minPlayers) || 0),
            p2kWinProbability: clamp(Number(summary.p2kWinProbability), 0, 1)
          });
        } catch (_) { /* Ignore an individual incompatible sheet. */ }
      };
      snapshots.forEach(snapshot => addPoint(snapshot.match, snapshot.trackedAt, false));
      const historicalPointCount = points.length;
      if (!historicalPointCount) return;
      if (liveMatch) addPoint(liveMatch, new Date().toISOString(), true);
      points.sort((a, b) => pointTimestamp(a) - pointTimestamp(b) || Number(a.isLive) - Number(b.isLive));
      const graphPoints = omitUnchangedLineups(points);
      if (!graphPoints.length) return;
      renderHistoryHost(host, graphPoints);
      host.hidden = false;
      host.dataset.p2kHistoryLoaded = "true";
    } catch (_) {
      /* Tracking is optional; absence or server errors must not affect analysis. */
    } finally {
      hostsInFlight.delete(host);
    }
  }

  document.addEventListener("keydown", event => {
    if (!openDetailsPanel) return;
    if (event.key === "Escape") { closeDetails(openDetailsPanel); return; }
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    const panel = openDetailsPanel;
    const host = panel.closest(".p2k-match-history");
    const points = panel._p2kPoints || [];
    const current = Number(panel.dataset.index || 0);
    const next = event.key === "ArrowLeft" ? current - 1 : current + 1;
    if (!host || next < 0 || next >= points.length) return;
    event.preventDefault();
    openChanges(host, points, next, null);
  });

  function hydrate(root = document) {
    root.querySelectorAll?.("[data-p2k-match-history]").forEach(host => { void hydrateHost(host); });
  }

  window.P2K_MATCH_HISTORY_UI = Object.freeze({ placeholderHTML, hydrate });
})();
