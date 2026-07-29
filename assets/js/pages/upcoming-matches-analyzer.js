/* Upcoming Matches Analyzer page logic. */
(() => {
  "use strict";

  const CLUB_ID = "promote-to-king";
  const MATCH_LIST_URL = `https://api.chess.com/pub/club/${CLUB_ID}/matches`;
  const PAGE_SIZE = 5;
  const REQUEST_ATTEMPTS = 3;
  const JSONP_TIMEOUT_MS = 20000;
  const LEAGUE_ACRONYMS = ["1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW"];
  const SIGNIFICANT_P2K_LINEUP_ADVANTAGE = 100;
  const LEAGUE_MINIMUM_WIN_PROBABILITY = .65;
  const FRIENDLY_RELIABILITY_RATING = 1200;
  const FRIENDLY_MINIMUM_AVERAGE_MARGIN = 10;
  const FRIENDLY_NET_BOARD_MARGIN_RATE = .05;
  const FRIENDLY_TOP_HALF_ENTRY_CUSHION = 50;

  const root = document.getElementById("p2kUpcomingAnalyzer");
  const analyzeButton = document.getElementById("p2kAnalyzeButton");
  const statusBox = document.getElementById("p2kStatus");
  const statusText = document.getElementById("p2kStatusText");
  const progressTrack = document.getElementById("p2kProgressTrack");
  const progressBar = document.getElementById("p2kProgressBar");
  const processingActions = document.getElementById("p2kProcessingActions");
  const cancelButton = document.getElementById("p2kCancelButton");
  const resultsBox = document.getElementById("p2kResults");
  const recruitmentRecap = document.getElementById("p2kRecruitmentRecap");
  const searchToolbar = document.getElementById("p2kSearchToolbar");
  const matchSearchInput = document.getElementById("p2kMatchSearch");
  const clearSearchButton = document.getElementById("p2kClearSearch");
  const chartModal = document.getElementById("p2kChartModal");
  const chartModalTitle = document.getElementById("p2kChartModalTitle");
  const chartModalBody = document.getElementById("p2kChartModalBody");
  const chartModalClose = document.getElementById("p2kChartModalClose");
  const copyChoiceModal = document.getElementById("p2kCopyChoiceModal");
  const copyChoiceTitle = document.getElementById("p2kCopyChoiceTitle");
  const copyChoiceClose = document.getElementById("p2kCopyChoiceClose");
  const copyFormattedButton = document.getElementById("p2kCopyFormatted");
  const copyPlainTextButton = document.getElementById("p2kCopyPlainText");
  const copyHTMLSourceButton = document.getElementById("p2kCopyHTMLSource");
  const copyModal = document.getElementById("p2kCopyModal");
  const copyModalClose = document.getElementById("p2kCopyModalClose");
  const copyModalCloseBottom = document.getElementById("p2kCopyModalCloseBottom");
  const copyManualText = document.getElementById("p2kCopyManualText");
  const copyModalTitle = document.getElementById("p2kCopyModalTitle");
  let pendingCopyPayload = null;
  const copySelectAll = document.getElementById("p2kCopySelectAll");
  let lastChartModalTrigger = null;
  let activeAnalysis = null;

  /*
   * This is the in-memory cache. Once all @id URLs have been processed,
   * changing a radio button only filters/sorts this array and makes no API call.
   */
  const state = {
    processed: false,
    processing: false,
    matches: [],
    failedCount: 0,
    visibleCount: PAGE_SIZE,
    expandedDetails: new Set(),
    recruitmentRecapExpanded: false
  };

  class HttpError extends Error {
    constructor(status, url) {
      super(`HTTP ${status} while loading ${url}`);
      this.name = "HttpError";
      this.status = status;
      this.url = url;
    }
  }

  function escapeHTML(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
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

      const onAbort = () => {
        window.clearTimeout(timer);
        signal?.removeEventListener("abort", onAbort);
        reject(new CancellationError());
      };

      const timer = window.setTimeout(() => {
        signal?.removeEventListener("abort", onAbort);
        resolve();
      }, milliseconds);

      signal?.addEventListener("abort", onAbort, { once: true });
    });
  }

  function allowScreenUpdate() {
    return new Promise(resolve => {
      window.requestAnimationFrame(() => window.requestAnimationFrame(resolve));
    });
  }

  function setStatus(message, type = "working", progress = null) {
    statusBox.style.display = "block";
    statusBox.classList.toggle("p2k-error", type === "error");
    statusBox.classList.toggle("p2k-success", type === "success");
    statusText.textContent = message;

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
    processingActions.style.display = "flex";
    cancelButton.disabled = false;
    cancelButton.textContent = "Cancel";
  }

  function hideCancelButton() {
    processingActions.style.display = "none";
    cancelButton.disabled = false;
    cancelButton.textContent = "Cancel";
  }

  function appendCallbackParameter(url, callbackName) {
    return `${url}${url.includes("?") ? "&" : "?"}callback=${encodeURIComponent(callbackName)}`;
  }

  function loadJSONP(url, signal = null) {
    return new Promise((resolve, reject) => {
      if (signal?.aborted) {
        reject(new CancellationError());
        return;
      }

      const callbackName = `p2kUpcomingJsonp_${Date.now()}_${Math.random().toString(36).slice(2)}`;
      const script = document.createElement("script");
      let timer = null;

      const cleanup = () => {
        if (timer !== null) window.clearTimeout(timer);
        signal?.removeEventListener("abort", onAbort);
        script.remove();
        try {
          delete window[callbackName];
        } catch (_) {
          window[callbackName] = undefined;
        }
      };

      const onAbort = () => {
        cleanup();
        reject(new CancellationError());
      };

      window[callbackName] = data => {
        cleanup();
        resolve(data);
      };

      script.onerror = () => {
        cleanup();
        reject(new Error(`JSONP request failed for ${url}`));
      };

      timer = window.setTimeout(() => {
        cleanup();
        reject(new Error(`JSONP request timed out for ${url}`));
      }, JSONP_TIMEOUT_MS);

      signal?.addEventListener("abort", onAbort, { once: true });
      script.src = appendCallbackParameter(url, callbackName);
      script.async = true;
      document.head.appendChild(script);
    });
  }

  async function requestJSONOnce(url, signal = null, options = {}) {
    if (signal?.aborted) throw new CancellationError();

    try {
      const response = await fetch(url, {
        method: "GET",
        mode: "cors",
        cache: "no-store",
        headers: { Accept: "application/json" },
        signal,
        p2kCacheMode: options.networkOnly === true ? "network-only" : "default"
      });

      if (!response.ok) throw new HttpError(response.status, url);
      return await response.json();
    } catch (error) {
      if (signal?.aborted || error?.name === "AbortError") throw new CancellationError();
      if (error instanceof HttpError) throw error;
      return await loadJSONP(url, signal);
    }
  }

  async function loadJSON(url, signal = null, options = {}) {
    let lastError = null;

    for (let attempt = 1; attempt <= REQUEST_ATTEMPTS; attempt += 1) {
      try {
        return await requestJSONOnce(url, signal, options);
      } catch (error) {
        lastError = error;
        if (error instanceof CancellationError) throw error;
        if (error instanceof HttpError && error.status === 404) throw error;
        if (attempt < REQUEST_ATTEMPTS) await delay(attempt * 800, signal);
      }
    }

    throw lastError || new Error(`Unable to load ${url}`);
  }

  function selectedValue(name, fallback) {
    return root.querySelector(`input[name="${name}"]:checked`)?.value || fallback;
  }


  function normalizedSearchText(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function findP2KTeam(match) {
    return Object.values(match?.teams || {}).find(team => {
      const apiURL = String(team?.["@id"] || "").toLowerCase();
      const webURL = String(team?.url || "").toLowerCase();
      return apiURL.includes(`/club/${CLUB_ID}`) || webURL.includes(`/club/${CLUB_ID}`);
    }) || null;
  }

  function findOpponentTeam(match, p2kTeam) {
    return Object.values(match?.teams || {}).find(team => team !== p2kTeam) || null;
  }

  function numericSetting(value, fallback) {
    if (value === undefined || value === null || value === "") return fallback;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function ratingBounds(match) {
    return {
      minimum: numericSetting(match?.settings?.min_rating, 0),
      maximum: numericSetting(match?.settings?.max_rating, Number.POSITIVE_INFINITY)
    };
  }

  function minimumTeamPlayers(match) {
    const value = Number(match?.settings?.min_team_players);
    return Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
  }

  function maximumTeamPlayers(match) {
    const value = Number(match?.settings?.max_team_players);
    return Number.isFinite(value) && value > 0
      ? Math.floor(value)
      : Number.POSITIVE_INFINITY;
  }

  function players(team) {
    return Array.isArray(team?.players) ? team.players : [];
  }

  function ratedPlayers(team, bounds = null) {
    return players(team)
      .map(player => {
        const rating = Number(player?.rating);
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

  function selectedLineupRatings(team, maxPlayers, bounds = null) {
    const ratings = ratedPlayers(team, bounds).map(player => player.rating);
    return Number.isFinite(maxPlayers) ? ratings.slice(0, maxPlayers) : ratings;
  }

  function sumRatings(ratings) {
    return ratings.reduce((sum, rating) => sum + rating, 0);
  }

  function stableHash(value) {
    const stringValue = String(value || "");
    let hash = 2166136261;

    for (let index = 0; index < stringValue.length; index += 1) {
      hash ^= stringValue.charCodeAt(index);
      hash = Math.imul(hash, 16777619);
    }

    return (hash >>> 0).toString(36);
  }

  function eloExpectedScore(rating1, rating2) {
    return 1 / (1 + Math.pow(10, (rating2 - rating1) / 400));
  }

  function matchOutcomeProbabilities(boardExpectedScores) {
    /*
     * Elo gives an expected score E between 0 and 1. To preserve that
     * expectation while allowing a game to score 0, 0.5 or 1, model each
     * game as two independent half-point trials with probability E.
     * Each board contains two games, hence four half-point trials.
     */
    let distribution = [1];

    boardExpectedScores.forEach(expectedScore => {
      for (let trial = 0; trial < 4; trial += 1) {
        const next = new Array(distribution.length + 1).fill(0);

        for (let units = 0; units < distribution.length; units += 1) {
          next[units] += distribution[units] * (1 - expectedScore);
          next[units + 1] += distribution[units] * expectedScore;
        }

        distribution = next;
      }
    });

    const drawThresholdUnits = boardExpectedScores.length * 2;
    let p2kWin = 0;
    let draw = 0;
    let opponentWin = 0;

    const expectedHalfPointUnits = boardExpectedScores.reduce(
      (sum, expectedScore) => sum + 4 * expectedScore,
      0
    );
    let mostLikelyHalfPointUnits = 0;
    let mostLikelyProbability = -1;

    distribution.forEach((probability, halfPointUnits) => {
      if (halfPointUnits > drawThresholdUnits) p2kWin += probability;
      else if (halfPointUnits < drawThresholdUnits) opponentWin += probability;
      else draw += probability;

      const isMoreLikely = probability > mostLikelyProbability + Number.EPSILON;
      const isEquivalentAndCloser =
        Math.abs(probability - mostLikelyProbability) <= Number.EPSILON &&
        Math.abs(halfPointUnits - expectedHalfPointUnits) <
          Math.abs(mostLikelyHalfPointUnits - expectedHalfPointUnits);

      if (isMoreLikely || isEquivalentAndCloser) {
        mostLikelyProbability = probability;
        mostLikelyHalfPointUnits = halfPointUnits;
      }
    });

    return {
      p2kWin,
      opponentWin,
      draw,
      mostLikelyHalfPointUnits
    };
  }


  function ratingBracketStart(rating, bracketSize = 100) {
    return Math.floor(Number(rating) / bracketSize) * bracketSize;
  }


  function percentile(sortedRatings, percentileValue) {
    if (!sortedRatings.length) return null;
    if (sortedRatings.length === 1) return sortedRatings[0];

    const position = (sortedRatings.length - 1) * percentileValue;
    const lowerIndex = Math.floor(position);
    const upperIndex = Math.ceil(position);
    const fraction = position - lowerIndex;

    if (lowerIndex === upperIndex) return sortedRatings[lowerIndex];
    return sortedRatings[lowerIndex] +
      (sortedRatings[upperIndex] - sortedRatings[lowerIndex]) * fraction;
  }

  function summarizeRatings(ratings, ratedRegistrationCount) {
    const sorted = ratings.filter(Number.isFinite).slice().sort((a, b) => a - b);
    const count = sorted.length;

    if (count === 0) {
      return {
        count: 0,
        share: ratedRegistrationCount > 0 ? 0 : null,
        mean: null,
        median: null,
        standardDeviation: null,
        q1: null,
        q3: null,
        minimum: null,
        maximum: null
      };
    }

    const mean = sorted.reduce((sum, rating) => sum + rating, 0) / count;
    const variance = sorted.reduce((sum, rating) => sum + Math.pow(rating - mean, 2), 0) / count;

    return {
      count,
      share: ratedRegistrationCount > 0 ? count / ratedRegistrationCount : null,
      mean,
      median: percentile(sorted, .5),
      standardDeviation: Math.sqrt(variance),
      q1: percentile(sorted, .25),
      q3: percentile(sorted, .75),
      minimum: sorted[0],
      maximum: sorted[sorted.length - 1]
    };
  }

  function buildRatingStatistics(registeredRatings, ratingEligibleRatings) {
    const eligibleSetCounts = new Map();
    ratingEligibleRatings.forEach(rating => {
      eligibleSetCounts.set(rating, (eligibleSetCounts.get(rating) || 0) + 1);
    });

    const nonEligibleRatings = [];
    registeredRatings.forEach(rating => {
      const remaining = eligibleSetCounts.get(rating) || 0;
      if (remaining > 0) {
        eligibleSetCounts.set(rating, remaining - 1);
      } else {
        nonEligibleRatings.push(rating);
      }
    });

    return {
      eligible: summarizeRatings(ratingEligibleRatings, registeredRatings.length),
      nonEligible: summarizeRatings(nonEligibleRatings, registeredRatings.length)
    };
  }

  function buildRatingBracketHistogram(
    registeredRatings1,
    registeredRatings2,
    eligibleRatings1,
    eligibleRatings2,
    bracketSize = 100
  ) {
    const combined = [...registeredRatings1, ...registeredRatings2].filter(Number.isFinite);
    if (combined.length === 0) return [];

    const minimum = ratingBracketStart(Math.min(...combined), bracketSize);
    const maximum = ratingBracketStart(Math.max(...combined), bracketSize);
    const binMap = new Map();

    for (let start = minimum; start <= maximum; start += bracketSize) {
      binMap.set(start, {
        start,
        end: start + bracketSize - 1,
        team1Registered: 0,
        team2Registered: 0,
        team1Eligible: 0,
        team2Eligible: 0
      });
    }

    const addRatings = (ratings, field) => {
      ratings.forEach(rating => {
        if (!Number.isFinite(rating)) return;
        const start = ratingBracketStart(rating, bracketSize);
        if (!binMap.has(start)) {
          binMap.set(start, {
            start,
            end: start + bracketSize - 1,
            team1Registered: 0,
            team2Registered: 0,
            team1Eligible: 0,
            team2Eligible: 0
          });
        }
        binMap.get(start)[field] += 1;
      });
    };

    addRatings(registeredRatings1, "team1Registered");
    addRatings(registeredRatings2, "team2Registered");
    addRatings(eligibleRatings1, "team1Eligible");
    addRatings(eligibleRatings2, "team2Eligible");

    return Array.from(binMap.values())
      .sort((a, b) => a.start - b.start)
      .map(bin => ({
        ...bin,
        team1NonEligible: Math.max(0, bin.team1Registered - bin.team1Eligible),
        team2NonEligible: Math.max(0, bin.team2Registered - bin.team2Eligible)
      }));
  }


  function buildCumulativeRatingData(ratings1, ratings2, step = 100) {
    const team1Ratings = ratings1.filter(Number.isFinite);
    const team2Ratings = ratings2.filter(Number.isFinite);
    const combined = [...team1Ratings, ...team2Ratings];
    if (combined.length === 0) return [];

    const minimumRating = Math.min(...combined);
    const maximumRating = Math.max(...combined);
    const firstThreshold = Math.floor(minimumRating / step) * step;
    const lastThreshold = Math.floor(maximumRating / step) * step;
    const data = [];

    for (let threshold = firstThreshold; threshold <= lastThreshold; threshold += step) {
      data.push({
        threshold,
        team1: team1Ratings.filter(rating => rating >= threshold).length,
        team2: team2Ratings.filter(rating => rating >= threshold).length
      });
    }

    return data;
  }

  function detailedLineupAnalysis(p2kTeam, opponentTeam, bounds, maxPlayers) {
    const p2kRegisteredRatings = ratedPlayers(p2kTeam).map(player => player.rating);
    const opponentRegisteredRatings = ratedPlayers(opponentTeam).map(player => player.rating);
    const p2kRatingEligibleRatings = ratedPlayers(p2kTeam, bounds).map(player => player.rating);
    const opponentRatingEligibleRatings = ratedPlayers(opponentTeam, bounds).map(player => player.rating);

    /*
     * The eligible strength must represent the lineup that can actually be
     * paired. Apply the rating window, cap by max_team_players when present,
     * then use the same number of boards for both teams.
     */
    const configuredBoardLimit = Number.isFinite(maxPlayers)
      ? maxPlayers
      : Number.POSITIVE_INFINITY;
    const boardCount = Math.min(
      configuredBoardLimit,
      p2kRatingEligibleRatings.length,
      opponentRatingEligibleRatings.length
    );
    const p2kEligibleRatings = p2kRatingEligibleRatings.slice(0, boardCount);
    const opponentEligibleRatings = opponentRatingEligibleRatings.slice(0, boardCount);
    const p2kEligibleStrength = sumRatings(p2kEligibleRatings);
    const opponentEligibleStrength = sumRatings(opponentEligibleRatings);

    const boardAdvantages = {
      overall: { team1: 0, team2: 0 },
      under50: { team1: 0, team2: 0 },
      from50To100: { team1: 0, team2: 0 },
      over100: { team1: 0, team2: 0 }
    };

    const boardExpectedScores = [];

    for (let board = 0; board < boardCount; board += 1) {
      const rating1 = p2kEligibleRatings[board];
      const rating2 = opponentEligibleRatings[board];
      const difference = rating1 - rating2;
      const absoluteDifference = Math.abs(difference);
      const advantagedTeam = difference > 0 ? "team1" : difference < 0 ? "team2" : null;

      if (advantagedTeam) {
        boardAdvantages.overall[advantagedTeam] += 1;

        if (absoluteDifference < 50) {
          boardAdvantages.under50[advantagedTeam] += 1;
        } else if (absoluteDifference < 100) {
          boardAdvantages.from50To100[advantagedTeam] += 1;
        } else {
          boardAdvantages.over100[advantagedTeam] += 1;
        }
      }

      boardExpectedScores.push(eloExpectedScore(rating1, rating2));
    }

    const totalAvailablePoints = boardCount * 2;
    const outcomeProbabilities = matchOutcomeProbabilities(boardExpectedScores);
    /*
     * Use the most likely aggregate result from the half-point score
     * distribution. This guarantees scores ending in .0 or .5 while keeping
     * both teams' predicted totals equal to the points available in the match.
     */
    const predictedScoreTeam1 = outcomeProbabilities.mostLikelyHalfPointUnits / 2;
    const predictedScoreTeam2 = totalAvailablePoints - predictedScoreTeam1;

    return {
      p2kRegisteredStrength: sumRatings(p2kRegisteredRatings),
      opponentRegisteredStrength: sumRatings(opponentRegisteredRatings),
      ratingBracketHistogram: buildRatingBracketHistogram(
        p2kRegisteredRatings,
        opponentRegisteredRatings,
        p2kRatingEligibleRatings,
        opponentRatingEligibleRatings,
        100
      ),
      ratingStatistics: {
        team1: buildRatingStatistics(p2kRegisteredRatings, p2kRatingEligibleRatings),
        team2: buildRatingStatistics(opponentRegisteredRatings, opponentRatingEligibleRatings)
      },
      cumulativeRatingData: buildCumulativeRatingData(
        p2kRegisteredRatings,
        opponentRegisteredRatings,
        100
      ),
      p2kRatingEligibleCount: p2kRatingEligibleRatings.length,
      opponentRatingEligibleCount: opponentRatingEligibleRatings.length,
      p2kEligibleCount: p2kEligibleRatings.length,
      opponentEligibleCount: opponentEligibleRatings.length,
      p2kEligibleStrength,
      opponentEligibleStrength,
      boardCount,
      deltaStrength: p2kEligibleStrength - opponentEligibleStrength,
      boardAdvantages,
      predictedScoreTeam1,
      predictedScoreTeam2,
      p2kWinProbability: outcomeProbabilities.p2kWin,
      opponentWinProbability: outcomeProbabilities.opponentWin,
      drawProbability: outcomeProbabilities.draw
    };
  }

  function compareLineups(p2kRatings, opponentRatings) {
    /* Compare the same number of boards on both sides. */
    const boardCount = Math.min(p2kRatings.length, opponentRatings.length);
    if (boardCount === 0) return null;

    const p2kTotal = p2kRatings.slice(0, boardCount).reduce((sum, rating) => sum + rating, 0);
    const opponentTotal = opponentRatings.slice(0, boardCount).reduce((sum, rating) => sum + rating, 0);

    return {
      boardCount,
      p2kAverage: p2kTotal / boardCount,
      opponentAverage: opponentTotal / boardCount,
      opponentAdvantage: (opponentTotal - p2kTotal) / boardCount
    };
  }

  function matchingLeagueAcronyms(name) {
    const upperName = String(name || "").toUpperCase();
    return LEAGUE_ACRONYMS.filter(acronym => upperName.includes(acronym));
  }


  function reliabilityAdjustedFriendlyRating(rating) {
    const numericRating = Number(rating);
    if (!Number.isFinite(numericRating)) return 0;
    if (numericRating >= FRIENDLY_RELIABILITY_RATING) return numericRating;

    /*
     * Ratings below 1200 are treated conservatively for friendly-match
     * recruitment planning because those registrations are less stable.
     * This affects only the internal recommendation model, never displayed Elo.
     */
    const shortfall = FRIENDLY_RELIABILITY_RATING - numericRating;
    return numericRating - 25 - (shortfall * .25);
  }

  function compareRecruitmentLineups(p2kRatings, opponentRatings, maxPlayers) {
    const cappedP2K = Number.isFinite(maxPlayers)
      ? p2kRatings.slice(0, maxPlayers)
      : [...p2kRatings];
    const cappedOpponent = Number.isFinite(maxPlayers)
      ? opponentRatings.slice(0, maxPlayers)
      : [...opponentRatings];
    const boardCount = Math.min(cappedP2K.length, cappedOpponent.length);
    const topHalfBoardCount = Math.ceil(boardCount / 2);
    const boardExpectedScores = [];
    let p2kAdvantageBoards = 0;
    let opponentAdvantageBoards = 0;
    let tiedBoards = 0;
    let ratingDelta = 0;
    let reliabilityAdjustedDelta = 0;
    let topHalfRatingDelta = 0;

    for (let board = 0; board < boardCount; board += 1) {
      const difference = cappedP2K[board] - cappedOpponent[board];
      ratingDelta += difference;
      reliabilityAdjustedDelta +=
        reliabilityAdjustedFriendlyRating(cappedP2K[board]) -
        reliabilityAdjustedFriendlyRating(cappedOpponent[board]);
      if (board < topHalfBoardCount) topHalfRatingDelta += difference;
      if (difference > 0) p2kAdvantageBoards += 1;
      else if (difference < 0) opponentAdvantageBoards += 1;
      else tiedBoards += 1;
      boardExpectedScores.push(
        eloExpectedScore(cappedP2K[board], cappedOpponent[board])
      );
    }

    const outcomeProbabilities = boardExpectedScores.length > 0
      ? matchOutcomeProbabilities(boardExpectedScores)
      : { p2kWin: 0, opponentWin: 0, draw: 1 };

    return {
      boardCount,
      topHalfBoardCount,
      p2kAdvantageBoards,
      opponentAdvantageBoards,
      tiedBoards,
      netBoardAdvantage: p2kAdvantageBoards - opponentAdvantageBoards,
      averageRatingDifference: boardCount > 0 ? ratingDelta / boardCount : 0,
      reliabilityAdjustedAverageDifference: boardCount > 0
        ? reliabilityAdjustedDelta / boardCount
        : 0,
      topHalfAverageRatingDifference: topHalfBoardCount > 0
        ? topHalfRatingDelta / topHalfBoardCount
        : 0,
      p2kWinProbability: outcomeProbabilities.p2kWin,
      opponentWinProbability: outcomeProbabilities.opponentWin,
      drawProbability: outcomeProbabilities.draw
    };
  }

  function recruitmentTargetReached(comparison, targetBoardCount, isLeagueMatch) {
    if (!comparison || comparison.boardCount < targetBoardCount) return false;

    if (isLeagueMatch) {
      const requiredNetBoardAdvantage = Math.min(
        targetBoardCount,
        Math.max(2, Math.ceil(targetBoardCount * .20))
      );
      const requiredAdvantagedBoards = Math.max(
        requiredNetBoardAdvantage,
        Math.ceil(targetBoardCount * .60)
      );
      return comparison.netBoardAdvantage >= requiredNetBoardAdvantage &&
        comparison.p2kAdvantageBoards >= requiredAdvantagedBoards &&
        comparison.p2kWinProbability >= LEAGUE_MINIMUM_WIN_PROBABILITY;
    }

    const requiredNetBoardAdvantage = Math.max(
      1,
      Math.ceil(targetBoardCount * FRIENDLY_NET_BOARD_MARGIN_RATE)
    );
    return comparison.netBoardAdvantage >= requiredNetBoardAdvantage &&
      comparison.averageRatingDifference >= FRIENDLY_MINIMUM_AVERAGE_MARGIN &&
      comparison.reliabilityAdjustedAverageDifference >= FRIENDLY_MINIMUM_AVERAGE_MARGIN &&
      comparison.topHalfAverageRatingDifference >= 0;
  }

  function friendlyPreferredCandidateFloor(opponentRatings, targetBoardCount, minimumRating, maximumRating) {
    if (!Array.isArray(opponentRatings) || opponentRatings.length === 0) {
      return Math.min(maximumRating, Math.max(minimumRating, FRIENDLY_RELIABILITY_RATING));
    }

    const comparedCount = Math.max(1, Math.min(targetBoardCount, opponentRatings.length));
    const topHalfCount = Math.max(1, Math.ceil(comparedCount / 2));
    const topHalfBoundary = Number(opponentRatings[topHalfCount - 1]) || FRIENDLY_RELIABILITY_RATING;
    const preferred = Math.max(
      FRIENDLY_RELIABILITY_RATING,
      topHalfBoundary + FRIENDLY_TOP_HALF_ENTRY_CUSHION
    );
    return Math.min(maximumRating, Math.max(minimumRating, Math.ceil(preferred / 25) * 25));
  }

  function recruitmentCandidateRatings(minimumRating, maximumRating, preferredFloor, isLeagueMatch) {
    const ratings = [];
    const appendRange = (start, end) => {
      for (let rating = start; rating <= end; rating += 25) ratings.push(rating);
    };

    if (isLeagueMatch) {
      appendRange(minimumRating, maximumRating);
      return ratings;
    }

    /* Prefer candidates who enter the upper half, then fall back lower only if needed. */
    appendRange(preferredFloor, maximumRating);
    if (preferredFloor > minimumRating) appendRange(minimumRating, preferredFloor - 25);
    return ratings;
  }

  function buildRecruitmentRecommendation(match, p2kTeam, opponentTeam, bounds, maxPlayers, minPlayers, isLeagueMatch) {
    const p2kRatings = ratedPlayers(p2kTeam, bounds).map(player => player.rating);
    const opponentRatings = ratedPlayers(opponentTeam, bounds).map(player => player.rating);
    const mandatoryMinimumPlayers = Math.max(
      0,
      Number.isFinite(Number(minPlayers))
        ? Math.floor(Number(minPlayers))
        : 0
    );
    const cappedMandatoryMinimum = Number.isFinite(maxPlayers)
      ? Math.min(maxPlayers, mandatoryMinimumPlayers)
      : mandatoryMinimumPlayers;
    const missingForMinimum = Math.max(
      0,
      cappedMandatoryMinimum - p2kRatings.length
    );
    const opponentCapacity = Number.isFinite(maxPlayers)
      ? Math.min(maxPlayers, opponentRatings.length)
      : opponentRatings.length;

    /*
     * League planning is cumulative: project both sides to min_players, ensure
     * P2K has no player-count deficit, then test dominance and win probability
     * on that complete projected lineup.
     */
    const targetBoardCount = isLeagueMatch
      ? (Number.isFinite(maxPlayers)
          ? Math.min(maxPlayers, Math.max(cappedMandatoryMinimum, opponentCapacity))
          : Math.max(cappedMandatoryMinimum, opponentCapacity))
      : opponentCapacity;

    const opponentProjection = (() => {
      if (!isLeagueMatch || opponentRatings.length >= targetBoardCount) {
        return {
          ratings: [...opponentRatings],
          addedPlayers: 0,
          assumedRating: null
        };
      }

      const current = [...opponentRatings].sort((a, b) => b - a);
      const boundedFallback = Math.max(
        bounds.minimum,
        Math.min(
          Number.isFinite(bounds.maximum) ? bounds.maximum : FRIENDLY_RELIABILITY_RATING,
          FRIENDLY_RELIABILITY_RATING
        )
      );
      const averageRating = current.length > 0
        ? current.reduce((sum, rating) => sum + rating, 0) / current.length
        : boundedFallback;
      const medianRating = current.length > 0
        ? current[Math.floor(current.length / 2)]
        : boundedFallback;
      const assumedRating = Math.max(
        bounds.minimum,
        Math.min(
          Number.isFinite(bounds.maximum) ? bounds.maximum : 3000,
          Math.ceil(Math.max(averageRating, medianRating) / 25) * 25
        )
      );
      const addedPlayers = Math.max(0, targetBoardCount - current.length);
      return {
        ratings: [
          ...current,
          ...Array.from({ length: addedPlayers }, () => assumedRating)
        ].sort((a, b) => b - a),
        addedPlayers,
        assumedRating
      };
    })();

    const comparisonOpponentRatings = isLeagueMatch
      ? opponentProjection.ratings
      : opponentRatings;
    const current = compareRecruitmentLineups(
      p2kRatings,
      comparisonOpponentRatings,
      maxPlayers
    );
    const leagueNetBoardTarget = Math.min(
      targetBoardCount,
      Math.max(2, Math.ceil(targetBoardCount * .20))
    );
    const leagueWinPercent = Math.round(LEAGUE_MINIMUM_WIN_PROBABILITY * 100);
    const targetLabel = isLeagueMatch
      ? `at least ${targetBoardCount} eligible player${targetBoardCount === 1 ? "" : "s"} on each projected side, no P2K player-count deficit, a significant board advantage (at least +${leagueNetBoardTarget} net board${leagueNetBoardTarget === 1 ? "" : "s"} and 60% of boards ahead), and at least a ${leagueWinPercent}% projected win chance`
      : "a balanced lineup or a small P2K board advantage";
    const opponentProjectionDetail = isLeagueMatch && opponentProjection.addedPlayers > 0
      ? ` The opponent projection includes ${opponentProjection.addedPlayers} additional player${opponentProjection.addedPlayers === 1 ? "" : "s"} at approximately ${opponentProjection.assumedRating} to reach the match minimum.`
      : "";

    if (p2kTeam?.locked === true) {
      return {
        actionable: false,
        needsRecruitment: false,
        isLeagueMatch,
        targetBoardCount,
        current,
        targetLabel,
        summary: "Recruitment is unavailable because the P2K team is locked.",
        detail: "The recommendation is informational only; registrations can no longer be added."
      };
    }

    if (targetBoardCount === 0 && missingForMinimum === 0) {
      return {
        actionable: false,
        needsRecruitment: false,
        isLeagueMatch,
        targetBoardCount,
        current,
        targetLabel,
        summary: "No recruitment recommendation is available yet.",
        detail: "The opponent has no rating-eligible registered lineup to compare."
      };
    }

    const missingForFullComparison = Math.max(
      0,
      targetBoardCount - p2kRatings.length,
      missingForMinimum
    );
    const missingShare = targetBoardCount > 0
      ? missingForFullComparison / targetBoardCount
      : 0;
    const friendlyAlreadyAdvantagedWithSmallShortfall =
      !isLeagueMatch &&
      current &&
      current.boardCount > 0 &&
      current.netBoardAdvantage > 0 &&
      current.averageRatingDifference >= 0 &&
      current.reliabilityAdjustedAverageDifference >= 0 &&
      current.topHalfAverageRatingDifference >= 0 &&
      missingShare <= .20;

    const playerCountTargetReached = p2kRatings.length >= targetBoardCount;
    if (
      missingForMinimum === 0 &&
      (
        friendlyAlreadyAdvantagedWithSmallShortfall ||
        (
          playerCountTargetReached &&
          recruitmentTargetReached(current, targetBoardCount, isLeagueMatch)
        )
      )
    ) {
      return {
        actionable: true,
        needsRecruitment: false,
        isLeagueMatch,
        targetBoardCount,
        current,
        targetLabel,
        summary: "No recruitment needed: the target lineup balance is already reached.",
        detail: `Current board balance: P2K ${current.p2kAdvantageBoards}, opponent ${current.opponentAdvantageBoards}, ` +
          `${current.tiedBoards} tied across ${current.boardCount} paired board${current.boardCount === 1 ? "" : "s"}.` +
          opponentProjectionDetail
      };
    }

    const observedTopRating = Math.max(
      1800,
      p2kRatings[0] || 0,
      comparisonOpponentRatings[0] || 0
    );
    const maximumCandidateRating = Number.isFinite(bounds.maximum)
      ? Math.min(3000, Math.floor(bounds.maximum / 25) * 25)
      : Math.min(3000, Math.ceil((observedTopRating + 400) / 25) * 25);
    const minimumCandidateRating = Math.min(
      maximumCandidateRating,
      Math.max(100, Math.ceil(bounds.minimum / 25) * 25)
    );
    const maximumRecruitCount = Math.max(
      missingForFullComparison,
      Math.min(
        12,
        Math.max(Number.isFinite(maxPlayers) ? Math.min(maxPlayers, 8) : 6, 1)
      )
    );

    let solution = null;
    const preferredCandidateFloor = isLeagueMatch
      ? minimumCandidateRating
      : friendlyPreferredCandidateFloor(
          comparisonOpponentRatings,
          targetBoardCount,
          minimumCandidateRating,
          maximumCandidateRating
        );
    const candidateRatings = recruitmentCandidateRatings(
      minimumCandidateRating,
      maximumCandidateRating,
      preferredCandidateFloor,
      isLeagueMatch
    );

    const minimumRecruitCount = Math.max(1, missingForFullComparison);
    for (
      let recruitCount = minimumRecruitCount;
      recruitCount <= maximumRecruitCount && !solution;
      recruitCount += 1
    ) {
      for (const candidateRating of candidateRatings) {
        const projectedRatings = [
          ...p2kRatings,
          ...Array.from({ length: recruitCount }, () => candidateRating)
        ].sort((a, b) => b - a);
        const projected = compareRecruitmentLineups(
          projectedRatings,
          comparisonOpponentRatings,
          maxPlayers
        );

        const minimumReached =
          projectedRatings.length >= cappedMandatoryMinimum;
        const playerCountReached =
          projectedRatings.length >= targetBoardCount;
        const lineupTargetReached =
          targetBoardCount === 0 ||
          recruitmentTargetReached(
            projected,
            targetBoardCount,
            isLeagueMatch
          );
        if (minimumReached && playerCountReached && lineupTargetReached) {
          solution = { recruitCount, candidateRating, projected };
          break;
        }
      }
    }

    if (!solution) {
      const fallbackCount = Math.max(1, missingForFullComparison);
      const projectedRatings = [
        ...p2kRatings,
        ...Array.from({ length: fallbackCount }, () => maximumCandidateRating)
      ].sort((a, b) => b - a);
      const projected = compareRecruitmentLineups(
        projectedRatings,
        comparisonOpponentRatings,
        maxPlayers
      );
      return {
        actionable: true,
        needsRecruitment: true,
        feasible: false,
        isLeagueMatch,
        targetBoardCount,
        current,
        projected,
        targetLabel,
        recruitCount: fallbackCount,
        minimumRating: maximumCandidateRating,
        maximumRating: Number.isFinite(bounds.maximum) ? bounds.maximum : null,
        summary: `Recruit at least ${fallbackCount} of the strongest eligible player${fallbackCount === 1 ? "" : "s"} available.`,
        detail: `Aim for ${targetLabel}. The current board balance is ${current.p2kAdvantageBoards}–${current.opponentAdvantageBoards}; ` +
          `players rated around ${maximumCandidateRating}+ would provide the strongest available improvement, although the target is not guaranteed by the current model.` +
          opponentProjectionDetail
      };
    }

    const rangeMaximum = Number.isFinite(bounds.maximum)
      ? Math.min(bounds.maximum, solution.candidateRating + 99)
      : solution.candidateRating + 99;
    const countText = `${solution.recruitCount} eligible player${solution.recruitCount === 1 ? "" : "s"}`;
    const ratingText = rangeMaximum > solution.candidateRating
      ? `${solution.candidateRating}–${Math.floor(rangeMaximum)}`
      : `${solution.candidateRating}+`;

    return {
      actionable: true,
      needsRecruitment: true,
      feasible: true,
      isLeagueMatch,
      targetBoardCount,
      current,
      projected: solution.projected,
      targetLabel,
      recruitCount: solution.recruitCount,
      minimumRating: solution.candidateRating,
      maximumRating: rangeMaximum,
      summary: `Recruit ${countText} rated approximately ${ratingText}.`,
      detail: `This is the lowest tested recruitment profile reaching ${targetLabel}. ` +
        `Projected board balance: P2K ${solution.projected.p2kAdvantageBoards}, opponent ${solution.projected.opponentAdvantageBoards}, ` +
        `${solution.projected.tiedBoards} tied across ${solution.projected.boardCount} board${solution.projected.boardCount === 1 ? "" : "s"}.` +
        opponentProjectionDetail
    };
  }

  function analyzeMatch(match) {
    const p2kTeam = findP2KTeam(match);
    const opponentTeam = findOpponentTeam(match, p2kTeam);

    if (!p2kTeam || !opponentTeam) return null;

    const p2kPlayers = players(p2kTeam);
    const opponentPlayers = players(opponentTeam);
    const bounds = ratingBounds(match);
    const maxPlayers = maximumTeamPlayers(match);
    const minPlayers = minimumTeamPlayers(match);
    const eligibleP2KPlayers = ratedPlayers(p2kTeam, bounds).length;
    const p2kLineup = selectedLineupRatings(p2kTeam, maxPlayers, bounds);
    const opponentLineup = selectedLineupRatings(opponentTeam, maxPlayers, bounds);
    const lineupComparison = compareLineups(p2kLineup, opponentLineup);
    const detailedAnalysis = detailedLineupAnalysis(
      p2kTeam,
      opponentTeam,
      bounds,
      maxPlayers
    );
    const hasEnoughEligiblePlayers = minPlayers <= 0 || eligibleP2KPlayers >= minPlayers;
    const hasDefinedMaximumPlayers = Number.isFinite(maxPlayers);
    const hasReachedEligibleMaximumPlayers =
      hasDefinedMaximumPlayers && eligibleP2KPlayers >= maxPlayers;
    const hasLineupAdvantage = Boolean(
      lineupComparison && lineupComparison.opponentAdvantage < 0
    );
    const leagueMatches = matchingLeagueAcronyms(match?.name);
    const recruitmentRecommendation = buildRecruitmentRecommendation(
      match,
      p2kTeam,
      opponentTeam,
      bounds,
      maxPlayers,
      minPlayers,
      leagueMatches.length > 0
    );
    const priorityReasons = [];

    /*
     * A league acronym normally makes the match a priority. However, when
     * P2K already has a significant projected lineup advantage, the league
     * criterion alone does not turn the match into a priority. Other risk
     * criteria below can still make it a priority.
     */
    const hasSignificantP2KLineupAdvantage =
      lineupComparison &&
      lineupComparison.opponentAdvantage <= -SIGNIFICANT_P2K_LINEUP_ADVANTAGE;

    if (leagueMatches.length > 0 && !hasSignificantP2KLineupAdvantage) {
      priorityReasons.push(`League match: ${leagueMatches.join(", ")}.`);
    }

    if (opponentPlayers.length > p2kPlayers.length) {
      priorityReasons.push(
        `${opponentTeam.name || "The opponent"} has ${opponentPlayers.length} registered players; ` +
        `Promote to King has ${p2kPlayers.length}.`
      );
    }

    if (lineupComparison && lineupComparison.opponentAdvantage > 0) {
      priorityReasons.push(
        `The opponent's projected lineup averages ${Math.round(lineupComparison.opponentAdvantage)} rating points higher ` +
        `across ${lineupComparison.boardCount} compared board${lineupComparison.boardCount === 1 ? "" : "s"}.`
      );
    }

    if (minPlayers > 0 && eligibleP2KPlayers < minPlayers) {
      priorityReasons.push(
        `Promote to King has ${eligibleP2KPlayers} eligible registered player${eligibleP2KPlayers === 1 ? "" : "s"}; ` +
        `the match requires at least ${minPlayers}.`
      );
    }

    const startTimestamp = Number(match?.start_time);

    return {
      raw: match,
      name: String(match?.name || "Unnamed match"),
      url: String(match?.url || match?.["@id"] || "#"),
      cacheKey: String(match?.["@id"] || match?.url || match?.name || Math.random()),
      detailId: `p2kMatchDetails_${stableHash(match?.["@id"] || match?.url || match?.name)}`,
      startTimestamp: Number.isFinite(startTimestamp) ? startTimestamp : Number.POSITIVE_INFINITY,
      p2kTeam,
      opponentTeam,
      p2kPlayerCount: p2kPlayers.length,
      opponentPlayerCount: opponentPlayers.length,
      eligibleP2KPlayers,
      minPlayers,
      maxPlayers,
      lineupComparison,
      detailedAnalysis,
      recruitmentRecommendation,
      hasEnoughEligiblePlayers,
      hasDefinedMaximumPlayers,
      hasReachedEligibleMaximumPlayers,
      hasLineupAdvantage,
      isLeagueMatch: leagueMatches.length > 0,
      locked: p2kTeam.locked === true,
      explicitlyOpen: p2kTeam.locked === false,
      /*
       * League matches are never lockable. A non-league match is lockable
       * only when max_team_players is explicitly defined and P2K has enough
       * rating-eligible registrations to fill that limit. Unlimited matches
       * are never lockable.
       */
      isLockable:
        p2kTeam.locked === false &&
        leagueMatches.length === 0 &&
        hasReachedEligibleMaximumPlayers &&
        hasLineupAdvantage,
      isPriority: priorityReasons.length > 0,
      priorityReasons
    };
  }

  function formatStartDate(timestamp) {
    if (!Number.isFinite(timestamp)) return "Start date unavailable";

    return new Intl.DateTimeFormat(undefined, {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      timeZoneName: "short"
    }).format(new Date(timestamp * 1000));
  }

  function formatRecruitmentStartDate(timestamp) {
    if (!Number.isFinite(timestamp)) return "start unavailable";
    const date = new Date(timestamp * 1000);
    const pad = value => String(value).padStart(2, "0");
    const offsetMinutes = -date.getTimezoneOffset();
    const sign = offsetMinutes >= 0 ? "+" : "-";
    const absoluteOffset = Math.abs(offsetMinutes);
    const offsetHours = Math.floor(absoluteOffset / 60);
    const offsetRemainder = absoluteOffset % 60;
    const offsetText = offsetRemainder === 0
      ? `UTC${sign}${offsetHours}`
      : `UTC${sign}${offsetHours}:${pad(offsetRemainder)}`;
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())} ${offsetText}`;
  }

  function roundedRecruitmentRatingBounds(recommendation) {
    if (!recommendation) return { lower: null, upper: null };
    const lower = Number.isFinite(recommendation.minimumRating)
      ? Math.floor(recommendation.minimumRating / 100) * 100
      : null;
    const upper = Number.isFinite(recommendation.maximumRating)
      ? Math.ceil(recommendation.maximumRating / 100) * 100
      : null;
    return { lower, upper };
  }

  function recruitmentRatingPhrase(recommendation) {
    const { lower, upper } = roundedRecruitmentRatingBounds(recommendation);

    if (Number.isFinite(lower) && Number.isFinite(upper)) {
      if (lower < upper) return `rated ${lower}–${upper}`;
      /* A collapsed range normally means that the match's upper rating cap
         is also the recommended boundary. "Up to" reads more naturally
         than a duplicated value such as 1800–1800. */
      return `rated up to ${upper}`;
    }
    if (Number.isFinite(upper)) return `rated up to ${upper}`;
    if (Number.isFinite(lower)) return `rated ${lower} or above`;
    return "with any eligible rating";
  }

  function conciseRecruitmentText(match, includeStart = true) {
    const recommendation = match?.recruitmentRecommendation;
    if (!recommendation?.needsRecruitment || !Number.isFinite(recommendation.recruitCount)) {
      return recommendation?.summary || "No recruitment needed";
    }
    const count = recommendation.recruitCount;
    const need = `${count} player${count === 1 ? "" : "s"} ${recruitmentRatingPhrase(recommendation)} needed`;
    return includeStart
      ? `${need}. Starts ${formatRecruitmentStartDate(match.startTimestamp)}`
      : `${need}.`;
  }

  function excludeLowUrgencyFriendlyFromRecap(match) {
    if (
      Number(match?.minPlayers) > 0 &&
      Number(match?.eligibleP2KPlayers) < Number(match?.minPlayers)
    ) {
      return false;
    }
    if (!match || match.isLeagueMatch) return false;
    const recommendation = match.recruitmentRecommendation;
    const current = recommendation?.current;
    const target = Number(recommendation?.targetBoardCount);
    if (!recommendation?.needsRecruitment || !current || !Number.isFinite(target) || target <= 0) return false;

    const missingPlayers = Math.max(0, target - Number(current.boardCount || 0));
    const missingShare = missingPlayers / target;
    const alreadyHasAdvantage =
      Number(current.netBoardAdvantage || 0) > 0 &&
      Number(current.averageRatingDifference || 0) >= FRIENDLY_MINIMUM_AVERAGE_MARGIN &&
      Number(current.reliabilityAdjustedAverageDifference || 0) >= FRIENDLY_MINIMUM_AVERAGE_MARGIN &&
      Number(current.topHalfAverageRatingDifference || 0) >= 0;

    return alreadyHasAdvantage && missingShare <= .20;
  }

  function formatMaximumPlayers(value) {
    return Number.isFinite(value) ? String(value) : "No limit";
  }

  function filteredAndSortedMatches() {
    const lockFilter = selectedValue("p2kLockFilter", "open");
    const sortMode = selectedValue("p2kSortMode", "date");
    const lockableFilter = selectedValue("p2kLockableFilter", "all");
    const searchQuery = normalizedSearchText(matchSearchInput.value);

    const filtered = state.matches.filter(match => {
      if (lockFilter !== "all" && !match.explicitlyOpen) return false;
      if (lockableFilter === "lockable" && !match.isLockable) return false;

      if (searchQuery) {
        const searchableText = normalizedSearchText([
          match.name,
          match.opponentTeam?.name,
          match.p2kTeam?.name,
          match.url
        ].join(" "));
        if (!searchableText.includes(searchQuery)) return false;
      }

      return true;
    });

    filtered.sort((a, b) => {
      if (sortMode === "priority" && a.isPriority !== b.isPriority) {
        return Number(b.isPriority) - Number(a.isPriority);
      }

      return a.startTimestamp - b.startTimestamp || a.name.localeCompare(b.name);
    });

    return filtered;
  }

  function comparisonValueClass(value, otherValue, p2kColumn) {
    if (value === otherValue) return "p2k-neutral-value";
    const p2kFavored = p2kColumn ? value > otherValue : value < otherValue;
    return p2kFavored ? "p2k-favorable" : "p2k-unfavorable";
  }

  function signedValueClass(value) {
    if (value > 0) return "p2k-favorable";
    if (value < 0) return "p2k-unfavorable";
    return "p2k-neutral-value";
  }

  function formatDecimal(value, digits = 1) {
    return Number(value).toLocaleString(undefined, {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits
    });
  }

  function formatInteger(value) {
    return Number(value).toLocaleString(undefined, {
      maximumFractionDigits: 0
    });
  }

  function formatPercentage(probability) {
    return `${formatDecimal(probability * 100, 1)}%`;
  }


  function predictionOutcomeClass(details) {
    if (!details || details.boardCount === 0) return "p2k-prediction-neutral";
    if (details.predictedScoreTeam1 > details.predictedScoreTeam2) return "p2k-prediction-positive";
    if (details.predictedScoreTeam1 < details.predictedScoreTeam2) return "p2k-prediction-negative";
    return "p2k-prediction-neutral";
  }


  function escapeAttribute(value) {
    return escapeHTML(value).replace(/`/g, '&#096;');
  }


  function formatStatistic(value, digits = 1) {
    if (!Number.isFinite(value)) return "—";
    return formatDecimal(value, digits);
  }

  function formatStatRange(first, second) {
    if (!Number.isFinite(first) || !Number.isFinite(second)) return "—";
    return `${formatInteger(Math.round(first))}–${formatInteger(Math.round(second))}`;
  }

  function infoMarkHTML(message, accessibleLabel = "Information") {
    const safeMessage = escapeAttribute(message);
    return `<span class="p2k-info-mark" tabindex="0" role="img" aria-label="${escapeAttribute(accessibleLabel)}: ${safeMessage}" title="${safeMessage}" data-tooltip="${safeMessage}">i</span>`;
  }

  function renderRatingStatisticsHTML(statistics, p2kName, opponentName) {
    if (!statistics?.team1 || !statistics?.team2) return "";

    const panel = (teamName, data, accentClass) => {
      const eligible = data.eligible;
      const nonEligible = data.nonEligible;
      const row = (label, eligibleValue, nonEligibleValue) => `
        <tr>
          <td>${escapeHTML(label)}</td>
          <td>${eligibleValue}</td>
          <td>${nonEligibleValue}</td>
        </tr>`;

      return `
        <div class="p2k-stats-panel">
          <div class="p2k-stats-team ${accentClass}">${escapeHTML(teamName)}</div>
          <table class="p2k-stats-table" aria-label="Rating statistics for ${escapeAttribute(teamName)}">
            <thead>
              <tr>
                <th scope="col">Statistic</th>
                <th scope="col" class="p2k-stats-eligible">Eligible</th>
                <th scope="col" class="p2k-stats-noneligible">Non-eligible</th>
              </tr>
            </thead>
            <tbody>
              ${row("Players", formatInteger(eligible.count), formatInteger(nonEligible.count))}
              ${row("Share", eligible.share === null ? "—" : formatPercentage(eligible.share), nonEligible.share === null ? "—" : formatPercentage(nonEligible.share))}
              ${row("Mean", formatStatistic(eligible.mean), formatStatistic(nonEligible.mean))}
              ${row("Median", formatStatistic(eligible.median), formatStatistic(nonEligible.median))}
              ${row("Std. deviation", formatStatistic(eligible.standardDeviation), formatStatistic(nonEligible.standardDeviation))}
              ${row("Q1–Q3", formatStatRange(eligible.q1, eligible.q3), formatStatRange(nonEligible.q1, nonEligible.q3))}
              ${row("Min–max", formatStatRange(eligible.minimum, eligible.maximum), formatStatRange(nonEligible.minimum, nonEligible.maximum))}
            </tbody>
          </table>
        </div>`;
    };

    return `
      <div class="p2k-stats-title">
        Rating distribution statistics
        ${infoMarkHTML("Eligible and non-eligible groups are determined only by the match rating window. The lineup-size limit is applied separately when simulating boards.", "Rating statistics information")}
      </div>
      <div class="p2k-stats-grid">
        ${panel(p2kName, statistics.team1, "p2k-favorable")}
        ${panel(opponentName, statistics.team2, "p2k-unfavorable")}
      </div>
    `;
  }

  function renderRatingHistogramHTML(histogram, statistics, p2kName, opponentName, options = {}) {
    if (!Array.isArray(histogram) || histogram.length === 0) {
      return '<div class="p2k-chart-note">No rating distribution data available.</div>';
    }

    const large = options.large === true;
    const matchKey = String(options.matchKey || "");
    const chartWidth = large
      ? Math.max(1120, 72 + histogram.length * 46)
      : 760;
    const leftPadding = large ? 58 : 46;
    const rightPadding = large ? 20 : 12;
    const topPadding = large ? 20 : 12;
    const bottomPadding = large ? 92 : 82;
    const chartHeight = large ? 380 : 230;
    const chartBottom = topPadding + chartHeight;
    const plotWidth = chartWidth - leftPadding - rightPadding;
    const groupWidth = plotWidth / Math.max(1, histogram.length);
    const baseGap = large ? 2.5 : 1.4;
    const teamGap = large ? 5 : 3;
    const availableForBars = Math.max(4, groupWidth - teamGap - baseGap * 2);
    const barWidth = Math.max(1.5, Math.min(large ? 11 : 7, availableForBars / 4));
    const allCounts = histogram.flatMap(bin => [
      bin.team1Eligible,
      bin.team1NonEligible,
      bin.team2Eligible,
      bin.team2NonEligible
    ]);
    const maximumCount = Math.max(1, ...allCounts);
    const tickStep = Math.max(1, Math.ceil(maximumCount / 5));
    const axisMaximum = Math.ceil(maximumCount / tickStep) * tickStep;
    const hasTeam1NonEligible = histogram.some(bin => bin.team1NonEligible > 0);
    const hasTeam2NonEligible = histogram.some(bin => bin.team2NonEligible > 0);

    let grid = "";
    for (let tick = 0; tick <= axisMaximum; tick += tickStep) {
      const y = chartBottom - (tick / axisMaximum) * chartHeight;
      grid += `
        <line x1="${leftPadding}" y1="${y.toFixed(2)}" x2="${(chartWidth - rightPadding).toFixed(2)}" y2="${y.toFixed(2)}" stroke="rgba(255,255,255,.08)" stroke-width="1" />
        <text x="${leftPadding - 7}" y="${(y + 4).toFixed(2)}" text-anchor="end" fill="#bfb7ae" font-size="${large ? 12 : 10}">${tick}</text>`;
    }

    const barRect = (x, count, fill, opacity, title) => {
      if (count <= 0) return "";
      const height = (count / axisMaximum) * chartHeight;
      const y = chartBottom - height;
      return `
        <rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${height.toFixed(2)}" rx="1.5" fill="${fill}" fill-opacity="${opacity}">
          <title>${escapeAttribute(title)}</title>
        </rect>`;
    };

    const bars = histogram.map((bin, index) => {
      const groupX = leftPadding + index * groupWidth;
      const label = `${bin.start}-${bin.end}`;
      const series = [
        { count: bin.team1Eligible, fill: "#91e09a", opacity: 1, title: `${label} | ${p2kName} eligible: ${bin.team1Eligible}` },
        { count: bin.team1NonEligible, fill: "#91e09a", opacity: .34, title: `${label} | ${p2kName} non-eligible: ${bin.team1NonEligible}` },
        { separator: true },
        { count: bin.team2Eligible, fill: "#ff8b79", opacity: 1, title: `${label} | ${opponentName} eligible: ${bin.team2Eligible}` },
        { count: bin.team2NonEligible, fill: "#ff8b79", opacity: .34, title: `${label} | ${opponentName} non-eligible: ${bin.team2NonEligible}` }
      ];
      const visibleBars = series.filter(item => !item.separator && item.count > 0);
      const team1Bars = visibleBars.filter(item => item.fill === "#91e09a");
      const team2Bars = visibleBars.filter(item => item.fill === "#ff8b79");
      const totalWidth = visibleBars.length * barWidth + Math.max(0, visibleBars.length - 1) * baseGap + (team1Bars.length && team2Bars.length ? teamGap : 0);
      let currentX = groupX + (groupWidth - totalWidth) / 2;
      let barsHTML = "";

      team1Bars.forEach((item, itemIndex) => {
        barsHTML += barRect(currentX, item.count, item.fill, item.opacity, item.title);
        currentX += barWidth + baseGap;
      });

      if (team1Bars.length && team2Bars.length) {
        const separatorX = currentX - baseGap / 2 + teamGap / 2;
        barsHTML += `<line x1="${separatorX.toFixed(2)}" y1="${topPadding}" x2="${separatorX.toFixed(2)}" y2="${chartBottom}" stroke="rgba(255,255,255,.12)" stroke-width="1" />`;
        currentX += teamGap;
      }

      team2Bars.forEach(item => {
        barsHTML += barRect(currentX, item.count, item.fill, item.opacity, item.title);
        currentX += barWidth + baseGap;
      });

      const labelX = groupX + groupWidth / 2;
      const labelY = chartBottom + 10;

      return `
        <g>
          ${barsHTML}
          <text
            x="${labelX.toFixed(2)}"
            y="${labelY.toFixed(2)}"
            text-anchor="end"
            fill="#d7cfc5"
            font-size="${large ? 10 : 8}"
            transform="rotate(-90 ${labelX.toFixed(2)} ${labelY.toFixed(2)})"
          >${label}</text>
        </g>`;
    }).join("");

    const expandButton = !large && matchKey
      ? `<button class="p2k-chart-expand" type="button" data-chart-type="distribution" data-match-key="${escapeAttribute(matchKey)}">View larger</button>`
      : "";

    const legendItems = [
      `<span><i class="p2k-chart-swatch" style="background:#91e09a"></i>${escapeHTML(p2kName)} eligible</span>`,
      hasTeam1NonEligible
        ? `<span><i class="p2k-chart-swatch" style="background:rgba(145,224,154,.34)"></i>${escapeHTML(p2kName)} non-eligible</span>`
        : "",
      `<span><i class="p2k-chart-swatch" style="background:#ff8b79"></i>${escapeHTML(opponentName)} eligible</span>`,
      hasTeam2NonEligible
        ? `<span><i class="p2k-chart-swatch" style="background:rgba(255,139,121,.34)"></i>${escapeHTML(opponentName)} non-eligible</span>`
        : ""
    ].filter(Boolean).join("");

    return `
      ${large ? "" : `<div class="p2k-chart-heading"><div class="p2k-chart-title">Registered players by rating bracket (100 Elo)${infoMarkHTML("Bars show registered players in each 100-Elo bracket. Solid bars are rating-eligible; translucent bars are outside the match rating window. Hover a bar for the exact count.", "Rating bracket chart information")}</div>${expandButton}</div>`}
      <div class="p2k-chart-wrap">
        <div class="p2k-chart-scroll">
          <svg class="p2k-chart-svg" width="${chartWidth}" height="${chartBottom + bottomPadding}" viewBox="0 0 ${chartWidth} ${chartBottom + bottomPadding}" role="img" aria-label="Registered eligible and non-eligible players by rating bracket for ${escapeAttribute(p2kName)} and ${escapeAttribute(opponentName)}">
            ${grid}
            <line x1="${leftPadding}" y1="${chartBottom}" x2="${chartWidth - rightPadding}" y2="${chartBottom}" stroke="rgba(255,255,255,.22)" stroke-width="1" />
            ${bars}
          </svg>
        </div>
        <div class="p2k-chart-legend">${legendItems}</div>
      </div>
    `;
  }


  function renderCumulativeRatingHTML(data, p2kName, opponentName, options = {}) {
    if (!Array.isArray(data) || data.length === 0) {
      return '<div class="p2k-chart-note">No cumulative rating data available.</div>';
    }

    const large = options.large === true;
    const matchKey = String(options.matchKey || "");
    const chartWidth = large
      ? Math.max(1120, 72 + data.length * 46)
      : 760;
    const leftPadding = large ? 58 : 46;
    const rightPadding = large ? 24 : 14;
    const topPadding = large ? 20 : 12;
    const bottomPadding = large ? 88 : 76;
    const chartHeight = large ? 380 : 230;
    const chartBottom = topPadding + chartHeight;
    const plotWidth = chartWidth - leftPadding - rightPadding;
    const maximumCount = Math.max(1, ...data.flatMap(point => [point.team1, point.team2]));
    const tickStep = Math.max(1, Math.ceil(maximumCount / 5));
    const axisMaximum = Math.ceil(maximumCount / tickStep) * tickStep;

    let grid = "";
    for (let tick = 0; tick <= axisMaximum; tick += tickStep) {
      const y = chartBottom - (tick / axisMaximum) * chartHeight;
      grid += `
        <line x1="${leftPadding}" y1="${y.toFixed(2)}" x2="${(chartWidth - rightPadding).toFixed(2)}" y2="${y.toFixed(2)}" stroke="rgba(255,255,255,.08)" stroke-width="1" />
        <text x="${leftPadding - 7}" y="${(y + 4).toFixed(2)}" text-anchor="end" fill="#bfb7ae" font-size="${large ? 12 : 10}">${tick}</text>`;
    }

    const xForIndex = index => {
      if (data.length === 1) return leftPadding + plotWidth / 2;
      return leftPadding + (index / (data.length - 1)) * plotWidth;
    };
    const yForCount = count => chartBottom - (count / axisMaximum) * chartHeight;

    const team1Points = data.map((point, index) => `${xForIndex(index).toFixed(2)},${yForCount(point.team1).toFixed(2)}`).join(" ");
    const team2Points = data.map((point, index) => `${xForIndex(index).toFixed(2)},${yForCount(point.team2).toFixed(2)}`).join(" ");

    const pointElements = data.map((point, index) => {
      const x = xForIndex(index);
      const y1 = yForCount(point.team1);
      const y2 = yForCount(point.team2);
      const labelY = chartBottom + 10;
      const team1Title = `${point.threshold} Elo and above | ${p2kName}: ${point.team1} registered player${point.team1 === 1 ? "" : "s"}`;
      const team2Title = `${point.threshold} Elo and above | ${opponentName}: ${point.team2} registered player${point.team2 === 1 ? "" : "s"}`;

      return `
        <g>
          <circle cx="${x.toFixed(2)}" cy="${y1.toFixed(2)}" r="${large ? 5 : 3.5}" fill="#91e09a" stroke="#171513" stroke-width="1.5">
            <title>${escapeAttribute(team1Title)}</title>
          </circle>
          <circle cx="${x.toFixed(2)}" cy="${y2.toFixed(2)}" r="${large ? 5 : 3.5}" fill="#ff8b79" stroke="#171513" stroke-width="1.5">
            <title>${escapeAttribute(team2Title)}</title>
          </circle>
          <text
            x="${x.toFixed(2)}"
            y="${labelY.toFixed(2)}"
            text-anchor="end"
            fill="#d7cfc5"
            font-size="${large ? 10 : 8}"
            transform="rotate(-90 ${x.toFixed(2)} ${labelY.toFixed(2)})"
          >${point.threshold}</text>
        </g>`;
    }).join("");

    const expandButton = !large && matchKey
      ? `<button class="p2k-chart-expand" type="button" data-chart-type="cumulative" data-match-key="${escapeAttribute(matchKey)}">View larger</button>`
      : "";

    return `
      ${large ? "" : `<div class="p2k-chart-heading"><div class="p2k-chart-title">Cumulative registered players at or above rating (100 Elo steps)${infoMarkHTML("Each point is the number of registered players rated at or above that 100-Elo threshold. Hover a point for the exact total.", "Cumulative chart information")}</div>${expandButton}</div>`}
      <div class="p2k-chart-wrap">
        <div class="p2k-chart-scroll">
          <svg class="p2k-chart-svg" width="${chartWidth}" height="${chartBottom + bottomPadding}" viewBox="0 0 ${chartWidth} ${chartBottom + bottomPadding}" role="img" aria-label="Cumulative registered player counts at or above each rating threshold for ${escapeAttribute(p2kName)} and ${escapeAttribute(opponentName)}">
            ${grid}
            <line x1="${leftPadding}" y1="${chartBottom}" x2="${chartWidth - rightPadding}" y2="${chartBottom}" stroke="rgba(255,255,255,.22)" stroke-width="1" />
            <polyline points="${team1Points}" fill="none" stroke="#91e09a" stroke-width="${large ? 3 : 2}" stroke-linejoin="round" stroke-linecap="round" />
            <polyline points="${team2Points}" fill="none" stroke="#ff8b79" stroke-width="${large ? 3 : 2}" stroke-linejoin="round" stroke-linecap="round" />
            ${pointElements}
          </svg>
        </div>
        <div class="p2k-chart-legend">
          <span><i class="p2k-chart-swatch" style="background:#91e09a"></i>${escapeHTML(p2kName)}</span>
          <span><i class="p2k-chart-swatch" style="background:#ff8b79"></i>${escapeHTML(opponentName)}</span>
        </div>
      </div>
    `;
  }

  function openChartModal(matchKey, chartType = "distribution", triggerButton = null) {
    const match = state.matches.find(item => item.cacheKey === matchKey);
    if (!match?.detailedAnalysis) return;

    lastChartModalTrigger = triggerButton;
    const p2kName = "Promote to King";
    const opponentName = String(match.opponentTeam.name || "Other team");

    if (chartType === "cumulative") {
      chartModalTitle.innerHTML = `${escapeHTML(match.name)} — cumulative players at or above rating${infoMarkHTML("Each point is the number of registered players rated at or above that 100-Elo threshold. Hover a point for the exact total.", "Cumulative chart information")}`;
      chartModalBody.innerHTML = renderCumulativeRatingHTML(
        match.detailedAnalysis.cumulativeRatingData,
        p2kName,
        opponentName,
        { large: true }
      );
    } else {
      chartModalTitle.innerHTML = `${escapeHTML(match.name)} — registered players by rating bracket${infoMarkHTML("Bars show registered players in each 100-Elo bracket. Solid bars are rating-eligible; translucent bars are outside the match rating window. Hover a bar for the exact count.", "Rating bracket chart information")}`;
      chartModalBody.innerHTML = renderRatingHistogramHTML(
        match.detailedAnalysis.ratingBracketHistogram,
        match.detailedAnalysis.ratingStatistics,
        p2kName,
        opponentName,
        { large: true }
      );
    }

    chartModal.hidden = false;
    chartModal.setAttribute("aria-hidden", "false");
    chartModalClose.focus();
  }

  function closeChartModal() {
    if (chartModal.hidden) return;
    chartModal.hidden = true;
    chartModal.setAttribute("aria-hidden", "true");
    chartModalBody.innerHTML = "";
    lastChartModalTrigger?.focus();
    lastChartModalTrigger = null;
  }


  function recruitmentPanelHTML(match) {
    const recommendation = match.recruitmentRecommendation;
    if (!recommendation) return "";

    const panelClass = recommendation.needsRecruitment
      ? "p2k-recruitment-panel p2k-recruitment-needed"
      : "p2k-recruitment-panel p2k-recruitment-ready";
    const goalInformation = match.isLeagueMatch
      ? "League matches must first reach the minimum-player requirement, then target a significant P2K board advantage: at least a 20% net board margin (normally a minimum of two extra advantageous boards, or one when only one board is available), and at least 60% of paired boards rated in P2K's favour."
      : "Friendly matches must first reach the minimum-player requirement, then target parity or a small P2K board advantage. The model recommends the lowest tested player count and rating threshold that reaches at least an even board balance.";

    return `
      <div class="${panelClass}">
        <div class="p2k-recruitment-title">
          Recruitment recommendation
          ${infoMarkHTML(goalInformation, "Recruitment target")}
        </div>
        <div class="p2k-recruitment-main">${escapeHTML(recommendation.needsRecruitment ? conciseRecruitmentText(match, false) : recommendation.summary)}</div>
        ${recommendation.needsRecruitment ? "" : `<div class="p2k-recruitment-meta">${escapeHTML(recommendation.detail)}</div>`}
      </div>
    `;
  }

  function recruitmentRecapItemHTML(match) {
    return `
      <li>
        <a href="${escapeAttribute(match.url)}" target="_blank" rel="noopener noreferrer">${escapeHTML(match.name)}</a>
        — ${escapeHTML(conciseRecruitmentText(match, true))}
      </li>
    `;
  }

  function renderRecruitmentRecap() {
    if (!state.processed) {
      recruitmentRecap.hidden = true;
      recruitmentRecap.innerHTML = "";
      return;
    }

    const nowTimestamp = Date.now() / 1000;
    const recapEndTimestamp = nowTimestamp + (10 * 24 * 60 * 60);
    const openRecruitmentMatches = state.matches
      .filter(match =>
        match.recruitmentRecommendation?.needsRecruitment &&
        match.p2kTeam?.locked === false &&
        Number.isFinite(match.startTimestamp) &&
        match.startTimestamp >= nowTimestamp &&
        match.startTimestamp <= recapEndTimestamp &&
        !excludeLowUrgencyFriendlyFromRecap(match)
      )
      .sort((a, b) => a.startTimestamp - b.startTimestamp || a.name.localeCompare(b.name));
    const leagueMatches = openRecruitmentMatches.filter(match => match.isLeagueMatch);
    const friendlyMatches = openRecruitmentMatches.filter(match => !match.isLeagueMatch);
    const groupHTML = (title, matches, emptyText) => `
      <div class="p2k-recruitment-group">
        <div class="p2k-recruitment-group-heading">${escapeHTML(title)}</div>
        ${matches.length > 0
          ? `<ul class="p2k-recruitment-list">${matches.map(recruitmentRecapItemHTML).join("")}</ul>`
          : `<div class="p2k-recruitment-empty">${escapeHTML(emptyText)}</div>`}
      </div>
    `;
    const expanded = state.recruitmentRecapExpanded === true;

    recruitmentRecap.innerHTML = `
      <div class="p2k-recruitment-recap-header">
        <div class="p2k-recruitment-recap-title">
          <button class="p2k-recruitment-recap-toggle" type="button" aria-expanded="${expanded}" aria-controls="p2kRecruitmentRecapBody">
            <span class="p2k-recruitment-recap-chevron" aria-hidden="true">▶</span>
            <span>Recruitment recap — next 10 days</span>
          </button>
          ${infoMarkHTML("Only matches starting during the next 10 days are included. League matches are listed first. Friendly matches are omitted when P2K already has a lineup advantage and is missing no more than 20% of the target players. Rating ranges are rounded down at the lower bound and up at the upper bound to the nearest hundred.", "Recruitment recap methodology")}
        </div>
        <div class="p2k-recruitment-recap-actions">
          <button class="p2k-recruitment-copy" type="button" title="Choose how to copy the recruitment recap" aria-label="Choose how to copy the recruitment recap">
            <span class="p2k-copy-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" focusable="false">
                <rect x="6.5" y="6.5" width="9" height="10" rx="1.5"></rect>
                <path d="M4.5 13.5h-1a1.5 1.5 0 0 1-1.5-1.5V3.5A1.5 1.5 0 0 1 3.5 2h8A1.5 1.5 0 0 1 13 3.5v1"></path>
              </svg>
            </span>
          </button>
        </div>
      </div>
      <div id="p2kRecruitmentRecapBody" class="p2k-recruitment-recap-body" ${expanded ? "" : "hidden"}>
        <div class="p2k-recruitment-recap-intro">
          ${openRecruitmentMatches.length} open match${openRecruitmentMatches.length === 1 ? "" : "es"} starting within the next 10 days require${openRecruitmentMatches.length === 1 ? "s" : ""} recruitment.
        </div>
        ${groupHTML("League matches", leagueMatches, "No open league match starting within the next 10 days currently requires recruitment.")}
        ${groupHTML("Friendly matches", friendlyMatches, "No open friendly match starting within the next 10 days currently requires recruitment.")}
      </div>
    `;
    recruitmentRecap.hidden = false;
    bindRecruitmentRecapControls();
  }

  function detailedInfoHTML(match) {
    const details = match.detailedAnalysis;
    const p2kName = "Promote to King";
    const opponentName = String(match.opponentTeam.name || "Other team");

    if (!details) {
      return `
        <div id="${escapeHTML(match.detailId)}" class="p2k-expanded-details">
          Detailed lineup analysis is unavailable.
        </div>
      `;
    }

    const advantage = details.boardAdvantages;
    const p2kRegisteredClass = comparisonValueClass(
      match.p2kPlayerCount,
      match.opponentPlayerCount,
      true
    );
    const opponentRegisteredClass = comparisonValueClass(
      match.opponentPlayerCount,
      match.p2kPlayerCount,
      false
    );
    const p2kRatingEligibleClass = comparisonValueClass(
      details.p2kRatingEligibleCount,
      details.opponentRatingEligibleCount,
      true
    );
    const opponentRatingEligibleClass = comparisonValueClass(
      details.opponentRatingEligibleCount,
      details.p2kRatingEligibleCount,
      false
    );
    const p2kStrengthClass = comparisonValueClass(
      details.p2kRegisteredStrength,
      details.opponentRegisteredStrength,
      true
    );
    const opponentStrengthClass = comparisonValueClass(
      details.opponentRegisteredStrength,
      details.p2kRegisteredStrength,
      false
    );
    const p2kEligibleStrengthClass = comparisonValueClass(
      details.p2kEligibleStrength,
      details.opponentEligibleStrength,
      true
    );
    const opponentEligibleStrengthClass = comparisonValueClass(
      details.opponentEligibleStrength,
      details.p2kEligibleStrength,
      false
    );

    const registrationTable = `
      <table class="p2k-detail-table" aria-label="Team registration and strength comparison">
        <thead>
          <tr>
            <th scope="col">Registration data</th>
            <th scope="col">${escapeHTML(p2kName)}</th>
            <th scope="col">${escapeHTML(opponentName)}</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Registered players</td>
            <td><span class="${p2kRegisteredClass}">${formatInteger(match.p2kPlayerCount)}</span></td>
            <td><span class="${opponentRegisteredClass}">${formatInteger(match.opponentPlayerCount)}</span></td>
          </tr>
          <tr>
            <td>Rating-eligible players</td>
            <td><span class="${p2kRatingEligibleClass}">${formatInteger(details.p2kRatingEligibleCount)}</span></td>
            <td><span class="${opponentRatingEligibleClass}">${formatInteger(details.opponentRatingEligibleCount)}</span></td>
          </tr>
          <tr>
            <td>Total strength</td>
            <td><span class="${p2kStrengthClass}">${formatInteger(details.p2kRegisteredStrength)}</span></td>
            <td><span class="${opponentStrengthClass}">${formatInteger(details.opponentRegisteredStrength)}</span></td>
          </tr>
          <tr>
            <td>Eligible lineup strength (${details.boardCount} board${details.boardCount === 1 ? "" : "s"})</td>
            <td><span class="${p2kEligibleStrengthClass}">${formatInteger(details.p2kEligibleStrength)}</span></td>
            <td><span class="${opponentEligibleStrengthClass}">${formatInteger(details.opponentEligibleStrength)}</span></td>
          </tr>
        </tbody>
      </table>
    `;

    const ratingHistogram = renderRatingHistogramHTML(
      details.ratingBracketHistogram,
      details.ratingStatistics,
      p2kName,
      opponentName,
      { matchKey: match.cacheKey }
    );
    const cumulativeRatingChart = renderCumulativeRatingHTML(
      details.cumulativeRatingData,
      p2kName,
      opponentName,
      { matchKey: match.cacheKey }
    );
    const ratingStatistics = renderRatingStatisticsHTML(
      details.ratingStatistics,
      p2kName,
      opponentName
    );

    if (details.boardCount === 0) {
      return `
        <div id="${escapeHTML(match.detailId)}" class="p2k-expanded-details">
          ${registrationTable}
          ${recruitmentPanelHTML(match)}
          <div class="p2k-detail-line">Detailed lineup analysis is unavailable because no eligible board can be paired.</div>
          ${cumulativeRatingChart}
          ${ratingHistogram}
          ${ratingStatistics}
        </div>
      `;
    }

    const ratingRow = (label, pair) => {
      const p2kClass = comparisonValueClass(pair.team1, pair.team2, true);
      const opponentClass = comparisonValueClass(pair.team2, pair.team1, false);
      return `
        <tr>
          <td>${escapeHTML(label)}</td>
          <td><span class="${p2kClass}">${formatInteger(pair.team1)}</span></td>
          <td><span class="${opponentClass}">${formatInteger(pair.team2)}</span></td>
        </tr>
      `;
    };

    const p2kScoreClass = comparisonValueClass(
      details.predictedScoreTeam1,
      details.predictedScoreTeam2,
      true
    );
    const opponentScoreClass = comparisonValueClass(
      details.predictedScoreTeam2,
      details.predictedScoreTeam1,
      false
    );
    const p2kProbabilityClass = comparisonValueClass(
      details.p2kWinProbability,
      details.opponentWinProbability,
      true
    );
    const opponentProbabilityClass = comparisonValueClass(
      details.opponentWinProbability,
      details.p2kWinProbability,
      false
    );

    return `
      <div id="${escapeHTML(match.detailId)}" class="p2k-expanded-details">
        ${registrationTable}
        ${recruitmentPanelHTML(match)}

        <div class="p2k-detail-line">
          <span class="p2k-detail-label">Delta strength over eligible boards (P2K - opponent):</span>
          <span class="${signedValueClass(details.deltaStrength)}">${formatInteger(details.deltaStrength)}</span>
        </div>

        <div class="p2k-rating-title">Rating board advantage:</div>
        <table class="p2k-detail-table" aria-label="Rating board advantage">
          <thead>
            <tr>
              <th scope="col">Rating difference</th>
              <th scope="col">${escapeHTML(p2kName)}</th>
              <th scope="col">${escapeHTML(opponentName)}</th>
            </tr>
          </thead>
          <tbody>
            ${ratingRow("Overall", advantage.overall)}
            ${ratingRow("]0..50[", advantage.under50)}
            ${ratingRow("[50..100[", advantage.from50To100)}
            ${ratingRow("100+", advantage.over100)}
          </tbody>
        </table>

        <hr class="p2k-detail-separator">

        <div class="p2k-prediction-panel ${predictionOutcomeClass(details)}">
          <table class="p2k-detail-table" aria-label="Predicted match result">
            <thead>
              <tr>
                <th scope="col">Prediction${infoMarkHTML("Win probabilities are computed from the Elo expected score of every simulated pairing over two games per board, using half-point score units so draws are included. Predicted scores use the most likely aggregate half-point result and therefore always end in .0 or .5.", "Prediction methodology")}</th>
                <th scope="col">${escapeHTML(p2kName)}</th>
                <th scope="col">${escapeHTML(opponentName)}</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Predicted score</td>
                <td><span class="${p2kScoreClass}">${formatDecimal(details.predictedScoreTeam1, 1)}</span></td>
                <td><span class="${opponentScoreClass}">${formatDecimal(details.predictedScoreTeam2, 1)}</span></td>
              </tr>
              <tr>
                <td>Match win probability</td>
                <td><span class="${p2kProbabilityClass}">${formatPercentage(details.p2kWinProbability)}</span></td>
                <td><span class="${opponentProbabilityClass}">${formatPercentage(details.opponentWinProbability)}</span></td>
              </tr>
              <tr>
                <td>Match draw probability</td>
                <td colspan="2"><span class="p2k-neutral-value">${formatPercentage(details.drawProbability)}</span></td>
              </tr>
            </tbody>
          </table>

        </div>

        ${cumulativeRatingChart}
        ${ratingHistogram}
        ${ratingStatistics}
      </div>
    `;
  }

  function cardHTML(match) {
    /* Locked green overrides priority red, as requested. */
    const cardClass = match.locked
      ? "p2k-card p2k-locked"
      : match.isPriority
        ? "p2k-card p2k-priority"
        : "p2k-card";

    const badges = [
      match.locked
        ? '<span class="p2k-badge p2k-locked-badge">Locked</span>'
        : "",
      match.isPriority
        ? '<span class="p2k-badge p2k-priority-badge">Priority</span>'
        : ""
    ].join("");

    const comparison = match.lineupComparison;
    const lineupText = comparison
      ? `Projected lineup (${comparison.boardCount} board${comparison.boardCount === 1 ? "" : "s"}): ` +
        `P2K ${Math.round(comparison.p2kAverage)} vs ${escapeHTML(match.opponentTeam.name || "Opponent")} ` +
        `${Math.round(comparison.opponentAverage)}`
      : "Projected lineup comparison unavailable";

    const reasons = match.priorityReasons.length > 0
      ? `<ul class="p2k-reasons">${match.priorityReasons.map(reason => `<li>${escapeHTML(reason)}</li>`).join("")}</ul>`
      : "";

    const detailsExpanded = state.expandedDetails.has(match.cacheKey);
    const prediction = match.detailedAnalysis;
    const predictionSummary = prediction && prediction.boardCount > 0
      ? `
        <div class="p2k-card-prediction ${predictionOutcomeClass(prediction)}">
          <div>
            <strong>Predicted score:</strong>
            Promote to King <span class="${comparisonValueClass(prediction.predictedScoreTeam1, prediction.predictedScoreTeam2, true)}">${formatDecimal(prediction.predictedScoreTeam1, 1)}</span>
            –
            <span class="${comparisonValueClass(prediction.predictedScoreTeam2, prediction.predictedScoreTeam1, false)}">${formatDecimal(prediction.predictedScoreTeam2, 1)}</span>
            ${escapeHTML(match.opponentTeam.name || "Opponent")}
          </div>
          <div>
            <strong>P2K victory probability:</strong>
            <span class="${comparisonValueClass(prediction.p2kWinProbability, prediction.opponentWinProbability, true)}">${formatPercentage(prediction.p2kWinProbability)}</span>
          </div>
        </div>`
      : "";

    return `
      <div class="${cardClass}">
        <div class="p2k-card-header">
          <div class="p2k-card-heading">
            ${badges}
            <div>
              <a class="p2k-match-title" href="${escapeHTML(match.url)}" target="_blank" rel="noopener noreferrer">
                ${escapeHTML(match.name)}
              </a>
            </div>
          </div>
          <div class="p2k-card-actions">
            <button
              class="p2k-copy-html"
              type="button"
              data-match-key="${escapeHTML(match.cacheKey)}"
              title="Choose how to copy this match vignette"
              aria-label="Copy vignette HTML"
            >
              <span class="p2k-copy-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false">
                  <rect x="6.5" y="6.5" width="9" height="10" rx="1.5"></rect>
                  <path d="M4.5 13.5h-1a1.5 1.5 0 0 1-1.5-1.5V3.5A1.5 1.5 0 0 1 3.5 2h8A1.5 1.5 0 0 1 13 3.5v1"></path>
                </svg>
              </span>
            </button>
            <button
              class="p2k-detail-toggle"
              type="button"
              data-match-key="${escapeHTML(match.cacheKey)}"
              aria-expanded="${detailsExpanded ? "true" : "false"}"
              aria-controls="${escapeHTML(match.detailId)}"
            >
              ${detailsExpanded ? "Hide details" : "Show details"}
            </button>
          </div>
        </div>
        <div class="p2k-details">
          <div><span class="p2k-date">Start:</span> ${escapeHTML(formatStartDate(match.startTimestamp))}</div>
          <div>
            Registered players: Promote to King ${match.p2kPlayerCount} / ${escapeHTML(match.opponentTeam.name || "Opponent")} ${match.opponentPlayerCount}
          </div>
          <div>
            Eligible P2K players: ${match.eligibleP2KPlayers} &nbsp;|&nbsp;
            Minimum: ${match.minPlayers} &nbsp;|&nbsp;
            Maximum per team: ${escapeHTML(formatMaximumPlayers(match.maxPlayers))}
          </div>
          <div>${lineupText}</div>
        </div>
        ${predictionSummary}
        ${reasons}
        ${detailsExpanded ? detailedInfoHTML(match) : ""}
      </div>
    `;
  }

  function legacyCopyText(text) {
    const activeElement = document.activeElement;
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("aria-hidden", "true");
    textarea.style.position = "fixed";
    textarea.style.left = "0";
    textarea.style.top = "0";
    textarea.style.width = "1px";
    textarea.style.height = "1px";
    textarea.style.padding = "0";
    textarea.style.border = "0";
    textarea.style.opacity = "0.01";
    textarea.style.pointerEvents = "none";
    document.body.appendChild(textarea);

    let copied = false;
    try {
      textarea.focus({ preventScroll: true });
      textarea.select();
      textarea.setSelectionRange(0, textarea.value.length);
      copied = document.execCommand("copy") === true;
    } catch (_) {
      copied = false;
    } finally {
      textarea.remove();
      if (activeElement && typeof activeElement.focus === "function") {
        try { activeElement.focus({ preventScroll: true }); } catch (_) { activeElement.focus(); }
      }
    }

    return copied;
  }

  function plainTextFromHTML(html) {
    const container = document.createElement("div");
    container.innerHTML = String(html || "");
    container.querySelectorAll("style, script").forEach(element => element.remove());
    return String(container.innerText || container.textContent || "")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  }

  function legacyCopyFormattedHTML(html) {
    const activeElement = document.activeElement;
    const container = document.createElement("div");
    container.setAttribute("contenteditable", "true");
    container.setAttribute("aria-hidden", "true");
    container.style.position = "fixed";
    container.style.left = "-10000px";
    container.style.top = "0";
    container.style.width = "760px";
    container.style.opacity = "0.01";
    container.style.pointerEvents = "none";
    container.innerHTML = html;
    document.body.appendChild(container);

    const selection = window.getSelection();
    const range = document.createRange();
    let copied = false;

    try {
      range.selectNodeContents(container);
      selection.removeAllRanges();
      selection.addRange(range);
      copied = document.execCommand("copy") === true;
    } catch (_) {
      copied = false;
    } finally {
      selection.removeAllRanges();
      container.remove();
      if (activeElement && typeof activeElement.focus === "function") {
        try { activeElement.focus({ preventScroll: true }); } catch (_) { activeElement.focus(); }
      }
    }

    return copied;
  }

  async function copyHTMLSourceToClipboard(html) {
    if (legacyCopyText(html)) return "legacy-text";

    if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === "function") {
      await navigator.clipboard.writeText(html);
      return "write-text";
    }

    throw new Error("Automatic HTML-source copy is unavailable.");
  }

  function solidFormattedClipboardHTML(html) {
    const template = document.createElement("template");
    template.innerHTML = String(html || "").trim();

    const solidTargets = template.content.querySelectorAll(
      ".p2k-card, .p2k-recruitment-recap, [id^='p2kCopiedVignette_']"
    );

    solidTargets.forEach(target => {
      target.style.setProperty("background", "#2b2b2b", "important");
      target.style.setProperty("background-color", "#2b2b2b", "important");
      target.style.setProperty("background-image", "none", "important");
      target.style.setProperty("color", "#eee", "important");
      target.setAttribute("bgcolor", "#2b2b2b");
    });

    /*
     * Rich-text editors may discard a card's background while preserving table
     * cell backgrounds. Wrap the copied object in a presentation table with
     * both CSS and the legacy bgcolor attribute for maximum compatibility.
     */
    const styleNodes = Array.from(template.content.querySelectorAll(":scope > style"));
    const styleHTML = styleNodes.map(node => node.outerHTML).join("");
    styleNodes.forEach(node => node.remove());

    const bodyHTML = Array.from(template.content.childNodes)
      .map(node => node.outerHTML ?? node.textContent ?? "")
      .join("");

    return `${styleHTML}<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#2b2b2b" style="width:100%;max-width:760px;border-collapse:collapse;background:#2b2b2b !important;background-color:#2b2b2b !important;background-image:none !important;color:#eee;"><tbody><tr><td bgcolor="#2b2b2b" style="padding:0;background:#2b2b2b !important;background-color:#2b2b2b !important;background-image:none !important;color:#eee;">${bodyHTML}</td></tr></tbody></table>`;
  }

  async function copyFormattedHTMLToClipboard(html) {
    const formattedHTML = solidFormattedClipboardHTML(html);
    const plainText = plainTextFromHTML(formattedHTML);

    if (
      navigator.clipboard &&
      window.isSecureContext &&
      window.ClipboardItem &&
      typeof navigator.clipboard.write === "function"
    ) {
      try {
        const item = new ClipboardItem({
          "text/html": new Blob([formattedHTML], { type: "text/html" }),
          "text/plain": new Blob([plainText], { type: "text/plain" })
        });
        await navigator.clipboard.write([item]);
        return "clipboard-item";
      } catch (error) {
        console.warn("P2K Upcoming Matches Analyzer: rich clipboard write failed", error);
      }
    }

    if (legacyCopyFormattedHTML(formattedHTML)) return "legacy-rich";

    throw new Error("Automatic formatted copy is unavailable.");
  }

  function setCopyButtonFeedback(button, success, message) {
    if (!button) return;
    const original = {
      html: button.innerHTML,
      title: button.title,
      ariaLabel: button.getAttribute("aria-label") || "Copy"
    };

    button.disabled = true;
    button.classList.remove("p2k-copy-success", "p2k-copy-error");
    button.innerHTML = `<span class="p2k-copy-icon" aria-hidden="true">${success ? "✓" : "!"}</span>`;
    button.title = message;
    button.setAttribute("aria-label", message);
    button.classList.add(success ? "p2k-copy-success" : "p2k-copy-error");

    window.setTimeout(() => {
      if (!button.isConnected) return;
      button.innerHTML = original.html;
      button.title = original.title;
      button.setAttribute("aria-label", original.ariaLabel);
      button.classList.remove("p2k-copy-success", "p2k-copy-error");
      button.disabled = false;
    }, 1800);
  }

  function openCopyChoice(payload) {
    pendingCopyPayload = payload;
    copyChoiceTitle.textContent = payload.label || "Choose copy format";
    copyPlainTextButton.hidden = !String(payload.plainText || "").trim();
    copyChoiceModal.hidden = false;
    copyChoiceModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    copyFormattedButton.focus();
  }

  function closeCopyChoice() {
    copyChoiceModal.hidden = true;
    copyChoiceModal.setAttribute("aria-hidden", "true");
    pendingCopyPayload = null;
    if (copyModal.hidden && chartModal.hidden) document.body.style.overflow = "";
  }

  async function performPendingCopy(format) {
    const payload = pendingCopyPayload;
    if (!payload) return;

    const { html, plainText, button } = payload;
    closeCopyChoice();

    try {
      if (format === "formatted") {
        await copyFormattedHTMLToClipboard(html);
        setCopyButtonFeedback(button, true, "Formatted object copied");
      } else if (format === "plain") {
        await copyHTMLSourceToClipboard(plainText);
        setCopyButtonFeedback(button, true, "Plain text copied");
      } else {
        await copyHTMLSourceToClipboard(html);
        setCopyButtonFeedback(button, true, "HTML source copied");
      }
    } catch (error) {
      console.error("P2K Upcoming Matches Analyzer: copy failed", error);
      if (format === "html") {
        showManualCopyModal(html, "Copy HTML source manually");
      } else if (format === "plain") {
        showManualCopyModal(plainText, "Copy plain text manually");
      } else {
        showManualCopyModal(html, "Formatted copy blocked — copy the HTML source manually");
      }
      setCopyButtonFeedback(button, false, "Automatic copy blocked");
    }
  }

  function selectManualCopyText() {
    copyManualText.focus({ preventScroll: true });
    copyManualText.select();
    copyManualText.setSelectionRange(0, copyManualText.value.length);
  }

  function showManualCopyModal(text, title = "Copy HTML source manually") {
    copyModalTitle.textContent = title;
    copyManualText.value = text;
    copyModal.hidden = false;
    copyModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    window.setTimeout(selectManualCopyText, 0);
  }

  function closeManualCopyModal() {
    copyModal.hidden = true;
    copyModal.setAttribute("aria-hidden", "true");
    copyManualText.value = "";
    if (copyChoiceModal.hidden && chartModal.hidden) document.body.style.overflow = "";
  }

  function applyRuleStyle(target, ruleStyle) {
    Array.from(ruleStyle || []).forEach(property => {
      target.style.setProperty(
        property,
        ruleStyle.getPropertyValue(property),
        ruleStyle.getPropertyPriority(property)
      );
    });
  }

  function inlineAuthoredStyles(sourceRoot, cloneRoot) {
    const sourceElements = [sourceRoot, ...sourceRoot.querySelectorAll("*")];
    const cloneElements = [cloneRoot, ...cloneRoot.querySelectorAll("*")];

    const processRules = rules => {
      Array.from(rules || []).forEach(rule => {
        if (rule.type === CSSRule.STYLE_RULE) {
          const selectors = String(rule.selectorText || "")
            .split(",")
            .map(selector => selector.trim())
            .filter(Boolean);

          sourceElements.forEach((sourceElement, index) => {
            const cloneElement = cloneElements[index];
            if (!cloneElement) return;

            const matches = selectors.some(selector => {
              try { return sourceElement.matches(selector); } catch (_) { return false; }
            });

            if (matches) applyRuleStyle(cloneElement, rule.style);
          });
          return;
        }

        if (rule.type === CSSRule.MEDIA_RULE) {
          if (window.matchMedia(rule.conditionText).matches) processRules(rule.cssRules);
          return;
        }

        if (typeof CSSRule.SUPPORTS_RULE !== "undefined" && rule.type === CSSRule.SUPPORTS_RULE) {
          processRules(rule.cssRules);
        }
      });
    };

    Array.from(document.styleSheets).forEach(styleSheet => {
      try { processRules(styleSheet.cssRules); } catch (_) { /* Cross-origin stylesheet. */ }
    });
  }

  function recruitmentPlainTextRange(recommendation) {
    const { lower, upper } = roundedRecruitmentRatingBounds(recommendation);
    if (Number.isFinite(lower) && Number.isFinite(upper)) {
      return lower < upper ? `${lower}–${upper}` : `up to ${upper}`;
    }
    if (Number.isFinite(upper)) return `up to ${upper}`;
    if (Number.isFinite(lower)) return `${lower} or above`;
    return "any eligible rating";
  }

  function recruitmentRecapPlainText() {
    const nowTimestamp = Date.now() / 1000;
    const recapEndTimestamp = nowTimestamp + (10 * 24 * 60 * 60);
    const matches = state.matches
      .filter(match =>
        match.recruitmentRecommendation?.needsRecruitment &&
        match.p2kTeam?.locked === false &&
        Number.isFinite(match.startTimestamp) &&
        match.startTimestamp >= nowTimestamp &&
        match.startTimestamp <= recapEndTimestamp &&
        !excludeLowUrgencyFriendlyFromRecap(match)
      )
      .sort((a, b) => {
        if (a.isLeagueMatch !== b.isLeagueMatch) return a.isLeagueMatch ? -1 : 1;
        return a.startTimestamp - b.startTimestamp || a.name.localeCompare(b.name);
      });

    const lineForMatch = match => {
      const recommendation = match.recruitmentRecommendation;
      const count = recommendation.recruitCount;
      const playerWord = count === 1 ? "player" : "players";
      const opponent = String(match.opponentTeam?.name || "opponent").trim();
      const leaguePrefix = match.isLeagueMatch
        ? `${matchingLeagueAcronyms(match.name).join("/") || "League"}: `
        : "";
      return `${leaguePrefix}${count} ${playerWord} in range ${recruitmentPlainTextRange(recommendation)} needed vs ${opponent} @ ${match.url}.`;
    };

    const sections = [];
    const leagueMatches = matches.filter(match => match.isLeagueMatch);
    const friendlyMatches = matches.filter(match => !match.isLeagueMatch);

    if (leagueMatches.length) {
      sections.push(`League matches\n${leagueMatches.map(lineForMatch).join("\n")}`);
    }
    if (friendlyMatches.length) {
      sections.push(`Friendly matches\n${friendlyMatches.map(lineForMatch).join("\n")}`);
    }

    return sections.join("\n\n");
  }

  function recruitmentRecapHTMLForClipboard() {
    if (!recruitmentRecap || recruitmentRecap.hidden) {
      throw new Error("The recruitment recap is unavailable.");
    }

    const clone = recruitmentRecap.cloneNode(true);
    inlineAuthoredStyles(recruitmentRecap, clone);

    const toggle = clone.querySelector(".p2k-recruitment-recap-toggle");
    if (toggle) {
      const title = document.createElement("div");
      title.className = "p2k-recruitment-recap-title";
      title.textContent = "Recruitment recap — next 10 days";
      toggle.replaceWith(title);
    }

    clone.querySelectorAll(
      ".p2k-recruitment-copy, .p2k-info-mark, .p2k-recruitment-recap-chevron"
    ).forEach(control => control.remove());

    const body = clone.querySelector(".p2k-recruitment-recap-body");
    if (body) {
      body.hidden = false;
      body.removeAttribute("hidden");
      body.style.removeProperty("display");
    }

    clone.style.setProperty("background", "#2b2b2b", "important");
    clone.style.setProperty("background-color", "#2b2b2b", "important");
    clone.style.setProperty("background-image", "none", "important");
    clone.style.setProperty("color", "#eee", "important");
    clone.style.setProperty("font-family", "Arial, Helvetica, sans-serif", "important");
    clone.style.setProperty("max-width", "760px", "important");

    return clone.outerHTML;
  }

  function bindRecruitmentRecapControls() {
    const toggle = recruitmentRecap.querySelector(".p2k-recruitment-recap-toggle");
    const body = recruitmentRecap.querySelector(".p2k-recruitment-recap-body");
    const copyButton = recruitmentRecap.querySelector(".p2k-recruitment-copy");

    toggle?.addEventListener("click", () => {
      state.recruitmentRecapExpanded = !state.recruitmentRecapExpanded;
      toggle.setAttribute("aria-expanded", String(state.recruitmentRecapExpanded));
      if (body) body.hidden = !state.recruitmentRecapExpanded;
    });

    copyButton?.addEventListener("click", () => {
      try {
        openCopyChoice({
          label: "Copy recruitment recap",
          html: recruitmentRecapHTMLForClipboard(),
          plainText: recruitmentRecapPlainText(),
          button: copyButton
        });
      } catch (error) {
        console.error("P2K Upcoming Matches Analyzer: recruitment recap copy preparation failed", error);
        setCopyButtonFeedback(copyButton, false, "Copy preparation failed");
      }
    });
  }

  function selectorTouchesCard(selector, card) {
    if (
      selector.includes(".p2k-copy-html") ||
      selector.includes(".p2k-detail-toggle") ||
      selector.includes(".p2k-chart-expand")
    ) return false;

    try {
      return Array.from(document.querySelectorAll(selector)).some(
        element => element === card || card.contains(element)
      );
    } catch (_) {
      return false;
    }
  }

  function scopedVignetteCSS(scopeId, card) {
    const originalScope = "#p2kUpcomingAnalyzer";
    const replacementScope = `#${scopeId}`;
    const output = [
      `${replacementScope}, ${replacementScope} * { box-sizing: border-box; }`,
      `${replacementScope} { width: 100%; max-width: 760px; color: #eee; font-family: Arial, Helvetica, sans-serif; }`
    ];

    const processRules = rules => {
      const renderedRules = [];

      Array.from(rules || []).forEach(rule => {
        if (rule.type === CSSRule.STYLE_RULE) {
          const selector = String(rule.selectorText || "");
          if (!selector.includes(originalScope)) return;

          const selectors = selector
            .split(",")
            .map(item => item.trim())
            .filter(item => item !== originalScope)
            .filter(item => selectorTouchesCard(item, card))
            .map(item => item.replaceAll(originalScope, replacementScope));

          if (selectors.length > 0) {
            renderedRules.push(`${selectors.join(", ")} { ${rule.style.cssText} }`);
          }
          return;
        }

        if (rule.type === CSSRule.MEDIA_RULE) {
          const nested = processRules(rule.cssRules);
          if (nested) renderedRules.push(`@media ${rule.conditionText} { ${nested} }`);
          return;
        }

        if (typeof CSSRule.SUPPORTS_RULE !== "undefined" && rule.type === CSSRule.SUPPORTS_RULE) {
          const nested = processRules(rule.cssRules);
          if (nested) renderedRules.push(`@supports ${rule.conditionText} { ${nested} }`);
        }
      });

      return renderedRules.join("\n");
    };

    Array.from(document.styleSheets).forEach(styleSheet => {
      try {
        const css = processRules(styleSheet.cssRules);
        if (css) output.push(css);
      } catch (error) {
        console.warn("P2K Upcoming Matches Analyzer: unable to include a stylesheet in copied HTML", error);
      }
    });

    return output.join("\n");
  }

  function vignetteHTMLForClipboard(button) {
    const card = button.closest(".p2k-card");
    if (!card) throw new Error("The match vignette could not be found.");

    const clone = card.cloneNode(true);

    /*
     * Inline styles before removing controls so the source and cloned element
     * trees retain identical traversal order.
     */
    inlineAuthoredStyles(card, clone);

    clone.querySelectorAll(
      ".p2k-copy-html, .p2k-detail-toggle, .p2k-chart-expand"
    ).forEach(control => control.remove());

    /*
     * Copied vignettes may be pasted onto light pages or forum posts. Force a
     * solid dark-grey card background in the copied payload only, while
     * retaining the live analyzer's colored accent border and typography.
     */
    clone.style.setProperty("background", "#2b2b2b", "important");
    clone.style.setProperty("background-color", "#2b2b2b", "important");
    clone.style.setProperty("background-image", "none", "important");

    const actions = clone.querySelector(".p2k-card-actions");
    if (actions && actions.children.length === 0) actions.remove();

    /* Include the original responsive rules under a unique wrapper scope. */

    const scopeId = `p2kCopiedVignette_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
    const css = scopedVignetteCSS(scopeId, card);

    return `<style>${css}</style><div id="${scopeId}">${clone.outerHTML}</div>`;
  }

  function renderResults() {
    if (!state.processed) return;

    renderRecruitmentRecap();
    searchToolbar.hidden = false;

    const matches = filteredAndSortedMatches();
    const shownMatches = matches.slice(0, state.visibleCount);

    if (matches.length === 0) {
      const hasSearch = normalizedSearchText(matchSearchInput.value).length > 0;
      resultsBox.innerHTML = `<div class="p2k-empty">${
        hasSearch
          ? "No analyzed match corresponds to this search and the selected filters."
          : "No match corresponds to the selected filter."
      }</div>`;
      return;
    }

    let html = `
      <div class="p2k-result-summary">
        Showing ${Math.min(state.visibleCount, matches.length)} of ${matches.length} match${matches.length === 1 ? "" : "es"}${
          normalizedSearchText(matchSearchInput.value)
            ? ` matching “${escapeHTML(matchSearchInput.value.trim())}”`
            : ""
        }
      </div>
      ${shownMatches.map(cardHTML).join("")}
    `;

    if (shownMatches.length < matches.length) {
      html += `
        <div class="p2k-controls">
          <button id="p2kLoadMore" class="p2k-load-button" type="button">Load 5 more</button>
          <button id="p2kLoadAll" class="p2k-load-button p2k-secondary" type="button">Load all</button>
        </div>
      `;
    }

    if (state.failedCount > 0) {
      html += `
        <div class="p2k-warning">
          ${state.failedCount} match detail request${state.failedCount === 1 ? "" : "s"} could not be loaded and ${state.failedCount === 1 ? "was" : "were"} skipped.
        </div>
      `;
    }

    resultsBox.innerHTML = html;

    document.getElementById("p2kLoadMore")?.addEventListener("click", () => {
      state.visibleCount += PAGE_SIZE;
      renderResults();
    });

    document.getElementById("p2kLoadAll")?.addEventListener("click", () => {
      state.visibleCount = matches.length;
      renderResults();
    });

    resultsBox.querySelectorAll(".p2k-copy-html").forEach(button => {
      button.addEventListener("click", () => {
        try {
          openCopyChoice({
            label: "Copy match vignette",
            html: vignetteHTMLForClipboard(button),
            button
          });
        } catch (error) {
          console.error("P2K Upcoming Matches Analyzer: vignette copy preparation failed", error);
          setCopyButtonFeedback(button, false, "Copy preparation failed");
        }
      });
    });

    resultsBox.querySelectorAll(".p2k-detail-toggle").forEach(button => {
      button.addEventListener("click", () => {
        const matchKey = button.dataset.matchKey;
        if (!matchKey) return;

        if (state.expandedDetails.has(matchKey)) {
          state.expandedDetails.delete(matchKey);
        } else {
          state.expandedDetails.add(matchKey);
        }

        renderResults();
      });
    });

    resultsBox.querySelectorAll(".p2k-chart-expand").forEach(button => {
      button.addEventListener("click", () => {
        const matchKey = button.dataset.matchKey;
        if (!matchKey) return;
        const chartType = button.dataset.chartType || "distribution";
        openChartModal(matchKey, chartType, button);
      });
    });
  }

  async function analyzeUpcomingMatches() {
    if (state.processing) return;

    const controller = new AbortController();
    activeAnalysis = controller;
    const signal = controller.signal;

    state.processing = true;
    state.processed = false;
    state.matches = [];
    state.failedCount = 0;
    state.visibleCount = PAGE_SIZE;
    state.expandedDetails.clear();
    state.recruitmentRecapExpanded = false;
    analyzeButton.disabled = true;
    analyzeButton.textContent = "Analyzing...";
    resultsBox.innerHTML = "";
    recruitmentRecap.hidden = true;
    recruitmentRecap.innerHTML = "";
    searchToolbar.hidden = true;
    showCancelButton();

    try {
      setStatus("Loading registration match list...", "working", 0);
      await allowScreenUpdate();

      /*
       * Always await a fresh club match index. It is the authoritative source
       * for whether a match is still in registration, so stale-while-revalidate
       * is intentionally disabled for this one request.
       */
      const listData = await loadJSON(MATCH_LIST_URL, signal, { networkOnly: true });
      const registrationMatches = p2kPrioritizeMatchReferences(
        Array.isArray(listData?.registered) ? listData.registered : []
      );
      const total = registrationMatches.length;

      if (total === 0) {
        state.processed = true;
        setStatus("No registration matches were found.", "success", 100);
        renderResults();
        return;
      }

      /* Sequential requests are intentional: do not replace with Promise.all(). */
      for (let index = 0; index < total; index += 1) {
        if (signal.aborted) throw new CancellationError();

        const sequentialNumber = index + 1;
        setStatus(
          `Processing match ${sequentialNumber} of ${total}`,
          "working",
          ((sequentialNumber - 1) / total) * 100
        );
        await allowScreenUpdate();

        try {
          const matchData = await loadJSON(registrationMatches[index]["@id"], signal);
          const analyzedMatch = analyzeMatch(matchData);
          if (analyzedMatch) state.matches.push(analyzedMatch);
        } catch (error) {
          if (error instanceof CancellationError) throw error;
          state.failedCount += 1;
          console.warn("P2K Upcoming Matches Analyzer: skipped match", registrationMatches[index], error);
        }

        progressBar.style.width = `${(sequentialNumber / total) * 100}%`;
      }

      /*
       * Recheck the authoritative registration index at completion. A match
       * may start while a long analysis is processing its detail endpoints.
       */
      setStatus("Refreshing current registration status...", "working", 99);
      await allowScreenUpdate();
      let matchesRemovedAfterRefresh = 0;
      try {
        const finalListData = await loadJSON(MATCH_LIST_URL, signal, { networkOnly: true });
        const currentRegisteredIDs = new Set(
          (Array.isArray(finalListData?.registered) ? finalListData.registered : [])
            .map(reference => String(reference?.["@id"] || ""))
            .filter(Boolean)
        );
        const previousMatchCount = state.matches.length;
        state.matches = state.matches.filter(match =>
          currentRegisteredIDs.has(String(match.raw?.["@id"] || match.cacheKey || ""))
        );
        matchesRemovedAfterRefresh = previousMatchCount - state.matches.length;
      } catch (refreshError) {
        if (refreshError instanceof CancellationError) throw refreshError;
        console.warn("P2K Upcoming Matches Analyzer: final registration refresh failed", refreshError);
      }

      state.processed = true;
      const removalNote = matchesRemovedAfterRefresh > 0
        ? ` ${matchesRemovedAfterRefresh} match${matchesRemovedAfterRefresh === 1 ? "" : "es"} that left registration during processing ${matchesRemovedAfterRefresh === 1 ? "was" : "were"} removed.`
        : "";
      setStatus(
        `Analysis complete: ${state.matches.length} current registration match${state.matches.length === 1 ? "" : "es"} processed.${removalNote}`,
        "success",
        100
      );
      renderResults();
    } catch (error) {
      if (error instanceof CancellationError || signal.aborted) {
        state.processed = true;
        setStatus(
          `Processing cancelled: ${state.matches.length} match${state.matches.length === 1 ? "" : "es"} loaded.`,
          "working",
          null
        );
        renderResults();
      } else {
        console.error("P2K Upcoming Matches Analyzer", error);
        setStatus(`Unable to analyze matches: ${error.message || error}`, "error", null);
      }
    } finally {
      if (activeAnalysis === controller) activeAnalysis = null;
      state.processing = false;
      hideCancelButton();
      analyzeButton.disabled = false;
      analyzeButton.textContent = "Analyze upcoming matches";
    }
  }

  window.__P2K_RECRUITMENT_HELPERS__ = {
    compareRecruitmentLineups,
    recruitmentTargetReached,
    buildRecruitmentRecommendation
  };

  chartModalClose.addEventListener("click", closeChartModal);

  chartModal.addEventListener("click", event => {
    if (event.target === chartModal) closeChartModal();
  });

  document.addEventListener("keydown", event => {
    if (event.key !== "Escape") return;
    if (!copyChoiceModal.hidden) closeCopyChoice();
    else if (!copyModal.hidden) closeManualCopyModal();
    else if (!chartModal.hidden) closeChartModal();
  });

  cancelButton.addEventListener("click", () => {
    if (!state.processing || !activeAnalysis) return;
    cancelButton.disabled = true;
    cancelButton.textContent = "Cancelling...";
    activeAnalysis.abort();
  });

  copyChoiceClose.addEventListener("click", closeCopyChoice);
  copyFormattedButton.addEventListener("click", () => performPendingCopy("formatted"));
  copyPlainTextButton.addEventListener("click", () => performPendingCopy("plain"));
  copyHTMLSourceButton.addEventListener("click", () => performPendingCopy("html"));
  copyChoiceModal.addEventListener("click", event => {
    if (event.target === copyChoiceModal) closeCopyChoice();
  });

  copyModalClose.addEventListener("click", closeManualCopyModal);
  copyModalCloseBottom.addEventListener("click", closeManualCopyModal);
  copySelectAll.addEventListener("click", selectManualCopyText);
  copyModal.addEventListener("click", event => {
    if (event.target === copyModal) closeManualCopyModal();
  });

  analyzeButton.addEventListener("click", analyzeUpcomingMatches);

  matchSearchInput.addEventListener("input", () => {
    clearSearchButton.disabled = matchSearchInput.value.length === 0;
    if (!state.processed) return;
    state.visibleCount = PAGE_SIZE;
    renderResults();
  });

  clearSearchButton.addEventListener("click", () => {
    if (!matchSearchInput.value) return;
    matchSearchInput.value = "";
    clearSearchButton.disabled = true;
    state.visibleCount = PAGE_SIZE;
    if (state.processed) renderResults();
    matchSearchInput.focus();
  });

  clearSearchButton.disabled = matchSearchInput.value.length === 0;

  root.querySelectorAll(
    'input[name="p2kLockFilter"], input[name="p2kSortMode"], input[name="p2kLockableFilter"]'
  ).forEach(input => {
    input.addEventListener("change", () => {
      if (!state.processed) return;
      state.visibleCount = PAGE_SIZE;
      renderResults();
    });
  });
})();
