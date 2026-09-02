#!/usr/bin/env python3
"""Extract Admin transport primitives and log-controller ownership."""
from __future__ import annotations
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/admin-features.js"

def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    core_start = source.index("  async function fetchJSON")
    core_end = source.index("  async function loadMatchLogs", core_start)
    core = source[core_start:core_end]
    (ROOT / "assets/js/admin/admin-runtime.js").write_text("""/* Shared Administration transport and rendering primitives. */
(() => {
\"use strict\";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.runtime = Object.freeze({
create(context) {
const { byId, escapeHTML } = context;
%s
return Object.freeze({ fetchJSON, metric, feedback });
}
});
})();
""" % core, encoding="utf-8")
    source = source[:core_start] + source[core_end:]
    log_start = source.index("  function isoDate")
    log_end = source.index("  let recordingInProgress", log_start)
    logs = source[log_start:log_end]
    (ROOT / "assets/js/admin/logs-controller.js").write_text("""/* Existing match-assistant and scheduled-task log behavior. */
(() => {
\"use strict\";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.logs = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, metric, feedback, formatDateTime } = context;
%s
return Object.freeze({ loadMatchLogs, loadTaskLogs });
}
});
})();
""" % logs, encoding="utf-8")
    source = source[:log_start] + source[log_end:]
    anchor = "    adminOpen.hidden = false;\n"
    initialization = anchor + "    const { fetchJSON, metric, feedback } = window.P2K_ADMIN_FEATURE_MODULES.runtime.create({ byId, escapeHTML });\n    const { loadMatchLogs, loadTaskLogs } = window.P2K_ADMIN_FEATURE_MODULES.logs.create({ byId, escapeHTML, fetchJSON, metric, feedback, formatDateTime });\n"
    source = source.replace(anchor, initialization, 1)
    SOURCE.write_text(source, encoding="utf-8")

if __name__ == "__main__": main()
