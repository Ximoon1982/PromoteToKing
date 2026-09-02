#!/usr/bin/env python3
"""Extract Insights modal, achievements/profile composition and lazy feature bridge."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
MODULE = ROOT / "assets/js/dashboard/insights-controller.js"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index("  function insightAction")
    end = source.index("function integratedFrames", start)
    body = source[start:end]
    exports = re.findall(r"^\s{2}(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    deps = ["state", "byId", "escapeHTML", "number", "setText", "nativeLink", "cellWithSub", "statusChip", "loadPublicCachedJSON", "writeNavigationState", "rankThumbnailAsset", "originalRankAsset", "adminEntryUsername", "selectHallSubtab", "showPublicPage", "formatDateOnly"]
    MODULE.parent.mkdir(parents=True, exist_ok=True)
    MODULE.write_text("""/* Existing Insights, achievement catalogue and unified profile behavior. */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.insights = Object.freeze({create(context) {
const { %s } = context;
%s
return Object.freeze({ %s, getAchievementNavigation: () => achievementDetailNav });
}});
})();
""" % (", ".join(deps), body, ", ".join(exports)), encoding="utf-8")
    replacement = """  const { %s, getAchievementNavigation } = window.P2K_DASHBOARD_MODULES.insights.create({
    state, byId, escapeHTML, number, setText, nativeLink, cellWithSub, statusChip,
    loadPublicCachedJSON, writeNavigationState, rankThumbnailAsset, originalRankAsset,
    adminEntryUsername, selectHallSubtab, showPublicPage, formatDateOnly
  });
""" % ", ".join(exports)
    source = source[:start] + replacement + source[end:]
    source = source.replace("achievementDetailNav&&(event.key", "getAchievementNavigation()&&(event.key")
    source = source.replace("const n=achievementDetailNav.index+(event.key", "const n=getAchievementNavigation().index+(event.key")
    source = source.replace("n<achievementDetailNav.items.length", "n<getAchievementNavigation().items.length")
    SOURCE.write_text(source, encoding="utf-8")
if __name__ == "__main__": main()
