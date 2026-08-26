(() => {
      "use strict";

      const CLUB_ID = window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king";
      const CLUB_MATCHES_URL = `https://api.chess.com/pub/club/${CLUB_ID}/matches`;
      const REQUEST_ATTEMPTS = window.P2K_SITE_CONFIG?.api?.defaultAttempts || 3;
            const PAGE_SIZE = 5;
      const PRIORITY_LEAGUE_ACRONYMS = [...(window.P2K_SITE_CONFIG?.leagueAcronyms || ["1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW"])];
      const usernameInput = document.getElementById("p2kUsername");
      const searchButton = document.getElementById("p2kSearchButton");
      const statusBox = document.getElementById("p2kStatus");
      const statusText = document.getElementById("p2kStatusText");
      const progressTrack = document.getElementById("p2kProgressTrack");
      const progressBar = document.getElementById("p2kProgressBar");
      const processingActions = document.getElementById("p2kProcessingActions");
      const cancelButton = document.getElementById("p2kCancelButton");
      const ratingsBox = document.getElementById("p2kRatings");
      const matchSearchToolbar = document.getElementById("p2kMatchSearchToolbar");
      const matchSearchInput = document.getElementById("p2kMatchSearch");
      const clearMatchSearchButton = document.getElementById("p2kClearMatchSearch");
      const resultsBox = document.getElementById("p2kResults");
      const userAvatar = document.getElementById("p2kUserAvatar");
      const dashboardParams = new URLSearchParams(window.location.search);
      const dashboardRecommendationMode = dashboardParams.get("dashboardRecommendations") === "1";
      const dashboardAssistantMode = dashboardParams.get("dashboardAssistant") === "1";
      const dashboardRecommendationUsername = String(dashboardParams.get("username") || "").trim();
      const dashboardAssistantUsername = String(dashboardParams.get("username") || "").trim();
      let dashboardPresetFilter = String(dashboardParams.get("dashboardFilter") || "").trim().toLowerCase();
      if (dashboardAssistantMode) document.documentElement.classList.add("p2k-dashboard-assistant-hydrating");
      let processedCache = null;
      let dashboardRecommendationWarning = "";
      let dashboardRecommendationFromCache = false;
      let dashboardRecommendationTerminal = false;
      let activeSearch = null;

      let resultState = {
        matches: [],
        username: "",
        failedCount: 0,
        registrationStatus: "not_registered",
        visibleCount: PAGE_SIZE
      };

      const dashboardCacheKey = username => `p2k-dashboard-match-cache:${normalizeUsername(username)}`;
      function persistDashboardCache(username) {
        if (!dashboardRecommendationMode || !processedCache) return;
        const snapshot = {
          version: 1,
          savedAt: Date.now(),
          username: processedCache.username || username,
          usernameKey: processedCache.usernameKey || normalizeUsername(username),
          ratings: processedCache.ratings || {},
          profile: processedCache.profile || null,
          avatarUrl: processedCache.avatarUrl || "",
          entries: Array.isArray(processedCache.entries) ? processedCache.entries : [],
          failedItems: Array.isArray(processedCache.failedItems) ? processedCache.failedItems : [],
          failedCount: Number(processedCache.failedCount || 0),
          opponentClubExcludedCount: Number(processedCache.opponentClubExcludedCount || 0),
          playerClubCount: Number(processedCache.playerClubCount || 0),
          totalMatches: Number(processedCache.totalMatches || 0),
          processedCount: Number(processedCache.processedCount || 0),
          cancelled: Boolean(processedCache.cancelled),
          processedAt: Number(processedCache.processedAt || Date.now())
        };
        try { sessionStorage.setItem(dashboardCacheKey(username), JSON.stringify(snapshot)); }
        catch (error) { console.warn("Unable to cache Match Assistant data for the dashboard.", error); }
      }
      function readDashboardCache(username) {
        try {
          const raw = sessionStorage.getItem(dashboardCacheKey(username));
          if (!raw) return null;
          const snapshot = JSON.parse(raw);
          if (!snapshot || snapshot.version !== 1 || Date.now() - Number(snapshot.savedAt || 0) > 30 * 60 * 1000) return null;
          if (normalizeUsername(snapshot.usernameKey || snapshot.username) !== normalizeUsername(username)) return null;
          return snapshot;
        } catch (error) {
          console.warn("Unable to read cached Match Assistant data.", error);
          return null;
        }
      }
      function restoreDashboardCache(username) {
        const snapshot = readDashboardCache(username);
        if (!snapshot) return false;
        processedCache = { ...snapshot, playerClubMemberships: new Set() };
        return true;
      }
      function notifyDashboardAssistantReady(username) {
        if (!dashboardAssistantMode || window.parent === window) return;
        document.documentElement.classList.remove("p2k-dashboard-assistant-hydrating");
        window.parent.postMessage({ type: "p2k-dashboard-assistant-ready", username: String(username || "") }, "*");
        reportHeight();
      }
      class CancellationError extends Error {
        constructor(message = "Processing cancelled by the user.") {
          super(message);
          this.name = "CancellationError";
        }
      }

      function delay(milliseconds, signal = null) {
        return new Promise((resolve, reject) => {
          if (signal?.aborted) {
            reject(new CancellationError());
            return;
          }
          const timer = window.setTimeout(() => {
            signal?.removeEventListener("abort", onAbort);
            resolve();
          }, milliseconds);

          const onAbort = () => {
            window.clearTimeout(timer);
            signal?.removeEventListener("abort", onAbort);
            reject(new CancellationError());
          };

          signal?.addEventListener("abort", onAbort, { once: true });
        });
      }
      function allowScreenUpdate() {
        return new Promise(resolve => {
          window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
        });
      }

      function logCompletedAnalysis(username, matchesFound, synchronized) {
        if (synchronized) return;
        const endpoint = window.P2K_SITE_CONFIG?.serverStorage?.matchAssistantLogEndpoint;
        if (!endpoint) return;
        const payload = {
          username: String(username || "").trim(),
          matchesFound: Math.max(0, Number(matchesFound) || 0)
        };
        if (!payload.username) return;
        void fetch(endpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-P2K-Request": "match-assistant-log"
          },
          body: JSON.stringify(payload),
          cache: "no-store",
          credentials: "same-origin",
          keepalive: true
        }).catch(() => {
          /* Usage logging is intentionally transparent and never blocks analysis. */
        });
      }

      function dashboardProgress(message, progress = null, state = "working") {
        if (!dashboardRecommendationMode || window.parent === window) return;
        const text = String(message || "");
        let value;
        if (Number.isFinite(Number(progress))) value = 35 + Math.max(0, Math.min(100, Number(progress))) * 0.6;
        else if (/ratings/i.test(text)) value = 7;
        else if (/profile/i.test(text)) value = 13;
        else if (/club memberships/i.test(text)) value = 20;
        else if (/registration match list/i.test(text)) value = 28;
        else if (state === "success") value = 100;
        else if (state === "error") value = 100;
        else value = 3;
        window.parent.postMessage({
          type: "p2k-dashboard-recommendations-progress",
          username: String(usernameInput?.value || dashboardRecommendationUsername || ""),
          progress: Math.max(0, Math.min(100, value)),
          message: text || "Analyzing recommended matches…"
        }, "*");
      }

      function setStatus(message, state = "working", progress = null) {
        statusBox.style.display = "block";
        statusBox.classList.toggle("error", state === "error");
        statusBox.classList.toggle("success", state === "success");
        statusText.textContent = message;
        dashboardProgress(message, progress, state);
        if (progress === null) {
          progressTrack.style.display = "none";
          progressBar.style.width = "0%";
        } else {
          const safeProgress = Math.max(0, Math.min(100, progress));
          progressTrack.style.display = "block";
          progressBar.style.width = `${safeProgress}%`;
        }
      }

      function showCancelButton() {
        cancelButton.dataset.p2kAction = "cancel";
        processingActions.style.display = "flex";
        cancelButton.disabled = false;
        cancelButton.textContent = "Cancel";
      }
      function hideCancelButton() {
        if (processedCache?.failedItems?.length && !activeSearch) {
          processingActions.style.display = "flex";
          cancelButton.disabled = false;
          cancelButton.dataset.p2kAction = "retry-failed";
          cancelButton.textContent = "Retry failed";
          cancelButton.title = "Retry only the match requests that failed and merge successful results into the current analysis.";
          return;
        }
        processingActions.style.display = "none";
        cancelButton.disabled = false;
        cancelButton.dataset.p2kAction = "cancel";
        cancelButton.textContent = "Cancel";
      }

      function selectedMatchType() {
        return document.querySelector('input[name="p2kMatchType"]:checked')?.value || "all";
      }

      function selectedRegistrationStatus() {
        return document.querySelector('input[name="p2kRegistrationStatus"]:checked')?.value || "not_registered";
      }
      function selectedRecommendationFilter() {
        return document.querySelector('input[name="p2kRecommendationFilter"]:checked')?.value || "recommended";
      }


      function selectedSortMode() {
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
        const rawReference = String(matchReference || "").trim();
        if (window.parent !== window) {
          window.parent.postMessage({ type: "p2k-open-detailed-analysis", matchReference: rawReference }, window.location.origin);
          return;
        }
        installDetailedAnalysisModal();
        const modal = document.getElementById("p2kDetailedAnalysisModal");
        const frame = modal?.querySelector(".p2k-detailed-analysis-frame");
        const loading = modal?.querySelector(".p2k-detailed-analysis-loading");
        if (!modal || !frame) return;
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
      function normalizeUsername(value) {
        return String(value || "").trim().toLowerCase();
      }

      function normalizeMatchSearch(value) {
        return String(value || "").trim().toLowerCase();
      }
      function showMatchSearchToolbar() {
        matchSearchToolbar.hidden = false;
        clearMatchSearchButton.disabled = normalizeMatchSearch(matchSearchInput.value) === "";
      }

      function hideMatchSearchToolbar({ clear = true } = {}) {
        matchSearchToolbar.hidden = true;
        if (clear) matchSearchInput.value = "";
        clearMatchSearchButton.disabled = true;
      }

      function entryMatchesSearch(entry, query) {
        if (!query) return true;
        const opponentTeam = entry?.recommendation?.opponentTeam || findOpponentTeam(entry?.match, entry?.team);
        const searchableText = [
          entry?.match?.name,
          opponentTeam?.name,
          opponentTeam?.url,
          opponentTeam?.["@id"]
        ]
          .filter(Boolean)
          .join(" ")
          .toLowerCase();

        return searchableText.includes(query);
      }
      async function loadJSON(url, signal = null) {
        if (!window.P2K_API_CLIENT) throw new Error("P2K_API_CLIENT is not loaded.");
        try {
          return await window.P2K_API_CLIENT.json(url, { signal, attempts: REQUEST_ATTEMPTS });
        } catch (error) {
          if (error?.category === "cancelled" || signal?.aborted) throw new CancellationError();
          throw error;
        }
      }

      function getLastRating(stats, key) {
        const value = stats?.[key]?.last?.rating;
        return Number.isFinite(Number(value)) ? Number(value) : null;
      }

      function getMatchRules(match) {
        return String(match?.settings?.rules || "").toLowerCase();
      }
      function isSupportedDailyMatch(match) {
        const timeClass = String(match?.settings?.time_class || match?.time_class || "").toLowerCase();
        const rules = getMatchRules(match);
        return timeClass === "daily" && (rules === "chess" || rules === "chess960");
      }
      function matchesTypeSelection(match, selectedType) {
        const rules = getMatchRules(match);
        if (selectedType === "all") return rules === "chess" || rules === "chess960";
        if (selectedType === "chess960") return rules === "chess960";
        return rules === "chess";
      }
      function findPromoteToKingTeam(match) {
        const teams = Object.values(match?.teams || {});
        const expectedApiFragment = `/club/${CLUB_ID}`;
        const expectedWebFragment = `/club/${CLUB_ID}`;

        return teams.find(team => {
          const apiUrl = String(team?.["@id"] || "").toLowerCase();
          const webUrl = String(team?.url || "").toLowerCase();
          return apiUrl.includes(expectedApiFragment) || webUrl.includes(expectedWebFragment);
        }) || null;
      }
      function findOpponentTeam(match, promoteToKingTeam) {
        return Object.values(match?.teams || {}).find(team => team !== promoteToKingTeam) || null;
      }

      function normalizeClubReference(value) {
        const rawValue = String(value || "").trim();
        if (!rawValue) return "";
        try {
          const parsedUrl = new URL(rawValue, "https://www.chess.com");
          const path = parsedUrl.pathname.replace(/\/+$/, "").toLowerCase();
          const apiMatch = path.match(/\/pub\/club\/([^/?#]+)/);
          const webMatch = path.match(/\/club\/([^/?#]+)/);
          const clubId = apiMatch?.[1] || webMatch?.[1] || "";
          return clubId ? decodeURIComponent(clubId).toLowerCase() : path;
        } catch (_) {
          return rawValue.toLowerCase().replace(/\/+$/, "");
        }
      }
      function buildPlayerClubMembershipSet(clubsResponse) {
        const memberships = new Set();

        for (const club of Array.isArray(clubsResponse?.clubs) ? clubsResponse.clubs : []) {
          for (const reference of [club?.["@id"], club?.url]) {
            const normalizedReference = normalizeClubReference(reference);
            if (normalizedReference) memberships.add(normalizedReference);
          }
        }

        return memberships;
      }
      function userBelongsToTeamClub(team, playerClubMemberships) {
        if (!team || !(playerClubMemberships instanceof Set)) return false;

        return [team?.["@id"], team?.url].some(reference => {
          const normalizedReference = normalizeClubReference(reference);
          return normalizedReference !== "" && playerClubMemberships.has(normalizedReference);
        });
      }

      function isTeamUnlocked(team) {
        return team?.locked === false;
      }
      function isUserRegistered(team, username) {
        const normalizedUsername = username.toLowerCase();
        return Array.isArray(team?.players) && team.players.some(player =>
          String(player?.username || "").toLowerCase() === normalizedUsername
        );
      }
      function findRegisteredPlayer(team, username) {
        const normalizedUsername = username.toLowerCase();
        return Array.isArray(team?.players)
          ? team.players.find(player => String(player?.username || "").toLowerCase() === normalizedUsername) || null
          : null;
      }

      function numericPlayerEntries(team, excludedUsername = null, bounds = null) {
        const normalizedExcluded = excludedUsername === null ? null : excludedUsername.toLowerCase();
        return (Array.isArray(team?.players) ? team.players : [])
          .filter(player => normalizedExcluded === null || String(player?.username || "").toLowerCase() !== normalizedExcluded)
          .map(player => {
            const rawRating = player?.rating;
            if (rawRating === undefined || rawRating === null || rawRating === "") return null;
            const rating = Number(rawRating);
            if (!Number.isFinite(rating)) return null;
            if (bounds && (rating < bounds.minimum || rating > bounds.maximum)) return null;
            return {
              username: String(player?.username || ""),
              rating
            };
          })
          .filter(Boolean)
          .sort((a, b) => b.rating - a.rating || a.username.localeCompare(b.username));
      }

      function numericPlayerRatings(team, excludedUsername = null, bounds = null) {
        return numericPlayerEntries(team, excludedUsername, bounds).map(player => player.rating);
      }
      function getMaximumTeamPlayers(match) {
        const value = Number(match?.settings?.max_team_players);
        return Number.isFinite(value) && value > 0 ? Math.floor(value) : Number.POSITIVE_INFINITY;
      }

      function getMinimumTeamPlayers(match) {
        const value = Number(match?.settings?.min_team_players);
        return Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
      }
      function limitLineupRatings(ratings, maximumPlayers) {
        if (!Number.isFinite(maximumPlayers)) return [...ratings];
        return ratings.slice(0, Math.max(0, maximumPlayers));
      }

      function projectedLineupWithUser(team, username, rating, maximumPlayers, alreadyRegistered) {
        if (rating === null || !Number.isFinite(Number(rating))) {
          return { selected: false, ratings: limitLineupRatings(numericPlayerRatings(team), maximumPlayers) };
        }
        const normalizedUsername = username.toLowerCase();
        const basePlayers = numericPlayerEntries(team, alreadyRegistered ? username : null);
        const higherRatedCount = basePlayers.filter(player => player.rating > rating).length;
        const selected = !Number.isFinite(maximumPlayers) || higherRatedCount < maximumPlayers;
        const projectedPlayers = [
          ...basePlayers,
          { username: normalizedUsername, rating: Number(rating), candidate: true }
        ].sort((a, b) => {
          const ratingDifference = b.rating - a.rating;
          if (ratingDifference !== 0) return ratingDifference;
          if (a.candidate && !b.candidate) return -1;
          if (!a.candidate && b.candidate) return 1;
          return a.username.localeCompare(b.username);
        });
        return {
          selected,
          ratings: limitLineupRatings(projectedPlayers.map(player => player.rating), maximumPlayers)
        };
      }

      function registeredUserIsSelected(team, username, rating, maximumPlayers) {
        if (rating === null || !Number.isFinite(Number(rating))) return false;
        if (!Number.isFinite(maximumPlayers)) return true;
        const otherPlayers = numericPlayerEntries(team, username);
        return otherPlayers.filter(player => player.rating > Number(rating)).length < maximumPlayers;
      }

      function countEligibleRegisteredPlayers(team, bounds) {
        return numericPlayerEntries(team, null, bounds).length;
      }
      function pairedLineupAdvantage(promoteRatings, opponentRatings) {
        const boardCount = Math.min(promoteRatings.length, opponentRatings.length);
        if (boardCount === 0) return null;

        let promoteTotal = 0;
        let opponentTotal = 0;

        for (let index = 0; index < boardCount; index += 1) {
          promoteTotal += promoteRatings[index];
          opponentTotal += opponentRatings[index];
        }
        return {
          boardCount,
          promoteAverage: promoteTotal / boardCount,
          opponentAverage: opponentTotal / boardCount,
          opponentAdvantage: (opponentTotal - promoteTotal) / boardCount
        };
      }

      function recommendationScoreLabel(score) {
        if (score >= 80) return "Strongly recommended";
        if (score >= 60) return "Recommended";
        if (score >= 40) return "Worth considering";
        return "Low priority";
      }

      function computeRecommendationScore(
        match,
        promoteTeam,
        opponentTeam,
        username,
        profileRating,
        registered,
        bounds,
        maximumPlayers,
        minimumPlayers,
        eligiblePromotePlayers,
        lineupSelected
      ) {
        const factors = [];
        let score = 0;
        const promotePlayers = Array.isArray(promoteTeam?.players) ? promoteTeam.players : [];
        const opponentPlayers = Array.isArray(opponentTeam?.players) ? opponentTeam.players : [];
        const opponentEligiblePlayers = countEligibleRegisteredPlayers(opponentTeam, bounds);
        const targetEligiblePlayers = Number.isFinite(maximumPlayers)
          ? Math.min(maximumPlayers, Math.max(minimumPlayers, opponentEligiblePlayers))
          : Math.max(minimumPlayers, opponentEligiblePlayers);
        const eligibleDeficit = Math.max(0, targetEligiblePlayers - eligiblePromotePlayers);
        const playerDeficit = Math.max(0, opponentPlayers.length - promotePlayers.length);

        if (isPriorityMatch(match)) {
          score += 22;
          factors.push("priority league match +22");
        }

        if (eligibleDeficit > 0) {
          const points = Math.min(22, eligibleDeficit * 6);
          score += points;
          factors.push(eligibleDeficit + " eligible-player gap +" + points);
        }

        if (playerDeficit > 0) {
          const points = Math.min(12, playerDeficit * 3);
          score += points;
          factors.push(playerDeficit + " registration gap +" + points);
        }

        const opponentRatings = limitLineupRatings(numericPlayerRatings(opponentTeam), maximumPlayers);
        const promoteRatings = limitLineupRatings(numericPlayerRatings(promoteTeam), maximumPlayers);
        const currentComparison = pairedLineupAdvantage(promoteRatings, opponentRatings);

        if (currentComparison && currentComparison.opponentAdvantage > 0) {
          const points = Math.min(16, Math.round(currentComparison.opponentAdvantage / 8));
          score += points;
          factors.push("lineup deficit +" + points);
        }

        let lineupImprovement = 0;
        if (registered) {
          const withoutUser = limitLineupRatings(
            numericPlayerRatings(promoteTeam, username),
            maximumPlayers
          );
          const comparisonWithoutUser = pairedLineupAdvantage(withoutUser, opponentRatings);
          if (currentComparison && comparisonWithoutUser) {
            lineupImprovement = Math.max(
              0,
              comparisonWithoutUser.opponentAdvantage - currentComparison.opponentAdvantage
            );
          }
        } else {
          const projected = projectedLineupWithUser(
            promoteTeam,
            username,
            profileRating,
            maximumPlayers,
            false
          );
          const comparisonWithUser = pairedLineupAdvantage(projected.ratings, opponentRatings);
          if (currentComparison && comparisonWithUser) {
            lineupImprovement = Math.max(
              0,
              currentComparison.opponentAdvantage - comparisonWithUser.opponentAdvantage
            );
          }
        }

        if (lineupImprovement > 0) {
          const points = Math.min(20, Math.max(1, Math.round(lineupImprovement / 5)));
          score += points;
          factors.push("projected lineup improvement +" + points);
        }

        if (lineupSelected) {
          score += 10;
          factors.push("projected in lineup +10");
        }

        if (profileRating !== null && Number.isFinite(Number(profileRating)) &&
            Number(profileRating) >= bounds.minimum && Number(profileRating) <= bounds.maximum) {
          score += 5;
          factors.push("rating eligible +5");
        }

        const startTimestamp = matchStartTimestamp(match);
        if (startTimestamp !== null) {
          const daysUntilStart = (startTimestamp * 1000 - Date.now()) / 86400000;
          const urgencyPoints = daysUntilStart <= 3 ? 10 : daysUntilStart <= 7 ? 8 : daysUntilStart <= 14 ? 5 : daysUntilStart <= 30 ? 2 : 0;
          if (urgencyPoints > 0) {
            score += urgencyPoints;
            factors.push("starts soon +" + urgencyPoints);
          }
        }

        score = Math.max(0, Math.min(100, Math.round(score)));
        return {
          score,
          label: recommendationScoreLabel(score),
          explanation: factors.length > 0
            ? "Score components: " + factors.join("; ") + "."
            : "No specific recruitment, lineup, priority, or urgency need was detected."
        };
      }

      function evaluateRecommendation(match, promoteTeam, username, profileRating, registered, bounds) {
        const opponentTeam = findOpponentTeam(match, promoteTeam);
        const promotePlayers = Array.isArray(promoteTeam?.players) ? promoteTeam.players : [];
        const opponentPlayers = Array.isArray(opponentTeam?.players) ? opponentTeam.players : [];
        const reasons = [];
        const maximumPlayers = getMaximumTeamPlayers(match);
        const minimumPlayers = getMinimumTeamPlayers(match);
        const eligiblePromotePlayers = countEligibleRegisteredPlayers(promoteTeam, bounds);
        if (!opponentTeam) {
          return {
            recommended: false,
            reasons,
            opponentTeam: null,
            lineupSelected: false,
            eligiblePromotePlayers,
            minimumPlayers,
            maximumPlayers
          };
        }
        const playerCountReason = opponentPlayers.length > promotePlayers.length;
        if (playerCountReason) {
          reasons.push(
            `${opponentTeam.name || "The opponent"} has ${opponentPlayers.length} registered player${opponentPlayers.length === 1 ? "" : "s"}; ` +
            `Promote to King has ${promotePlayers.length}.`
          );
        }
        const minimumPlayersReason = minimumPlayers > 0 && eligiblePromotePlayers < minimumPlayers;
        if (minimumPlayersReason) {
          reasons.push(
            `Promote to King has ${eligiblePromotePlayers} eligible registered player${eligiblePromotePlayers === 1 ? "" : "s"}; ` +
            `the match requires at least ${minimumPlayers}.`
          );
        }
        const opponentRatings = limitLineupRatings(numericPlayerRatings(opponentTeam), maximumPlayers);
        const promoteRatings = limitLineupRatings(numericPlayerRatings(promoteTeam), maximumPlayers);
        let ratingReason = false;
        let lineupSelected = false;
        if (registered) {
          lineupSelected = registeredUserIsSelected(promoteTeam, username, profileRating, maximumPlayers);
          const currentComparison = pairedLineupAdvantage(promoteRatings, opponentRatings);
          const lineupWithoutUser = limitLineupRatings(
            numericPlayerRatings(promoteTeam, username),
            maximumPlayers
          );
          const comparisonWithoutUser = pairedLineupAdvantage(lineupWithoutUser, opponentRatings);
          if (
            lineupSelected &&
            currentComparison !== null &&
            comparisonWithoutUser !== null &&
            currentComparison.opponentAdvantage > 0 &&
            comparisonWithoutUser.opponentAdvantage > currentComparison.opponentAdvantage
          ) {
            ratingReason = true;
            const improvement = Math.max(1, Math.round(
              comparisonWithoutUser.opponentAdvantage - currentComparison.opponentAdvantage
            ));
            reasons.push(
              `The opponent's selected lineup averages ${Math.max(1, Math.round(currentComparison.opponentAdvantage))} rating points higher; ` +
              `this user's place in the lineup improves the projected matchup by about ${improvement} point${improvement === 1 ? "" : "s"}.`
            );
          }
        } else {
          const projected = projectedLineupWithUser(
            promoteTeam,
            username,
            profileRating,
            maximumPlayers,
            false
          );
          lineupSelected = projected.selected;
          const currentComparison = pairedLineupAdvantage(promoteRatings, opponentRatings);
          const comparisonWithUser = pairedLineupAdvantage(projected.ratings, opponentRatings);
          if (
            lineupSelected &&
            currentComparison !== null &&
            comparisonWithUser !== null &&
            currentComparison.opponentAdvantage > 0 &&
            comparisonWithUser.opponentAdvantage < currentComparison.opponentAdvantage
          ) {
            ratingReason = true;
            const improvement = Math.max(1, Math.round(
              currentComparison.opponentAdvantage - comparisonWithUser.opponentAdvantage
            ));
            reasons.push(
              `The opponent's selected lineup currently averages ${Math.max(1, Math.round(currentComparison.opponentAdvantage))} rating points higher; ` +
              `joining improves the projected matchup by about ${improvement} point${improvement === 1 ? "" : "s"}.`
            );
          }
        }
        const scoreDetails = computeRecommendationScore(
          match,
          promoteTeam,
          opponentTeam,
          username,
          profileRating,
          registered,
          bounds,
          maximumPlayers,
          minimumPlayers,
          eligiblePromotePlayers,
          lineupSelected
        );
        const ruleRecommended = playerCountReason || ratingReason || minimumPlayersReason;
        return {
          recommended: ruleRecommended || scoreDetails.score >= 50,
          reasons,
          opponentTeam,
          lineupSelected,
          eligiblePromotePlayers,
          minimumPlayers,
          maximumPlayers,
          score: scoreDetails.score,
          scoreLabel: scoreDetails.label,
          scoreExplanation: scoreDetails.explanation
        };
      }

      function numericBound(value, fallback) {
        if (value === undefined || value === null || value === "") return fallback;
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }
      function getRatingBounds(match) {
        return {
          minimum: numericBound(match?.settings?.min_rating, 0),
          maximum: numericBound(match?.settings?.max_rating, Number.POSITIVE_INFINITY)
        };
      }

      function ratingForMatch(match, ratings) {
        return getMatchRules(match) === "chess960" ? ratings.chess960 : ratings.classical;
      }
      function isRatingEligible(rating, bounds) {
        return rating !== null && rating >= bounds.minimum && rating <= bounds.maximum;
      }

      function formatRating(value) {
        return value === null ? "not available" : String(value);
      }

      function formatRatingRange(bounds) {
        if (!Number.isFinite(bounds.maximum)) return `${bounds.minimum}+`;
        return `${bounds.minimum}–${bounds.maximum}`;
      }
      function formatMatchType(match) {
        return getMatchRules(match) === "chess960" ? "Chess 960" : "Classical chess";
      }

      function isPriorityMatch(match) {
        const name = String(match?.name || "").toUpperCase();
        return PRIORITY_LEAGUE_ACRONYMS.some(acronym => name.includes(acronym));
      }

      function matchStartTimestamp(match) {
        const value = Number(match?.start_time);
        return Number.isFinite(value) && value > 0 ? value : null;
      }
      function formatStartDate(match) {
        const timestamp = matchStartTimestamp(match);
        if (timestamp === null) return "Not scheduled";

        return new Intl.DateTimeFormat("en-GB", {
          day: "2-digit",
          month: "short",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit",
          hour12: false,
          timeZone: "UTC",
          timeZoneName: "short"
        }).format(new Date(timestamp * 1000));
      }
      function showRatings(username, ratings) {
        ratingsBox.style.display = "block";
        ratingsBox.innerHTML =
          `<strong>${escapeHTML(username)}</strong> — ` +
          `Daily classical: <strong>${formatRating(ratings.classical)}</strong> · ` +
          `Daily Chess 960: <strong>${formatRating(ratings.chess960)}</strong>`;
      }
      function hideUserAvatar() {
        userAvatar.hidden = true;
        userAvatar.removeAttribute("src");
        userAvatar.alt = "";
        userAvatar.title = "";
      }

      function showUserAvatar(profile, fallbackUsername) {
        const avatarUrl = String(profile?.avatar || "").trim();
        if (!avatarUrl) {
          hideUserAvatar();
          return;
        }
        const displayUsername = String(profile?.username || fallbackUsername || "Chess.com user");
        userAvatar.src = avatarUrl;
        userAvatar.alt = `${displayUsername}'s Chess.com avatar`;
        userAvatar.title = `${displayUsername}'s Chess.com avatar`;
        userAvatar.hidden = false;
      }
      function escapeHTML(value) {
        return String(value ?? "")
          .replaceAll("&", "&amp;")
          .replaceAll("<", "&lt;")
          .replaceAll(">", "&gt;")
          .replaceAll('"', "&quot;")
          .replaceAll("'", "&#039;");
      }
      function sortMatchesForDisplay(matches) {
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
      }
      function resultSummaryLabel(total, registrationStatus) {
        if (registrationStatus === "registered") {
          return `${total} registered open match${total === 1 ? "" : "es"}`;
        }
        if (registrationStatus === "not_registered") {
          return `${total} available unregistered match${total === 1 ? "" : "es"}`;
        }
        return `${total} matching open match${total === 1 ? "" : "es"}`;
      }
      function renderResults() {
        const { matches, username, failedCount, registrationStatus } = resultState;
        const searchQuery = normalizeMatchSearch(matchSearchInput.value);
        const searchedMatches = searchQuery
          ? matches.filter(entry => entryMatchesSearch(entry, searchQuery))
          : matches;
        const total = searchedMatches.length;
        clearMatchSearchButton.disabled = searchQuery === "";
        if (total === 0) {
          let emptyMessage = searchQuery
            ? `No match name or opponent corresponds to “${matchSearchInput.value.trim()}”.`
            : "No open match corresponds to the selected filters.";
          if (!searchQuery && registrationStatus === "registered") {
            emptyMessage = "This user is not registered in any open match matching the selected filters.";
          } else if (!searchQuery && registrationStatus === "not_registered") {
            emptyMessage = "No unlocked, rating-eligible, lineup-selected, unregistered match corresponds to the selected filters.";
          } else if (!searchQuery && registrationStatus === "all") {
            emptyMessage = "No unlocked, rating-eligible, lineup-selected match corresponds to the selected filters.";
          }
          resultsBox.innerHTML = `<div class="empty">${escapeHTML(emptyMessage)}</div>`;

          if (failedCount > 0) {
            resultsBox.insertAdjacentHTML(
              "beforeend",
              `<div class="warning">${failedCount} match${failedCount === 1 ? "" : "es"} could not be loaded.</div>`
            );
          }
          return;
        }
        const visibleCount = Math.min(resultState.visibleCount, total);
        const visibleMatches = searchedMatches.slice(0, visibleCount);
        const baseLabel = resultSummaryLabel(total, registrationStatus);
        const searchSuffix = searchQuery
          ? ` matching “${escapeHTML(matchSearchInput.value.trim())}”`
          : "";
        let html = `<div class="result-summary">Showing ${visibleCount} of ${baseLabel}${searchSuffix}</div>`;
        for (const entry of visibleMatches) {
          const { match, rating, bounds, registered, registeredOverride, recommendation } = entry;
          const matchName = escapeHTML(match.name || "Unnamed match");
          const matchUrl = escapeHTML(match.url || "#");
          const type = escapeHTML(formatMatchType(match));
          const startDate = escapeHTML(formatStartDate(match));
          const ratingText = rating === null ? "not available" : escapeHTML(rating);
          const range = escapeHTML(formatRatingRange(bounds));
          let statusLine;
          if (registeredOverride) {
            statusLine =
              `<span class="registered-text">${escapeHTML(username)} is registered</span>` +
              ` · <span class="override-text">Shown regardless of team lock and rating limits</span>`;
          } else {
            statusLine =
              `<span class="eligible">Eligible, registration unlocked, and projected in the playing lineup</span>` +
              (registered
                ? ` · <span class="registered-text">${escapeHTML(username)} is already registered</span>`
                : ` · ${escapeHTML(username)} is not registered`);
          }
          const priority = isPriorityMatch(match);
          const recommendationScore = Number(recommendation?.score || 0);
          const recommendationScoreClass = recommendationScore >= 70
            ? "recommendation-score-high"
            : recommendationScore >= 40
              ? "recommendation-score-medium"
              : "recommendation-score-low";
          const recommendationReasonText = recommendation?.reasons?.length
            ? recommendation.reasons.join(" ")
            : "No specific team need was detected by the rule-based checks.";
          const matchReference = String(match?.url || match?.["@id"] || "");
          const matchIdMatch = matchReference.match(/(\d+)(?:\/)?$/);
          const detailedAnalysisReference = matchIdMatch ? matchIdMatch[1] : matchReference;
          const recommendationPopoverId = `p2kRecommendationInfo-${String(detailedAnalysisReference || recommendationScore).replace(/[^a-zA-Z0-9_-]/g, "-")}`;
          const recommendationInfoHTML = '<strong>Recommendation</strong><br>' + escapeHTML(recommendationReasonText) +
            '<br><br><strong>Score calculation</strong><br>' + escapeHTML(recommendation?.scoreExplanation || "No score details are available.") +
            '<button type="button" class="recommendation-analysis-button" data-match-reference="' + escapeHTML(detailedAnalysisReference) + '" ' +
              '>Detailed match analysis</button>';
          const recommendationLine = recommendation
            ? '<div class="recommendation-line">' +
                '<span class="recommendation-score ' + recommendationScoreClass + '">' + recommendationScore + '/100</span>' +
                '<span class="recommendation-score-label">' + escapeHTML(recommendation.scoreLabel || "Low priority") + '</span>' +
                '<span class="recommendation-info-wrap">' +
                  '<button type="button" class="recommendation-info p2k-info-button" aria-label="Show recommendation details" aria-expanded="false" ' +
                    'aria-controls="' + recommendationPopoverId + '" data-p2k-info-trigger="' + recommendationPopoverId + '">i</button>' +
                  '<span id="' + recommendationPopoverId + '" class="recommendation-info-tooltip p2k-info-popover" data-kind="dialog" ' +
                    'data-p2k-info-popover="dialog" role="dialog" aria-label="Recommendation details" hidden>' + recommendationInfoHTML + '</span>' +
                '</span>' +
              '</div>'
            : "";

          const recommended = Boolean(recommendation?.recommended);
          html +=
            `<article class="match-card${recommended ? " recommended" : ""}${priority ? " priority" : ""}">` +
              `<a class="match-title" href="${matchUrl}" target="_blank" rel="noopener noreferrer">${matchName}</a>` +
              `<div class="match-details">` +
                `<span class="start-date">Start: ${startDate}</span><br>` +
                `${type} · Your rating: <strong>${ratingText}</strong> · Accepted range: <strong>${range}</strong><br>` +
                statusLine +
                recommendationLine +
              `</div>` +
            `</article>`;
        }
        if (visibleCount < total) {
          html +=
            `<div class="result-controls">` +
              `<button id="p2kLoadMore" class="load-button" type="button" title="Display the next five matching results without reloading the API.">Load 5 more</button>` +
              `<button id="p2kLoadAll" class="load-button secondary" type="button" title="Display every matching result without reloading the API.">Load all</button>` +
            `</div>`;
        }
        if (failedCount > 0) {
          html += `<div class="warning">${failedCount} match${failedCount === 1 ? "" : "es"} could not be loaded.</div>`;
        }

        resultsBox.innerHTML = html;

        resultsBox.querySelectorAll(".recommendation-analysis-button").forEach(button => {
          button.addEventListener("click", () => {
            window.P2K_INFO_POPOVER?.close({ immediate: true });
            window.p2kOpenDetailedAnalysis(button.dataset.matchReference);
          });
        });

        document.getElementById("p2kLoadMore")?.addEventListener("click", () => {
          resultState.visibleCount = Math.min(resultState.visibleCount + PAGE_SIZE, total);
          renderResults();
        });
        document.getElementById("p2kLoadAll")?.addEventListener("click", () => {
          resultState.visibleCount = total;
          renderResults();
        });
      }

      function dashboardRecommendationEntry(entry) {
        const match = entry?.match || {};
        const recommendation = entry?.recommendation || {};
        const priorityLeague = PRIORITY_LEAGUE_ACRONYMS.find(acronym =>
          String(match?.name || "").toUpperCase().includes(String(acronym).toUpperCase())
        ) || "Open";
        return {
          name: String(match?.name || "Unnamed match"),
          url: String(match?.url || ""),
          apiUrl: String(match?.["@id"] || ""),
          startTime: matchStartTimestamp(match),
          league: priorityLeague,
          priority: isPriorityMatch(match),
          rules: formatMatchType(match),
          rating: entry?.rating,
          ratingRange: formatRatingRange(entry?.bounds || getRatingBounds(match)),
          registered: Boolean(entry?.registered),
          score: Number(recommendation?.score || 0),
          scoreLabel: String(recommendation?.scoreLabel || "Low priority"),
          reasons: Array.isArray(recommendation?.reasons) ? recommendation.reasons.map(String) : [],
          scoreExplanation: String(recommendation?.scoreExplanation || "")
        };
      }

      function dashboardTeamIndicators(entries) {
        const all = Array.isArray(entries) ? entries : [];
        const ratios = (source, targetGetter, currentGetter) => {
          const values = source.map(entry => {
            const target = Number(targetGetter(entry));
            const current = Number(currentGetter(entry));
            if (!Number.isFinite(target) || target <= 0 || !Number.isFinite(current)) return null;
            return Math.max(0, Math.min(1, current / target));
          }).filter(value => value !== null);
          return values.length ? Math.round(values.reduce((sum, value) => sum + value, 0) / values.length * 100) : null;
        };
        const enriched = all.map(entry => {
          const match = entry?.match || {};
          const promoteTeam = entry?.team || {};
          const opponentTeam = entry?.recommendation?.opponentTeam || findOpponentTeam(match, promoteTeam) || {};
          const promoteCount = Array.isArray(promoteTeam?.players) ? promoteTeam.players.length : 0;
          const opponentCount = Array.isArray(opponentTeam?.players) ? opponentTeam.players.length : 0;
          const minimum = Number(entry?.recommendation?.minimumPlayers || getMinimumTeamPlayers(match) || 0);
          const maximum = Number(entry?.recommendation?.maximumPlayers ?? getMaximumTeamPlayers(match));
          const opponentEligible = countEligibleRegisteredPlayers(opponentTeam, entry?.bounds || getRatingBounds(match));
          const eligibleTarget = Number.isFinite(maximum)
            ? Math.min(maximum, Math.max(minimum, opponentEligible))
            : Math.max(minimum, opponentEligible);
          const registrationTarget = Number.isFinite(maximum)
            ? Math.min(maximum, Math.max(minimum, opponentCount))
            : Math.max(minimum, opponentCount);
          return { entry, promoteCount, eligibleTarget, registrationTarget };
        });
        const league = enriched.filter(item => isPriorityMatch(item.entry?.match));
        const now = Date.now() / 1000;
        const sevenDays = now + 7 * 86400;
        return {
          lineupReadiness: ratios(league, item => item.eligibleTarget, item => item.entry?.recommendation?.eligiblePromotePlayers || 0),
          registrationTargets: ratios(enriched, item => item.registrationTarget, item => item.promoteCount),
          leagueMatches: league.length,
          registrationMatches: enriched.length,
          startingWithinSevenDays: enriched.filter(item => {
            const timestamp = matchStartTimestamp(item.entry?.match);
            return timestamp !== null && timestamp >= now && timestamp <= sevenDays;
          }).length,
          priorityCalls: league.filter(item => item.entry?.recommendation?.recommended && item.entry?.unlocked).length
        };
      }


      function dashboardMatchWinProbability(entry) {
        const match = entry?.match || {};
        const promoteTeam = entry?.team || {};
        const opponentTeam = entry?.recommendation?.opponentTeam || findOpponentTeam(match, promoteTeam) || {};
        const bounds = entry?.bounds || getRatingBounds(match);
        const maximumRaw = Number(entry?.recommendation?.maximumPlayers ?? getMaximumTeamPlayers(match));
        const maximum = Number.isFinite(maximumRaw) && maximumRaw > 0 ? maximumRaw : Infinity;
        // Keep the admin forecast on the exact Match Analyzer model input:
        // rating-window eligible players, strongest first, capped by max boards.
        const promoteRatings = limitLineupRatings(numericPlayerRatings(promoteTeam, null, bounds), maximum);
        const opponentRatings = limitLineupRatings(numericPlayerRatings(opponentTeam, null, bounds), maximum);
        const boardCount = Math.min(promoteRatings.length, opponentRatings.length);
        if (!boardCount) return null;
        const expectedScores = [];
        for (let index = 0; index < boardCount; index += 1) {
          expectedScores.push(1 / (1 + Math.pow(10, (opponentRatings[index] - promoteRatings[index]) / 400)));
        }
        let distribution = [1];
        expectedScores.forEach(expectedScore => {
          for (let trial = 0; trial < 4; trial += 1) {
            const next = new Array(distribution.length + 1).fill(0);
            distribution.forEach((probability, units) => {
              next[units] += probability * (1 - expectedScore);
              next[units + 1] += probability * expectedScore;
            });
            distribution = next;
          }
        });
        const drawUnits = boardCount * 2;
        return distribution.reduce((sum, probability, units) => units > drawUnits ? sum + probability : sum, 0);
      }

      function dashboardAdminQueue(entries) {
        const all = Array.isArray(entries) ? entries : [];
        const now = Date.now() / 1000;
        const within48 = now + 48 * 3600;
        const rows = all.map(entry => {
          const match = entry?.match || {};
          const promoteTeam = entry?.team || {};
          const opponentTeam = entry?.recommendation?.opponentTeam || findOpponentTeam(match, promoteTeam) || {};
          const promoteCount = Array.isArray(promoteTeam?.players) ? promoteTeam.players.length : 0;
          const opponentCount = Array.isArray(opponentTeam?.players) ? opponentTeam.players.length : 0;
          const minimum = Math.max(0, Number(entry?.recommendation?.minimumPlayers || getMinimumTeamPlayers(match) || 0));
          const maximumRaw = Number(entry?.recommendation?.maximumPlayers ?? getMaximumTeamPlayers(match));
          const maximum = Number.isFinite(maximumRaw) && maximumRaw > 0 ? maximumRaw : null;
          const bounds = entry?.bounds || getRatingBounds(match);
          const promoteEligible = countEligibleRegisteredPlayers(promoteTeam, bounds);
          const opponentEligible = countEligibleRegisteredPlayers(opponentTeam, bounds);
          const targetRaw = Math.max(minimum, opponentCount, opponentEligible);
          const target = maximum === null ? targetRaw : Math.min(maximum, targetRaw);
          const recruits = Math.max(0, target - promoteCount);
          const belowMinimum = minimum > 0 && promoteEligible < minimum;
          const league = isPriorityMatch(match);
          const startTime = matchStartTimestamp(match);
          const startsWithin48 = startTime !== null && startTime >= now && startTime <= within48;
          const startsWithin7Days = startTime !== null && startTime >= now && startTime <= now + 7 * 24 * 3600;
          const belowMinimumWarning = belowMinimum && startsWithin7Days;
          const hoursUntilStart = startTime === null ? null : Math.max(0, Math.round((startTime - now) / 3600));
          const winProbability = belowMinimum ? 0 : dashboardMatchWinProbability(entry);
          const needsRecruitment = Boolean(entry?.unlocked) && (belowMinimumWarning || (league && recruits > 0));
          const lockable = Boolean(entry?.unlocked) && minimum > 0 && promoteCount >= minimum && promoteCount > opponentCount;
          let tone = "info";
          if ((belowMinimumWarning && startsWithin48) || (league && recruits > 0 && hoursUntilStart !== null && hoursUntilStart <= 24)) tone = "bad";
          else if (belowMinimumWarning || (league && recruits > 0) || startsWithin48) tone = "warn";
          const details = [];
          if (belowMinimumWarning) details.push(`Below minimum by ${minimum - promoteEligible}`);
          if (!belowMinimum && recruits > 0) details.push(`${recruits} recruit${recruits === 1 ? "" : "s"} advised`);
          if (hoursUntilStart !== null && hoursUntilStart <= 72) details.push(`starts in ${hoursUntilStart} h`);
          if (bounds) details.push(formatRatingRange(bounds));
          details.push(winProbability === null ? "win probability unavailable" : `P2K win probability ${Math.round(winProbability * 100)}%`);
          if (!details.length && lockable) details.push("Registration can be reviewed for locking");
          const action = needsRecruitment ? "Recruit" : league && entry?.recommendation?.recommended ? "Analyze" : "Review";
          const urgency = hoursUntilStart === null ? 0 : Math.max(0, 72 - hoursUntilStart);
          const score = (tone === "bad" ? 300 : tone === "warn" ? 180 : 60)
            + (league ? 80 : 0)
            + Math.min(80, recruits * 8)
            + urgency
            + (belowMinimum ? 100 : 0);
          const matchIdMatch = String(match?.url || match?.["@id"] || "").match(/(?:match\/|match\/live\/|\/)(\d+)(?:\/?$|[?#])/i);
          const matchId = Number(match?.id || match?.match_id || matchIdMatch?.[1] || 0);
          return {
            tone,
            matchId,
            match_id: matchId,
            status: String(match?.status || "registration"),
            title: String(match?.name || "Unnamed match"),
            detail: details.join(" · ") || "Administrator review recommended",
            action,
            url: String(match?.url || ""),
            apiUrl: String(match?.["@id"] || ""),
            startTime,
            league,
            belowMinimum,
            belowMinimumWarning,
            startsWithin7Days,
            startsWithin48,
            needsRecruitment,
            lockable,
            recruits,
            winProbability: winProbability === null ? null : Math.round(winProbability * 1000) / 10,
            score
          };
        });
        const attention = rows.filter(row => row.belowMinimumWarning || row.startsWithin48 || row.needsRecruitment || row.lockable);
        const queue = attention.sort((a, b) => b.score - a.score || (a.startTime || Number.MAX_SAFE_INTEGER) - (b.startTime || Number.MAX_SAFE_INTEGER)).slice(0, 6);
        return {
          generatedAt: new Date().toISOString(),
          metrics: {
            underfilled: rows.filter(row => row.belowMinimumWarning).length,
            starts48: rows.filter(row => row.startsWithin48).length,
            leagueRecruitment: rows.filter(row => row.league && row.needsRecruitment).length,
            recruitsAdvised: rows.filter(row => row.league && row.needsRecruitment).reduce((sum, row) => sum + row.recruits, 0),
            lockable: rows.filter(row => row.lockable).length
          },
          queue,
          rows: rows.map(({ score, ...row }) => row)
        };
      }

      function postDashboardRecommendations(matches, username, failedCount, error = "") {
        if (!dashboardRecommendationFromCache) persistDashboardCache(username);
        if (!dashboardRecommendationMode || window.parent === window) return;
        dashboardProgress(error || "Recommendation analysis finished", 100, error ? "error" : "success");
        window.parent.postMessage({
          type: "p2k-dashboard-recommendations",
          username: String(username || dashboardRecommendationUsername || ""),
          failedCount: Number(failedCount || 0),
          error: String(error || ""),
          warning: String(dashboardRecommendationWarning || ""),
          cached: Boolean(dashboardRecommendationFromCache),
          terminal: Boolean(dashboardRecommendationTerminal),
          recommendations: Array.isArray(matches) ? matches.slice(0, PAGE_SIZE).map(dashboardRecommendationEntry) : [],
          teamIndicators: dashboardTeamIndicators(processedCache?.entries || matches),
          adminQueue: dashboardAdminQueue(processedCache?.entries || matches)
        }, "*");
      }

      function displayResults(matches, username, failedCount, registrationStatus) {
        const sortedMatches = sortMatchesForDisplay(matches);
        resultState = {
          matches: sortedMatches,
          username,
          failedCount,
          registrationStatus,
          visibleCount: PAGE_SIZE
        };
        renderResults();
        postDashboardRecommendations(sortedMatches, username, failedCount);
      }
      function validateRequestedRatings(selectedType, ratings, registrationStatus) {
        if (registrationStatus === "registered") return;

        if (selectedType === "classical" && ratings.classical === null) {
          throw new Error("This user has no Daily classical rating available.");
        }

        if (selectedType === "chess960" && ratings.chess960 === null) {
          throw new Error("This user has no Daily Chess 960 rating available.");
        }
        if (selectedType === "all" && ratings.classical === null && ratings.chess960 === null) {
          throw new Error("This user has no Daily classical or Daily Chess 960 rating available.");
        }
      }

      function applyFiltersFromCache({ announce = false } = {}) {
        if (!processedCache) return null;

        const currentUsernameKey = normalizeUsername(usernameInput.value);
        if (currentUsernameKey !== processedCache.usernameKey) return null;
        const selectedType = selectedMatchType();
        const registrationStatus = selectedRegistrationStatus();
        const recommendationFilter = selectedRecommendationFilter();

        try {
          validateRequestedRatings(selectedType, processedCache.ratings, registrationStatus);
          const nowTimestamp = Date.now() / 1000;
          const presetCutoff = nowTimestamp + 7 * 86400;
          const filteredMatches = processedCache.entries
            .filter(entry => matchesTypeSelection(entry.match, selectedType))
            .filter(entry => {
              if (dashboardPresetFilter === "next7") { const ts = matchStartTimestamp(entry.match); return ts !== null && ts >= nowTimestamp && ts <= presetCutoff; }
              if (dashboardPresetFilter === "priority") return isPriorityMatch(entry.match) && Boolean(entry?.recommendation?.recommended) && Boolean(entry?.unlocked);
              return true;
            })
            .filter(entry => {
              if (recommendationFilter === "recommended") return entry.recommendation.recommended;
              if (recommendationFilter === "league") return isPriorityMatch(entry.match);
              return true;
            })
            .filter(entry => {
              if (registrationStatus === "registered") return entry.registered;
              if (!entry.unlocked || !entry.ratingEligible || !entry.lineupSelected) return false;
              if (registrationStatus === "not_registered") return !entry.registered;
              return true;
            })
            .map(entry => ({
              ...entry,
              registeredOverride: registrationStatus === "registered"
            }));
          displayResults(
            filteredMatches,
            processedCache.username,
            processedCache.failedCount,
            registrationStatus
          );

          if (announce) {
            setStatus(
              `Results updated from cached data: ${filteredMatches.length} match${filteredMatches.length === 1 ? "" : "es"} match the current filters.`,
              "success"
            );
          }
          return filteredMatches.length;
        } catch (error) {
          resultsBox.innerHTML = "";
          setStatus(error?.message || "Unable to apply the selected filters.", "error");
          return null;
        }
      }

      function handleFilterChange() {
        applyFiltersFromCache({ announce: true });
      }

      async function searchMatches(options = {}) {
        const synchronized = options?.synchronized === true;
        if (activeSearch) return;

        const username = usernameInput.value.trim();
        const usernameKey = normalizeUsername(username);
        if (!username) {
          setStatus("Please enter your user name.", "error");
          usernameInput.focus();
          return;
        }

        const run = {
          cancelled: false,
          controller: new AbortController()
        };
        activeSearch = run;
        searchButton.disabled = true;
        hideCancelButton();
        const dashboardFallbackSnapshot = dashboardRecommendationMode ? readDashboardCache(username) : null;
        dashboardRecommendationWarning = "";
        dashboardRecommendationFromCache = false;
        dashboardRecommendationTerminal = false;
        processedCache = null;
        hideMatchSearchToolbar();
        resultsBox.innerHTML = "";
        ratingsBox.style.display = "none";
        ratingsBox.innerHTML = "";
        hideUserAvatar();

        try {
          setStatus(`Loading ratings for ${username}...`);
          const statsUrl = `server/team-points/public/player-stats.php?username=${encodeURIComponent(usernameKey)}`;
          const statsResponse = await fetch(statsUrl, { signal: run.controller.signal, credentials: "same-origin", cache: "no-store" });
          const stats = await statsResponse.json();
          if (!statsResponse.ok || stats?.ok === false) throw new Error(stats?.error?.message || `HTTP ${statsResponse.status}`);
          const ratings = {
            classical: getLastRating(stats, "chess_daily"),
            chess960: getLastRating(stats, "chess960_daily")
          };

          showRatings(username, ratings);
          setStatus(`Loading profile for ${username}...`);
          const profileUrl = `https://api.chess.com/pub/player/${encodeURIComponent(usernameKey)}`;
          const profile = await loadJSON(profileUrl, run.controller.signal);
          showUserAvatar(profile, username);
          setStatus(`Loading club memberships for ${username}...`);
          const clubsUrl = `https://api.chess.com/pub/player/${encodeURIComponent(usernameKey)}/clubs`;
          const clubsResponse = await loadJSON(clubsUrl, run.controller.signal);
          const playerClubMemberships = buildPlayerClubMembershipSet(clubsResponse);
          const promoteToKingClubId = normalizeClubReference(
            `https://www.chess.com/club/${CLUB_ID}`
          );
          if (!playerClubMemberships.has(promoteToKingClubId)) {
            processedCache = null;
            hideMatchSearchToolbar();
            resultsBox.innerHTML = "";
            setStatus(
              `${username} is not a member of Promote to King. Match analysis has been cancelled.`,
              "error"
            );
            return;
          }
          setStatus("Loading the registration match list...");
          const clubMatches = await loadJSON(CLUB_MATCHES_URL, run.controller.signal);
          const prioritizeMatchReferences =
            window.P2K_MATCH_PRIORITY?.prioritizeMatchReferences ||
            window.P2K_API_CLIENT?.prioritizeMatchReferences ||
            (values => [...(Array.isArray(values) ? values : [])]);
          const registrationMatches = prioritizeMatchReferences(
            Array.isArray(clubMatches.registered)
              ? clubMatches.registered.filter(item => String(item?.time_class || "").toLowerCase() === "daily")
              : []
          );
          const totalMatches = registrationMatches.length;
          const processedEntries = [];
          let failedCount = 0;
          const failedItems = [];
          let opponentClubExcludedCount = 0;
          let processedCount = 0;
          if (totalMatches === 0) {
            processedCache = {
              username,
              usernameKey,
              ratings,
              profile,
              avatarUrl: String(profile?.avatar || ""),
              entries: [],
              failedCount: 0,
              opponentClubExcludedCount: 0,
              playerClubCount: playerClubMemberships.size,
              totalMatches: 0,
              processedCount: 0,
              cancelled: false,
              processedAt: Date.now()
            };
            setStatus("There are currently no daily matches open for registration.", "success");
            showMatchSearchToolbar();
            const matchesFound = applyFiltersFromCache();
            searchButton.textContent = "Refresh matches";
            searchButton.title = "Refresh ratings, clubs, and open match recommendations.";
            if (matchesFound !== null) {
              logCompletedAnalysis(profile?.username || username, matchesFound, synchronized);
            }
            window.P2K_ANALYSIS_COORDINATOR?.complete?.("find", {
              synchronized,
              username: usernameKey,
              failedCount: 0,
              processedAt: Date.now()
            });
            return;
          }

          showCancelButton();

          const analyzeRegistrationMatch = async (listItem, index) => {
            if (run.cancelled || run.controller.signal.aborted) throw new CancellationError();
            if (!listItem?.["@id"]) throw new Error("The match has no @id URL.");
            const matchDetails = await loadJSON(listItem["@id"], run.controller.signal);
            const match = { ...listItem, ...matchDetails };
            if (!isSupportedDailyMatch(match)) return { skipped: true };
            const team = findPromoteToKingTeam(match);
            if (!team) return { skipped: true };
            const opponentTeam = findOpponentTeam(match, team);
            if (userBelongsToTeamClub(opponentTeam, playerClubMemberships)) return { opponentExcluded: true };
            const registered = isUserRegistered(team, username);
            const rating = ratingForMatch(match, ratings);
            const bounds = getRatingBounds(match);
            const recommendationRating = registered
              ? numericBound(findRegisteredPlayer(team, username)?.rating, rating)
              : rating;
            const recommendation = evaluateRecommendation(
              match, team, username, recommendationRating, registered, bounds
            );
            return { entry: {
              match, team, rating, bounds, registered,
              unlocked: isTeamUnlocked(team),
              ratingEligible: isRatingEligible(rating, bounds),
              lineupSelected: recommendation.lineupSelected,
              recommendation
            }};
          };

          const batch = await window.P2K_API_CLIENT.processPriority(
            registrationMatches,
            analyzeRegistrationMatch,
            {
              signal: run.controller.signal,
              getKey: item => item?.["@id"] || "",
              onProgress: progressState => {
                processedCount = progressState.settled;
                setStatus(
                  `Processing match ${Math.min(progressState.settled + 1, totalMatches)} of ${totalMatches}`,
                  "working",
                  totalMatches ? Math.round(progressState.settled / totalMatches * 100) : 100
                );
              }
            }
          );
          batch.succeeded.sort((a,b)=>a.index-b.index).forEach(result => {
            if (result.value?.opponentExcluded) opponentClubExcludedCount += 1;
            if (result.value?.entry) processedEntries.push(result.value.entry);
          });
          failedItems.push(...batch.failures.map(failure => failure.item));
          failedCount = failedItems.length;
          processedCount = batch.succeeded.length + batch.failures.length;
          if (batch.cancelled || run.controller.signal.aborted) run.cancelled = true;
          processedCache = {
            username,
            usernameKey,
            ratings,
            profile,
            avatarUrl: String(profile?.avatar || ""),
            entries: processedEntries,
              failedItems,
              playerClubMemberships,
              failedCount,
            opponentClubExcludedCount,
            playerClubCount: playerClubMemberships.size,
            totalMatches,
            processedCount,
            cancelled: run.cancelled,
            processedAt: Date.now()
          };
          const opponentClubMessage = opponentClubExcludedCount > 0
            ? ` ${opponentClubExcludedCount} match${opponentClubExcludedCount === 1 ? " was" : "es were"} hidden because ${username} belongs to the opponent club.`
            : "";
          if (run.cancelled) {
            const cancelledProgress = totalMatches > 0
              ? Math.round((processedCount / totalMatches) * 100)
              : 0;
            setStatus(
              `Processing cancelled after ${processedCount} of ${totalMatches} matches.${opponentClubMessage} Showing the results loaded so far.`,
              "success",
              cancelledProgress
            );
          } else {
            setStatus(
              `Processing completed: ${totalMatches - failedCount} of ${totalMatches} matches loaded.${opponentClubMessage}`,
              "success",
              100
            );
          }
          showMatchSearchToolbar();
          const matchesFound = applyFiltersFromCache();
          searchButton.textContent = "Refresh matches";
          searchButton.title = "Refresh ratings, clubs, and open match recommendations.";
          if (!run.cancelled && matchesFound !== null) {
            logCompletedAnalysis(profile?.username || username, matchesFound, synchronized);
          }
          window.P2K_ANALYSIS_COORDINATOR?.complete?.("find", {
            synchronized,
            username: usernameKey,
            failedCount,
            processedAt: Date.now()
          });
        } catch (error) {
          if (error instanceof CancellationError || run.cancelled) {
            setStatus("Processing cancelled.", "success");
          } else {
            if (dashboardRecommendationMode && dashboardFallbackSnapshot) {
              processedCache = { ...dashboardFallbackSnapshot, playerClubMemberships: new Set() };
              dashboardRecommendationWarning = `Live recommendation refresh failed (${error?.message || "temporary error"}). Showing the most recent successful dashboard analysis.`;
              dashboardRecommendationFromCache = true;
              dashboardRecommendationTerminal = true;
              showRatings(processedCache.username || username, processedCache.ratings || {});
              if (processedCache.profile || processedCache.avatarUrl) showUserAvatar(processedCache.profile || { avatar: processedCache.avatarUrl }, processedCache.username || username);
              showMatchSearchToolbar();
              const restoredCount = applyFiltersFromCache();
              if (restoredCount !== null) {
                setStatus(dashboardRecommendationWarning, "success");
                console.warn("Dashboard Recommendation Resilience fallback used.", error);
                return;
              }
            }
            processedCache = null;
            hideMatchSearchToolbar();
            hideUserAvatar();
            postDashboardRecommendations([], username, 0, error?.message || "Unable to complete the recommendation analysis.");
            console.error(error);
            if (Number(error?.status) === 404 || error?.category === "not-found") {
              setStatus("The Chess.com user name was not found.", "error");
            } else {
              setStatus(error?.message || "Unable to complete the search. Please try again later.", "error");
            }
          }
        } finally {
          hideCancelButton();
          searchButton.disabled = false;
          if (activeSearch === run) activeSearch = null;
        }
      }

      async function retryFailedMatches() {
        if (activeSearch || !processedCache?.failedItems?.length) return;
        const run = { cancelled: false, controller: new AbortController() };
        activeSearch = run;
        const retryItems = [...processedCache.failedItems];
        cancelButton.dataset.p2kAction = "cancel";
        showCancelButton();
        searchButton.disabled = true;
        setStatus(`Retrying 0 of ${retryItems.length} failed matches…`, "working", 0);

        const analyzeItem = async listItem => {
          if (!listItem?.["@id"]) throw new Error("The match has no @id URL.");
          const matchDetails = await loadJSON(listItem["@id"], run.controller.signal);
          const match = { ...listItem, ...matchDetails };
          if (!isSupportedDailyMatch(match)) return null;
          const team = findPromoteToKingTeam(match);
          if (!team) return null;
          const opponentTeam = findOpponentTeam(match, team);
          if (userBelongsToTeamClub(opponentTeam, processedCache.playerClubMemberships)) return null;
          const registered = isUserRegistered(team, processedCache.username);
          const rating = ratingForMatch(match, processedCache.ratings);
          const bounds = getRatingBounds(match);
          const recommendationRating = registered
            ? numericBound(findRegisteredPlayer(team, processedCache.username)?.rating, rating)
            : rating;
          const recommendation = evaluateRecommendation(
            match, team, processedCache.username, recommendationRating, registered, bounds
          );
          return {
            match, team, rating, bounds, registered,
            unlocked: isTeamUnlocked(team),
            ratingEligible: isRatingEligible(rating, bounds),
            lineupSelected: recommendation.lineupSelected,
            recommendation
          };
        };

        try {
          const batch = await window.P2K_API_CLIENT.processPriority(
            retryItems,
            analyzeItem,
            {
              signal: run.controller.signal,
              getKey: item => item?.["@id"] || "",
              onProgress: progress => setStatus(
                `Retrying ${progress.settled} of ${progress.total} failed matches…`,
                "working",
                progress.total ? Math.round(progress.settled / progress.total * 100) : 100
              )
            }
          );
          const existing = new Set(processedCache.entries.map(entry => entry.match?.["@id"] || entry.match?.url));
          batch.succeeded.forEach(result => {
            const entry = result.value;
            const key = entry?.match?.["@id"] || entry?.match?.url;
            if (entry && key && !existing.has(key)) {
              existing.add(key);
              processedCache.entries.push(entry);
            }
          });
          processedCache.failedItems = batch.failures.map(failure => failure.item);
          processedCache.failedCount = processedCache.failedItems.length;
          processedCache.processedCount = processedCache.totalMatches - processedCache.failedCount;
          processedCache.cancelled = batch.cancelled;
          applyFiltersFromCache();
          setStatus(
            processedCache.failedCount
              ? `Retry complete: ${processedCache.failedCount} match request${processedCache.failedCount === 1 ? "" : "s"} still failed.`
              : "All failed match requests were loaded successfully.",
            processedCache.failedCount ? "error" : "success",
            100
          );
        } catch (error) {
          if (error?.category !== "cancelled") console.error(error);
          setStatus("Retry stopped. Results loaded before the interruption are retained.", "error");
        } finally {
          activeSearch = null;
          searchButton.disabled = false;
          hideCancelButton();
        }
      }

      cancelButton.addEventListener("click", () => {
        if (cancelButton.dataset.p2kAction === "retry-failed") {
          void retryFailedMatches();
          return;
        }
        if (!activeSearch || activeSearch.cancelled) return;
        activeSearch.cancelled = true;
        cancelButton.disabled = true;
        cancelButton.textContent = "Cancelling...";
        statusText.textContent = "Cancelling match processing...";
        activeSearch.controller.abort();
      });

      matchSearchInput.addEventListener("input", () => {
        resultState.visibleCount = PAGE_SIZE;
        renderResults();
      });
      clearMatchSearchButton.addEventListener("click", () => {
        if (!matchSearchInput.value) return;
        matchSearchInput.value = "";
        resultState.visibleCount = PAGE_SIZE;
        renderResults();
        matchSearchInput.focus();
      });

      searchButton.addEventListener("click", () => { void searchMatches(); });
      usernameInput.addEventListener("keydown", event => {
        if (event.key === "Enter") void searchMatches();
      });
      document.querySelectorAll(
        'input[name="p2kMatchType"], input[name="p2kRegistrationStatus"], input[name="p2kRecommendationFilter"], input[name="p2kSortMode"]'
      ).forEach(input => input.addEventListener("change", handleFilterChange));

      window.P2K_ANALYSIS_COORDINATOR?.register?.("find", {
        isBusy: () => Boolean(activeSearch),
        canRefresh: () => Boolean(processedCache?.usernameKey && usernameInput.value.trim()),
        refresh: ({ synchronized = false } = {}) => searchMatches({ synchronized })
      });
      function reportHeight() {
        if (window.parent === window) return;
        window.parent.postMessage(
          {
            type: "p2k-match-finder-height",
            height: Math.ceil(document.documentElement.scrollHeight)
          },
          "*"
        );
      }

      if ("ResizeObserver" in window) {
        new ResizeObserver(reportHeight).observe(document.body);
      }
      window.addEventListener("load", reportHeight);


      window.addEventListener("message", event => {
        if (event.source !== window.parent || event.origin !== window.location.origin) return;
        if (event.data?.type !== "p2k-dashboard-show-full-assistant") return;
        document.documentElement.classList.add("p2k-dashboard-assistant-hydrating");
        showMatchSearchToolbar();
        applyFiltersFromCache();
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
          document.documentElement.classList.remove("p2k-dashboard-assistant-hydrating");
          window.parent.postMessage({
            type: "p2k-dashboard-full-assistant-ready",
            username: String(processedCache?.username || usernameInput.value || "")
          }, window.location.origin);
          reportHeight();
        }));
      });

      if (dashboardRecommendationMode && dashboardRecommendationUsername) {
        usernameInput.value = dashboardRecommendationUsername;
        window.setTimeout(() => {
          if (restoreDashboardCache(dashboardRecommendationUsername)) {
            dashboardRecommendationWarning = "Showing the most recent successful recommendation analysis while live data refreshes.";
            dashboardRecommendationFromCache = true;
            dashboardRecommendationTerminal = false;
            applyFiltersFromCache();
          }
          void searchMatches({ synchronized: true });
        }, 0);
      } else if (dashboardAssistantMode && dashboardAssistantUsername) {
        usernameInput.value = dashboardAssistantUsername;
        window.setTimeout(async () => {
          const restored = restoreDashboardCache(dashboardAssistantUsername);
          if (restored) {
            showRatings(processedCache.username || dashboardAssistantUsername, processedCache.ratings || {});
            if (processedCache.profile || processedCache.avatarUrl) {
              showUserAvatar(processedCache.profile || { avatar: processedCache.avatarUrl }, processedCache.username || dashboardAssistantUsername);
            }
            showMatchSearchToolbar();
            applyFiltersFromCache();
            searchButton.textContent = "Refresh matches";
            searchButton.title = "Refresh ratings, clubs, and open match recommendations.";
            setStatus(`Cached analysis ready for ${processedCache.username || dashboardAssistantUsername}.`, "success");
          } else {
            await searchMatches({ synchronized: true });
          }
          notifyDashboardAssistantReady(processedCache?.username || dashboardAssistantUsername);
        }, 0);
      }
    
      window.addEventListener("message", event => {
        if (event.origin !== window.location.origin || event.data?.type !== "p2k-dashboard-apply-filter") return;
        dashboardPresetFilter = ["next7","priority"].includes(String(event.data.filter || "")) ? String(event.data.filter) : "";
        if (processedCache) applyFiltersFromCache({ announce: true });
      });
})();
