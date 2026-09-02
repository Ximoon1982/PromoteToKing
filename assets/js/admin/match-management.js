/* Existing tracked-match management behavior. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.match_management = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, feedback, writeHeaders, formatDateTime, formatEpoch, statusLabel, openHistory } = context;
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


return Object.freeze({ loadMatchManagement });
}
});
})();
