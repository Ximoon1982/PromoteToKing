/* Existing manual match-recording behavior. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.recording_controller = Object.freeze({
create(context) {
const { byId, fetchJSON, writeHeaders, activeAdminTab, activeLogTab, loadTaskLogs, loadMatchManagement } = context;
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



return Object.freeze({ recordMatchData });
}
});
})();
