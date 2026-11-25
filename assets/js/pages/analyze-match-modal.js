/* Preserve existing analysis bookmarks; the actual view is served by PHP only after authorization. */
(() => {
  "use strict";
  if (/\/AnalyzeMatchModal\.html$/i.test(window.location.pathname)) {
    const target = new URL("/server/team-points/public/detailed-match-analysis.php", window.location.origin);
    const match = new URLSearchParams(window.location.search).get("match");
    if (match !== null) target.searchParams.set("match", match);
    window.location.replace(target.href);
    return;
  }
  window.P2K_UPCOMING_MODE = Object.freeze({ embeddedSingleMatch: true, includeCopy: false, includeStartInRecruitText: true });
  // The protected page is under /server/. Keep API routes at the P2K root.
  const existing = window.P2K_SITE_CONFIG || {};
  window.P2K_SITE_CONFIG = {
    ...existing,
    api: { ...existing.api, oauthGatewayEndpoint: "/server/team-points/public/oauth.php" },
    serverStorage: {
      ...existing.serverStorage,
      matchHistoryEndpoint: "/api/match-history/",
      opportunisticObservationEndpoint: "/server/team-points/public/observe.php"
    }
  };
})();
