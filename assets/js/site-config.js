/* Central deployment configuration. Override values before loading page scripts when needed. */
window.P2K_SITE_CONFIG = Object.freeze({
  routes: Object.freeze({
    findMatch: "FindMatch_v1.htm",
    upcomingMatches: "AnalyzeMatches_v1.htm",
    matchCreation: "MatchCreationAnalyzer_v1.htm",
    analyzeMatch: "AnalyzeMatch.html",
    analyzeMatchModal: "AnalyzeMatchModal.html"
  }),
  legacySources: Object.freeze({
    findMatch: "https://raw.githubusercontent.com/Ximoon1982/P2KMatchFinder/ee3de88243845808d5de5adb957dc79ae6a54ad1/FindMatch_v1.htm",
    matchCreation: "https://raw.githubusercontent.com/Ximoon1982/P2KMatchFinder/1eb2d6b717cc2de3cfeebc2300a41399d3cca4ed/MatchCreationAnalyzer_v1.htm"
  })
});
