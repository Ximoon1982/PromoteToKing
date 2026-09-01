/* Promote to King v2.10.9.8: Recruitment admin + Members Insights table alignment. */
(() => {
  "use strict";

  const VERSION = "2.10.9.8";
  const $ = id => document.getElementById(id);
  const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));
  const number = value => {
    if (value === null || value === undefined || value === "") return null;
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
  };
  const text = value => String(value ?? "");
  const escapeHTML = value => text(value).replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]));

  function recruitmentEndpoint(action = "state") {
    const configured = window.P2K_SITE_CONFIG?.serverStorage?.recruitmentAdminEndpoint || "server/team-points/public/recruitment-admin.php";
    const url = new URL(configured, window.location.href);
    url.searchParams.set("action", action);
    return url;
  }

  function fixMembersInsightsHeader() {
    const root = document.getElementById("membersDataTable");
    if (!root) return;
    const winRate = root.querySelector('thead th[data-key="win_rate"]');
    if (!winRate || root.querySelector('thead th[data-key="result_coverage_percent"]')) return;
    const coverage = document.createElement("th");
    coverage.dataset.key = "result_coverage_percent";
    coverage.textContent = "Result coverage";
    winRate.before(coverage);
  }

  async function server(action, { method = "GET", body = null } = {}) {
    const response = await fetch(recruitmentEndpoint(action), {
      method,
      credentials: "same-origin",
      headers: body === null ? { Accept: "application/json" } : { Accept: "application/json", "Content-Type": "application/json" },
      body: body === null ? undefined : JSON.stringify(body),
      cache: "no-store"
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false) {
      const error = new Error(payload?.error?.message || `Recruitment server request failed (${response.status}).`);
      error.code = payload?.error?.code || "SERVER_REQUEST_FAILED";
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function addRecruitmentStyles() {
    if (document.getElementById("p2kRecruitmentStyles")) return;
    const style = document.createElement("style");
    style.id = "p2kRecruitmentStyles";
    style.textContent = `
      .p2k-recruitment-two{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:12px}
      .p2k-recruitment-fieldset{min-width:0;margin:0;padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:8px}
      .p2k-recruitment-fieldset legend{padding:0 6px;color:#f2dfbf;font-weight:700}
      .p2k-recruitment-field{display:grid;gap:5px;margin:0 0 9px}.p2k-recruitment-field label{font-size:12px;color:#cfc6bb;font-weight:700}
      .p2k-recruitment-input,.p2k-recruitment-textarea{width:100%;box-sizing:border-box;border:1px solid rgba(255,255,255,.15);border-radius:6px;background:#151311;color:#f1eee9;padding:8px 9px}
      .p2k-recruitment-textarea{min-height:176px;resize:vertical;line-height:1.35}
      .p2k-recruitment-criteria{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 10px}
      .p2k-recruitment-checks{display:grid;gap:7px;margin-top:5px}.p2k-recruitment-checks label{font-size:12px;color:#cfc6bb}
      .p2k-recruitment-help{margin-top:6px;color:#9e958b;font-size:11px;line-height:1.45}
      .p2k-recruitment-saved{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;font-size:12px;color:#cfc6bb}
      .p2k-recruitment-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
      .p2k-recruitment-summary>div{padding:9px 10px;border:1px solid rgba(255,255,255,.10);border-radius:7px;background:rgba(0,0,0,.12)}
      .p2k-recruitment-summary span{display:block;color:#a9a096;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.p2k-recruitment-summary strong{display:block;margin-top:2px;color:#f6b73c;font-size:20px}
      .p2k-recruitment-decision{display:inline-block;padding:2px 7px;border-radius:999px;border:1px solid rgba(255,255,255,.17);font-size:11px}.p2k-recruitment-decision.is-selected{color:#83d99a;border-color:#3e8150}.p2k-recruitment-decision.is-rejected{color:#ff9586;border-color:#8f463d}
      .p2k-recruitment-progress{height:7px;margin-top:10px;overflow:hidden;border-radius:999px;background:rgba(255,255,255,.08)}.p2k-recruitment-progress span{display:block;width:0;height:100%;background:#d98d18;transition:width .2s ease}
      #recruitmentRows td:first-child,#recruitmentRows td:last-child{text-align:left}.p2k-recruitment-table th,.p2k-recruitment-table td{white-space:nowrap}.p2k-recruitment-table td:last-child{white-space:normal;min-width:240px}
      @media(max-width:900px){.p2k-recruitment-two{grid-template-columns:1fr}}
      @media(max-width:620px){.p2k-recruitment-criteria,.p2k-recruitment-summary{grid-template-columns:1fr}.p2k-recruitment-saved{align-items:flex-start;flex-direction:column}}
    `;
    document.head.appendChild(style);
  }

  function recruitmentMarkup() {
    return `
      <div class="p2k-tp-card-head">
        <div><h2>Recruitment</h2><p>Maintain a server-side candidate pool, define eligibility criteria, and run a parallel browser-side Chess.com scan. The criteria and candidate set are snapshotted when a run starts, and every checked candidate is checkpointed so a later session resumes only the pending names.</p></div>
        <span class="p2k-tp-pill">Server list · resumable scan</span>
      </div>
      <div class="p2k-recruitment-two">
        <fieldset class="p2k-recruitment-fieldset">
          <legend>Candidate pool</legend>
          <div class="p2k-recruitment-saved"><div><strong>Saved server candidate list</strong><div id="recruitmentSavedStatus">No saved list loaded.</div></div><button class="p2k-tp-button" id="recruitmentLoadPool" type="button">Load saved list</button></div>
          <div class="p2k-recruitment-field"><label for="recruitmentCandidates">Paste usernames or Chess.com player URLs</label><textarea class="p2k-recruitment-textarea" id="recruitmentCandidates" spellcheck="false" placeholder="candidate_alpha&#10;https://www.chess.com/member/candidate_beta&#10;candidate_gamma"></textarea></div>
          <div class="p2k-recruitment-help">One per line. Usernames are normalized case-insensitively, duplicate entries are removed, and original list order is preserved.</div>
          <div class="p2k-tp-actions"><button class="p2k-tp-button primary" id="recruitmentSavePool" type="button">Save candidate list</button><button class="p2k-tp-button" id="recruitmentClearEditor" type="button">Clear editor</button></div>
        </fieldset>
        <fieldset class="p2k-recruitment-fieldset">
          <legend>Eligibility settings</legend>
          <div class="p2k-recruitment-criteria">
            <div class="p2k-recruitment-field"><label for="recruitmentMinRating">Minimum Daily rating</label><input class="p2k-recruitment-input" id="recruitmentMinRating" type="number" min="0" value="1300"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxRating">Maximum Daily rating</label><input class="p2k-recruitment-input" id="recruitmentMaxRating" type="number" min="0" placeholder="Doesn't matter"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxTimeout">Maximum timeout rate (%)</label><input class="p2k-recruitment-input" id="recruitmentMaxTimeout" type="number" min="0" step="0.1" value="8"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxRd">Maximum rating deviation (RD)</label><input class="p2k-recruitment-input" id="recruitmentMaxRd" type="number" min="0" value="100"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMinGames">Current Daily games · minimum</label><input class="p2k-recruitment-input" id="recruitmentMinGames" type="number" min="0" value="2"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxGames">Current Daily games · maximum</label><input class="p2k-recruitment-input" id="recruitmentMaxGames" type="number" min="0" value="25"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMinCompleted">Minimum completed Daily games</label><input class="p2k-recruitment-input" id="recruitmentMinCompleted" type="number" min="0" value="50"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxOffline">Last online · maximum days ago</label><input class="p2k-recruitment-input" id="recruitmentMaxOffline" type="number" min="0" value="14"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMaxSpm">Average seconds per move · maximum</label><input class="p2k-recruitment-input" id="recruitmentMaxSpm" type="number" min="0" placeholder="Doesn't matter"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentMinAge">Minimum account age (days)</label><input class="p2k-recruitment-input" id="recruitmentMinAge" type="number" min="0" placeholder="Doesn't matter"></div>
            <div class="p2k-recruitment-field"><label for="recruitmentWorkers">Parallel candidates</label><input class="p2k-recruitment-input" id="recruitmentWorkers" type="number" min="1" max="12" value="4"></div>
          </div>
          <div class="p2k-recruitment-checks">
            <label><input type="checkbox" checked disabled> Exclude players already stored as current P2K members.</label>
            <label><input id="recruitmentExcludeFormer" type="checkbox"> Exclude former P2K members as well.</label>
            <label><input type="checkbox" checked disabled> Reject closed / unavailable accounts automatically.</label>
          </div>
          <div class="p2k-recruitment-help">Empty numeric fields mean “doesn’t matter”. A resumed run always keeps its original criteria snapshot even if the editor has since changed.</div>
        </fieldset>
      </div>
      <div class="p2k-tp-actions">
        <button class="p2k-tp-button primary" id="recruitmentStart" type="button">Start / resume scan</button>
        <button class="p2k-tp-button blue" id="recruitmentPause" type="button" disabled>Pause</button>
        <button class="p2k-tp-button danger" id="recruitmentRestart" type="button">Restart scan</button>
        <button class="p2k-tp-button" id="recruitmentCsv" type="button" disabled>Download selected CSV</button>
      </div>
      <div class="p2k-recruitment-summary">
        <div><span>Candidates</span><strong id="recruitmentMetricTotal">0</strong></div>
        <div><span>Checked</span><strong id="recruitmentMetricChecked">0</strong></div>
        <div><span>Selected</span><strong id="recruitmentMetricSelected">0</strong></div>
        <div><span>Errors / unavailable</span><strong id="recruitmentMetricErrors">0</strong></div>
      </div>
      <div class="p2k-recruitment-progress"><span id="recruitmentProgressBar"></span></div>
      <div class="p2k-tp-status" id="recruitmentStatus">Ready. Save the candidate pool, then start a scan.</div>
      <div class="p2k-tp-table-wrap p2k-recruitment-table">
        <table><thead><tr><th>Candidate</th><th>Decision</th><th>Daily rating</th><th>Timeout %</th><th>Current games</th><th>Completed games</th><th>Last online</th><th>Account age</th><th>P2K state</th><th>Reason</th></tr></thead><tbody id="recruitmentRows"><tr><td colspan="10">No scan results yet.</td></tr></tbody></table>
      </div>
      <p class="p2k-recruitment-help"><strong>Data path:</strong> Chess.com profile supplies account state, joined and last-online timestamps; Daily stats supply rating, RD, completed record, timeout percentage and average move time when available; current Daily games supply the ongoing-game count. P2K current/former membership is resolved authoritatively from the Team Points member database when each result is checkpointed.</p>
    `;
  }

  let adminState = { pool: null, run: null };
  let activeScan = false;
  let scanGeneration = 0;

  function setStatus(message, error = false) {
    const host = $("recruitmentStatus");
    if (!host) return;
    host.textContent = message;
    host.classList.toggle("is-error", error);
  }

  function setPoolStatus(pool) {
    const host = $("recruitmentSavedStatus");
    if (!host) return;
    const count = Array.isArray(pool?.candidates) ? pool.candidates.length : 0;
    host.textContent = count ? `${count} normalized unique username${count === 1 ? "" : "s"} · revision ${Number(pool?.revision || 0)}` : "No saved candidate list.";
  }

  function displayDays(value) {
    const numeric = number(value);
    if (numeric === null) return "—";
    if (numeric < 1) return "today";
    return `${Math.round(numeric)} d`;
  }

  function renderRun(run = adminState.run) {
    adminState.run = run && Object.keys(run).length ? run : null;
    const summary = adminState.run?.summary || {};
    const total = Number(summary.total || 0), checked = Number(summary.checked || 0), selected = Number(summary.selected || 0), errors = Number(summary.errors || 0);
    if ($("recruitmentMetricTotal")) $("recruitmentMetricTotal").textContent = String(total);
    if ($("recruitmentMetricChecked")) $("recruitmentMetricChecked").textContent = String(checked);
    if ($("recruitmentMetricSelected")) $("recruitmentMetricSelected").textContent = String(selected);
    if ($("recruitmentMetricErrors")) $("recruitmentMetricErrors").textContent = String(errors);
    if ($("recruitmentProgressBar")) $("recruitmentProgressBar").style.width = `${total ? Math.min(100, checked * 100 / total) : 0}%`;
    if ($("recruitmentCsv")) $("recruitmentCsv").disabled = selected < 1;
    if ($("recruitmentPause")) $("recruitmentPause").disabled = !activeScan;
    if ($("recruitmentStart")) $("recruitmentStart").disabled = activeScan;

    const tbody = $("recruitmentRows");
    if (!tbody) return;
    const rows = Array.isArray(adminState.run?.results) ? adminState.run.results : [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="10">No scan results yet.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(row => {
      const data = row?.data || {};
      const decision = row?.selected ? "Selected" : "Rejected";
      return `<tr><td>${escapeHTML(row?.username)}</td><td><span class="p2k-recruitment-decision ${row?.selected ? "is-selected" : "is-rejected"}">${decision}</span></td><td>${data.daily_rating ?? "—"}</td><td>${data.timeout_percent ?? "—"}</td><td>${data.current_daily_games ?? "—"}</td><td>${data.completed_daily_games ?? "—"}</td><td>${displayDays(data.last_online_days)}</td><td>${displayDays(data.account_age_days)}</td><td>${escapeHTML(data.p2k_state || "none")}</td><td>${escapeHTML(row?.reason || "—")}</td></tr>`;
    }).join("");
  }

  function applyCriteria(criteria = {}) {
    const fields = [
      ["recruitmentMinRating", "minRating"], ["recruitmentMaxRating", "maxRating"], ["recruitmentMaxTimeout", "maxTimeout"],
      ["recruitmentMaxRd", "maxRd"], ["recruitmentMinGames", "minGames"], ["recruitmentMaxGames", "maxGames"],
      ["recruitmentMinCompleted", "minCompleted"], ["recruitmentMaxOffline", "maxOffline"], ["recruitmentMaxSpm", "maxSpm"],
      ["recruitmentMinAge", "minAge"], ["recruitmentWorkers", "parallelWorkers"]
    ];
    fields.forEach(([id, key]) => {
      const node = $(id);
      if (node && Object.prototype.hasOwnProperty.call(criteria, key)) node.value = criteria[key] === null ? "" : String(criteria[key]);
    });
    if ($("recruitmentExcludeFormer") && Object.prototype.hasOwnProperty.call(criteria, "excludeFormer")) {
      $("recruitmentExcludeFormer").checked = Boolean(criteria.excludeFormer);
    }
  }

  function criteriaFromForm() {
    const numeric = id => {
      const raw = $(id)?.value?.trim() ?? "";
      return raw === "" ? null : Number(raw);
    };
    return {
      minRating: numeric("recruitmentMinRating"), maxRating: numeric("recruitmentMaxRating"), maxTimeout: numeric("recruitmentMaxTimeout"), maxRd: numeric("recruitmentMaxRd"),
      minGames: numeric("recruitmentMinGames"), maxGames: numeric("recruitmentMaxGames"), minCompleted: numeric("recruitmentMinCompleted"), maxOffline: numeric("recruitmentMaxOffline"),
      maxSpm: numeric("recruitmentMaxSpm"), minAge: numeric("recruitmentMinAge"), excludeFormer: Boolean($("recruitmentExcludeFormer")?.checked),
      parallelWorkers: Math.max(1, Math.min(12, Number($("recruitmentWorkers")?.value || 4)))
    };
  }

  async function loadState({ populateEditor = true } = {}) {
    const payload = await server("state");
    adminState.pool = payload.pool || { candidates: [] };
    adminState.run = payload.run && Object.keys(payload.run).length ? payload.run : null;
    setPoolStatus(adminState.pool);
    if (populateEditor && $("recruitmentCandidates")) $("recruitmentCandidates").value = (adminState.pool.candidates || []).join("\n");
    if (adminState.run?.criteria) applyCriteria(adminState.run.criteria);
    renderRun();
    const status = adminState.run?.status;
    if (status === "completed") setStatus(`Completed · ${adminState.run.summary?.checked || 0} candidates checked · ${adminState.run.summary?.selected || 0} selected.`);
    else if (status === "paused") setStatus(`Paused · ${adminState.run.summary?.pending || 0} candidates remain. Start / resume continues only pending candidates.`);
    else if (status === "running") setStatus(`Checkpoint recovered · ${adminState.run.summary?.pending || 0} candidates remain. Start / resume continues the run.`);
    return payload;
  }

  async function chessJSON(url, attempts = 3) {
    const client = window.P2K_API_CLIENT;
    if (client && typeof client.json === "function") {
      return client.json(url, { attempts, cacheMode: "network-only", trafficClass: "foreground" });
    }
    let lastError = null;
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      try {
        const response = await fetch(url, { mode: "cors", headers: { Accept: "application/json" }, cache: "no-store" });
        if (response.ok) return await response.json();
        const error = new Error(`Chess.com returned HTTP ${response.status}.`);
        error.status = response.status;
        if (![429, 500, 502, 503, 504].includes(response.status) || attempt >= attempts) throw error;
        lastError = error;
      } catch (error) {
        lastError = error;
        if (attempt >= attempts) throw error;
      }
      await sleep(350 * attempt);
    }
    throw lastError || new Error("Chess.com request failed.");
  }

  function daysSinceUnix(value) {
    const timestamp = number(value);
    return timestamp !== null && timestamp > 0 ? Math.max(0, (Date.now() / 1000 - timestamp) / 86400) : null;
  }

  async function loadMembershipStates(usernames) {
    const map = new Map();
    const chunks = [];
    for (let index = 0; index < usernames.length; index += 75) chunks.push(usernames.slice(index, index + 75));
    for (const chunk of chunks) {
      if (!activeScan) break;
      const url = new URL("server/team-points/public/members-insights.php", window.location.href);
      url.searchParams.set("section", "table");
      url.searchParams.set("filter", "all");
      url.searchParams.set("page", "1");
      url.searchParams.set("page_size", "100");
      url.searchParams.set("usernames", chunk.join(","));
      try {
        const response = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" }, cache: "no-store" });
        const payload = await response.json();
        if (!response.ok || payload?.ok === false) throw new Error(payload?.error?.message || "Member database lookup failed.");
        (payload.rows || []).forEach(row => map.set(text(row.username).toLowerCase(), row.current_member ? "current" : "former"));
      } catch (error) {
        console.warn("P2K recruitment membership precheck failed; the server checkpoint remains authoritative.", error);
      }
    }
    usernames.forEach(username => {
      const key = username.toLowerCase();
      if (!map.has(key)) map.set(key, "none");
    });
    return map;
  }

  async function scanCandidate(username, p2kState) {
    const base = `https://api.chess.com/pub/player/${encodeURIComponent(username)}`;
    let profile;
    try {
      profile = await chessJSON(base);
    } catch (error) {
      if ([404, 410].includes(Number(error?.status))) return { username, data: { closed: true, p2k_state: p2kState, error: "" } };
      return { username, data: { closed: false, p2k_state: p2kState, error: error?.message || "Profile unavailable." } };
    }

    const closed = /closed/i.test(text(profile?.status));
    const common = {
      p2k_state: p2kState,
      last_online_days: daysSinceUnix(profile?.last_online),
      account_age_days: daysSinceUnix(profile?.joined)
    };
    if (closed) return { username, data: { ...common, closed: true, error: "" } };

    let stats, games;
    try {
      [stats, games] = await Promise.all([chessJSON(`${base}/stats`), chessJSON(`${base}/games`)]);
    } catch (error) {
      return { username, data: { ...common, closed: false, error: error?.message || "Stats or current games unavailable." } };
    }

    const daily = stats?.chess_daily || {};
    const record = daily?.record || {};
    const completed = [record.win, record.loss, record.draw].reduce((sum, value) => sum + (number(value) ?? 0), 0);
    const currentGames = Array.isArray(games?.games) ? games.games.length : null;
    return {
      username,
      data: {
        ...common,
        daily_rating: number(daily?.last?.rating ?? daily?.best?.rating),
        timeout_percent: number(record?.timeout_percent),
        current_daily_games: currentGames,
        daily_rd: number(daily?.last?.rd),
        completed_daily_games: completed,
        avg_seconds_per_move: number(record?.time_per_move),
        closed: false,
        error: ""
      }
    };
  }

  async function runScan(run) {
    const generation = ++scanGeneration;
    activeScan = true;
    renderRun(run);
    const checked = new Set((run?.results || []).map(row => text(row.username).toLowerCase()));
    const pending = (run?.candidates || []).filter(username => !checked.has(text(username).toLowerCase()));
    if (!pending.length) {
      activeScan = false;
      await loadState({ populateEditor: false });
      return;
    }

    setStatus(`Preparing ${pending.length} pending candidate${pending.length === 1 ? "" : "s"}…`);
    const membership = await loadMembershipStates(pending);
    if (!activeScan || generation !== scanGeneration) return;
    const queue = pending.slice();
    const workers = Math.max(1, Math.min(12, Number(run?.criteria?.parallelWorkers || 4)));
    setStatus(`Scan running · ${workers} parallel candidate${workers === 1 ? "" : "s"} · each result is checkpointed on the server.`);

    async function worker() {
      while (activeScan && generation === scanGeneration) {
        const username = queue.shift();
        if (!username) return;
        const result = await scanCandidate(username, membership.get(text(username).toLowerCase()) || "none");
        if (!activeScan || generation !== scanGeneration) return;
        try {
          const payload = await server("checkpoint", {
            method: "POST",
            body: { runId: run.id, results: [result] }
          });
          renderRun(payload.run);
          if (payload.run?.status === "completed") {
            activeScan = false;
            return;
          }
        } catch (error) {
          if (!activeScan || generation !== scanGeneration) return;
          if (error?.code === "RUN_CHANGED" || error?.code === "RUN_COMPLETED") {
            activeScan = false;
            await loadState({ populateEditor: false }).catch(() => {});
            return;
          }
          setStatus(`Checkpoint failed for ${username}: ${error.message || error}`, true);
          activeScan = false;
          return;
        }
        await sleep(150);
      }
    }

    await Promise.all(Array.from({ length: Math.min(workers, pending.length) }, () => worker()));
    if (generation !== scanGeneration) return;
    activeScan = false;
    renderRun();
    await loadState({ populateEditor: false }).catch(error => setStatus(error.message || String(error), true));
  }

  async function startScan() {
    if (activeScan) return;
    try {
      setStatus("Starting or resuming the server checkpoint…");
      const payload = await server("start", { method: "POST", body: { criteria: criteriaFromForm() } });
      adminState.run = payload.run;
      if (payload.run?.criteria) applyCriteria(payload.run.criteria);
      renderRun(payload.run);
      await runScan(payload.run);
    } catch (error) {
      activeScan = false;
      renderRun();
      setStatus(error.message || String(error), true);
    }
  }

  async function pauseScan() {
    scanGeneration += 1;
    activeScan = false;
    renderRun();
    try {
      const payload = await server("pause", { method: "POST", body: {} });
      renderRun(payload.run);
      setStatus(`Paused · ${payload.run?.summary?.pending || 0} candidates remain. Start / resume continues only pending candidates.`);
    } catch (error) {
      setStatus(error.message || String(error), true);
    }
  }

  function bindRecruitment() {
    $("recruitmentLoadPool")?.addEventListener("click", async () => {
      try { await loadState({ populateEditor: true }); }
      catch (error) { setStatus(error.message || String(error), true); }
    });
    $("recruitmentClearEditor")?.addEventListener("click", () => {
      if ($("recruitmentCandidates")) $("recruitmentCandidates").value = "";
    });
    $("recruitmentSavePool")?.addEventListener("click", async () => {
      try {
        const payload = await server("save-pool", { method: "POST", body: { candidates: $("recruitmentCandidates")?.value || "" } });
        adminState.pool = payload.pool;
        setPoolStatus(payload.pool);
        $("recruitmentCandidates").value = (payload.pool?.candidates || []).join("\n");
        setStatus(`Candidate pool saved · ${payload.pool?.candidates?.length || 0} normalized unique usernames.`);
      } catch (error) {
        setStatus(error.message || String(error), true);
      }
    });
    $("recruitmentStart")?.addEventListener("click", startScan);
    $("recruitmentPause")?.addEventListener("click", pauseScan);
    $("recruitmentRestart")?.addEventListener("click", async () => {
      if (!window.confirm("Clear the current Recruitment run checkpoint? The saved candidate pool will be kept.")) return;
      scanGeneration += 1;
      activeScan = false;
      try {
        await server("restart", { method: "POST", body: {} });
        adminState.run = null;
        renderRun(null);
        setStatus("Run checkpoint cleared. The saved candidate pool is unchanged.");
      } catch (error) {
        setStatus(error.message || String(error), true);
      }
    });
    $("recruitmentCsv")?.addEventListener("click", () => {
      const anchor = document.createElement("a");
      anchor.href = recruitmentEndpoint("csv").href;
      anchor.download = "p2k-recruitment-selected.csv";
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
    });
  }

  function mountRecruitment() {
    const panel = document.querySelector('section[data-panel="members"]');
    if (!panel || document.getElementById("p2kRecruitmentAdmin")) return;
    addRecruitmentStyles();
    const card = document.createElement("section");
    card.className = "p2k-tp-card";
    card.id = "p2kRecruitmentAdmin";
    card.dataset.release = VERSION;
    card.innerHTML = recruitmentMarkup();
    panel.appendChild(card);
    bindRecruitment();
    loadState().catch(error => setStatus(`Unable to load Recruitment state: ${error.message || error}`, true));
  }

  function mount() {
    fixMembersInsightsHeader();
    mountRecruitment();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, { once: true });
  else mount();
})();
