#!/usr/bin/env python3
"""Extract cohesive Admin feature controllers while preserving classic-script timing."""
from __future__ import annotations
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/admin-features.js"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start_marker = "  let latestDiagnosticSnapshot = null;"
    final_listener = '  byId("clearDiagnosticsCache").addEventListener("click", clearDiagnosticsCache);'
    start = source.index(start_marker); end = source.index(final_listener, start) + len(final_listener)
    body = source[start:end] + "\n"
    module = ROOT / "assets/js/admin/diagnostics-controller.js"; module.parent.mkdir(parents=True, exist_ok=True)
    module.write_text("""/* Existing Administration diagnostics behavior, isolated behind a feature controller. */
(() => {
\"use strict\";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.diagnostics = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, feedback } = context;
%s
return Object.freeze({ refreshDiagnostics });
}
});
})();
""" % body, encoding="utf-8")
    insertion_marker = "    adminOpen.hidden = false;\n"
    insertion = insertion_marker + "    const { refreshDiagnostics } = window.P2K_ADMIN_FEATURE_MODULES.diagnostics.create({ byId, escapeHTML, fetchJSON, feedback });\n"
    source = source[:start] + source[end:]; source = source.replace(insertion_marker, insertion, 1)
    SOURCE.write_text(source, encoding="utf-8")
if __name__ == "__main__": main()
