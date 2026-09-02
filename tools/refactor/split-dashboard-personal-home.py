#!/usr/bin/env python3
"""Extract authenticated personal home, modes, activity and player games."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "assets/js/pages/dashboard-v2.js"
MODULE = ROOT / "assets/js/dashboard/personal-home.js"
def main() -> None:
    source = SOURCE.read_text(encoding="utf-8")
    start = source.index("  function currentSession()")
    end = source.index("  const { matchLists", start)
    body = source[start:end]
    exports = re.findall(r"^\s{2}(?:async )?function\s+([A-Za-z_$][\w$]*)\s*\(", body, re.MULTILINE)
    deps = ["state", "byId", "setText", "number", "escapeHTML", "viewed", "adminEntryUsername", "showPublicPage", "selectHallSubtab", "openAchievementCatalog", "loadPublicCachedJSON", "writeNavigationState", "verifyAdmin", "loadRecommendations", "renderLiveRanksNative", "ranks", "unrankedRank", "rankThumbnailAsset"]
    MODULE.parent.mkdir(parents=True, exist_ok=True)
    MODULE.write_text("""/* Existing authenticated personal dashboard and activity behavior. */
(() => {
\"use strict\";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.personalHome = Object.freeze({create(context) {
const { %s } = context;
%s
return Object.freeze({ %s });
}});
})();
""" % (", ".join(deps), body, ", ".join(exports)), encoding="utf-8")
    replacement = """  const { %s } = window.P2K_DASHBOARD_MODULES.personalHome.create({
    state, byId, setText, number, escapeHTML, viewed, adminEntryUsername,
    showPublicPage, selectHallSubtab, openAchievementCatalog, loadPublicCachedJSON,
    writeNavigationState, verifyAdmin,
    loadRecommendations: (...args) => loadRecommendations(...args),
    renderLiveRanksNative: (...args) => renderLiveRanksNative(...args),
    ranks, unrankedRank, rankThumbnailAsset
  });
""" % ", ".join(exports)
    SOURCE.write_text(source[:start] + replacement + source[end:], encoding="utf-8")
if __name__ == "__main__": main()
