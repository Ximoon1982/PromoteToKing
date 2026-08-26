/* Standalone single-match analyzer page logic. */
(async () => {
  "use strict";
  if (window.P2K_ADMIN_ACCESS_READY && !(await window.P2K_ADMIN_ACCESS_READY)) return;

  const REQUEST_ATTEMPTS = window.P2K_SITE_CONFIG?.api?.defaultAttempts || 3;
  const LEAGUE_ACRONYMS = [...(window.P2K_SITE_CONFIG?.leagueAcronyms || ["1WL", "TCMAC", "KOTML", "TMCL", "WKCL", "PCL", "CW"])];

  const root = document.getElementById("p2kUpcomingAnalyzer");
  const matchForm = document.getElementById("p2kMatchForm");
  const matchInput = document.getElementById("p2kMatchReference");
  const analyzeButton = document.getElementById("p2kAnalyzeButton");
  const statusBox = document.getElementById("p2kStatus");
  const statusText = document.getElementById("p2kStatusText");
  const progressTrack = document.getElementById("p2kProgressTrack");
  const progressBar = document.getElementById("p2kProgressBar");
  const teamSelector = document.getElementById("p2kTeamSelector");
  const teamChoices = document.getElementById("p2kTeamChoices");
  const resultsBox = document.getElementById("p2kResults");
  const chartModal = document.getElementById("p2kChartModal");
  const chartModalTitle = document.getElementById("p2kChartModalTitle");
  const chartModalBody = document.getElementById("p2kChartModalBody");
  const chartModalClose = document.getElementById("p2kChartModalClose");
  const selectedTeamLogo = document.getElementById("p2kSelectedTeamLogo");
  const standaloneLink = document.getElementById("p2kStandaloneLink");

  const state = {
    rawMatch: null,
    matchId: null,
    selectedTeamKey: null,
    preferredClubSlug: "",
    lockedClubScope: false,
    startedMatchData: null,
    analysis: null,
    clubProfiles: new Map(),
    logoRequestToken: 0,
    lastChartTrigger: null
  };

  class CancellationError extends Error {
    constructor(message = "The operation was cancelled.") {
      super(message);
      this.name = "CancellationError";
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

  function setStatus(message, type = "working", progress = null) {
    statusBox.style.display = "block";
    statusBox.classList.toggle("p2k-error", type === "error");
    statusBox.classList.toggle("p2k-success", type === "success");
    statusText.textContent = message;
    if (progress === null) {
      progressTrack.style.display = "none";
      progressBar.style.width = "0%";
    } else {
      progressTrack.style.display = "block";
      progressBar.style.width = `${Math.max(0, Math.min(100, progress))}%`;
    }
  }

  function parseMatchReference(input) {
    const value = String(input || "").trim();
    if (!value) throw new Error("Enter a match number or URL.");

    if (/^\d+$/.test(value)) return value;

    let decoded = value;
    try { decoded = decodeURIComponent(value); } catch (_) {}

    try {
      const url = new URL(decoded, window.location.href);
      const pathNumbers = url.pathname.match(/\d+/g);
      if (pathNumbers?.length) return pathNumbers[pathNumbers.length - 1];
      const nestedMatch = url.searchParams.get("match") || url.searchParams.get("id");
      if (nestedMatch) return parseMatchReference(nestedMatch);
    } catch (_) {}

    const matches = decoded.match(/\d+/g);
    if (matches?.length) return matches[matches.length - 1];
    throw new Error("No numeric Chess.com match ID was found in that value.");
  }



  function parseClubReference(input) {
    const original = String(input || "").trim();
    if (!original) return "";

    let value = original;
    try { value = decodeURIComponent(value); } catch (_) {}

    try {
      const url = new URL(value, window.location.href);
      const parts = url.pathname.split("/").filter(Boolean);
      const clubIndex = parts.findIndex(part => part.toLowerCase() === "club");
      if (clubIndex >= 0 && parts[clubIndex + 1]) {
        return parts[clubIndex + 1].toLowerCase();
      }
      const pubIndex = parts.findIndex((part, index) =>
        part.toLowerCase() === "pub" && parts[index + 1]?.toLowerCase() === "club"
      );
      if (pubIndex >= 0 && parts[pubIndex + 2]) {
        return parts[pubIndex + 2].toLowerCase();
      }
    } catch (_) {}

    return value
      .replace(/^@+/, "")
      .replace(/^\/+|\/+$/g, "")
      .split(/[?#]/, 1)[0]
      .toLowerCase();
  }

  function slugifyClubName(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  function teamEntries(match) {
    return Object.entries(match?.teams || {}).filter(([, team]) => team && typeof team === "object");
  }

  function teamName(team, fallback) {
    return String(team?.name || team?.club?.name || fallback || "Team");
  }



  function teamClubSlug(team) {
    return parseClubReference(team?.["@id"]) ||
      parseClubReference(team?.url) ||
      slugifyClubName(teamName(team, ""));
  }
  /* P2K_SELECTED_TEAM_LOGO */
  function hideSelectedTeamLogo() {
    if (!selectedTeamLogo) return;
    selectedTeamLogo.hidden = true;
    selectedTeamLogo.removeAttribute("src");
    selectedTeamLogo.alt = "";
    selectedTeamLogo.title = "";
  }

  async function updateSelectedTeamLogo() {
    const requestToken = ++state.logoRequestToken;
    const selectedEntry = teamEntries(state.rawMatch)
      .find(([key]) => key === state.selectedTeamKey);

    hideSelectedTeamLogo();
    if (!selectedTeamLogo || !selectedEntry) return;

    const selectedTeam = selectedEntry[1];
    const selectedName = teamName(selectedTeam, selectedEntry[0]);
    const clubSlug = teamClubSlug(selectedTeam);
    if (!clubSlug) return;

    let clubProfile;
    if (state.clubProfiles.has(clubSlug)) {
      clubProfile = state.clubProfiles.get(clubSlug);
    } else {
      try {
        clubProfile = await loadJSON(
          `https://api.chess.com/pub/club/${encodeURIComponent(clubSlug)}`
        );
      } catch (error) {
        console.warn(`Unable to load the ${selectedName} club logo.`, error);
        clubProfile = null;
      }
      state.clubProfiles.set(clubSlug, clubProfile);
    }

    if (requestToken !== state.logoRequestToken) return;

    const iconURL = String(clubProfile?.icon || "").trim();
    if (!iconURL) return;

    selectedTeamLogo.onerror = () => {
      if (requestToken === state.logoRequestToken) {
        hideSelectedTeamLogo();
      }
    };
    selectedTeamLogo.src = iconURL;
    selectedTeamLogo.alt = `${selectedName} club logo`;
    selectedTeamLogo.title = selectedName;
    selectedTeamLogo.hidden = false;
  }

  function teamKeyForClub(match, clubReference) {
    const requestedSlug = parseClubReference(clubReference);
    if (!requestedSlug) return null;
    const entries = teamEntries(match);
    const requestedNameSlug = slugifyClubName(requestedSlug);
    const found = entries.find(([, team]) => {
      const teamSlug = teamClubSlug(team);
      return teamSlug === requestedSlug ||
        slugifyClubName(teamName(team, "")) === requestedSlug ||
        slugifyClubName(teamName(team, "")) === requestedNameSlug;
    });
    return found?.[0] || null;
  }

  function normalizeMatchStatus(value) {
    return String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
  }

  function isMatchInProgress(match) {
    return ["in_progress", "inprogress", "started", "playing"].includes(
      normalizeMatchStatus(match?.status)
    );
  }

  function isMatchFinished(match) {
    return ["finished", "complete", "completed"].includes(
      normalizeMatchStatus(match?.status)
    );
  }

  function boardNumberFromURL(value) {
    const match = String(value || "").match(/\/(\d+)\/?(?:[?#].*)?$/);
    return match ? Number(match[1]) : null;
  }

  function recognizedGamePoints(result) {
    const code = String(result || "").toLowerCase();
    if (code === "win") return 1;
    if (["agreed", "repetition", "stalemate", "insufficient", "50move", "timevsinsufficient"].includes(code)) return .5;
    if (["checkmated", "timeout", "resigned", "lose", "abandoned", "kingofthehill", "threecheck", "bughousepartnerlose"].includes(code)) return 0;
    return null;
  }

  function matchPlayerStartRating(player) {
    const candidates = [
      player?.rating,
      player?.start_rating,
      player?.rating_at_start,
      player?.initial_rating
    ];
    for (const candidate of candidates) {
      const rating = Number(candidate);
      if (Number.isFinite(rating)) return rating;
    }
    return null;
  }

  function playerResultIsFinished(player, colour) {
    const result = colour === "white" ? player?.played_as_white : player?.played_as_black;
    return recognizedGamePoints(result) !== null;
  }

  function playersByBoard(team) {
    const map = new Map();
    players(team).forEach((player, index) => {
      const explicitBoard = boardNumberFromURL(player?.board);
      const boardNumber = Number.isFinite(explicitBoard) ? explicitBoard : index + 1;
      if (!map.has(boardNumber)) map.set(boardNumber, player);
    });
    return map;
  }

  function buildStartedMatchDataFromMatch(rawMatch) {
    const enrichedMatch = JSON.parse(JSON.stringify(rawMatch));
    const entries = teamEntries(enrichedMatch);

    /* Normalize any start-rating field to `rating` so the existing detailed
       lineup, table and graph calculations can reuse it without extra calls. */
    entries.forEach(([, team]) => {
      players(team).forEach(player => {
        const rating = matchPlayerStartRating(player);
        if (Number.isFinite(rating)) player.rating = rating;
      });
    });

    const maps = new Map(entries.map(([key, team]) => [key, playersByBoard(team)]));
    const explicitBoards = Number(enrichedMatch?.boards);
    const highestBoard = Math.max(
      0,
      ...[...maps.values()].flatMap(map => [...map.keys()])
    );
    const boardCount = Number.isFinite(explicitBoards) && explicitBoards > 0
      ? Math.floor(explicitBoards)
      : highestBoard;
    const boards = [];

    for (let boardNumber = 1; boardNumber <= boardCount; boardNumber += 1) {
      const participants = {};
      entries.forEach(([key, team]) => {
        const player = maps.get(key)?.get(boardNumber) || players(team)[boardNumber - 1] || null;
        participants[key] = {
          username: String(player?.username || "Unknown"),
          rating: matchPlayerStartRating(player),
          source: player
        };
      });

      const firstEntry = entries[0];
      const secondEntry = entries[1];
      const firstPlayer = participants[firstEntry?.[0]]?.source || null;
      const secondPlayer = participants[secondEntry?.[0]]?.source || null;
      const ratingsByTeam = Object.fromEntries(entries.map(([key]) => [key, participants[key]?.rating]));

      /* A team-match board contains two games. The first record below denotes
         the first listed team's White game; the second denotes its Black game.
         Either side's result field is enough to establish that the game ended. */
      const games = [
        {
          finished: playerResultIsFinished(firstPlayer, "white") || playerResultIsFinished(secondPlayer, "black"),
          ratingsByTeam
        },
        {
          finished: playerResultIsFinished(firstPlayer, "black") || playerResultIsFinished(secondPlayer, "white"),
          ratingsByTeam
        }
      ];

      entries.forEach(([key]) => delete participants[key].source);
      boards.push({ boardNumber, participants, games });
    }

    return {
      boards,
      failures: [],
      requestedBoardCount: boardCount,
      enrichedMatch
    };
  }

  function matchWebURL(match, matchId) {
    const direct = String(match?.url || "").trim();
    if (direct) return direct;
    return `https://www.chess.com/club/matches/${matchId}`;
  }

  function formatStatus(value) {
    const normalized = String(value || "Unknown").replace(/_/g, " ");
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
  }

  function formatTimeControl(match) {
    const settings = match?.settings || {};
    const timeClass = String(settings.time_class || settings.timeclass || "Daily");
    const days = numericSetting(settings.time_per_move, null);
    if (Number.isFinite(days)) return `${timeClass} — ${days} day${days === 1 ? "" : "s"} per move`;
    return timeClass;
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

  async function loadJSON(url, signal = null, options = {}) {
    if (signal?.aborted) throw new CancellationError();
    if (!window.P2K_API_CLIENT) throw new Error("P2K_API_CLIENT is not loaded.");
    try {
      return await window.P2K_API_CLIENT.json(url, {
        signal,
        attempts: REQUEST_ATTEMPTS,
        timeoutMs: options.timeoutMs,
        cacheMode: options.networkOnly === true ? "network-only" : "default",
        priority: options.priority
      });
    } catch (error) {
      if (signal?.aborted || error?.category === "cancelled") throw new CancellationError();
      throw error;
    }
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

  function compareRecruitmentLineups(p2kRatings, opponentRatings, maxPlayers) {
    const cappedP2K = Number.isFinite(maxPlayers)
      ? p2kRatings.slice(0, maxPlayers)
      : [...p2kRatings];
    const cappedOpponent = Number.isFinite(maxPlayers)
      ? opponentRatings.slice(0, maxPlayers)
      : [...opponentRatings];
    const boardCount = Math.min(cappedP2K.length, cappedOpponent.length);
    let p2kAdvantageBoards = 0;
    let opponentAdvantageBoards = 0;
    let tiedBoards = 0;
    let ratingDelta = 0;

    for (let board = 0; board < boardCount; board += 1) {
      const difference = cappedP2K[board] - cappedOpponent[board];
      ratingDelta += difference;
      if (difference > 0) p2kAdvantageBoards += 1;
      else if (difference < 0) opponentAdvantageBoards += 1;
      else tiedBoards += 1;
    }

    return {
      boardCount,
      p2kAdvantageBoards,
      opponentAdvantageBoards,
      tiedBoards,
      netBoardAdvantage: p2kAdvantageBoards - opponentAdvantageBoards,
      averageRatingDifference: boardCount > 0 ? ratingDelta / boardCount : 0
    };
  }

  function recruitmentTargetReached(comparison, targetBoardCount, isLeagueMatch) {
    if (!comparison || comparison.boardCount < targetBoardCount) return false;

    if (isLeagueMatch) {
      const requiredNetBoardAdvantage = Math.min(targetBoardCount, Math.max(2, Math.ceil(targetBoardCount * .20)));
      const requiredAdvantagedBoards = Math.max(
        requiredNetBoardAdvantage,
        Math.ceil(targetBoardCount * .60)
      );
      return comparison.netBoardAdvantage >= requiredNetBoardAdvantage &&
        comparison.p2kAdvantageBoards >= requiredAdvantagedBoards;
    }

    /* Friendlies aim for parity or a small positive board margin. */
    return comparison.netBoardAdvantage >= 0 && comparison.netBoardAdvantage <= 2;
  }

  function formatStartDate(timestamp) {
    if (!Number.isFinite(timestamp)) return "Start date unavailable";

    return new Intl.DateTimeFormat("en-GB", {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: "UTC",
      timeZoneName: "short"
    }).format(new Date(timestamp * 1000));
  }

  function formatMaximumPlayers(value) {
    return Number.isFinite(value) ? String(value) : "No limit";
  }

  function signedValueClass(value) {
    if (value > 0) return "p2k-favorable";
    if (value < 0) return "p2k-unfavorable";
    return "p2k-neutral-value";
  }

  function formatDecimal(value, digits = 1) {
    return Number(value).toLocaleString("en-GB", {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits
    });
  }

  function formatInteger(value) {
    return Number(value).toLocaleString("en-GB", {
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
    return `<button type="button" class="p2k-info-mark p2k-info-button" aria-expanded="false" aria-controls="p2kSharedInfoPopover" aria-label="${escapeAttribute(accessibleLabel)}: ${safeMessage}" data-p2k-info-message="${safeMessage}">i</button>`;
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
      ${large ? "" : `<div class="p2k-chart-heading"><div class="p2k-chart-title">Registered players by rating bracket (100 Elo)${infoMarkHTML("Bars show registered players in each 100-Elo bracket. Solid bars are rating-eligible; translucent bars are outside the match rating window. Hover or tap a bar for the exact count.", "Rating bracket chart information")}</div>${expandButton}</div>`}
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
      ${large ? "" : `<div class="p2k-chart-heading"><div class="p2k-chart-title">Cumulative registered players at or above rating (100 Elo steps)${infoMarkHTML("Each point is the number of registered players rated at or above that 100-Elo threshold. Hover or tap a point for the exact total.", "Cumulative chart information")}</div>${expandButton}</div>`}
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

  function buildRecruitmentRecommendation(match, selectedTeam, otherTeam, bounds, maxPlayers, minPlayers, isLeagueMatch, selectedName, otherName) {
    const selectedRatings = ratedPlayers(selectedTeam, bounds).map(player => player.rating);
    const otherRatings = ratedPlayers(otherTeam, bounds).map(player => player.rating);
    /* P2K_MANDATORY_SELECTED_MINIMUM */
    const mandatoryMinimumPlayers = Math.max(
      0,
      Number.isFinite(Number(minPlayers))
        ? Math.floor(Number(minPlayers))
        : 0
    );
    const missingForMinimum = Math.max(
      0,
      mandatoryMinimumPlayers - selectedRatings.length
    );
    const opponentCapacity = Number.isFinite(maxPlayers)
      ? Math.min(maxPlayers, otherRatings.length)
      : otherRatings.length;
    const targetBoardCount = opponentCapacity;
    const current = compareRecruitmentLineups(selectedRatings, otherRatings, maxPlayers);
    const leagueNetBoardTarget = Math.min(
      targetBoardCount,
      Math.max(2, Math.ceil(targetBoardCount * .20))
    );
    const targetLabel = isLeagueMatch
      ? `a significant board advantage for ${selectedName} (at least +${leagueNetBoardTarget} net boards and 60% of boards ahead)`
      : `a balanced lineup or a small ${selectedName} board advantage`;

    if (selectedTeam?.locked === true) {
      return {
        needsRecruitment: false,
        isLeagueMatch,
        targetLabel,
        summary: `Recruitment is unavailable because ${selectedName} is locked.`,
        detail: "The recommendation is informational only; registrations can no longer be added."
      };
    }

    if (targetBoardCount === 0 && missingForMinimum === 0) {
      return {
        needsRecruitment: false,
        isLeagueMatch,
        targetLabel,
        summary: "No recruitment recommendation is available yet.",
        detail: `${otherName} has no rating-eligible registered lineup to compare.`
      };
    }

    const friendlyTargetAlreadyExceeded =
      !isLeagueMatch &&
      current.boardCount >= targetBoardCount &&
      current.netBoardAdvantage > 2;

    if (
      missingForMinimum === 0 &&
      (
        friendlyTargetAlreadyExceeded ||
        recruitmentTargetReached(
          current,
          targetBoardCount,
          isLeagueMatch
        )
      )
    ) {
      return {
        needsRecruitment: false,
        isLeagueMatch,
        targetLabel,
        summary: friendlyTargetAlreadyExceeded
          ? `No recruitment needed: ${selectedName} already exceeds the friendly-match target.`
          : "No recruitment needed: the target lineup balance is already reached.",
        detail: `Current board balance: ${selectedName} ${current.p2kAdvantageBoards}, ${otherName} ${current.opponentAdvantageBoards}, ` +
          `${current.tiedBoards} tied across ${current.boardCount} paired board${current.boardCount === 1 ? "" : "s"}.`
      };
    }

    const missingForFullComparison = Math.max(
      0,
      targetBoardCount - selectedRatings.length,
      missingForMinimum
    );
    const observedTopRating = Math.max(1800, selectedRatings[0] || 0, otherRatings[0] || 0);
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
        Math.max(
          Number.isFinite(maxPlayers) ? Math.min(maxPlayers, 8) : 6,
          1
        )
      )
    );

    let solution = null;
    for (let recruitCount = Math.max(1, missingForFullComparison); recruitCount <= maximumRecruitCount && !solution; recruitCount += 1) {
      for (let candidateRating = minimumCandidateRating; candidateRating <= maximumCandidateRating; candidateRating += 25) {
        const projectedRatings = [
          ...selectedRatings,
          ...Array.from({ length: recruitCount }, () => candidateRating)
        ].sort((a, b) => b - a);
        const projected = compareRecruitmentLineups(projectedRatings, otherRatings, maxPlayers);
        const minimumReached =
          projectedRatings.length >= mandatoryMinimumPlayers;
        const lineupTargetReached =
          targetBoardCount === 0 ||
          recruitmentTargetReached(
            projected,
            targetBoardCount,
            isLeagueMatch
          );
        if (minimumReached && lineupTargetReached) {
          solution = { recruitCount, candidateRating, projected };
          break;
        }
      }
    }

    if (!solution) {
      const fallbackCount = Math.max(1, missingForFullComparison);
      return {
        needsRecruitment: true,
        feasible: false,
        isLeagueMatch,
        targetLabel,
        summary: `Recruit at least ${fallbackCount} of the strongest eligible player${fallbackCount === 1 ? "" : "s"} available for ${selectedName}.`,
        detail: `Aim for ${targetLabel}. Players rated around ${maximumCandidateRating}+ would provide the strongest available improvement, although the target is not guaranteed by the current model.`
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
      needsRecruitment: true,
      feasible: true,
      isLeagueMatch,
      targetLabel,
      summary: `Recruit ${countText} rated approximately ${ratingText} for ${selectedName}.`,
      detail: `This is the lowest tested recruitment profile reaching ${targetLabel}. Projected board balance: ` +
        `${selectedName} ${solution.projected.p2kAdvantageBoards}, ${otherName} ${solution.projected.opponentAdvantageBoards}, ` +
        `${solution.projected.tiedBoards} tied across ${solution.projected.boardCount} board${solution.projected.boardCount === 1 ? "" : "s"}.`
    };
  }



  function currentTeamScore(team) {
    const score = Number(team?.score);
    return Number.isFinite(score) ? score : 0;
  }

  function remainingGamesProjection(expectedScores, currentSelectedScore, currentOtherScore, totalPoints) {
    const officialRemainingPoints = Math.max(0, totalPoints - currentSelectedScore - currentOtherScore);
    const remainingGames = Math.max(0, Math.round(officialRemainingPoints));
    const normalizedExpectedScores = expectedScores.slice(0, remainingGames);
    let neutralFallbackCount = 0;
    while (normalizedExpectedScores.length < remainingGames) {
      normalizedExpectedScores.push(.5);
      neutralFallbackCount += 1;
    }

    let distribution = [1];
    normalizedExpectedScores.forEach(expectedScore => {
      const probability = Math.max(0, Math.min(1, Number(expectedScore)));
      for (let trial = 0; trial < 2; trial += 1) {
        const next = new Array(distribution.length + 1).fill(0);
        distribution.forEach((weight, units) => {
          next[units] += weight * (1 - probability);
          next[units + 1] += weight * probability;
        });
        distribution = next;
      }
    });

    const currentUnits = Math.round(currentSelectedScore * 2);
    const expectedAdditionalUnits = normalizedExpectedScores.reduce((sum, score) => sum + 2 * score, 0);
    let selectedWinProbability = 0;
    let drawProbability = 0;
    let otherWinProbability = 0;
    let mostLikelyAdditionalUnits = 0;
    let mostLikelyProbability = -1;

    distribution.forEach((probability, additionalUnits) => {
      const selectedFinalUnits = currentUnits + additionalUnits;
      const selectedFinalScore = selectedFinalUnits / 2;
      const otherFinalScore = totalPoints - selectedFinalScore;
      if (selectedFinalScore > otherFinalScore) selectedWinProbability += probability;
      else if (selectedFinalScore < otherFinalScore) otherWinProbability += probability;
      else drawProbability += probability;

      const better = probability > mostLikelyProbability + Number.EPSILON;
      const closer = Math.abs(probability - mostLikelyProbability) <= Number.EPSILON &&
        Math.abs(additionalUnits - expectedAdditionalUnits) <
          Math.abs(mostLikelyAdditionalUnits - expectedAdditionalUnits);
      if (better || closer) {
        mostLikelyProbability = probability;
        mostLikelyAdditionalUnits = additionalUnits;
      }
    });

    const projectedSelectedScore = (currentUnits + mostLikelyAdditionalUnits) / 2;
    return {
      currentSelectedScore,
      currentOtherScore,
      totalPoints,
      remainingGames,
      neutralFallbackCount,
      projectedSelectedScore,
      projectedOtherScore: totalPoints - projectedSelectedScore,
      selectedWinProbability,
      otherWinProbability,
      drawProbability
    };
  }

  function buildInProgressProjection(rawMatch, selectedTeamKey, otherTeamKey, startedData) {
    if (!isMatchInProgress(rawMatch) || !startedData) return null;
    const entries = teamEntries(rawMatch);
    const selectedTeam = entries.find(([key]) => key === selectedTeamKey)?.[1];
    const otherTeam = entries.find(([key]) => key === otherTeamKey)?.[1];
    if (!selectedTeam || !otherTeam) return null;

    const expectedScores = [];
    const boardRows = [];
    let observedFinishedGames = 0;

    startedData.boards.forEach(board => {
      const selectedParticipant = board.participants?.[selectedTeamKey] || {};
      const otherParticipant = board.participants?.[otherTeamKey] || {};
      const defaultSelectedRating = Number(selectedParticipant.rating);
      const defaultOtherRating = Number(otherParticipant.rating);
      let finishedOnBoard = 0;
      let projectedRemainingPoints = 0;

      board.games.forEach(game => {
        if (game.finished) {
          finishedOnBoard += 1;
          observedFinishedGames += 1;
          return;
        }
        const selectedRating = Number(game.ratingsByTeam?.[selectedTeamKey]);
        const otherRating = Number(game.ratingsByTeam?.[otherTeamKey]);
        const rating1 = Number.isFinite(selectedRating) ? selectedRating : defaultSelectedRating;
        const rating2 = Number.isFinite(otherRating) ? otherRating : defaultOtherRating;
        const expected = Number.isFinite(rating1) && Number.isFinite(rating2)
          ? eloExpectedScore(rating1, rating2)
          : .5;
        expectedScores.push(expected);
        projectedRemainingPoints += expected;
      });

      const missingGames = Math.max(0, 2 - board.games.length);
      for (let missing = 0; missing < missingGames; missing += 1) {
        const expected = Number.isFinite(defaultSelectedRating) && Number.isFinite(defaultOtherRating)
          ? eloExpectedScore(defaultSelectedRating, defaultOtherRating)
          : .5;
        expectedScores.push(expected);
        projectedRemainingPoints += expected;
      }

      boardRows.push({
        boardNumber: board.boardNumber,
        selectedUsername: selectedParticipant.username || "Unknown",
        selectedRating: Number.isFinite(defaultSelectedRating) ? defaultSelectedRating : null,
        otherUsername: otherParticipant.username || "Unknown",
        otherRating: Number.isFinite(defaultOtherRating) ? defaultOtherRating : null,
        finishedGames: finishedOnBoard,
        projectedRemainingPoints,
        loadedGames: board.games.length
      });
    });

    const boardCount = Number(rawMatch?.boards) > 0
      ? Math.floor(Number(rawMatch.boards))
      : startedData.requestedBoardCount;
    const totalPoints = Math.max(0, boardCount * 2);
    const projection = remainingGamesProjection(
      expectedScores,
      currentTeamScore(selectedTeam),
      currentTeamScore(otherTeam),
      totalPoints
    );

    return {
      ...projection,
      boardCount,
      observedFinishedGames,
      loadedBoardCount: startedData.boards.length,
      failedBoardCount: startedData.failures.length,
      boardRows
    };
  }

  function finishedScorePanelHTML(analysis) {
    if (!isMatchFinished(analysis.raw)) return "";
    const selectedScore = currentTeamScore(analysis.selectedTeam);
    const otherScore = currentTeamScore(analysis.otherTeam);
    const outcomeClass = selectedScore > otherScore
      ? "p2k-prediction-positive"
      : selectedScore < otherScore
        ? "p2k-prediction-negative"
        : "p2k-prediction-neutral";
    return `
      <div class="p2k-prediction-panel ${outcomeClass}">
        <table class="p2k-detail-table" aria-label="Final match score">
          <thead><tr>
            <th scope="col">Final result</th>
            <th scope="col" class="p2k-team-column-selected">${escapeHTML(analysis.selectedName)}</th>
            <th scope="col" class="p2k-team-column-other">${escapeHTML(analysis.otherName)}</th>
          </tr></thead>
          <tbody><tr>
            <td>Final score</td>
            <td class="p2k-team-column-selected">${formatDecimal(selectedScore, 1)}</td>
            <td class="p2k-team-column-other">${formatDecimal(otherScore, 1)}</td>
          </tr></tbody>
        </table>
      </div>`;
  }

  function inProgressProjectionHTML(analysis) {
    const projection = analysis.startedProjection;
    if (!projection) return "";
    const outcomeClass = projection.projectedSelectedScore > projection.projectedOtherScore
      ? "p2k-prediction-positive"
      : projection.projectedSelectedScore < projection.projectedOtherScore
        ? "p2k-prediction-negative"
        : "p2k-prediction-neutral";
    const fallbackText = projection.neutralFallbackCount > 0
      ? `${projection.neutralFallbackCount} remaining game${projection.neutralFallbackCount === 1 ? "" : "s"} lacked a usable start-time rating in the match response and were modelled at 50/50.`
      : "All remaining games used the players’ start-time ratings contained in the match response.";
    const failuresText = "";
    const rows = projection.boardRows.map(row => `
      <tr>
        <td>${formatInteger(row.boardNumber)}</td>
        <td class="p2k-team-column-selected"><span class="p2k-board-player-rating">${escapeHTML(row.selectedUsername)} (${row.selectedRating === null ? "?" : formatInteger(row.selectedRating)})</span></td>
        <td class="p2k-team-column-other"><span class="p2k-board-player-rating">${escapeHTML(row.otherUsername)} (${row.otherRating === null ? "?" : formatInteger(row.otherRating)})</span></td>
        <td>${formatInteger(row.finishedGames)}/2</td>
        <td>${formatDecimal(row.projectedRemainingPoints, 2)}</td>
      </tr>`).join("");

    return `
      <div class="p2k-prediction-panel ${outcomeClass}">
        <table class="p2k-detail-table" aria-label="In-progress match projection">
          <thead>
            <tr>
              <th scope="col">In-progress projection${infoMarkHTML("The official current team scores are fixed. Each unfinished game is projected from the players’ start-time ratings contained in the match response. Completed results are read from played_as_white and played_as_black. The probability model preserves the Elo expected score while allowing wins, draws and losses in half-point increments.", "In-progress projection methodology")}</th>
              <th scope="col" class="p2k-team-column-selected">${escapeHTML(analysis.selectedName)}</th>
              <th scope="col" class="p2k-team-column-other">${escapeHTML(analysis.otherName)}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Current score</td>
              <td class="p2k-team-column-selected">${formatDecimal(projection.currentSelectedScore, 1)}</td>
              <td class="p2k-team-column-other">${formatDecimal(projection.currentOtherScore, 1)}</td>
            </tr>
            <tr>
              <td>Projected final score</td>
              <td class="p2k-team-column-selected">${formatDecimal(projection.projectedSelectedScore, 1)}</td>
              <td class="p2k-team-column-other">${formatDecimal(projection.projectedOtherScore, 1)}</td>
            </tr>
            <tr>
              <td>Match win probability</td>
              <td class="p2k-team-column-selected">${formatPercentage(projection.selectedWinProbability)}</td>
              <td class="p2k-team-column-other">${formatPercentage(projection.otherWinProbability)}</td>
            </tr>
            <tr>
              <td>Match draw probability</td>
              <td colspan="2"><span class="p2k-neutral-value">${formatPercentage(projection.drawProbability)}</span></td>
            </tr>
            <tr>
              <td>Games accounted for</td>
              <td colspan="2">${formatInteger(projection.observedFinishedGames)} observed finished; ${formatInteger(projection.remainingGames)} remaining</td>
            </tr>
          </tbody>
        </table>
        <div class="p2k-live-projection-note ${projection.neutralFallbackCount || projection.failedBoardCount ? "p2k-projection-warning" : ""}">${escapeHTML(fallbackText + failuresText)}</div>
      </div>
      <details class="p2k-board-projection-details">
        <summary>Board-by-board projection inputs (${formatInteger(projection.loadedBoardCount)} boards from the match response)</summary>
        <div class="p2k-board-projection-scroll">
          <table class="p2k-board-projection-table" aria-label="Board-by-board projection inputs">
            <thead><tr>
              <th>Board</th>
              <th class="p2k-team-column-selected">${escapeHTML(analysis.selectedName)}</th>
              <th class="p2k-team-column-other">${escapeHTML(analysis.otherName)}</th>
              <th>Finished games</th>
              <th>Expected remaining points for ${escapeHTML(analysis.selectedName)}</th>
            </tr></thead>
            <tbody>${rows || '<tr><td colspan="5">No board pairing data was available in the match response.</td></tr>'}</tbody>
          </table>
        </div>
      </details>`;
  }

  function standardPredictionPanelHTML(analysis) {
    const details = analysis.details;
    return `
      <div class="p2k-prediction-panel ${predictionOutcomeClass(details)}">
        <table class="p2k-detail-table" aria-label="Predicted match result">
          <thead>
            <tr>
              <th scope="col">Prediction${infoMarkHTML("Win probabilities are computed from the Elo expected score of every simulated pairing over two games per board, using half-point score units so draws are included. Predicted scores use the most likely aggregate half-point result and therefore always end in .0 or .5.", "Prediction methodology")}</th>
              <th scope="col" class="p2k-team-column-selected">${escapeHTML(analysis.selectedName)}</th>
              <th scope="col" class="p2k-team-column-other">${escapeHTML(analysis.otherName)}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Predicted score</td>
              <td class="p2k-team-column-selected">${formatDecimal(details.predictedScoreTeam1, 1)}</td>
              <td class="p2k-team-column-other">${formatDecimal(details.predictedScoreTeam2, 1)}</td>
            </tr>
            <tr>
              <td>Match win probability</td>
              <td class="p2k-team-column-selected">${formatPercentage(details.p2kWinProbability)}</td>
              <td class="p2k-team-column-other">${formatPercentage(details.opponentWinProbability)}</td>
            </tr>
            <tr>
              <td>Match draw probability</td>
              <td colspan="2"><span class="p2k-neutral-value">${formatPercentage(details.drawProbability)}</span></td>
            </tr>
          </tbody>
        </table>
      </div>`;
  }

  function predictionPanelHTML(analysis) {
    if (isMatchInProgress(analysis.raw)) return inProgressProjectionHTML(analysis);
    if (isMatchFinished(analysis.raw)) return finishedScorePanelHTML(analysis);
    return standardPredictionPanelHTML(analysis);
  }

  function analyzePerspective(rawMatch, selectedTeamKey) {
    const entries = teamEntries(rawMatch);
    if (entries.length !== 2) throw new Error("This match does not expose exactly two teams in the Chess.com API response.");

    const selectedEntry = entries.find(([key]) => key === selectedTeamKey) || entries[0];
    const otherEntry = entries.find(([key]) => key !== selectedEntry[0]);
    const selectedTeam = selectedEntry[1];
    const otherTeam = otherEntry[1];
    const selectedName = teamName(selectedTeam, "Selected team");
    const otherName = teamName(otherTeam, "Other team");
    const bounds = ratingBounds(rawMatch);
    const maxPlayers = maximumTeamPlayers(rawMatch);
    const minPlayers = minimumTeamPlayers(rawMatch);
    const selectedLineup = selectedLineupRatings(selectedTeam, maxPlayers, bounds);
    const otherLineup = selectedLineupRatings(otherTeam, maxPlayers, bounds);
    const details = detailedLineupAnalysis(selectedTeam, otherTeam, bounds, maxPlayers);
    const selectedEligibleCount = ratedPlayers(selectedTeam, bounds).length;
    const configuredClubKey = teamKeyForClub(rawMatch, window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king");
    const configuredClubEntry = entries.find(([key]) => key === configuredClubKey);
    const configuredClubEligibleCount = configuredClubEntry ? ratedPlayers(configuredClubEntry[1], bounds).length : selectedEligibleCount;
    if (minPlayers > 0 && configuredClubEligibleCount < minPlayers) {
      const configuredClubIsSelected = !configuredClubKey || selectedEntry[0] === configuredClubKey;
      details.p2kWinProbability = configuredClubIsSelected ? 0 : 1;
      details.opponentWinProbability = configuredClubIsSelected ? 1 : 0;
      details.drawProbability = 0;
      details.minimumEligibilityForfeit = true;
    }
    const lineupComparison = compareLineups(selectedLineup, otherLineup);
    const isLeagueMatch = matchingLeagueAcronyms(rawMatch?.name).length > 0;

    return {
      raw: rawMatch,
      selectedTeamKey: selectedEntry[0],
      otherTeamKey: otherEntry[0],
      selectedTeam,
      otherTeam,
      selectedName,
      otherName,
      selectedPlayerCount: players(selectedTeam).length,
      otherPlayerCount: players(otherTeam).length,
      selectedEligibleCount: ratedPlayers(selectedTeam, bounds).length,
      otherEligibleCount: ratedPlayers(otherTeam, bounds).length,
      bounds,
      maxPlayers,
      minPlayers,
      details,
      lineupComparison,
      isLeagueMatch,
      startedProjection: buildInProgressProjection(
        rawMatch,
        selectedEntry[0],
        otherEntry[0],
        state.startedMatchData
      ),
      recruitmentRecommendation: buildRecruitmentRecommendation(
        rawMatch,
        selectedTeam,
        otherTeam,
        bounds,
        maxPlayers,
        minPlayers,
        isLeagueMatch,
        selectedName,
        otherName
      ),
      cacheKey: `single_${stableHash(state.matchId || rawMatch?.["@id"] || rawMatch?.url || rawMatch?.name)}`
    };
  }

  window.P2K_MATCH_HISTORY_SUMMARIZER = rawMatch => {
    const p2kKey = teamKeyForClub(rawMatch, window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king");
    if (!p2kKey) return null;
    const analysis = analyzePerspective(rawMatch, p2kKey);
    if (!analysis?.details) return null;
    return {
      p2kName: analysis.selectedName || "Promote to King",
      opponentName: analysis.otherName || "Opponent",
      p2kCount: analysis.selectedPlayerCount,
      opponentCount: analysis.otherPlayerCount,
      minPlayers: analysis.minPlayers,
      p2kWinProbability: analysis.details.p2kWinProbability
    };
  };

  function recruitmentPanelHTML(analysis) {
    if (isMatchInProgress(analysis.raw) || isMatchFinished(analysis.raw)) return "";
    const recommendation = analysis.recruitmentRecommendation;
    if (!recommendation) return "";
    const panelClass = recommendation.needsRecruitment
      ? "p2k-recruitment-panel p2k-recruitment-needed"
      : "p2k-recruitment-panel p2k-recruitment-ready";
    const goalInformation = analysis.isLeagueMatch
      ? `League matches must first reach the minimum-player requirement, then target a significant ${analysis.selectedName} board advantage: at least a 20% net board margin and at least 60% of paired boards rated in its favour.`
      : `Friendly matches must first reach the minimum-player requirement, then target parity or a small ${analysis.selectedName} board advantage. The model recommends the lowest tested player count and rating threshold that reaches at least an even board balance.`;
    return `
      <div class="${panelClass}">
        <div class="p2k-recruitment-title">
          Recruitment recommendation
          ${infoMarkHTML(goalInformation, "Recruitment target")}
        </div>
        <div class="p2k-recruitment-main">${escapeHTML(recommendation.summary)}</div>
        <div class="p2k-recruitment-meta">${escapeHTML(recommendation.detail)}</div>
      </div>`;
  }

  function detailedInfoHTML(analysis) {
    const details = analysis.details;
    const selectedName = analysis.selectedName;
    const otherName = analysis.otherName;

    const registrationTable = `
      <table class="p2k-detail-table" aria-label="Team registration and strength comparison">
        <thead>
          <tr>
            <th scope="col">Registration data</th>
            <th scope="col" class="p2k-team-column-selected">${escapeHTML(selectedName)}</th>
            <th scope="col" class="p2k-team-column-other">${escapeHTML(otherName)}</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Registered players</td>
            <td class="p2k-team-column-selected">${formatInteger(analysis.selectedPlayerCount)}</td>
            <td class="p2k-team-column-other">${formatInteger(analysis.otherPlayerCount)}</td>
          </tr>
          <tr>
            <td>Rating-eligible players</td>
            <td class="p2k-team-column-selected">${formatInteger(details.p2kRatingEligibleCount)}</td>
            <td class="p2k-team-column-other">${formatInteger(details.opponentRatingEligibleCount)}</td>
          </tr>
          <tr>
            <td>Total strength</td>
            <td class="p2k-team-column-selected">${formatInteger(details.p2kRegisteredStrength)}</td>
            <td class="p2k-team-column-other">${formatInteger(details.opponentRegisteredStrength)}</td>
          </tr>
          <tr>
            <td>Eligible lineup strength (${details.boardCount} board${details.boardCount === 1 ? "" : "s"})</td>
            <td class="p2k-team-column-selected">${formatInteger(details.p2kEligibleStrength)}</td>
            <td class="p2k-team-column-other">${formatInteger(details.opponentEligibleStrength)}</td>
          </tr>
        </tbody>
      </table>`;

    const cumulative = renderCumulativeRatingHTML(
      details.cumulativeRatingData,
      selectedName,
      otherName,
      { matchKey: analysis.cacheKey }
    );
    const histogram = renderRatingHistogramHTML(
      details.ratingBracketHistogram,
      details.ratingStatistics,
      selectedName,
      otherName,
      { matchKey: analysis.cacheKey }
    );
    const statistics = renderRatingStatisticsHTML(
      details.ratingStatistics,
      selectedName,
      otherName
    );
    const historyChart = window.P2K_MATCH_HISTORY_UI?.placeholderHTML?.(
      analysis.raw?.["@id"] || analysis.raw?.url || state.matchId
    ) || "";

    if (details.boardCount === 0) {
      return `
        <div class="p2k-expanded-details">
          <div class="p2k-perspective-note">Green accents represent ${escapeHTML(selectedName)}; red accents represent ${escapeHTML(otherName)}.</div>
          ${registrationTable}
          ${recruitmentPanelHTML(analysis)}
          <div class="p2k-detail-line">Detailed lineup prediction is unavailable because no rating-eligible board can be paired.</div>
          ${cumulative}
          ${histogram}
          ${historyChart}
          ${statistics}
        </div>`;
    }

    const advantage = details.boardAdvantages;
    const ratingRow = (label, pair) => `
      <tr>
        <td>${escapeHTML(label)}</td>
        <td class="p2k-team-column-selected">${formatInteger(pair.team1)}</td>
        <td class="p2k-team-column-other">${formatInteger(pair.team2)}</td>
      </tr>`;

    return `
      <div class="p2k-expanded-details">
        <div class="p2k-perspective-note">Green accents represent ${escapeHTML(selectedName)}; red accents represent ${escapeHTML(otherName)}.</div>
        ${registrationTable}
        ${recruitmentPanelHTML(analysis)}

        <div class="p2k-detail-line">
          <span class="p2k-detail-label">Delta strength over eligible boards (${escapeHTML(selectedName)} − ${escapeHTML(otherName)}):</span>
          <span class="${signedValueClass(details.deltaStrength)}">${formatInteger(details.deltaStrength)}</span>
        </div>

        <div class="p2k-rating-title">Rating board advantage:</div>
        <table class="p2k-detail-table" aria-label="Rating board advantage">
          <thead>
            <tr>
              <th scope="col">Rating difference</th>
              <th scope="col" class="p2k-team-column-selected">${escapeHTML(selectedName)}</th>
              <th scope="col" class="p2k-team-column-other">${escapeHTML(otherName)}</th>
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

        ${predictionPanelHTML(analysis)}

        ${cumulative}
        ${histogram}
        ${historyChart}
        ${statistics}
      </div>`;
  }

  function renderTeamSelector() {
    if (state.lockedClubScope) {
      teamChoices.innerHTML = "";
      teamSelector.hidden = true;
      return;
    }
    const entries = teamEntries(state.rawMatch);
    teamChoices.innerHTML = entries.map(([key, team]) => `
      <label>
        <input type="radio" name="p2kSelectedTeam" value="${escapeAttribute(key)}" ${key === state.selectedTeamKey ? "checked" : ""}>
        <span>${escapeHTML(teamName(team, key))}</span>
      </label>`).join("");
    teamSelector.hidden = false;
  }

  function renderAnalysis() {
    if (!state.rawMatch || !state.analysis) return;
    const analysis = state.analysis;
    const raw = state.rawMatch;
    const startTimestamp = Number(raw?.start_time);
    const startText = Number.isFinite(startTimestamp) ? formatStartDate(startTimestamp) : "Start date unavailable";
    const boundsText = `${Number.isFinite(analysis.bounds.minimum) ? analysis.bounds.minimum : 0}–${Number.isFinite(analysis.bounds.maximum) ? analysis.bounds.maximum : "No limit"}`;
    const matchName = String(raw?.name || `Match ${state.matchId}`);
    const matchURL = matchWebURL(raw, state.matchId);
    const lineup = analysis.lineupComparison;
    const lineupText = lineup
      ? `${analysis.selectedName} ${Math.round(lineup.p2kAverage)} vs ${analysis.otherName} ${Math.round(lineup.opponentAverage)} across ${lineup.boardCount} board${lineup.boardCount === 1 ? "" : "s"}`
      : "No eligible paired lineup is currently available.";

    resultsBox.innerHTML = `
      <section class="p2k-match-summary">
        <div class="p2k-match-summary-title">
          <a href="${escapeAttribute(matchURL)}" target="_blank" rel="noopener noreferrer">${escapeHTML(matchName)}</a>
        </div>
        <div class="p2k-summary-grid">
          <div class="p2k-summary-item"><strong>Match ID:</strong> ${escapeHTML(state.matchId)}</div>
          <div class="p2k-summary-item"><strong>Status:</strong> ${escapeHTML(formatStatus(raw?.status))}</div>
          <div class="p2k-summary-item"><strong>Start:</strong> ${escapeHTML(startText)}</div>
          <div class="p2k-summary-item"><strong>Time control:</strong> ${escapeHTML(formatTimeControl(raw))}</div>
          <div class="p2k-summary-item"><strong>Rating window:</strong> ${escapeHTML(boundsText)}</div>
          <div class="p2k-summary-item"><strong>Players per team:</strong> min ${analysis.minPlayers || 0}, max ${escapeHTML(formatMaximumPlayers(analysis.maxPlayers))}</div>
          <div class="p2k-summary-item" style="grid-column:1/-1"><strong>${isMatchInProgress(raw) ? "Start-time board ratings" : "Projected lineup"}:</strong> ${escapeHTML(lineupText)}</div>
        </div>
      </section>
      ${detailedInfoHTML(analysis)}`;
    window.P2K_MATCH_HISTORY_UI?.hydrate?.(resultsBox);
  }



  function updateStandaloneLink() {
    if (!standaloneLink) return;
    const embedded = new URLSearchParams(window.location.search).get("embedded") === "1" || window.self !== window.top;
    standaloneLink.hidden = !embedded;
    try {
      const url = new URL("AnalyzeMatch.html", window.location.href);
      if (state.matchId) url.searchParams.set("match", state.matchId);
      const selectedTeam = teamEntries(state.rawMatch).find(([key]) => key === state.selectedTeamKey)?.[1];
      const selectedSlug = teamClubSlug(selectedTeam) || state.preferredClubSlug;
      if (selectedSlug) url.searchParams.set("club", selectedSlug);
      if (state.lockedClubScope) url.searchParams.set("scope", state.preferredClubSlug || "promote-to-king");
      standaloneLink.href = url.href;
    } catch (_) { standaloneLink.href = "AnalyzeMatch.html"; }
  }

  function updateAddressBar() {
    try {
      const url = new URL(window.location.href);
      if (state.matchId) url.searchParams.set("match", state.matchId);
      const selectedTeam = teamEntries(state.rawMatch).find(([key]) => key === state.selectedTeamKey)?.[1];
      const selectedSlug = teamClubSlug(selectedTeam);
      if (selectedSlug) url.searchParams.set("club", selectedSlug);
      else url.searchParams.delete("club");
      if (state.lockedClubScope) url.searchParams.set("scope", state.preferredClubSlug || "promote-to-king"); else url.searchParams.delete("scope");
      url.searchParams.delete("team");
      url.searchParams.delete("id");
      url.searchParams.delete("url");
      history.replaceState(null, "", url);
    } catch (_) {}
    updateStandaloneLink();
  }

  function selectPerspective(teamKey) {
    state.selectedTeamKey = teamKey;
    state.preferredClubSlug = teamClubSlug(
      teamEntries(state.rawMatch).find(([key]) => key === teamKey)?.[1]
    );
    state.analysis = analyzePerspective(state.rawMatch, teamKey);
    updateAddressBar();
    renderTeamSelector();
    renderAnalysis();
    void updateSelectedTeamLogo();
  }

  function openChartModal(chartType, triggerButton = null) {
    const analysis = state.analysis;
    if (!analysis?.details) return;
    state.lastChartTrigger = triggerButton;
    if (chartType === "cumulative") {
      chartModalTitle.innerHTML = `${escapeHTML(state.rawMatch?.name || "Match")} — cumulative players at or above rating${infoMarkHTML("Each point is the number of registered players rated at or above that 100-Elo threshold. Hover or tap a point for the exact total.", "Cumulative chart information")}`;
      chartModalBody.innerHTML = renderCumulativeRatingHTML(
        analysis.details.cumulativeRatingData,
        analysis.selectedName,
        analysis.otherName,
        { large: true }
      );
    } else {
      chartModalTitle.innerHTML = `${escapeHTML(state.rawMatch?.name || "Match")} — registered players by rating bracket${infoMarkHTML("Bars show registered players in each 100-Elo bracket. Solid bars are rating-eligible; translucent bars are outside the match rating window. Hover or tap a bar for the exact count.", "Rating bracket chart information")}`;
      chartModalBody.innerHTML = renderRatingHistogramHTML(
        analysis.details.ratingBracketHistogram,
        analysis.details.ratingStatistics,
        analysis.selectedName,
        analysis.otherName,
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
    state.lastChartTrigger?.focus();
    state.lastChartTrigger = null;
  }

  async function analyzeReference(reference, preferredClubReference = null) {
    let matchId;
    try {
      matchId = parseMatchReference(reference);
    } catch (error) {
      setStatus(error.message, "error");
      window.CLUB_ANALYSIS_FAILURE_UI?.attach?.(statusText, [{
        error,
        phase: "Match reference",
        matchName: String(reference || "Invalid match reference")
      }], { title: "Open Match Analyzer failure" });
      resultsBox.innerHTML = `<div class="p2k-empty-prompt">${escapeHTML(error.message)}</div>`;
      teamSelector.hidden = true;
      return;
    }

    const matchApiUrl = `https://api.chess.com/pub/match/${encodeURIComponent(matchId)}`;
    state.matchId = matchId;
    state.preferredClubSlug = parseClubReference(preferredClubReference) || state.preferredClubSlug;
    state.startedMatchData = null;
    state.logoRequestToken += 1;
    hideSelectedTeamLogo();
    analyzeButton.disabled = true;
    matchInput.disabled = true;
    teamSelector.hidden = true;
    resultsBox.innerHTML = "";
    setStatus(`Loading match ${matchId}…`, "working", 25);

    try {
      const initialMatch = await loadJSON(matchApiUrl, null, { networkOnly: true });
      let rawMatch = initialMatch;

      if (isMatchInProgress(initialMatch)) {
        setStatus(`Match ${matchId} is in progress. Building projection from start-time ratings…`, "working", 55);
        state.startedMatchData = buildStartedMatchDataFromMatch(initialMatch);
        rawMatch = state.startedMatchData.enrichedMatch;
      }

      const entries = teamEntries(rawMatch);
      if (entries.length !== 2) throw new Error("The Chess.com API response does not contain two analyzable teams.");
      state.rawMatch = rawMatch;
      const preferredKey = teamKeyForClub(rawMatch, state.preferredClubSlug);
      state.selectedTeamKey = preferredKey || entries[0][0];
      state.preferredClubSlug = teamClubSlug(
        entries.find(([key]) => key === state.selectedTeamKey)?.[1]
      );
      state.analysis = analyzePerspective(rawMatch, state.selectedTeamKey);
      matchInput.value = matchId;
      updateAddressBar();
      renderTeamSelector();
      renderAnalysis();
      void updateSelectedTeamLogo();

      const missingClubText = preferredClubReference && !preferredKey
        ? ` The requested club was not one of the two match teams, so ${state.analysis.selectedName} was selected.`
        : "";
      const boardText = state.startedMatchData
        ? ` ${state.startedMatchData.boards.length} boards projected from the match response; no board endpoints requested.`
        : "";
      setStatus(`Match ${matchId} loaded.${boardText}${missingClubText} Select either team to change the analysis perspective.`, "success", 100);
      document.title = `${rawMatch?.name || `Match ${matchId}`} — Open Match Analyzer`;
    } catch (error) {
      console.error(error);
      const message = (error?.status === 404 || error?.category === "not-found")
        ? `Match ${matchId} was not found in the Chess.com public API.`
        : `Unable to analyze match ${matchId}: ${error?.message || error}`;
      setStatus(message, "error");
      window.CLUB_ANALYSIS_FAILURE_UI?.attach?.(statusText, [{
        error,
        apiUrl: error?.url || matchApiUrl,
        matchId,
        phase: "Match analysis",
        matchName: `Match ${matchId}`
      }], { title: "Open Match Analyzer failure" });
      resultsBox.innerHTML = `<div class="p2k-empty-prompt">${escapeHTML(message)}</div>`;
      teamSelector.hidden = true;
      state.rawMatch = null;
      state.startedMatchData = null;
      state.analysis = null;
      state.logoRequestToken += 1;
      hideSelectedTeamLogo();
    } finally {
      analyzeButton.disabled = false;
      matchInput.disabled = false;
    }
  }

  matchForm.addEventListener("submit", event => {
    event.preventDefault();
    analyzeReference(matchInput.value, state.preferredClubSlug);
  });

  teamChoices.addEventListener("change", event => {
    const input = event.target.closest('input[name="p2kSelectedTeam"]');
    if (input) selectPerspective(input.value);
  });

  resultsBox.addEventListener("click", event => {
    const button = event.target.closest(".p2k-chart-expand");
    if (button) openChartModal(button.dataset.chartType || "distribution", button);
  });

  chartModalClose.addEventListener("click", closeChartModal);
  chartModal.addEventListener("click", event => {
    if (event.target === chartModal) closeChartModal();
  });
  document.addEventListener("keydown", event => {
    if (event.key === "Escape") closeChartModal();
  });

  updateStandaloneLink();
  const parameters = new URLSearchParams(window.location.search);
  const initialReference = parameters.get("match") || parameters.get("id") || parameters.get("url");
  const initialClubReference = parameters.get("club") || parameters.get("team") || "";
  const lockedScopeReference = parameters.get("scope") || "";
  state.lockedClubScope = Boolean(parseClubReference(lockedScopeReference));
  state.preferredClubSlug = parseClubReference(lockedScopeReference) || parseClubReference(initialClubReference);
  if (initialReference) {
    matchInput.value = initialReference;
    analyzeReference(initialReference, initialClubReference);
  } else {
    matchInput.focus();
  }
})();
