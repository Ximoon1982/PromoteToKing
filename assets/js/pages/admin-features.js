/* Administration, logs, manual tracking progress, and match-data management.
   Built locally on the v2.1.2 self-contained baseline. */
(() => {
  "use strict";
  const byId = id => document.getElementById(id);
  const escapeHTML = value => String(value ?? "").replace(/[&<>"']/g, character => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
  })[character]);
  const writeHeaders = value => ({ "X-Club-Tools-Request": value });
  const adminModal = byId("adminModal");
  const adminOpen = byId("adminOpen");
  if (!adminModal || !adminOpen) return;
  let adminFeaturesInitialized = false;

  function initializeAdminFeatures() {
    if (adminFeaturesInitialized || window.P2K_ADMIN_MODE !== true) return;
    adminFeaturesInitialized = true;
    adminOpen.hidden = false;

  function activeAdminTab() {
    return document.querySelector('.admin-tab[aria-selected="true"]')?.dataset.adminTab || "logs";
  }

  function activeLogTab() {
    return document.querySelector('.subtab[aria-selected="true"]')?.dataset.logTab || "match";
  }

  function refreshActiveLogs() {
    return activeLogTab() === "scheduled" ? loadTaskLogs() : loadMatchLogs();
  }

  function openAdmin() {
    adminModal.hidden = false;
    adminModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    refreshActiveLogs();
  }

  function closeAdmin() {
    adminModal.hidden = true;
    adminModal.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }

  adminOpen.addEventListener("click", openAdmin);
  byId("adminClose").addEventListener("click", closeAdmin);
  adminModal.addEventListener("click", event => {
    if (event.target === adminModal) closeAdmin();
  });

  function installTabs(buttonSelector, panelSelector, buttonKey, panelKey) {
    const buttons = Array.from(document.querySelectorAll(buttonSelector));
    const panels = Array.from(document.querySelectorAll(panelSelector));
    const select = (button, focus = false) => {
      const selectedValue = button.dataset[buttonKey];
      buttons.forEach(candidate => {
        const active = candidate === button;
        candidate.setAttribute("aria-selected", String(active));
        candidate.tabIndex = active ? 0 : -1;
      });
      panels.forEach(panel => { panel.hidden = panel.dataset[panelKey] !== selectedValue; });
      if (selectedValue === "logs") refreshActiveLogs();
      if (selectedValue === "match") loadMatchLogs();
      if (selectedValue === "scheduled") loadTaskLogs();
      if (selectedValue === "diagnostics") refreshDiagnostics();
      if (selectedValue === "management") loadMatchManagement();
      if (focus) button.focus();
    };
    buttons.forEach((button, index) => {
      button.addEventListener("click", () => select(button));
      button.addEventListener("keydown", event => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        let target = index;
        if (event.key === "ArrowRight") target = (index + 1) % buttons.length;
        else if (event.key === "ArrowLeft") target = (index - 1 + buttons.length) % buttons.length;
        else if (event.key === "Home") target = 0;
        else if (event.key === "End") target = buttons.length - 1;
        select(buttons[target], true);
      });
    });
  }
  installTabs(".admin-tab", ".admin-panel", "adminTab", "adminPanel");
  window.addEventListener("message", event => {
    if (event.origin !== window.location.origin || event.data?.type !== "p2k-frame-height") return;
    const frame = byId("tournamentManagementFrame");
    if (frame?.contentWindow !== event.source) return;
    frame.style.height = `${Math.max(420, Math.min(12000, Number(event.data.height) || 0))}px`;
  });
  installTabs(".subtab", ".subpanel", "logTab", "logPanel");
  const initialAdminParams = new URLSearchParams(window.location.search);
  const requestedAdminTab = initialAdminParams.get("adminTab");
  const requestedLogTab = initialAdminParams.get("logTab");
  if (requestedAdminTab) document.querySelector(`.admin-tab[data-admin-tab="${CSS.escape(requestedAdminTab)}"]`)?.click();
  if (requestedLogTab) document.querySelector(`.subtab[data-log-tab="${CSS.escape(requestedLogTab)}"]`)?.click();

  function isoDate(dateValue) { return dateValue.toISOString().slice(0, 10); }
  const today = new Date();
  const weekAgo = new Date(Date.now() - 6 * 864e5);
  ["matchLogTo", "taskLogTo"].forEach(id => { byId(id).value = isoDate(today); });
  ["matchLogFrom", "taskLogFrom"].forEach(id => { byId(id).value = isoDate(weekAgo); });

  async function fetchJSON(url, options = {}) {
    const { headers = {}, ...requestOptions } = options;
    const method = String(requestOptions.method || "GET").toUpperCase();
    if (method !== "GET" && window.P2K_TEAM_POINTS_CLIENT?.endpointRequest) {
      let body = requestOptions.body ?? null;
      if (typeof body === "string" && body) { try { body = JSON.parse(body); } catch (_) {} }
      return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(url, { method, body });
    }
    const response = await fetch(url, {
      ...requestOptions,
      cache: "no-store",
      headers: { Accept: "application/json", ...headers }
    });
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      throw new Error(`Server returned non-JSON content (HTTP ${response.status}).`);
    }
    if (!response.ok || data?.ok === false) {
      throw new Error(data?.error?.message || `HTTP ${response.status}`);
    }
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      throw new Error("Server returned an empty or invalid JSON object.");
    }
    return data;
  }

  function metric(label, value) {
    return `<div class="metric"><strong>${escapeHTML(label)}</strong><span>${escapeHTML(value)}</span></div>`;
  }

  function feedback(id, message, type = "") {
    const element = byId(id);
    element.textContent = message;
    element.className = `feedback${type ? ` ${type}` : ""}`;
  }

  async function loadMatchLogs() {
    feedback("matchLogFeedback", "Loading…");
    try {
      const query = new URLSearchParams({
        from: byId("matchLogFrom").value,
        to: byId("matchLogTo").value,
        user: byId("matchLogUser").value.trim()
      });
      const data = await fetchJSON(`api/match-assistant-logs/?${query}`);
      const summary = data?.summary || {};
      byId("matchLogSummary").innerHTML = metric("Analyses", summary.analyses || 0)
        + metric("Matches found", summary.matchesFound || 0)
        + metric("Different usernames", summary.distinctUsers || 0)
        + metric("Invalid lines", data.invalidLines || 0);
      byId("matchLogDaily").innerHTML = (data.daily || []).map(row => `
        <tr><td>${escapeHTML(row.date)}</td><td class="num">${row.analyses}</td><td class="num">${row.matchesFound}</td><td class="num">${row.distinctUsers}</td></tr>
      `).join("") || '<tr><td colspan="4">No entries.</td></tr>';
      byId("matchLogUsers").innerHTML = (data.users || []).map(row => `
        <tr><td><a target="_blank" rel="noopener" href="https://www.chess.com/member/${encodeURIComponent(row.username)}">${escapeHTML(row.username)}</a></td><td class="num">${row.analyses}</td><td class="num">${row.matchesFound}</td></tr>
      `).join("") || '<tr><td colspan="3">No entries.</td></tr>';
      byId("matchLogEntries").innerHTML = (Array.isArray(data.entries) ? data.entries : []).map(row => `
        <tr><td>${escapeHTML(formatDateTime(row.timestamp))}</td><td><a target="_blank" rel="noopener" href="https://www.chess.com/member/${encodeURIComponent(row.username)}">${escapeHTML(row.username)}</a></td><td class="num">${Number(row.matchesFound) || 0}</td></tr>
      `).join("") || '<tr><td colspan="3">No matching entries.</td></tr>';
      feedback("matchLogFeedback", data.truncated ? "The entry list was safely truncated; aggregates remain complete." : "Loaded.", "success");
    } catch (error) {
      feedback("matchLogFeedback", error.message, "error");
    }
  }
  function resetSevenDayRange(fromId, toId) {
    byId(toId).value = isoDate(new Date());
    byId(fromId).value = isoDate(new Date(Date.now() - 6 * 864e5));
  }

  function extendRangeBack(fromId, loader) {
    const current = new Date(`${byId(fromId).value}T00:00:00Z`);
    if (!Number.isFinite(current.getTime())) return;
    current.setUTCDate(current.getUTCDate() - 7);
    byId(fromId).value = current.toISOString().slice(0, 10);
    loader();
  }

  byId("matchLogApply").addEventListener("click", loadMatchLogs);
  byId("matchLogRefresh").addEventListener("click", loadMatchLogs);
  byId("matchLogUser").addEventListener("keydown", event => { if (event.key === "Enter") loadMatchLogs(); });
  byId("matchLogReset").addEventListener("click", () => { resetSevenDayRange("matchLogFrom", "matchLogTo"); byId("matchLogUser").value = ""; loadMatchLogs(); });
  byId("matchLogMore").addEventListener("click", () => extendRangeBack("matchLogFrom", loadMatchLogs));

  async function loadTaskLogs() {
    feedback("taskLogFeedback", "Loading…");
    try {
      const query = new URLSearchParams({
        from: byId("taskLogFrom").value,
        to: byId("taskLogTo").value,
        source: byId("taskLogSource").value,
        status: byId("taskLogStatus").value,
        taskType: byId("taskLogTaskType").value
      });
      const data = await fetchJSON(`api/scheduled-task-logs/?${query}`);
      const summary = data?.summary || {};
      byId("taskLogSummary").innerHTML = metric("Runs", summary.runs || 0)
        + metric("Items updated", summary.storedMatches || 0)
        + metric("Failed items", summary.failedMatches || 0)
        + metric("Manual / CRON", `${summary.manualRuns || 0} / ${summary.cronRuns || 0}`);
      const entries = Array.isArray(data?.entries) ? data.entries : [];
      const humanTask = value => String(value || "legacy-scheduled-task")
        .replace(/[._:-]+/g, " ")
        .replace(/\b\w/g, character => character.toUpperCase());
      byId("taskLogEntries").innerHTML = entries.map(row => {
        const storedIds = Array.isArray(row.storedMatchIds) ? row.storedMatchIds : [];
        const failedIds = Array.isArray(row.failedMatchIds) ? row.failedMatchIds : [];
        const details = [
          row.startedAt ? `Started: ${formatDateTime(row.startedAt)}` : "",
          row.endedAt ? `Ended: ${formatDateTime(row.endedAt)}` : "",
          row.message ? `Message: ${row.message}` : "",
          storedIds.length ? `Stored match IDs: ${storedIds.join(", ")}` : "",
          failedIds.length ? `Failed match IDs: ${failedIds.join(", ")}` : "",
          row.details ? `Details: ${JSON.stringify(row.details)}` : ""
        ].filter(Boolean).join("\n");
        const taskName = humanTask(row.taskId);
        const taskType = humanTask(row.taskType);
        const detailHTML = details ? `<details class="task-log-details"><summary>Run details</summary>${row.startedAt ? `<div><strong>Started:</strong> ${escapeHTML(formatDateTime(row.startedAt))}</div>` : ""}${row.endedAt ? `<div><strong>Ended:</strong> ${escapeHTML(formatDateTime(row.endedAt))}</div>` : ""}${row.message ? `<div><strong>Message:</strong> ${escapeHTML(row.message)}</div>` : ""}${storedIds.length ? `<div><strong>Stored:</strong> ${escapeHTML(storedIds.join(", "))}</div>` : ""}${failedIds.length ? `<div><strong>Failed:</strong> ${escapeHTML(failedIds.join(", "))}</div>` : ""}</details>` : "";
        return `
          <tr title="${escapeHTML(details)}">
            <td>${escapeHTML(formatDateTime(row.timestamp))}</td>
            <td><strong>${escapeHTML(taskName)}</strong><br/><span class="note">${escapeHTML(taskType)}</span>${detailHTML}</td>
            <td><code>${escapeHTML(row.runId || "—")}</code></td>
            <td>${escapeHTML(row.source || "—")}</td>
            <td><span class="status ${escapeHTML(row.status)}">${escapeHTML(row.status)}</span></td>
            <td class="num">${row.processedReferences ?? row.trackedReferences ?? row.leagueReferences ?? 0}</td>
            <td class="num">${row.storedMatches ?? row.updatedItems ?? 0}</td>
            <td class="num">${row.failedMatches ?? 0}</td>
            <td class="num">${Math.round(row.durationMs || 0)} ms</td>
          </tr>`;
      }).join("") || '<tr><td colspan="9">No task runs.</td></tr>';
      feedback("taskLogFeedback", Number(data?.invalidLines) ? `${Number(data?.invalidLines) || 0} malformed log line(s) were skipped safely.` : "Loaded.", "success");
    } catch (error) {
      feedback("taskLogFeedback", error.message, "error");
    }
  }
  byId("taskLogApply").addEventListener("click", loadTaskLogs);
  byId("taskLogRefresh").addEventListener("click", loadTaskLogs);
  byId("taskLogReset").addEventListener("click", () => { resetSevenDayRange("taskLogFrom", "taskLogTo"); byId("taskLogStatus").value = ""; byId("taskLogSource").value = ""; byId("taskLogTaskType").value = ""; loadTaskLogs(); });
  byId("taskLogMore").addEventListener("click", () => extendRangeBack("taskLogFrom", loadTaskLogs));

  let recordingInProgress = false;

  function recordingControls(context) {
    if (context === "management") {
      return {
        button: byId("managementRecordNow"),
        track: byId("managementProgressTrack"),
        bar: byId("managementProgressBar"),
        text: byId("managementProgressText")
      };
    }
    return {
      button: byId("recordNow"),
      track: byId("manualProgressTrack"),
      bar: byId("manualProgressBar"),
      text: byId("manualProgressText")
    };
  }

  function setRecordingProgress(context, message, current, total, type = "") {
    const controls = recordingControls(context);
    controls.track.hidden = false;
    controls.bar.style.width = `${total ? Math.round(current / total * 100) : 0}%`;
    controls.text.textContent = message;
    controls.text.className = `feedback${type ? ` ${type}` : ""}`;
  }

  function newTaskRunId(taskId) {
    const timestamp = new Date().toISOString().replace(/[-:.]/g, "").replace("000Z", "Z");
    let suffix = "";
    try { suffix = crypto.randomUUID().replace(/-/g, "").slice(0, 8); }
    catch (_) { suffix = Math.random().toString(16).slice(2, 10).padEnd(8, "0"); }
    return `${taskId}-${timestamp}-${suffix}`.toLowerCase();
  }

  async function recordMatchData(context = "scheduled") {
    if (recordingInProgress) return;
    recordingInProgress = true;
    const controls = recordingControls(context);
    const allButtons = [byId("recordNow"), byId("managementRecordNow")].filter(Boolean);
    allButtons.forEach(button => { button.disabled = true; });
    const originalText = controls.button.textContent;
    controls.button.textContent = "Recording…";
    const started = performance.now();
    const startedAt = new Date().toISOString();
    let stored = 0;
    let failed = 0;
    let references = [];
    let list = {};
    const taskId = "manual-record-active-matches";
    const runId = newTaskRunId(taskId);
    const storedMatchIds = [];
    const failedMatchIds = [];
    try {
      setRecordingProgress(context, `Loading current tracked match references… Run ${runId}`, 0, 1);
      list = await fetchJSON("api/league-match-references/");
      references = Array.isArray(list.references) ? list.references : [];
      setRecordingProgress(
        context,
        references.length ? `0 of ${references.length} matches recorded.` : "No active tracked match currently needs recording.",
        0,
        Math.max(1, references.length)
      );
      for (let index = 0; index < references.length; index++) {
        const reference = references[index];
        try {
          const response = await fetchJSON("api/record-league-match/", {
            method: "POST",
            headers: { "Content-Type": "application/json", ...writeHeaders("record-league-match") },
            body: JSON.stringify({ match: reference.apiUrl || reference.matchId })
          });
          stored++;
          const storedId = String(response?.stored?.matchId || reference.matchId || "").match(/\d+/)?.[0];
          if (storedId && !storedMatchIds.includes(storedId)) storedMatchIds.push(storedId);
        } catch (error) {
          failed++;
          const failedId = String(reference.matchId || reference.apiUrl || "").match(/\d+/)?.[0];
          if (failedId && !failedMatchIds.includes(failedId)) failedMatchIds.push(failedId);
          console.error(error);
        }
        setRecordingProgress(context, `${index + 1} of ${references.length} processed — ${stored} recorded, ${failed} failed.`, index + 1, references.length);
      }
      const status = failed === 0 ? "success" : stored ? "partial" : "failed";
      await fetchJSON("api/scheduled-task-log/", {
        method: "POST",
        headers: { "Content-Type": "application/json", ...writeHeaders("scheduled-task-log") },
        body: JSON.stringify({
          source: "manual",
          status,
          taskType: "match-update",
          taskId,
          runId,
          startedAt,
          endedAt: new Date().toISOString(),
          registeredReferences: list.registeredReferences || 0,
          autoLeagueReferences: list.autoLeagueReferences || 0,
          followedReferences: list.followedReferences || 0,
          trackedReferences: references.length,
          processedReferences: references.length,
          storedMatches: stored,
          skippedMatches: Math.max(0, (list.registeredReferences || 0) - (list.autoLeagueReferences || 0)),
          failedMatches: failed,
          durationMs: Math.round(performance.now() - started),
          storedMatchIds,
          failedMatchIds
        })
      });
      setRecordingProgress(context, `Recording complete (${runId}): ${stored} match${stored === 1 ? "" : "es"} recorded, ${failed} failed.`, Math.max(1, references.length), Math.max(1, references.length), failed ? "error" : "success");
      if (context === "management" && activeAdminTab() === "management") await loadMatchManagement();
      else if (context === "scheduled" && activeAdminTab() === "logs" && activeLogTab() === "scheduled") await loadTaskLogs();
    } catch (error) {
      setRecordingProgress(context, `Unable to record match data: ${error.message}`, 0, 1, "error");
      try {
        await fetchJSON("api/scheduled-task-log/", {
          method: "POST",
          headers: { "Content-Type": "application/json", ...writeHeaders("scheduled-task-log") },
          body: JSON.stringify({
            source: "manual",
            status: "failed",
            taskType: "match-update",
            taskId,
            runId,
            startedAt,
            endedAt: new Date().toISOString(),
            trackedReferences: references.length,
            processedReferences: stored + failed,
            storedMatches: stored,
            failedMatches: Math.max(1, failed),
            durationMs: Math.round(performance.now() - started),
            storedMatchIds,
            failedMatchIds,
            message: error.message
          })
        });
      } catch {
        // Keep the original recording error visible.
      }
    } finally {
      recordingInProgress = false;
      allButtons.forEach(button => { button.disabled = false; });
      controls.button.textContent = originalText;
    }
  }

  byId("recordNow").addEventListener("click", () => recordMatchData("scheduled"));
  byId("managementRecordNow").addEventListener("click", () => recordMatchData("management"));


  function formatDateTime(value) {
    if (!value) return "—";
    try {
      return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
        dateStyle: "medium",
        timeStyle: "short"
      }).format(new Date(value)) + " UTC";
    } catch {
      return String(value);
    }
  }

  function formatEpoch(value) {
    const numeric = Number(value || 0);
    return numeric > 0 ? formatDateTime(new Date(numeric * 1000).toISOString()) : "—";
  }

  function statusLabel(status) {
    return ({ registration: "Registration", ongoing: "Ongoing", finished: "Finished" })[status] || "Unknown";
  }

  let managedMatches = [];

  function managementComparator(mode) {
    const timeValue = match => Number(match.startTime || 0);
    const sizeValue = match => Number(match.boardCount || 0);
    if (mode === "date-old") return (left, right) => {
      const a = timeValue(left) || Number.MAX_SAFE_INTEGER;
      const b = timeValue(right) || Number.MAX_SAFE_INTEGER;
      return a - b || String(left.name).localeCompare(String(right.name));
    };
    if (mode === "size-large") return (left, right) => sizeValue(right) - sizeValue(left) || String(left.name).localeCompare(String(right.name));
    if (mode === "size-small") return (left, right) => sizeValue(left) - sizeValue(right) || String(left.name).localeCompare(String(right.name));
    if (mode === "name") return (left, right) => String(left.name).localeCompare(String(right.name));
    return (left, right) => {
      const a = timeValue(left);
      const b = timeValue(right);
      if (!a && !b) return String(left.name).localeCompare(String(right.name));
      if (!a) return 1;
      if (!b) return -1;
      return b - a || String(left.name).localeCompare(String(right.name));
    };
  }

  function renderMatchManagement() {
    const query = byId("managementSearch").value.trim().toLowerCase();
    const status = byId("managementStatus").value;
    const followState = byId("managementFollowState").value;
    const sortMode = byId("managementSort").value;
    const rows = managedMatches.filter(match => {
      const haystack = [
        match.name,
        match.matchId,
        ...(match.teams || []),
        ...(match.leagueAcronyms || []),
        match.status,
        match.followed ? "followed" : "unfollowed"
      ].join(" ").toLowerCase();
      const followMatches = !followState
        || (followState === "followed" && match.followed)
        || (followState === "unfollowed" && !match.followed);
      return (!query || haystack.includes(query)) && (!status || match.status === status) && followMatches;
    }).sort(managementComparator(sortMode));

    byId("managementVisible").textContent = `${rows.length} of ${managedMatches.length} matches`;
    byId("managementRows").innerHTML = rows.map(match => {
      const graphDisabled = !match.hasData ? " disabled" : "";
      const expiredByStart = !match.followed && match.autoStopReason === "started-over-24h";
      const followAction = match.followed
        ? `<button class="button danger compact" type="button" data-unfollow="${escapeHTML(match.matchId)}">Unfollow</button>`
        : `<button class="button compact" type="button" data-follow="${escapeHTML(match.matchId)}">${expiredByStart ? "Record once" : "Follow"}</button>`;
      return `
        <tr>
          <td>
            <a href="${escapeHTML(match.url || "#")}" target="_blank" rel="noopener">${escapeHTML(match.name)}</a><br>
            <span class="note">#${escapeHTML(match.matchId)}${(match.teams || []).length ? ` · ${escapeHTML(match.teams.join(" vs "))}` : ""}${(match.leagueAcronyms || []).length ? ` · ${escapeHTML(match.leagueAcronyms.join(", "))}` : ""}</span>
          </td>
          <td><span class="status ${escapeHTML(match.status)}">${escapeHTML(statusLabel(match.status))}</span></td>
          <td class="num">${Number(match.boardCount || 0) || "—"}</td>
          <td><span class="status ${match.followed ? "followed" : "unfollowed"}">${match.followed ? "Followed" : "Unfollowed"}</span></td>
          <td class="num">${Number(match.fileCount || 0)}</td>
          <td>${escapeHTML(formatEpoch(match.startTime))}</td>
          <td>${escapeHTML(formatDateTime(match.lastTrackedAt))}</td>
          <td>${escapeHTML(formatDateTime(match.nextCaptureAt))}<br><span class="note">${escapeHTML(match.samplingLabel || "")}</span></td>
          <td class="actions-cell">
            <button class="button compact" type="button" data-history="${escapeHTML(match.matchId)}"${graphDisabled}>Tracking graph</button>
            ${followAction}
            <button class="button danger compact" type="button" data-delete-data="${escapeHTML(match.matchId)}"${match.hasData ? "" : " disabled"}>Remove data</button>
          </td>
        </tr>
      `;
    }).join("") || '<tr><td colspan="9">No matches match the selected filters.</td></tr>';

    document.querySelectorAll("[data-history]").forEach(button => {
      button.addEventListener("click", () => openHistory(button.dataset.history));
    });
    document.querySelectorAll("[data-unfollow]").forEach(button => {
      button.addEventListener("click", () => unfollowMatch(button.dataset.unfollow));
    });
    document.querySelectorAll("[data-follow]").forEach(button => {
      button.addEventListener("click", () => followMatch(button.dataset.follow, true));
    });
    document.querySelectorAll("[data-delete-data]").forEach(button => {
      button.addEventListener("click", () => deleteMatchData(button.dataset.deleteData));
    });
  }

  async function loadMatchManagement() {
    feedback("managementFeedback", "Loading…");
    try {
      const data = await fetchJSON("api/tracked-match-data/");
      managedMatches = Array.isArray(data.matches) ? data.matches : [];
      const summary = data.summary || {};
      const migration = data.migration || {};
      const migrationMessages = [];
      if (Number(migration.convertedMatches || 0)) migrationMessages.push(`${migration.convertedMatches} legacy match${migration.convertedMatches === 1 ? "" : "es"} converted`);
      if (Number(migration.convertedSnapshots || 0)) migrationMessages.push(`${migration.convertedSnapshots} snapshot${migration.convertedSnapshots === 1 ? "" : "s"} migrated`);
      if (Number(migration.quarantinedFiles || 0)) migrationMessages.push(`${migration.quarantinedFiles} invalid file${migration.quarantinedFiles === 1 ? "" : "s"} moved to quarantine`);
      feedback("managementMigration", migrationMessages.length ? `Legacy v2.1.2 tracking migration: ${migrationMessages.join(", ")}.` : "", migrationMessages.length ? "success" : "");
      byId("managementSummary").innerHTML = metric("Followed", summary.followed || 0)
        + metric("Ongoing", summary.ongoing || 0)
        + metric("Finished", summary.finished || 0)
        + metric("Snapshot files", summary.files || 0);
      renderMatchManagement();
      feedback("managementFeedback", "Loaded.", "success");
    } catch (error) {
      feedback("managementFeedback", error.message, "error");
    }
  }

  async function followMatch(reference, followAgain = false) {
    const input = byId("managementAddInput");
    const button = byId("managementAddButton");
    const value = String(reference || input.value).trim();
    if (!value) {
      feedback("managementAddFeedback", "Enter a match ID, URL, or slug.", "error");
      input.focus();
      return;
    }
    button.disabled = true;
    feedback("managementAddFeedback", followAgain ? "Following match and recording current data…" : "Following match and recording current data…");
    try {
      const data = await fetchJSON("api/tracked-match-data/", {
        method: "POST",
        headers: { "Content-Type": "application/json", ...writeHeaders("tracked-match-data") },
        body: JSON.stringify({ action: "follow", match: value })
      });
      input.value = "";
      const displayName = data.stored?.name || data.match?.name || `Match ${data.stored?.matchId || data.match?.matchId || value}`;
      const expiredByStart = data.match?.autoStopReason === "started-over-24h" && !data.match?.followed;
      if (expiredByStart) {
        feedback("managementAddFeedback", data.captured === false
          ? `${displayName} remains outside continuous tracking because it started more than 24 hours ago. A new snapshot was not available: ${data.captureWarning || "Chess.com did not return current match data."}`
          : `${displayName} was recorded once. Continuous tracking remains stopped because it started more than 24 hours ago.`, "warning");
      } else if (data.captured === false) {
        feedback("managementAddFeedback", `${displayName} is followed using its archived data. A new snapshot was not available: ${data.captureWarning || "Chess.com did not return current match data."}`, "warning");
      } else {
        feedback("managementAddFeedback", `${displayName} is followed and its current data was recorded as a new snapshot.`, "success");
      }
      await loadMatchManagement();
    } catch (error) {
      feedback("managementAddFeedback", error.message, "error");
    } finally {
      button.disabled = false;
    }
  }

  async function unfollowMatch(identifier) {
    const match = managedMatches.find(item => String(item.matchId) === String(identifier));
    if (!confirm(`Unfollow ${match?.name || `match ${identifier}`}? Existing archive data will be kept, but future scheduled recordings will stop.`)) return;
    try {
      await fetchJSON(`api/tracked-match-data/?mode=unfollow&match=${encodeURIComponent(identifier)}`, {
        method: "DELETE",
        headers: writeHeaders("tracked-match-data")
      });
      feedback("managementFeedback", `${match?.name || `Match ${identifier}`} was unfollowed.`, "success");
      await loadMatchManagement();
    } catch (error) {
      feedback("managementFeedback", error.message, "error");
    }
  }

  async function deleteMatchData(identifier) {
    const match = managedMatches.find(item => String(item.matchId) === String(identifier));
    if (!confirm(`Remove every stored snapshot for ${match?.name || `match ${identifier}`}? Follow status will not change.`)) return;
    try {
      const data = await fetchJSON(`api/tracked-match-data/?mode=data&match=${encodeURIComponent(identifier)}`, {
        method: "DELETE",
        headers: writeHeaders("tracked-match-data")
      });
      feedback("managementFeedback", `${data.deletedFiles || 0} snapshot file(s) removed.`, "success");
      await loadMatchManagement();
    } catch (error) {
      feedback("managementFeedback", error.message, "error");
    }
  }

  async function removeAllFinishedData() {
    const candidates = managedMatches.filter(match => match.status === "finished" && Number(match.fileCount || 0) > 0);
    const files = candidates.reduce((total, match) => total + Number(match.fileCount || 0), 0);
    if (!candidates.length) {
      feedback("managementFeedback", "No finished match currently has stored data.", "success");
      return;
    }
    if (!confirm(`Remove all stored data for ${candidates.length} finished match(es), totalling ${files} snapshot file(s)? Follow status will not change.`)) return;
    const button = byId("removeFinishedData");
    button.disabled = true;
    try {
      const data = await fetchJSON("api/tracked-match-data/?mode=finished-data", {
        method: "DELETE",
        headers: writeHeaders("tracked-match-data")
      });
      feedback("managementFeedback", `${data.deletedFiles || 0} snapshot file(s) removed from ${data.deletedMatches || 0} finished match(es).`, "success");
      await loadMatchManagement();
    } catch (error) {
      feedback("managementFeedback", error.message, "error");
    } finally {
      button.disabled = false;
    }
  }

  byId("managementRefresh").addEventListener("click", loadMatchManagement);
  byId("managementAddButton").addEventListener("click", () => followMatch());
  byId("managementAddInput").addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      followMatch();
    }
  });
  ["managementSearch", "managementStatus", "managementFollowState", "managementSort"].forEach(id => {
    byId(id).addEventListener(id === "managementSearch" ? "input" : "change", renderMatchManagement);
  });
  byId("managementClear").addEventListener("click", () => {
    byId("managementSearch").value = "";
    byId("managementStatus").value = "";
    byId("managementFollowState").value = "";
    byId("managementSort").value = "date-new";
    renderMatchManagement();
  });
  byId("removeFinishedData").addEventListener("click", removeAllFinishedData);

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

  let latestDiagnosticSnapshot = null;

  function diagnosticContexts() {
    const routes = window.P2K_SITE_CONFIG?.routes || {};
    const known = [
      ["index", "index.html"], ["find", routes.find || "FindMatch.htm"],
      ["upcoming", routes.upcoming || "AnalyzeMatches.htm"], ["creation", routes.creation || "MatchCreationAnalyzer.htm"],
      ["open", routes.open || "AnalyzeMatch.html"], ["recruit", routes.recruit || "RecruitMatch.html"],
      ["challenges", routes.challenges || "ChallengeListAssistant.html"], ["teamPoints", routes.teamPoints || "TeamPointsAdmin.html"]
    ];
    const frames = window.P2K_TOOL_FRAMES;
    return known.map(([key, route]) => {
      if (key === "index") return { key, route, loaded: true, win: window };
      const frame = frames?.get?.(key);
      try {
        if (frame?.contentWindow) return { key, route, loaded: true, win: frame.contentWindow };
      } catch { /* Same-origin frame not ready. */ }
      return { key, route, loaded: false, win: null };
    });
  }

  function formatBytes(value) {
    const bytes = Math.max(0, Number(value) || 0);
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MiB`;
  }

  async function collectDiagnostics() {
    const contexts = [];
    for (const entry of diagnosticContexts()) {
      if (!entry.loaded || !entry.win) {
        contexts.push({ key: entry.key, route: entry.route, loaded: false });
        continue;
      }
      try {
        const client = entry.win.P2K_API_CLIENT;
        const cache = entry.win.P2K_API_CACHE;
        const coordinator = entry.win.P2K_ANALYSIS_COORDINATOR;
        contexts.push({
          key: entry.key,
          route: entry.route,
          loaded: true,
          client: client?.diagnostics?.() || { loaded: false },
          cache: cache?.diagnostics?.() || null,
          storage: await cache?.inspect?.() || null,
          coordinator: coordinator?.diagnostics?.() || null
        });
      } catch (error) {
        contexts.push({ key: entry.key, route: entry.route, loaded: true, error: error.message || String(error) });
      }
    }
    let backend = null;
    try { backend = await fetchJSON("api/diagnostics/"); }
    catch (error) { backend = { ok: false, error: error.message }; }
    let teamPoints = { client: window.P2K_TEAM_POINTS_CLIENT?.diagnostics?.() || { loaded: false } };
    try {
      if (window.P2K_TEAM_POINTS_CLIENT && window.P2K_AUTH?.getSession?.()?.username) {
        const status = await window.P2K_TEAM_POINTS_CLIENT.request("status");
        teamPoints = { ...teamPoints, status };
      }
    } catch (error) {
      teamPoints = { ...teamPoints, error: error.message || String(error) };
    }
    return {
      generatedAt: new Date().toISOString(),
      version: window.P2K_SITE_CONFIG?.version || "unknown",
      builtAt: window.P2K_SITE_CONFIG?.builtAt || null,
      schemaVersion: window.P2K_SITE_CONFIG?.schemaVersion || null,
      modelVersions: window.P2K_SITE_CONFIG?.modelVersions || {},
      userAgent: navigator.userAgent,
      online: navigator.onLine,
      origin: location.origin,
      backend,
      teamPoints,
      contexts
    };
  }

  function diagnosticCard(item) {
    if (item.loaded === false) return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list"><li><strong>Context:</strong> not loaded</li><li><strong>Route:</strong> ${escapeHTML(item.route || "—")}</li><li>Open this tool once to initialize its runtime diagnostics.</li></ul></section>`;
    if (item.error) return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list"><li><strong>Error:</strong> ${escapeHTML(item.error)}</li></ul></section>`;
    const client = item.client || {};
    const clientCounters = client.counters || {};
    const cache = item.cache || {};
    const cacheCounters = cache.counters || {};
    const storage = item.storage || {};
    const coordinator = item.coordinator || {};
    return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list">
      <li><strong>API client:</strong> ${client.loaded ? "loaded" : "not loaded"}</li>
      <li><strong>Requests:</strong> ${client.activeRequests || 0} active; ${client.queuedRequests || 0} queued; concurrency ${client.adaptiveConcurrency || 0} / ${client.configuredConcurrency || 0}</li>
      <li><strong>Transport:</strong> ${clientCounters.fetches || 0} fetches; ${clientCounters.retries || 0} retries; ${clientCounters.rateLimits || 0} rate limits; ${clientCounters.jsonp || 0} JSONP</li>
      <li><strong>Cache:</strong> ${storage.indexedDbEntries || 0} IndexedDB (${formatBytes(storage.indexedDbApproximateBytes)}); ${cache.memoryEntries || 0} memory (${formatBytes(cache.memoryApproximateBytes)})</li>
      <li><strong>Cache activity:</strong> ${clientCounters.cacheHits || 0} hits; ${clientCounters.cacheMisses || 0} misses; ${cacheCounters.writeFailures || 0} write failures</li>
      <li><strong>Coordination:</strong> BroadcastChannel ${cache.broadcastChannelAvailable ? "available" : "unavailable"}; ${(coordinator.registeredTools || []).join(", ") || "no registered analysis tool"}</li>
    </ul></section>`;
  }

  function renderDiagnostics(snapshot) {
    latestDiagnosticSnapshot = snapshot;
    const backend = snapshot.backend || {};
    const backendCard = `<section class="site-diagnostics-card"><h3>Server</h3><ul class="site-diagnostics-list">
      <li><strong>Backend:</strong> ${escapeHTML(backend.backend || backend.error || "unavailable")}</li>
      <li><strong>Writable storage:</strong> ${backend.writable === true ? "yes" : backend.writable === false ? "no" : "unknown"}</li>
      <li><strong>CRON token:</strong> ${backend.cronConfigured === true ? "configured" : backend.cronConfigured === false ? "not configured" : "unknown"}</li>
      <li><strong>Tracking index:</strong> ${backend.followRegistryEntries ?? "—"}</li>
      <li><strong>Legacy migration:</strong> ${backend.trackingMigration?.completedAt ? `${backend.trackingMigration.convertedMatches || 0} match(es), ${backend.trackingMigration.convertedSnapshots || 0} snapshot(s)` : "not required on this request"}</li>
    </ul></section>`;
    const releaseCard = `<section class="site-diagnostics-card"><h3>Release</h3><ul class="site-diagnostics-list">
      <li><strong>Package:</strong> ${escapeHTML(snapshot.version)}</li><li><strong>Built:</strong> ${escapeHTML(snapshot.builtAt || "—")}</li><li><strong>Package schema:</strong> ${escapeHTML(snapshot.schemaVersion ?? "—")}</li><li><strong>Baseline shell:</strong> v2.1.2 preserved</li>
    </ul></section>`;
    const tp = snapshot.teamPoints || {};
    const tpStatus = tp.status || {};
    const tpCron = tpStatus.cron_state || {};
    const tpJob = tpStatus.job || {};
    const teamPointsCard = `<section class="site-diagnostics-card"><h3>Team Points scheduler</h3><ul class="site-diagnostics-list">
      <li><strong>Client:</strong> ${tp.client?.loaded ? (tp.client.authenticated ? "secured session active" : "loaded, not connected") : "not loaded"}</li>
      <li><strong>Database schema:</strong> ${escapeHTML(tpStatus.schema_version ?? "—")}</li>
      <li><strong>Job:</strong> ${escapeHTML(tpJob.status || "no status")}${tpJob.id ? ` · ${escapeHTML(tpJob.id)}` : ""}</li>
      <li><strong>Queue:</strong> ${escapeHTML(tpJob.queue?.pending ?? "—")} pending; ${escapeHTML(tpJob.queue?.retry ?? "—")} retry; ${escapeHTML(tpJob.queue?.failed ?? "—")} failed</li>
      <li><strong>CRON chain:</strong> ${escapeHTML(tpCron.last_status || "not started")}; next ${escapeHTML(tpCron.next_run_at || "—")}</li>
      <li><strong>Last message:</strong> ${escapeHTML(tpCron.last_message || tp.error || "—")}</li>
    </ul></section>`;
    byId("runtimeDiagnosticsGrid").innerHTML = releaseCard + backendCard + teamPointsCard + snapshot.contexts.map(diagnosticCard).join("");
    const events = snapshot.contexts.flatMap(item => [
      ...(item.client?.events || []).map(event => ({ context: item.key, source: "client", ...event })),
      ...(item.cache?.events || []).map(event => ({ context: item.key, source: "cache", ...event }))
    ]).sort((a, b) => Number(b.at || 0) - Number(a.at || 0)).slice(0, 80);
    byId("runtimeDiagnosticsLog").textContent = events.length ? events.map(event => `${new Date(event.at || Date.now()).toISOString()} [${event.context}/${event.source}] ${event.type || event.message || JSON.stringify(event)}`).join("\n") : "No recent diagnostic events.";
  }

  async function refreshDiagnostics() {
    feedback("diagnosticFeedback", "Collecting diagnostics…");
    try {
      renderDiagnostics(await collectDiagnostics());
      feedback("diagnosticFeedback", "Diagnostics refreshed.", "success");
    } catch (error) {
      feedback("diagnosticFeedback", error.message, "error");
    }
  }

  async function copyDiagnostics() {
    latestDiagnosticSnapshot = await collectDiagnostics();
    const text = JSON.stringify(latestDiagnosticSnapshot, null, 2);
    try { await navigator.clipboard.writeText(text); }
    catch {
      const area = document.createElement("textarea"); area.value = text; document.body.appendChild(area); area.select(); document.execCommand("copy"); area.remove();
    }
    feedback("diagnosticFeedback", "Diagnostics copied.", "success");
  }

  async function clearDiagnosticsCache() {
    const seen = new Set();
    for (const entry of diagnosticContexts()) {
      if (!entry.loaded || !entry.win) continue;
      const cache = entry.win.P2K_API_CACHE;
      if (!cache || seen.has(cache)) continue;
      seen.add(cache);
      await cache.clear?.();
    }
    await refreshDiagnostics();
    feedback("diagnosticFeedback", "Shared cache cleared.", "success");
  }

  byId("refreshDiagnostics").addEventListener("click", refreshDiagnostics);
  byId("copyDiagnostics").addEventListener("click", copyDiagnostics);
  byId("clearDiagnosticsCache").addEventListener("click", clearDiagnosticsCache);
  }

  if (window.P2K_ADMIN_MODE === true) initializeAdminFeatures();
  window.addEventListener("club-admin-access-ready", event => {
    if (event.detail?.enabled) initializeAdminFeatures();
  });

})();
