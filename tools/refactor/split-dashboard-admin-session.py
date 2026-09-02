#!/usr/bin/env python3
"""Extract Admin authorization, priority health and throughput ownership."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
MODULE = ROOT / "assets/js/admin/admin-session-controller.js"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index("function adminEntryUsername")
    end = source.index("const { adminDetailDefinition", start)
    body = source[start:end]
    exports = re.findall(r"^(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    deps = ["state", "byId", "escapeHTML", "number", "setText", "showToast", "config", "clubSlug", "clubProfileAPI", "loadJSON", "setAdmin", "renderView", "writeNavigationState", "adminShellOpenDetail", "adminShellHref"]
    MODULE.parent.mkdir(parents=True, exist_ok=True)
    MODULE.write_text("""/* Existing Admin authorization, priority health and throughput behavior. */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.adminSession = Object.freeze({create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}});
})();
""" % (", ".join(deps), body, ", ".join(exports)), encoding="utf-8")
    replacement = """const { %s } = window.P2K_DASHBOARD_MODULES.adminSession.create({
state, byId, escapeHTML, number, setText, showToast, config, clubSlug, clubProfileAPI,
loadJSON, setAdmin, renderView, writeNavigationState,
adminShellOpenDetail: (...args) => adminShellOpenDetail(...args),
adminShellHref: (...args) => adminShellHref(...args)
});
""" % ", ".join(exports)
    SOURCE.write_text(source[:start] + replacement + source[end:], encoding="utf-8")
if __name__ == "__main__": main()
