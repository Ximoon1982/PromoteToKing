#!/usr/bin/env python3
"""Extract recommendation and embedded Match Assistant lifecycle ownership."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
MODULE = ROOT / "assets/js/dashboard/match-assistant.js"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index("  function stopRecommendationTimer()")
    end = source.index("  let dashboardHallModulePromise", start)
    body = "  let recommendationInfoSequence = 0;\n" + source[start:end]
    exports = re.findall(r"^\s{2}(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    dependencies = ["state", "byId", "viewed", "preservedURL", "config", "escapeHTML", "number", "setText", "writeNavigationState", "adminDetailDefinition"]
    MODULE.parent.mkdir(parents=True, exist_ok=True)
    MODULE.write_text("""/* Existing recommendations and Match Assistant iframe lifecycle. */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.matchAssistant = Object.freeze({
create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}
});
})();
""" % (", ".join(dependencies), body, ", ".join(exports)), encoding="utf-8")
    replacement = """  const { %s } = window.P2K_DASHBOARD_MODULES.matchAssistant.create({
    state, byId, viewed, preservedURL, config, escapeHTML, number, setText,
    writeNavigationState, adminDetailDefinition
  });
""" % ", ".join(exports)
    SOURCE.write_text(source[:start] + replacement + source[end:], encoding="utf-8")
if __name__ == "__main__": main()
