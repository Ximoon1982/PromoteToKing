/* Central club deployment and runtime configuration. */
(() => {
  "use strict";

  const existing = window.P2K_SITE_CONFIG || {};
  const branding = window.CLUB_SITE_BRANDING || {};
  const configuredClubSlug = existing.clubSlug || branding.clubSlug || "promote-to-king";
  const configuredClubUrl = existing.clubUrl || branding.clubUrl ||
    `https://www.chess.com/club/${encodeURIComponent(configuredClubSlug)}`;
  const configuredAdminUsernames = Array.isArray(existing.auth?.adminUsernames)
    ? existing.auth.adminUsernames
    : Array.isArray(branding.adminUsernames) ? branding.adminUsernames : [];
  const routes = Object.freeze({
    find: "FindMatch.htm",
    upcoming: "AnalyzeMatches.htm",
    creation: "MatchCreationAnalyzer.htm",
    open: "AnalyzeMatch.html",
    recruit: "RecruitMatch.html",
    challenges: "ChallengeListAssistant.html",
    teamPoints: "TeamPointsAdmin.html",
    teamInsights: "TeamInsights.html",
    tournaments: "Tournaments.html",
    analyzeMatchModal: "AnalyzeMatchModal.html",
    ...(existing.routes || {})
  });

  window.P2K_SITE_CONFIG = Object.freeze({
    version: "2.10.9.5",
    builtAt: "2026-08-30T21:10:18Z",
    schemaVersion: 6,
    siteName: existing.siteName || branding.title || "Promote to King",
    siteDescription: existing.siteDescription || branding.subtitle || "Play together. Improve together. Promote to King.",
    logoPath: existing.logoPath || branding.logoPath || "assets/images/p2k-logo.jpg",
    logoAlt: existing.logoAlt || branding.logoAlt || "Promote to King club logo",
    clubSlug: configuredClubSlug,
    clubUrl: configuredClubUrl,
    leagueAcronyms: Object.freeze(
      Array.isArray(existing.leagueAcronyms)
        ? [...existing.leagueAcronyms]
        : ["1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW"]
    ),
    routes,
    serverStorage: Object.freeze({
      challengeClubListEndpoint: existing.serverStorage?.challengeClubListEndpoint || "api/challenge-club-list/",
      matchAssistantLogEndpoint: existing.serverStorage?.matchAssistantLogEndpoint || "api/match-assistant-log/",
      matchAssistantLogsEndpoint: existing.serverStorage?.matchAssistantLogsEndpoint || "api/match-assistant-logs/",
      trackUpcomingLeagueMatchesEndpoint: existing.serverStorage?.trackUpcomingLeagueMatchesEndpoint || "api/track-upcoming-league-matches/",
      matchHistoryEndpoint: existing.serverStorage?.matchHistoryEndpoint || "api/match-history/",
      trackedMatchDataEndpoint: existing.serverStorage?.trackedMatchDataEndpoint || "api/tracked-match-data/",
      scheduledTaskLogsEndpoint: existing.serverStorage?.scheduledTaskLogsEndpoint || "api/scheduled-task-logs/",
      scheduledTaskLogEndpoint: existing.serverStorage?.scheduledTaskLogEndpoint || "api/scheduled-task-log/",
      leagueMatchReferencesEndpoint: existing.serverStorage?.leagueMatchReferencesEndpoint || "api/league-match-references/",
      recordLeagueMatchEndpoint: existing.serverStorage?.recordLeagueMatchEndpoint || "api/record-league-match/",
      diagnosticsEndpoint: existing.serverStorage?.diagnosticsEndpoint || "api/diagnostics/",
      trafficAnalyticsEndpoint: existing.serverStorage?.trafficAnalyticsEndpoint || "api/traffic/",
      teamPointsEndpoint: existing.serverStorage?.teamPointsEndpoint || "server/team-points/public/api.php",
      teamPointsSessionEndpoint: existing.serverStorage?.teamPointsSessionEndpoint || "server/team-points/public/session.php",
      teamPointsPublicEndpoint: existing.serverStorage?.teamPointsPublicEndpoint || "server/team-points/public/public.php",
      opportunisticObservationEndpoint: existing.serverStorage?.opportunisticObservationEndpoint || "server/team-points/public/observe.php",
      acamrPlanEndpoint: existing.serverStorage?.acamrPlanEndpoint || "server/team-points/public/acamr-plan.php"
    }),
    modelVersions: Object.freeze({
      matchAssistant: Object.freeze({
        label: "Match Assistant eligibility and recommendation",
        version: "FM-32",
        source: "FindMatch v32"
      }),
      upcomingAnalysis: Object.freeze({
        label: "Upcoming lineup, probability and recruitment",
        version: "UA-45",
        source: "Upcoming Matches Analyzer v45"
      }),
      matchCreation: Object.freeze({
        label: "Match Creation scoring and secured outcomes",
        version: "MC-23",
        source: "Match Creation Analyzer v23"
      }),
      inProgressProjection: Object.freeze({
        label: "In-progress match score projection",
        version: "AM-3",
        source: "Analyze Match v3"
      }),
      recruitmentEligibility: Object.freeze({
        label: "Recruitment candidate eligibility",
        version: "RM-2",
        source: "Recruit Match stored-rating top-player model v2"
      }),
      challengeValidation: Object.freeze({
        label: "Challenge validation, error classification, and recommendation",
        version: "CL-4",
        source: "Challenge validation and recommendation model v4"
      }),
      matchHistory: Object.freeze({
        label: "Match registration history and probability evolution",
        version: "MH-1",
        source: "Match history model v1"
      })
    }),
    api: Object.freeze({
      allowedOrigins: Object.freeze(["https://api.chess.com"]),
      defaultConcurrency: 5,
      maximumConcurrency: 12,
      requestTimeoutMs: 20000,
      defaultAttempts: 3,
      jsonpFallback: true,
      cache: Object.freeze({
        scheduledPruningEnabled: false,
        memoryMaximumEntries: 180,
        memoryMaximumBytes: 8 * 1024 * 1024,
        calibrationProposal: Object.freeze({
          observationDays: 30,
          clubMatchIndexMaximumAgeHours: 6,
          playerMaximumAgeHours: 48,
          activeMatchMaximumAgeDays: 7,
          finishedMatchMaximumAgeDays: 365,
          unknownMatchMaximumAgeDays: 30,
          maximumEntries: 10000,
          approximateMaximumMiB: 512,
          storageQuotaFraction: 0.60,
          minimumRecentMatchEntries: 5000
        })
      }),
      ...(existing.api || {})
    }),
    auth: Object.freeze({
      ...(existing.auth || {}),
      adminUsernames: Object.freeze(configuredAdminUsernames.map(value => String(value || "").trim()).filter(Boolean))
    }),
    features: Object.freeze({
      simulatedOAuth: existing.features?.simulatedOAuth === true,
      analytics: existing.features?.analytics !== false,
      diagnostics: existing.features?.diagnostics !== false,
      trafficAnalytics: existing.features?.trafficAnalytics !== false,
      analysisSynchronization: existing.features?.analysisSynchronization !== false
    })
  });
})();

// First-party cookieless traffic analytics. Loading is centralized here so every
// page using site-config inherits the same privacy contract.
(() => {
  if (window.P2K_SITE_CONFIG?.features?.trafficAnalytics === false) return;
  if (document.querySelector('script[data-p2k-traffic-analytics]')) return;
  const script=document.createElement('script');script.src='assets/js/shared/traffic-analytics.js?v=2.10.6.24';script.defer=true;script.dataset.p2kTrafficAnalytics='1';const mount=()=>document.head.appendChild(script);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();

// Shared live-chart maximize/restore controller.
(() => {
  if (document.querySelector('script[data-p2k-chart-maximize]')) return;
  const script=document.createElement('script');script.src='assets/js/shared/chart-maximize.js?v=2.10.6.24';script.defer=true;script.dataset.p2kChartMaximize='1';const mount=()=>document.head.appendChild(script);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();

// Members Insights period/ranking enhancement. The module wraps the lazy Insights
// factory, so it adds its controls without changing the large dashboard controller.
(() => {
  if (document.querySelector('script[data-p2k-members-insights-enhancement]')) return;
  const script=document.createElement('script');script.src='assets/js/pages/members-insights-enhancement.js?v=2.10.9.5-members-ranking-1';script.async=false;script.dataset.p2kMembersInsightsEnhancement='1';const mount=()=>document.head.appendChild(script);if(document.head)mount();else document.addEventListener('DOMContentLoaded',mount,{once:true});
})();
