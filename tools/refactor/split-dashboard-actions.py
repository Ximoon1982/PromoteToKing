#!/usr/bin/env python3
"""Extract dashboard match-list dialog and Administration tool registry."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"

def module(path: str, key: str, description: str, deps: list[str], body: str) -> list[str]:
    exports = re.findall(r"^(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    target = ROOT / path; target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text("""/* %s */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.%s = Object.freeze({create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}});
})();
""" % (description, key, ", ".join(deps), body, ", ".join(exports)), encoding="utf-8")
    return exports

def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index("function matchListLabel")
    end = source.index("const tools =", start)
    body = source[start:end]
    exports = module("assets/js/dashboard/match-list-dialog.js", "matchListDialog", "Existing dashboard match-list modal behavior.", ["state", "byId", "matchBoardCount", "matchListTotals", "number"], body)
    replacement = "const { %s } = window.P2K_DASHBOARD_MODULES.matchListDialog.create({ state, byId, matchBoardCount, matchListTotals, number });\n" % ", ".join(exports)
    source = source[:start] + replacement + source[end:]
    start = source.index("const tools =")
    end = source.index("function bindControls", start)
    body = source[start:end]
    exports = module("assets/js/admin/tool-registry.js", "adminTools", "Existing Administration tool catalogue and links.", ["byId", "integratedAdminHref", "preservedURL", "config"], body)
    replacement = "const { %s } = window.P2K_DASHBOARD_MODULES.adminTools.create({ byId, integratedAdminHref, preservedURL, config });\n" % ", ".join(exports)
    SOURCE.write_text(source[:start] + replacement + source[end:], encoding="utf-8")
if __name__ == "__main__": main()
