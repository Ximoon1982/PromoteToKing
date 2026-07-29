/* Match Assistant compatibility bootstrap and current feature patches. */
(() => {
  "use strict";
  const baseURL = window.P2K_SITE_CONFIG?.legacySources?.findMatch || "https://raw.githubusercontent.com/Ximoon1982/P2KMatchFinder/ee3de88243845808d5de5adb957dc79ae6a54ad1/FindMatch_v1.htm";
  const scoreCSS = "\n    .recommendation-line {\n      display: flex;\n      flex-wrap: wrap;\n      align-items: center;\n      gap: 5px 7px;\n    }\n    .recommendation-score {\n      display: inline-flex;\n      min-width: 54px;\n      align-items: center;\n      justify-content: center;\n      padding: 3px 7px;\n      border: 1px solid rgba(255, 255, 255, .28);\n      border-radius: 999px;\n      font-size: 11px;\n      font-weight: 800;\n      line-height: 1;\n    }\n    .recommendation-score-high {\n      border-color: rgba(104, 202, 116, .65);\n      background: rgba(80, 175, 92, .18);\n      color: #9be5a4;\n    }\n    .recommendation-score-medium {\n      border-color: rgba(246, 183, 60, .62);\n      background: rgba(217, 141, 24, .16);\n      color: #ffd078;\n    }\n    .recommendation-score-low {\n      border-color: rgba(255, 255, 255, .24);\n      background: rgba(255, 255, 255, .06);\n      color: #c9c2b9;\n    }\n    .recommendation-score-label {\n      color: #f0e4cf;\n      font-size: 12px;\n      font-weight: 800;\n    }\n    .recommendation-info-wrap {\n      position: relative;\n      display: inline-flex;\n      align-items: center;\n    }\n    .recommendation-info {\n      display: inline-flex;\n      width: 17px;\n      height: 17px;\n      padding: 0;\n      align-items: center;\n      justify-content: center;\n      border: 1px solid rgba(158, 216, 245, .55);\n      border-radius: 50%;\n      background: rgba(158, 216, 245, .08);\n      color: #9ed8f5;\n      font: inherit;\n      font-size: 10px;\n      font-weight: 800;\n      line-height: 1;\n      cursor: pointer;\n    }\n    .recommendation-info:hover,\n    .recommendation-info:focus-visible {\n      border-color: #9ed8f5;\n      background: rgba(158, 216, 245, .17);\n      outline: none;\n    }\n    .recommendation-info-tooltip {\n      display: none;\n      position: fixed;\n      z-index: 999999;\n      left: 50%;\n      top: 50%;\n      transform: translate(-50%, -50%);\n      width: min(360px, calc(100vw - 24px));\n      max-width: calc(100vw - 24px);\n      max-height: calc(100vh - 24px);\n      max-height: calc(100dvh - 24px);\n      overflow-x: hidden;\n      overflow-y: auto;\n      overscroll-behavior: contain;\n      -webkit-overflow-scrolling: touch;\n      padding: 12px 14px;\n      border: 1px solid rgba(158, 216, 245, .58);\n      border-radius: 10px;\n      background: #24211e;\n      box-shadow: 0 12px 36px rgba(0, 0, 0, .62);\n      color: #eee5d8;\n      font-size: 12px;\n      font-weight: 400;\n      line-height: 1.48;\n      text-align: left;\n      white-space: normal;\n      overflow-wrap: anywhere;\n      pointer-events: auto;\n    }\n    .recommendation-info-tooltip strong {\n      color: #9ed8f5;\n      font-weight: 800;\n    }\n    .recommendation-analysis-button {\n      display: flex;\n      width: 100%;\n      min-height: 36px;\n      margin-top: 12px;\n      padding: 8px 11px;\n      align-items: center;\n      justify-content: center;\n      border: 1px solid rgba(246, 183, 60, .68);\n      border-radius: 8px;\n      background: rgba(217, 141, 24, .16);\n      color: #ffd078;\n      font-size: 12px;\n      font-weight: 800;\n      line-height: 1.25;\n      text-align: center;\n      text-decoration: none;\n      cursor: pointer;\n      pointer-events: auto;\n    }\n    .recommendation-analysis-button:hover,\n    .recommendation-analysis-button:focus-visible {\n      border-color: #f6b73c;\n      background: rgba(217, 141, 24, .26);\n      color: #ffe1a1;\n      outline: none;\n    }\n    .recommendation-info-wrap:hover .recommendation-info-tooltip,\n    .recommendation-info-wrap.is-open .recommendation-info-tooltip {\n      display: block;\n    }\n    .p2k-detailed-analysis-modal[hidden] {\n      display: none !important;\n    }\n    .p2k-detailed-analysis-modal {\n      position: fixed;\n      inset: 0;\n      z-index: 1000000;\n      display: flex;\n      align-items: center;\n      justify-content: center;\n      padding: 12px;\n      background: rgba(0, 0, 0, .78);\n      backdrop-filter: blur(2px);\n    }\n    .p2k-detailed-analysis-dialog {\n      position: relative;\n      width: min(1180px, calc(100vw - 24px));\n      height: min(900px, calc(100vh - 24px));\n      height: min(900px, calc(100dvh - 24px));\n      overflow: hidden;\n      border: 1px solid rgba(246, 183, 60, .65);\n      border-radius: 12px;\n      background: #171513;\n      box-shadow: 0 18px 58px rgba(0, 0, 0, .72);\n    }\n    .p2k-detailed-analysis-header {\n      display: flex;\n      min-height: 48px;\n      align-items: center;\n      justify-content: space-between;\n      gap: 12px;\n      padding: 9px 10px 9px 14px;\n      border-bottom: 1px solid rgba(246, 183, 60, .30);\n      background: #24211e;\n    }\n    .p2k-detailed-analysis-title {\n      color: #ffd078;\n      font-size: 14px;\n      font-weight: 900;\n    }\n    .p2k-detailed-analysis-close {\n      min-width: 36px;\n      min-height: 32px;\n      padding: 5px 9px;\n      border: 1px solid rgba(255, 114, 95, .62);\n      border-radius: 7px;\n      background: rgba(217, 74, 58, .14);\n      color: #ff8b79;\n      font-size: 13px;\n      font-weight: 800;\n      cursor: pointer;\n    }\n    .p2k-detailed-analysis-close:hover,\n    .p2k-detailed-analysis-close:focus-visible {\n      border-color: #ff8b79;\n      background: rgba(217, 74, 58, .24);\n      outline: none;\n    }\n    .p2k-detailed-analysis-frame-wrap {\n      position: absolute;\n      inset: 49px 0 0;\n      background: #171513;\n    }\n    .p2k-detailed-analysis-loading {\n      position: absolute;\n      inset: 0;\n      z-index: 1;\n      display: flex;\n      align-items: center;\n      justify-content: center;\n      padding: 18px;\n      color: #ffd078;\n      font-size: 13px;\n      font-weight: 800;\n      text-align: center;\n      pointer-events: none;\n    }\n    .p2k-detailed-analysis-frame {\n      position: relative;\n      z-index: 2;\n      width: 100%;\n      height: 100%;\n      border: 0;\n      background: #171513;\n    }\n    body.p2k-analysis-modal-open {\n      overflow: hidden !important;\n    }\n    @media (max-width: 600px) {\n      .p2k-detailed-analysis-modal { padding: 4px; }\n      .p2k-detailed-analysis-dialog {\n        width: calc(100vw - 8px);\n        height: calc(100vh - 8px);\n        height: calc(100dvh - 8px);\n        border-radius: 8px;\n      }\n      .p2k-detailed-analysis-header { min-height: 44px; padding-left: 10px; }\n      .p2k-detailed-analysis-frame-wrap { inset: 45px 0 0; }\n    }\n\n";
  /* P2K_USERNAME_SEARCH_ROW */
  const usernameSearchRowCSS = `
    .p2k-user-search-row {
      display: flex;
      width: 100%;
      align-items: stretch;
      gap: 10px;
    }

    .p2k-user-search-row .username {
      width: auto;
      min-width: 0;
      flex: 1 1 auto;
    }

    .p2k-user-search-row .search-button {
      width: auto;
      flex: 0 0 auto;
      margin-top: 0 !important;
      white-space: nowrap;
    }

    @media (max-width: 620px) {
      .p2k-user-search-row {
        gap: 7px;
      }

      .p2k-user-search-row .search-button {
        width: auto;
        padding-right: 14px;
        padding-left: 14px;
      }
    }
  `;

  function installUsernameSearchRow(html) {
    html = mustReplace(
      html,
      /\n  <\/style>/,
      usernameSearchRowCSS + "\n  </style>",
      "username and Explore button row styles"
    );

    return mustReplace(
      html,
      /(\n    <input[\s\S]*?\bid="p2kUsername"[\s\S]*?\n    >)\s*(\n    <button[\s\S]*?\bid="p2kSearchButton"[\s\S]*?<\/button>)/,
      '\n    <div class="p2k-user-search-row">$1$2\n    </div>',
      "username and Explore button row"
    );
  }

  const registrationReplacement = "const registrationMatches = p2kPrioritizeMatchReferences(\n            Array.isArray(clubMatches.registered)\n              ? clubMatches.registered.filter(item => String(item?.time_class || \"\").toLowerCase() === \"daily\")\n              : []\n          );";
  const sortToggleHTML = `    <fieldset class="filter-group" title="Choose how the analyzed match results should be ordered.">
      <legend class="filter-title">Sort results</legend>
      <div class="choices">
        <label class="choice" title="Order matches from the highest recommendation score to the lowest.">
          <input type="radio" name="p2kSortMode" value="score" checked title="Sort primarily by recommendation score.">
          <span>Recommendation score</span>
        </label>
        <label class="choice" title="Order matches from the earliest scheduled start date to the latest.">
          <input type="radio" name="p2kSortMode" value="date" title="Sort primarily by start date.">
          <span>Start date</span>
        </label>
      </div>
    </fieldset>
`;
  const selectedSortModeFunction = `      function selectedSortMode() {
        return document.querySelector('input[name="p2kSortMode"]:checked')?.value || "score";
      }

      function installDetailedAnalysisModal() {
        if (document.getElementById("p2kDetailedAnalysisModal")) return;
        const modal = document.createElement("div");
        modal.id = "p2kDetailedAnalysisModal";
        modal.className = "p2k-detailed-analysis-modal";
        modal.hidden = true;
        modal.setAttribute("aria-hidden", "true");
        modal.innerHTML = '<div class="p2k-detailed-analysis-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kDetailedAnalysisTitle">' +
          '<div class="p2k-detailed-analysis-header">' +
            '<div id="p2kDetailedAnalysisTitle" class="p2k-detailed-analysis-title">Detailed match analysis</div>' +
            '<button type="button" class="p2k-detailed-analysis-close" aria-label="Close detailed match analysis">Close</button>' +
          '</div>' +
          '<div class="p2k-detailed-analysis-frame-wrap">' +
            '<div class="p2k-detailed-analysis-loading">Loading detailed match analysis…</div>' +
            '<iframe class="p2k-detailed-analysis-frame" title="Detailed match analysis" src="about:blank"></iframe>' +
          '</div>' +
        '</div>';
        modal.addEventListener("click", event => {
          if (event.target === modal) window.p2kCloseDetailedAnalysis();
        });
        modal.querySelector(".p2k-detailed-analysis-close")?.addEventListener("click", window.p2kCloseDetailedAnalysis);
        modal.querySelector(".p2k-detailed-analysis-frame")?.addEventListener("load", () => {
          const loading = modal.querySelector(".p2k-detailed-analysis-loading");
          if (loading) loading.style.display = "none";
        });
        document.body.appendChild(modal);
      }

      window.p2kOpenDetailedAnalysis = function(matchReference) {
        installDetailedAnalysisModal();
        const modal = document.getElementById("p2kDetailedAnalysisModal");
        const frame = modal?.querySelector(".p2k-detailed-analysis-frame");
        const loading = modal?.querySelector(".p2k-detailed-analysis-loading");
        if (!modal || !frame) return;
        const rawReference = String(matchReference || "").trim();
        const matchId = rawReference.match(/(\\d+)(?:\\/)?$/)?.[1] || rawReference;
        const url = new URL(window.P2K_SITE_CONFIG?.routes?.analyzeMatchModal || "AnalyzeMatchModal.html", window.location.href);
        url.searchParams.set("match", matchId);
                if (loading) loading.style.display = "flex";
        frame.src = url.href;
        modal.hidden = false;
        modal.setAttribute("aria-hidden", "false");
        document.body.classList.add("p2k-analysis-modal-open");
        requestAnimationFrame(() => modal.querySelector(".p2k-detailed-analysis-close")?.focus());
      };

      window.p2kCloseDetailedAnalysis = function() {
        const modal = document.getElementById("p2kDetailedAnalysisModal");
        const frame = modal?.querySelector(".p2k-detailed-analysis-frame");
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("p2k-analysis-modal-open");
        if (frame) frame.src = "about:blank";
      };

      window.addEventListener("keydown", event => {
        if (event.key === "Escape") window.p2kCloseDetailedAnalysis?.();
      });
`;
  const scoreFunctions = "\n      function recommendationScoreLabel(score) {\n        if (score >= 80) return \"Strongly recommended\";\n        if (score >= 60) return \"Recommended\";\n        if (score >= 40) return \"Worth considering\";\n        return \"Low priority\";\n      }\n\n      function computeRecommendationScore(\n        match,\n        promoteTeam,\n        opponentTeam,\n        username,\n        profileRating,\n        registered,\n        bounds,\n        maximumPlayers,\n        minimumPlayers,\n        eligiblePromotePlayers,\n        lineupSelected\n      ) {\n        const factors = [];\n        let score = 0;\n        const promotePlayers = Array.isArray(promoteTeam?.players) ? promoteTeam.players : [];\n        const opponentPlayers = Array.isArray(opponentTeam?.players) ? opponentTeam.players : [];\n        const opponentEligiblePlayers = countEligibleRegisteredPlayers(opponentTeam, bounds);\n        const targetEligiblePlayers = Number.isFinite(maximumPlayers)\n          ? Math.min(maximumPlayers, Math.max(minimumPlayers, opponentEligiblePlayers))\n          : Math.max(minimumPlayers, opponentEligiblePlayers);\n        const eligibleDeficit = Math.max(0, targetEligiblePlayers - eligiblePromotePlayers);\n        const playerDeficit = Math.max(0, opponentPlayers.length - promotePlayers.length);\n\n        if (isPriorityMatch(match)) {\n          score += 22;\n          factors.push(\"priority league match +22\");\n        }\n\n        if (eligibleDeficit > 0) {\n          const points = Math.min(22, eligibleDeficit * 6);\n          score += points;\n          factors.push(eligibleDeficit + \" eligible-player gap +\" + points);\n        }\n\n        if (playerDeficit > 0) {\n          const points = Math.min(12, playerDeficit * 3);\n          score += points;\n          factors.push(playerDeficit + \" registration gap +\" + points);\n        }\n\n        const opponentRatings = limitLineupRatings(numericPlayerRatings(opponentTeam), maximumPlayers);\n        const promoteRatings = limitLineupRatings(numericPlayerRatings(promoteTeam), maximumPlayers);\n        const currentComparison = pairedLineupAdvantage(promoteRatings, opponentRatings);\n\n        if (currentComparison && currentComparison.opponentAdvantage > 0) {\n          const points = Math.min(16, Math.round(currentComparison.opponentAdvantage / 8));\n          score += points;\n          factors.push(\"lineup deficit +\" + points);\n        }\n\n        let lineupImprovement = 0;\n        if (registered) {\n          const withoutUser = limitLineupRatings(\n            numericPlayerRatings(promoteTeam, username),\n            maximumPlayers\n          );\n          const comparisonWithoutUser = pairedLineupAdvantage(withoutUser, opponentRatings);\n          if (currentComparison && comparisonWithoutUser) {\n            lineupImprovement = Math.max(\n              0,\n              comparisonWithoutUser.opponentAdvantage - currentComparison.opponentAdvantage\n            );\n          }\n        } else {\n          const projected = projectedLineupWithUser(\n            promoteTeam,\n            username,\n            profileRating,\n            maximumPlayers,\n            false\n          );\n          const comparisonWithUser = pairedLineupAdvantage(projected.ratings, opponentRatings);\n          if (currentComparison && comparisonWithUser) {\n            lineupImprovement = Math.max(\n              0,\n              currentComparison.opponentAdvantage - comparisonWithUser.opponentAdvantage\n            );\n          }\n        }\n\n        if (lineupImprovement > 0) {\n          const points = Math.min(20, Math.max(1, Math.round(lineupImprovement / 5)));\n          score += points;\n          factors.push(\"projected lineup improvement +\" + points);\n        }\n\n        if (lineupSelected) {\n          score += 10;\n          factors.push(\"projected in lineup +10\");\n        }\n\n        if (profileRating !== null && Number.isFinite(Number(profileRating)) &&\n            Number(profileRating) >= bounds.minimum && Number(profileRating) <= bounds.maximum) {\n          score += 5;\n          factors.push(\"rating eligible +5\");\n        }\n\n        const startTimestamp = matchStartTimestamp(match);\n        if (startTimestamp !== null) {\n          const daysUntilStart = (startTimestamp * 1000 - Date.now()) / 86400000;\n          const urgencyPoints = daysUntilStart <= 3 ? 10 : daysUntilStart <= 7 ? 8 : daysUntilStart <= 14 ? 5 : daysUntilStart <= 30 ? 2 : 0;\n          if (urgencyPoints > 0) {\n            score += urgencyPoints;\n            factors.push(\"starts soon +\" + urgencyPoints);\n          }\n        }\n\n        score = Math.max(0, Math.min(100, Math.round(score)));\n        return {\n          score,\n          label: recommendationScoreLabel(score),\n          explanation: factors.length > 0\n            ? \"Score components: \" + factors.join(\"; \") + \".\"\n            : \"No specific recruitment, lineup, priority, or urgency need was detected.\"\n        };\n      }\n\n";
  const scoreResultReplacement = "        const scoreDetails = computeRecommendationScore(\n          match,\n          promoteTeam,\n          opponentTeam,\n          username,\n          profileRating,\n          registered,\n          bounds,\n          maximumPlayers,\n          minimumPlayers,\n          eligiblePromotePlayers,\n          lineupSelected\n        );\n        const ruleRecommended = playerCountReason || ratingReason || minimumPlayersReason;\n        return {\n          recommended: ruleRecommended || scoreDetails.score >= 50,\n          reasons,\n          opponentTeam,\n          lineupSelected,\n          eligiblePromotePlayers,\n          minimumPlayers,\n          maximumPlayers,\n          score: scoreDetails.score,\n          scoreLabel: scoreDetails.label,\n          scoreExplanation: scoreDetails.explanation\n        };";
  const sortFunctionReplacement = `      function sortMatchesForDisplay(matches) {
        const sortMode = selectedSortMode();
        return [...matches].sort((a, b) => {
          const aScore = Number(a.recommendation?.score || 0);
          const bScore = Number(b.recommendation?.score || 0);
          const aStart = matchStartTimestamp(a.match) ?? Number.POSITIVE_INFINITY;
          const bStart = matchStartTimestamp(b.match) ?? Number.POSITIVE_INFINITY;
          const priorityDifference = Number(isPriorityMatch(b.match)) - Number(isPriorityMatch(a.match));
          const nameDifference = String(a.match?.name || "").localeCompare(String(b.match?.name || ""));

          if (sortMode === "date") {
            return aStart - bStart || priorityDifference || bScore - aScore || nameDifference;
          }

          return bScore - aScore || priorityDifference || aStart - bStart || nameDifference;
        });
      }`;
  const renderReplacement = "          const recommendationScore = Number(recommendation?.score || 0);\n          const recommendationScoreClass = recommendationScore >= 70\n            ? \"recommendation-score-high\"\n            : recommendationScore >= 40\n              ? \"recommendation-score-medium\"\n              : \"recommendation-score-low\";\n          const recommendationReasonText = recommendation?.reasons?.length\n            ? recommendation.reasons.join(\" \")\n            : \"No specific team need was detected by the rule-based checks.\";\n          const matchReference = String(match?.url || match?.[\"@id\"] || \"\");\n          const matchIdMatch = matchReference.match(/(\\d+)(?:\\/)?$/);\n          const detailedAnalysisReference = matchIdMatch ? matchIdMatch[1] : matchReference;\n          const recommendationInfoHTML = '<strong>Recommendation</strong><br>' + escapeHTML(recommendationReasonText) +\n            '<br><br><strong>Score calculation</strong><br>' + escapeHTML(recommendation?.scoreExplanation || \"No score details are available.\") +\n            '<button type=\"button\" class=\"recommendation-analysis-button\" data-match-reference=\"' + escapeHTML(detailedAnalysisReference) + '\" ' +\n              'onclick=\"var w=this.closest(&#39;.recommendation-info-wrap&#39;);if(w){w.classList.remove(&#39;is-open&#39;);}window.p2kOpenDetailedAnalysis(this.dataset.matchReference);\">Detailed match analysis</button>';\n          const recommendationLine = recommendation\n            ? '<div class=\"recommendation-line\">' +\n                '<span class=\"recommendation-score ' + recommendationScoreClass + '\">' + recommendationScore + '/100</span>' +\n                '<span class=\"recommendation-score-label\">' + escapeHTML(recommendation.scoreLabel || \"Low priority\") + '</span>' +\n                '<span class=\"recommendation-info-wrap\">' +\n                  '<button type=\"button\" class=\"recommendation-info\" aria-label=\"Show recommendation details\" aria-expanded=\"false\" ' +\n                    'onclick=\"var w=this.parentElement,o=!w.classList.contains(&#39;is-open&#39;);w.classList.toggle(&#39;is-open&#39;,o);this.setAttribute(&#39;aria-expanded&#39;,String(o));\">i</button>' +\n                  '<span class=\"recommendation-info-tooltip\" role=\"dialog\" aria-label=\"Recommendation details\">' + recommendationInfoHTML + '</span>' +\n                '</span>' +\n              '</div>'\n            : \"\";";


  function installDetailedAnalysisModal() {
    if (document.getElementById("p2kDetailedAnalysisModal")) return;

    const modal = document.createElement("div");
    modal.id = "p2kDetailedAnalysisModal";
    modal.className = "p2k-detailed-analysis-modal";
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `
      <div class="p2k-detailed-analysis-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kDetailedAnalysisTitle">
        <div class="p2k-detailed-analysis-header">
          <div id="p2kDetailedAnalysisTitle" class="p2k-detailed-analysis-title">Detailed match analysis</div>
          <button type="button" class="p2k-detailed-analysis-close" aria-label="Close detailed match analysis">Close</button>
        </div>
        <div class="p2k-detailed-analysis-frame-wrap">
          <div class="p2k-detailed-analysis-loading">Loading detailed match analysis…</div>
          <iframe class="p2k-detailed-analysis-frame" title="Detailed match analysis" src="about:blank"></iframe>
        </div>
      </div>`;

    modal.addEventListener("click", event => {
      if (event.target === modal) window.p2kCloseDetailedAnalysis();
    });
    modal.querySelector(".p2k-detailed-analysis-close")?.addEventListener("click", window.p2kCloseDetailedAnalysis);
    modal.querySelector(".p2k-detailed-analysis-frame")?.addEventListener("load", () => {
      const loading = modal.querySelector(".p2k-detailed-analysis-loading");
      if (loading) loading.style.display = "none";
    });
    document.body.appendChild(modal);
  }

  window.p2kOpenDetailedAnalysis = function p2kOpenDetailedAnalysis(matchReference) {
    installDetailedAnalysisModal();
    const modal = document.getElementById("p2kDetailedAnalysisModal");
    const frame = modal?.querySelector(".p2k-detailed-analysis-frame");
    const loading = modal?.querySelector(".p2k-detailed-analysis-loading");
    if (!modal || !frame) return;

    const rawReference = String(matchReference || "").trim();
    const matchId = rawReference.match(/(\d+)(?:\/)?$/)?.[1] || rawReference;
    const url = new URL(window.P2K_SITE_CONFIG?.routes?.analyzeMatchModal || "AnalyzeMatchModal.html", window.location.href);
    url.searchParams.set("match", matchId);
    
    if (loading) loading.style.display = "flex";
    frame.src = url.href;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("p2k-analysis-modal-open");
    requestAnimationFrame(() => modal.querySelector(".p2k-detailed-analysis-close")?.focus());
  };

  window.p2kCloseDetailedAnalysis = function p2kCloseDetailedAnalysis() {
    const modal = document.getElementById("p2kDetailedAnalysisModal");
    const frame = modal?.querySelector(".p2k-detailed-analysis-frame");
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("p2k-analysis-modal-open");
    if (frame) frame.src = "about:blank";
  };

  window.addEventListener("keydown", event => {
    if (event.key === "Escape") window.p2kCloseDetailedAnalysis?.();
  });

  function mustReplace(html, pattern, replacement, label) {
    const updated = html.replace(pattern, replacement);
    if (updated === html) throw new Error("Unable to install " + label + ".");
    return updated;
  }

  function installSimulatedOAuthAssets(html) {
    if (!/assets\/css\/simulated-oauth\.css/i.test(html)) {
      html = mustReplace(
        html,
        /<\/head>/i,
        '  <link rel="stylesheet" href="assets/css/simulated-oauth.css">\n</head>',
        "simulated OAuth styles"
      );
    }

    if (!/assets\/js\/shared\/simulated-oauth\.js/i.test(html)) {
      html = mustReplace(
        html,
        /<\/body>/i,
        '  <script src="assets/js/shared/simulated-oauth.js"></script>\n</body>',
        "simulated OAuth runtime"
      );
    }

    return html;
  }

  function addRecommendationScores(html) {
    html = installSimulatedOAuthAssets(html);
    html = installUsernameSearchRow(html);
    /* P2K_GENERATED_FAVICON */
    if (!/<link\b[^>]*\brel=["'][^"']*\bicon\b/i.test(html)) {
      html = mustReplace(
        html,
        /<\/title>/i,
        '</title>\n  <link rel="icon" type="image/jpeg" href="assets/images/p2k-logo.jpg">',
        "generated Match Assistant favicon"
      );
    }
    html = html.replace(
      /<!--\s*Version 19:[\s\S]*?-->/i,
      "<!-- Version 30: version 29 functionality plus optional simulated Chess.com OAuth via ?oauth=1 -->"
    );

    html = mustReplace(
      html,
      /const registrationMatches = Array\.isArray\(clubMatches\.registered\)\s*\? clubMatches\.registered\.filter\(item => String\(item\?\.time_class \|\| ""\)\.toLowerCase\(\) === "daily"\)\s*:\s*\[\];/,
      registrationReplacement,
      "priority-first match processing"
    );

    html = mustReplace(html, /\n  <\/style>/, scoreCSS + "\n  </style>", "recommendation score styles");

    html = mustReplace(
      html,
      /    <div class="color-legend"/,
      sortToggleHTML + '    <div class="color-legend"',
      "result sorting toggle"
    );

    html = mustReplace(
      html,
      /      function normalizeUsername\(value\) \{/,
      selectedSortModeFunction + "      function normalizeUsername(value) {",
      "selected sorting mode function"
    );

    html = mustReplace(
      html,
      /      function evaluateRecommendation\(match, promoteTeam, username, profileRating, registered, bounds\) \{/,
      scoreFunctions + "      function evaluateRecommendation(match, promoteTeam, username, profileRating, registered, bounds) {",
      "recommendation scoring functions"
    );

    html = mustReplace(
      html,
      /        return \{\n          recommended: playerCountReason \|\| ratingReason \|\| minimumPlayersReason,\n          reasons,\n          opponentTeam,\n          lineupSelected,\n          eligiblePromotePlayers,\n          minimumPlayers,\n          maximumPlayers\n        \};/,
      scoreResultReplacement,
      "recommendation score result"
    );

    const sortFunctionPattern = /^      function sortMatchesForDisplay\(matches\)\s*\{[\s\S]*?^      \}/m;
    html = mustReplace(
      html,
      sortFunctionPattern,
      sortFunctionReplacement,
      "selectable result sorting"
    );

    html = mustReplace(
      html,
      /          const recommendationLine = recommendation\?\.recommended\n            \? `<div class="recommendation-line"><span class="recommended-badge">Recommended<\/span>\$\{escapeHTML\(recommendation\.reasons\.join\(" "\)\)\}<\/div>`\n            : "";/,
      renderReplacement,
      "recommendation score display"
    );

    html = mustReplace(
      html,
      /'input\[name="p2kMatchType"\], input\[name="p2kRegistrationStatus"\], input\[name="p2kRecommendationFilter"\]'/,
      '\'input[name="p2kMatchType"], input[name="p2kRegistrationStatus"], input[name="p2kRecommendationFilter"], input[name="p2kSortMode"]\'',
      "sorting toggle change listener"
    );

    return html;
  }

  async function loadAssistant() {
    const response = await fetch(baseURL, { cache: "no-store" });
    if (!response.ok) throw new Error("Unable to load base assistant: HTTP " + response.status);

    const html = addRecommendationScores(await response.text());
    document.open();
    document.write(html);
    document.close();
  }

  loadAssistant().catch(error => {
    console.error(error);
    const status = document.getElementById("p2kFindV28LoaderStatus");
    if (status) {
      status.textContent = "Unable to load the assistant: " + (error.message || error);
      status.style.color = "#ff8b79";
    }
  });
})();
