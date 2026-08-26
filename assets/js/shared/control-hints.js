(() => {
  "use strict";

  if (window.__P2K_CONTROL_HINTS_INSTALLED__) return;
  window.__P2K_CONTROL_HINTS_INSTALLED__ = true;

  const page = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();
  const excludedSelector = [
    ".p2k-info-button",
    ".p2k-info-popover",
    ".p2k-info-popover *",
    "[data-p2k-info-message]",
    "[aria-controls='p2kSharedInfoPopover']"
  ].join(",");

  const globalHints = [
    [".p2k-chart-modal-close, .site-diagnostics-close, .p2k-auth-close, .p2k-detailed-analysis-close", "Close this dialog."],
    [".p2k-cancel-button, .cancel-button", "Stop the current operation and keep results loaded so far."],
    [".p2k-detail-toggle", "Show or hide the detailed lineup analysis for this match."],
    [".p2k-chart-expand", "Open this chart in a larger view."],
    ["#p2kLoadMore", "Display the next five results without reloading data."],
    ["#p2kLoadAll", "Display all remaining results without reloading data."],
    ["[data-registration-list-toggle]", "Show more or fewer registration dates."],
    ["[data-opponent-action='expand']", "Expand every currently visible opposing team."],
    ["[data-opponent-action='collapse']", "Collapse all opposing-team sections."],
    ["[data-chart-mode='matches']", "Chart the number of matches started each day."],
    ["[data-chart-mode='boards']", "Chart the number of boards started each day."],
    ["[data-upcoming-chart-mode='matches']", "Chart upcoming registration matches by start date."],
    ["[data-upcoming-chart-mode='boards']", "Chart upcoming registration boards by start date."],
    [".p2k-recruitment-recap-toggle", "Expand or collapse the recruitment summary."],
    ["details > summary", "Expand or collapse this section."]
  ];

  const pageHints = {
    "index.html": [
      ["#p2kDiagnosticsOpen", "Open logs, runtime diagnostics, and match data management."],
      ["#tab-find", "Find open matches suitable for a Chess.com player."],
      ["#tab-upcoming", "Analyze upcoming lineups and recruitment needs."],
      ["#tab-creation", "Review match volume, opponents, players, and scoring."],
      ["#tab-open", "Analyze one Chess.com team match by ID or URL."],
      ["#tab-recruit", "Find team members eligible to join a selected match."],
      ["#tab-challenges", "Check club URLs and recent club match activity."],
      ["#p2kDiagnosticsRefresh", "Refresh runtime diagnostics."],
      ["#p2kDiagnosticsClearCache", "Delete locally cached Chess.com API responses."],
      ["#p2kDiagnosticsCopy", "Copy the full diagnostics report, including the selected usage-log summary."],
      ["#p2kUsageLogFrom", "Select the first UTC date included in the usage-log report."],
      ["#p2kUsageLogTo", "Select the last UTC date included in the usage-log report."],
      ["#p2kUsageLogUser", "Filter usage logs by a full or partial Chess.com username."],
      ["#p2kUsageLogApply", "Load usage logs using the selected dates and username filter."],
      ["#p2kUsageLogReset", "Reset the report to the latest seven UTC calendar days."],
      ["#p2kUsageLogMore", "Extend the selected period seven days further into the past."],
      ["#p2kAdminTabLogs", "Show Match Assistant usage logs."],
      ["#p2kAdminTabDiagnostics", "Show runtime, cache, model, and API diagnostics."],
      ["#p2kAdminTabCleanup", "Record, review, and remove stored match-history snapshots."],
      ["#p2kMatchTrackNow", "Record current upcoming league-match snapshots now."],
      ["#p2kMatchCleanupRefresh", "Reload the tracked-match file inventory."],
      [".site-cleanup-remove", "Remove every stored snapshot file for this match after confirmation."]
    ],
    "analyzematches.htm": [
      ["#p2kAnalyzeButton", "Load current upcoming matches and calculate lineup recommendations."],
      ["#p2kMatchSearch", "Filter loaded matches by match name or opponent club."],
      ["#p2kClearSearch", "Clear the match search."],
      ["#p2kCopyFormatted", "Copy rich content for pasting into a formatted editor."],
      ["#p2kCopyPlainText", "Copy concise recruitment lines as plain text."],
      ["#p2kCopyHTMLSource", "Copy the generated HTML source as plain text."],
      ["#p2kCopySelectAll", "Select all text in the manual-copy field."]
    ],
    "analyzematchmodal.html": [
      ["#p2kAnalyzeButton", "Load current upcoming matches and calculate lineup recommendations."],
      ["#p2kMatchSearch", "Filter loaded matches by match name or opponent club."],
      ["#p2kClearSearch", "Clear the match search."],
      ["#p2kCopyFormatted", "Copy rich content for pasting into a formatted editor."],
      ["#p2kCopyPlainText", "Copy concise recruitment lines as plain text."],
      ["#p2kCopyHTMLSource", "Copy the generated HTML source as plain text."],
      ["#p2kCopySelectAll", "Select all text in the manual-copy field."]
    ],
    "analyzematch.html": [
      ["#p2kMatchReference", "Enter a Chess.com team match ID or full match URL."],
      ["#p2kAnalyzeButton", "Load the match and calculate lineup and score projections."]
    ],
    "recruitmatch.html": [
      ["#p2kMatchReference", "Enter a Chess.com team match ID or full match URL."],
      ["#p2kLoadButton", "Load the match, teams, and rating requirements."],
      ["#p2kMaxTimeout", "Exclude players whose timeout percentage is above this value."],
      ["#p2kUseCurrentGames", "Enable minimum and maximum current-game limits."],
      ["#p2kMinCurrentGames", "Require at least this many current Daily Chess games."],
      ["#p2kMaxCurrentGames", "Allow at most this many current Daily Chess games."],
      ["#p2kScanButton", "Check team members and list players eligible for recruitment."]
    ],
    "matchcreationanalyzer.htm": [
      ["#p2kCreationAnalyzeButton", "Load detailed match records for all analysis tabs."],
      ["[data-progress-stage='registration']", "Display the cumulative analysis after registration matches."],
      ["[data-progress-stage='last30']", "Display registration plus matches from the last 30 days."],
      ["[data-progress-stage='remaining']", "Display the complete analysis of all matches."],
      ["#p2kOpponentSearch", "Filter teams, matches, or loaded player usernames."],
      ["[data-tab='creation']", "Show match-creation volume and timing statistics."],
      ["[data-tab='opponents']", "Show active opposing teams, matches, and players."],
      ["[data-tab='scoring']", "Show completed and projected scoring statistics."]
    ],
    "challengelistassistant.html": [
      ["#p2kClubCheckerTab", "Find club URLs whose Chess.com endpoint returns an error."],
      ["#p2kActivityCheckerTab", "Find clubs with no recent or active Daily team matches."],
      ["#p2kCheckerInput", "Paste one Chess.com club URL or slug per line."],
      ["#p2kCheckerFile", "Choose a text or CSV file containing club URLs or slugs."],
      ["#p2kCheckerStart", "Start or retry the club URL validation run."],
      ["#p2kCheckerPause", "Pause after the current club request finishes."],
      ["#p2kCheckerStop", "Stop the run and keep results already collected."],
      ["#p2kCheckerClear", "Clear the input and all URL-check results."],
      ["#p2kCheckerCopy", "Copy confirmed error URLs to the clipboard."],
      ["#p2kCheckerDownload", "Download confirmed error URLs as CSV."],
      ["#p2kActivityInput", "Paste one Chess.com club URL or slug per line."],
      ["#p2kActivityFile", "Choose a text or CSV file containing club URLs or slugs."],
      ["#p2kActivityStart", "Start or retry the club activity check."],
      ["#p2kActivityPause", "Pause after the current club request finishes."],
      ["#p2kActivityStop", "Stop the run and keep results already collected."],
      ["#p2kActivityClear", "Clear the input and all activity-check results."],
      ["#p2kActivityCopy", "Copy inactive club URLs to the clipboard."],
      ["#p2kActivityDownload", "Download inactive club URLs as CSV."]
    ]
  };

  const radioHints = {
    p2kLockFilter: {
      open: "Show registration matches that are still open.",
      all: "Include both open and locked registration matches."
    },
    p2kSortMode: {
      date: "Order matches by scheduled start date.",
      priority: "Show configured league-priority matches first."
    },
    p2kLockableFilter: {
      all: "Show every match allowed by the other filters.",
      lockable: "Show only matches that can currently be locked."
    },
    p2kSelectedTeam: {
      "*": "Analyze the match from this team's perspective."
    },
    p2kRecruitTeam: {
      "*": "Search eligible recruits for this team."
    },
    p2kPlayerTeamScope: {
      either: "Match player searches on either team.",
      own: "Match player searches on Promote to King only.",
      opponent: "Match player searches on opponent teams only."
    },
    p2kOpponentStatusFilter: {
      all: "Include registration and ongoing matches.",
      registered: "Show registration matches only."
    },
    p2kOpponentOrder: {
      matches: "Order opposing teams by total active matches.",
      boards: "Order opposing teams by total active boards."
    }
  };

  function excluded(element) {
    return !(element instanceof Element) || Boolean(element.closest(excludedSelector));
  }

  function setHint(element, hint, includeLabel = true) {
    if (!(element instanceof HTMLElement) || excluded(element) || !hint) return;
    if (!element.hasAttribute("title")) element.setAttribute("title", hint);

    if (includeLabel && element.matches("input, select, textarea")) {
      const labels = new Set();
      const wrappingLabel = element.closest("label");
      if (wrappingLabel) labels.add(wrappingLabel);
      if (element.id) {
        document.querySelectorAll("label[for]").forEach(label => {
          if (label.htmlFor === element.id) labels.add(label);
        });
      }
      labels.forEach(label => {
        if (!excluded(label) && !label.hasAttribute("title")) {
          label.setAttribute("title", hint);
        }
      });
    }
  }

  function applySelectorHints(rules) {
    for (const [selector, hint] of rules) {
      document.querySelectorAll(selector).forEach(element => setHint(element, hint));
    }
  }

  function applyRadioHints() {
    for (const [name, values] of Object.entries(radioHints)) {
      document.querySelectorAll(`input[name="${name}"]`).forEach(input => {
        const hint = values[input.value] || values["*"];
        setHint(input, hint);
      });
    }
  }

  function applyDynamicTextHints() {
    document.querySelectorAll("button").forEach(button => {
      if (excluded(button) || button.hasAttribute("title")) return;
      const text = button.textContent.replace(/\s+/g, " ").trim().toLowerCase();
      if (text === "show details" || text === "hide details") {
        setHint(button, "Show or hide the detailed lineup analysis for this match.");
      } else if (text === "view larger") {
        setHint(button, "Open this chart in a larger view.");
      } else if (text === "matches") {
        setHint(button, "Display match counts in this chart.");
      } else if (text === "boards") {
        setHint(button, "Display board counts in this chart.");
      } else if (text === "close") {
        setHint(button, "Close this dialog.");
      }
    });
  }

  function applyHints() {
    applySelectorHints(globalHints);
    applySelectorHints(pageHints[page] || []);
    applyRadioHints();
    applyDynamicTextHints();
  }

  let scheduled = false;
  function scheduleApply() {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      applyHints();
    });
  }

  function start() {
    applyHints();
    const observer = new MutationObserver(scheduleApply);
    observer.observe(document.documentElement, { childList: true, subtree: true });
    window.addEventListener("pagehide", () => observer.disconnect(), { once: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
