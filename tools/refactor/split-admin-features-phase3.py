#!/usr/bin/env python3
"""Extract recording, match-management and history feature controllers."""
from __future__ import annotations
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/admin-features.js"

def write(name: str, description: str, dependencies: list[str], body: str, exports: list[str]) -> None:
    (ROOT / f"assets/js/admin/{name}.js").write_text("""/* %s */
(() => {
\"use strict\";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.%s = Object.freeze({
create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}
});
})();
""" % (description, name.replace("-", "_"), ", ".join(dependencies), body, ", ".join(exports)), encoding="utf-8")

def cut(source: str, start_marker: str, end_marker: str) -> tuple[str, str]:
    start = source.index(start_marker); end = source.index(end_marker, start)
    return source[:start] + source[end:], source[start:end]

def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    source, management = cut(source, "  let managedMatches", "  const historyModal")
    write("match-management", "Existing tracked-match management behavior.",
          ["byId", "escapeHTML", "fetchJSON", "feedback", "writeHeaders", "formatDateTime", "formatEpoch", "statusLabel", "openHistory"], management, ["loadMatchManagement"])
    source, history = cut(source, "  const historyModal", "\n\n  }\n\n  if (window.P2K_ADMIN_MODE")
    write("history-controller", "Existing tracking graph and lineup-evolution behavior.",
          ["byId", "escapeHTML", "fetchJSON", "feedback", "formatDateTime", "adminModal", "closeAdmin"], history, ["openHistory"])
    source, recording = cut(source, "  let recordingInProgress", "  function formatDateTime")
    write("recording-controller", "Existing manual match-recording behavior.",
          ["byId", "fetchJSON", "writeHeaders", "activeAdminTab", "activeLogTab", "loadTaskLogs", "loadMatchManagement"], recording, ["recordMatchData"])
    anchor = "    const { refreshDiagnostics } = window.P2K_ADMIN_FEATURE_MODULES.diagnostics.create({ byId, escapeHTML, fetchJSON, feedback });\n"
    init = anchor + "    const { openHistory } = window.P2K_ADMIN_FEATURE_MODULES.history_controller.create({ byId, escapeHTML, fetchJSON, feedback, formatDateTime, adminModal, closeAdmin });\n    const { loadMatchManagement } = window.P2K_ADMIN_FEATURE_MODULES.match_management.create({ byId, escapeHTML, fetchJSON, feedback, writeHeaders, formatDateTime, formatEpoch, statusLabel, openHistory });\n    const { recordMatchData } = window.P2K_ADMIN_FEATURE_MODULES.recording_controller.create({ byId, fetchJSON, writeHeaders, activeAdminTab, activeLogTab, loadTaskLogs, loadMatchManagement });\n"
    source = source.replace(anchor, init, 1)
    SOURCE.write_text(source, encoding="utf-8")

if __name__ == "__main__": main()
