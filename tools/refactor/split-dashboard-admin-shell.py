#!/usr/bin/env python3
"""Extract the canonical Admin shell from dashboard-v2 without changing its code."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
MODULE = ROOT / "assets/js/admin/admin-shell.js"
START = "const ADMIN_DETAIL_DEFS = {"
END = "function ensureAdminInterface() {"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index(START); end = source.index(END, start)
    body = source[start:end].rstrip() + "\n"
    exports = re.findall(r"^(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    exports = list(dict.fromkeys(exports))
    dependencies = ["state", "byId", "escapeHTML", "number", "setText", "applyOAuthContext", "setIntegratedFrameActivity", "ensureIntegratedFrame", "writeNavigationState"]
    module = """/* Canonical Administration shell extracted without changing route or DOM semantics. */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.adminShell = Object.freeze({
create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}
});
})();
""" % (", ".join(dependencies), body, ", ".join(exports))
    MODULE.parent.mkdir(parents=True, exist_ok=True); MODULE.write_text(module, encoding="utf-8")
    replacement = """const { %s } = window.P2K_DASHBOARD_MODULES.adminShell.create({
state, byId, escapeHTML, number, setText, applyOAuthContext,
setIntegratedFrameActivity, ensureIntegratedFrame, writeNavigationState
});
""" % ", ".join(exports)
    SOURCE.write_text(source[:start] + replacement + source[end:], encoding="utf-8")
if __name__ == "__main__": main()
