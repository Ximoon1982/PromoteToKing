/* Existing match-assistant and scheduled-task log behavior. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.logs = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, metric, feedback, formatDateTime } = context;
  function isoDate(dateValue) { return dateValue.toISOString().slice(0, 10); }
  const today = new Date();
  const weekAgo = new Date(Date.now() - 6 * 864e5);
  ["matchLogTo", "taskLogTo"].forEach(id => { byId(id).value = isoDate(today); });
  ["matchLogFrom", "taskLogFrom"].forEach(id => { byId(id).value = isoDate(weekAgo); });

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


return Object.freeze({ loadMatchLogs, loadTaskLogs });
}
});
})();
