(async () => {
      "use strict";
  if (window.P2K_ADMIN_ACCESS_READY && !(await window.P2K_ADMIN_ACCESS_READY)) return;

      const MATCH_LIST_URL =
        `https://api.chess.com/pub/club/${window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king"}/matches`;
      const P2K_CLUB_SLUG = window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king";
      const REQUEST_ATTEMPTS = window.P2K_SITE_CONFIG?.api?.defaultAttempts || 3;
            const DAYS_CHART = 30;

      const root = document.getElementById("p2kCreationAnalyzer");
      const analyzeButton =
        document.getElementById("p2kCreationAnalyzeButton");
      const statusBox = document.getElementById("p2kCreationStatus");
      const statusText = document.getElementById("p2kCreationStatusText");
      const progressTrack = document.getElementById("p2kCreationProgressTrack");
      const progressBar = document.getElementById("p2kCreationProgressBar");
      const progressStages = document.getElementById(
        "p2kCreationProgressStages"
      );
      const progressStageElements = Array.from(
        document.querySelectorAll("[data-progress-stage]")
      );
      const processingActions = document.getElementById("p2kCreationProcessingActions");
      const cancelButton = document.getElementById("p2kCreationCancelButton");
      const resultsBox = document.getElementById("p2kCreationResults");

      let basicData = null;
      let currentRecords = null;
      let activeRunId = 0;
      let activeController = null;
      let cancelRequested = false;
      let isDetailedAnalysisRunning = false;
      let isDetailedAnalysisPaused = false;
      let hasDetailedAnalysisCompleted = false;

      class CancelledError extends Error {
        constructor() {
          super("Detailed analyzis cancelled");
          this.name = "CancelledError";
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

      function throwIfCancelled(runId = null) {
        if (
          cancelRequested ||
          (runId !== null && runId !== activeRunId)
        ) {
          throw new CancelledError();
        }
      }

      function delay(milliseconds, runId = null) {
        return new Promise((resolve, reject) => {
          const timer = window.setTimeout(() => {
            try {
              throwIfCancelled(runId);
              resolve();
            } catch (error) {
              reject(error);
            }
          }, milliseconds);

          if (activeController && runId !== null) {
            activeController.signal.addEventListener("abort", () => {
              window.clearTimeout(timer);
              reject(new CancelledError());
            }, { once: true });
          }
        });
      }

      function progressStageForRecord(record, plan) {
        if (plan.registration.includes(record)) return "registration";
        if (plan.last30.includes(record)) return "last30";
        return "remaining";
      }

      function progressStageLabel(stage) {
        if (stage === "registration") {
          return "Processing registration matches";
        }
        if (stage === "last30") {
          return "Processing matches from the last 30 days";
        }
        return "Processing remaining matches";
      }

      function stageProgress(segment) {
        const total = segment.length;
        const processed = segment.filter(
          record => record.detailState !== "pending"
        ).length;
        return { processed, total };
      }

      function updateProgressStages(plan, activeStage = null, visible = true) {
        const stageSegments = {
          registration: plan?.registration || [],
          last30: plan?.last30 || [],
          remaining: plan?.remaining || []
        };

        progressStages.style.display = visible ? "grid" : "none";

        progressStageElements.forEach(element => {
          const key = element.dataset.progressStage;
          const progress = stageProgress(stageSegments[key]);
          const countElement = element.querySelector(
            ".p2k-progress-stage-count"
          );

          if (countElement) {
            countElement.textContent =
              `${progress.processed} / ${progress.total}`;
          }

          const complete =
            progress.total === 0 ||
            progress.processed >= progress.total;

          element.classList.toggle("is-complete", complete);
          element.classList.toggle(
            "is-active",
            activeStage === key && !complete
          );
        });
      }

      function setStatus(message, type = "working", progress = null, cancellable = false) {
        statusBox.style.display = "block";
        statusBox.classList.toggle("p2k-error", type === "error");
        statusBox.classList.toggle("p2k-success", type === "success");
        statusBox.classList.toggle("p2k-warning", type === "warning");
        statusText.textContent = message;

        if (progress && progress.total > 0) {
          progressTrack.style.display = "block";
          progressBar.style.width = `${Math.max(
            0,
            Math.min(100, progress.current / progress.total * 100)
          ).toFixed(1)}%`;
        } else {
          progressTrack.style.display = "none";
          progressBar.style.width = "0%";
          progressStages.style.display = "none";
        }

        processingActions.style.display = cancellable ? "flex" : "none";
        cancelButton.disabled = false;
      }
      function analysisFailureItems(records = currentRecords) {
        if (!records) return [];
        return [...records.registered, ...records.inProgress, ...records.finished]
          .filter(record => record.detailState === "failed")
          .map(record => ({
            matchName: record.name,
            apiUrl: record.apiUrl || record.summary?.["@id"],
            webUrl: record.webUrl,
            error: record.error,
            phase: "Match detail"
          }));
      }

      function attachAnalysisFailures(failures, title = "Match Creation analysis failures") {
        return window.CLUB_ANALYSIS_FAILURE_UI?.attach?.(statusText, failures, { title });
      }

      async function loadJSON(url, runId = null, signal = null) {
        throwIfCancelled(runId);
        if (!window.P2K_API_CLIENT) throw new Error("P2K_API_CLIENT is not loaded.");
        try {
          return await window.P2K_API_CLIENT.json(url, {
            signal,
            attempts: REQUEST_ATTEMPTS
          });
        } catch (error) {
          if (signal?.aborted || error?.category === "cancelled") throw new CancelledError();
          throw error;
        }
      }

      function utcToday() {
        const now = new Date();
        return new Date(Date.UTC(
          now.getUTCFullYear(),
          now.getUTCMonth(),
          now.getUTCDate()
        ));
      }

      function dateKeyFromDate(date) {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, "0");
        const day = String(date.getUTCDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
      }

      function dateKeyFromTimestamp(timestamp) {
        if (
          timestamp === null ||
          timestamp === undefined ||
          timestamp === ""
        ) {
          return null;
        }

        const seconds = Number(timestamp);
        if (!Number.isFinite(seconds) || seconds <= 0) return null;

        const date = new Date(seconds * 1000);
        if (!Number.isFinite(date.getTime())) return null;

        return dateKeyFromDate(date);
      }

      function dateRangeKeys(numberOfDays) {
        const today = utcToday();
        const keys = [];

        for (let offset = numberOfDays - 1; offset >= 0; offset -= 1) {
          const date = new Date(today);
          date.setUTCDate(today.getUTCDate() - offset);
          keys.push(dateKeyFromDate(date));
        }

        return keys;
      }

      function dateFromKey(key) {
        return new Date(`${key}T00:00:00Z`);
      }

      function formatDate(key) {
        return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
          day: "2-digit",
          month: "short",
          year: "numeric"
        }).format(dateFromKey(key));
      }

      function formatShortDate(key) {
        return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
          day: "2-digit",
          month: "short"
        }).format(dateFromKey(key));
      }

      function normalizeBoards(value) {
        const boards = Number(value);
        return Number.isFinite(boards) && boards >= 0
          ? Math.round(boards)
          : null;
      }

      function sumBoards(records) {
        return records.reduce((result, record) => {
          if (record.boards === null) {
            result.unknown += 1;
          } else {
            result.total += record.boards;
          }
          return result;
        }, { total: 0, unknown: 0 });
      }

      function formatBoards(boardSummary, short = false) {
        const total = Number(boardSummary?.total || 0);
        const unknown = Number(boardSummary?.unknown || 0);
        if (unknown === 0) return String(total);
        if (total === 0) return short ? "?" : "Unavailable";
        return short ? `${total}+?` : `${total} + unavailable`;
      }

      function aggregateByDate(records, allowedKeys = null) {
        const aggregates = new Map();

        if (allowedKeys) {
          allowedKeys.forEach(key => aggregates.set(key, {
            matches: 0,
            boards: 0,
            unknownBoards: 0
          }));
        }

        records.forEach(record => {
          const key = dateKeyFromTimestamp(record.startTime);
          if (!key) return;
          if (allowedKeys && !aggregates.has(key)) return;

          if (!aggregates.has(key)) {
            aggregates.set(key, {
              matches: 0,
              boards: 0,
              unknownBoards: 0
            });
          }

          const aggregate = aggregates.get(key);
          aggregate.matches += 1;
          if (record.boards === null) {
            aggregate.unknownBoards += 1;
          } else {
            aggregate.boards += record.boards;
          }
        });

        return aggregates;
      }

      function opponentSlug(opponentUrl) {
        if (!opponentUrl) return "";

        try {
          const parsed = new URL(opponentUrl);
          const parts = parsed.pathname.split("/").filter(Boolean);
          return decodeURIComponent(parts[parts.length - 1] || "");
        } catch (_) {
          const parts = String(opponentUrl).split("/").filter(Boolean);
          return parts[parts.length - 1] || "";
        }
      }

      function opponentNameFromUrl(opponentUrl) {
        const slug = opponentSlug(opponentUrl);
        if (!slug) return "Unknown opponent";

        return slug
          .replace(/^[-_]+|[-_]+$/g, "")
          .replace(/[-_]+/g, " ")
          .replace(/\s+/g, " ")
          .trim()
          .replace(/\b\w/g, character => character.toUpperCase()) ||
          "Unknown opponent";
      }

      function opponentWebUrl(opponentUrl) {
        const slug = opponentSlug(opponentUrl);
        return slug
          ? `https://www.chess.com/club/${encodeURIComponent(slug)}`
          : "https://www.chess.com/clubs";
      }

      function matchWebUrl(summary, detail) {
        if (detail?.url) return detail.url;

        const apiUrl = String(summary?.["@id"] || detail?.["@id"] || "");
        const liveId = apiUrl.match(/\/match\/live\/(\d+)/i);
        if (liveId) {
          return `https://www.chess.com/club/matches/live/${liveId[1]}`;
        }

        const matchId = apiUrl.match(/\/match\/(\d+)/i);
        return matchId
          ? `https://www.chess.com/club/matches/${matchId[1]}`
          : apiUrl || "https://www.chess.com/clubs";
      }

      function matchTimeClass(match) {
        return String(
          match?.time_class ||
          match?.settings?.time_class ||
          ""
        ).trim().toLowerCase();
      }

      function isDailyMatch(match) {
        return matchTimeClass(match) === "daily";
      }

      function matchTeams(detail) {
        return detail?.teams && typeof detail.teams === "object"
          ? Object.values(detail.teams).filter(Boolean)
          : [];
      }

      function numericTeamScore(team) {
        const score = Number(team?.score);
        return Number.isFinite(score) ? score : null;
      }

      function extractP2KScore(detail) {
        const p2kTeam = matchTeams(detail).find(team =>
          opponentSlug(team?.["@id"] || team?.url).toLowerCase() ===
          P2K_CLUB_SLUG
        );
        return numericTeamScore(p2kTeam);
      }

      function extractOpponentScore(summary, detail) {
        const expectedSlug = opponentSlug(summary?.opponent).toLowerCase();
        const teams = matchTeams(detail);
        const opponentTeam = teams.find(team =>
          expectedSlug &&
          opponentSlug(team?.["@id"] || team?.url).toLowerCase() ===
            expectedSlug
        ) || teams.find(team => {
          const slug = opponentSlug(
            team?.["@id"] || team?.url
          ).toLowerCase();
          return slug && slug !== P2K_CLUB_SLUG;
        });
        return numericTeamScore(opponentTeam);
      }

      function teamUsernames(team) {
        if (!Array.isArray(team?.players)) return [];
        return team.players
          .map(player => String(player?.username || "").trim())
          .filter(Boolean);
      }

      function extractMatchPlayers(summary, detail) {
        const expectedSlug = opponentSlug(summary?.opponent).toLowerCase();
        const teams = matchTeams(detail);
        const ownTeam = teams.find(team =>
          opponentSlug(team?.["@id"] || team?.url).toLowerCase() ===
            P2K_CLUB_SLUG
        );
        const opponentTeam = teams.find(team =>
          expectedSlug &&
          opponentSlug(team?.["@id"] || team?.url).toLowerCase() ===
            expectedSlug
        ) || teams.find(team => {
          const slug = opponentSlug(
            team?.["@id"] || team?.url
          ).toLowerCase();
          return slug && slug !== P2K_CLUB_SLUG;
        });

        return {
          own: teamUsernames(ownTeam),
          opponent: teamUsernames(opponentTeam)
        };
      }

      function formatMatchScore(score) {
        const number = Number(score);
        if (!Number.isFinite(number)) return "—";
        return number.toFixed(1).replace(/\.0$/, "");
      }

      function securedOutcome(record) {
        if (record.boards === null) return null;
        if (
          record.p2kScore !== null &&
          record.p2kScore > record.boards
        ) {
          return "win";
        }
        if (
          record.p2kScore !== null &&
          record.p2kScore === record.boards
        ) {
          return "draw";
        }
        if (
          record.opponentScore !== null &&
          record.opponentScore > record.boards
        ) {
          return "loss";
        }
        return null;
      }

      function extractOpponent(summary, detail) {
        const summaryOpponent = String(summary?.opponent || "");
        const expectedSlug = opponentSlug(summaryOpponent).toLowerCase();
        const teams = detail?.teams && typeof detail.teams === "object"
          ? Object.values(detail.teams).filter(Boolean)
          : [];

        let opponentTeam = teams.find(team => {
          const teamSlug = opponentSlug(team?.["@id"] || team?.url).toLowerCase();
          return expectedSlug && teamSlug === expectedSlug;
        });

        if (!opponentTeam) {
          opponentTeam = teams.find(team => {
            const teamSlug = opponentSlug(team?.["@id"] || team?.url).toLowerCase();
            return teamSlug && teamSlug !== P2K_CLUB_SLUG;
          });
        }

        const apiUrl = String(opponentTeam?.["@id"] || summaryOpponent || "");
        const webUrl = String(opponentTeam?.url || opponentWebUrl(apiUrl));
        const name = String(
          opponentTeam?.name || opponentNameFromUrl(apiUrl || summaryOpponent)
        );

        return { apiUrl, webUrl, name };
      }

      function createRecord(summary, status, detail, error = null) {
        const opponent = extractOpponent(summary, detail);
        const players = extractMatchPlayers(summary, detail);
        const detailStartTime = Number(detail?.start_time);
        const summaryStartTime = Number(summary?.start_time);
        const startTime = Number.isFinite(detailStartTime) && detailStartTime > 0
          ? detailStartTime
          : Number.isFinite(summaryStartTime) && summaryStartTime > 0
            ? summaryStartTime
            : null;

        return {
          status,
          summary,
          detail,
          error,
          detailState: detail ? "loaded" : error ? "failed" : "pending",
          name: String(summary?.name || detail?.name || "Unnamed match").trim() || "Unnamed match",
          apiUrl: String(summary?.["@id"] || detail?.["@id"] || ""),
          webUrl: matchWebUrl(summary, detail),
          startTime,
          boards: normalizeBoards(detail?.boards),
          p2kScore: extractP2KScore(detail),
          opponentScore: extractOpponentScore(summary, detail),
          ownPlayers: players.own,
          opponentPlayers: players.opponent,
          playerDataLoaded: Boolean(detail),
          opponentApiUrl: opponent.apiUrl,
          opponentWebUrl: opponent.webUrl,
          opponentName: opponent.name
        };
      }

      function listFromData(data, preferredName, fallbackName = null) {
        if (Array.isArray(data?.[preferredName])) return data[preferredName];
        if (fallbackName && Array.isArray(data?.[fallbackName])) {
          return data[fallbackName];
        }
        return [];
      }

      function buildBasicRecords(data) {
        const registered = listFromData(data, "registered")
          .filter(isDailyMatch);
        const inProgress = listFromData(data, "in_progress", "on_going")
          .filter(isDailyMatch);
        const finished = listFromData(data, "finished")
          .filter(isDailyMatch);
        const chartKeySet = new Set(dateRangeKeys(DAYS_CHART));
        const recentFinished = finished.filter(match =>
          chartKeySet.has(dateKeyFromTimestamp(match?.start_time))
        );

        return {
          registered: registered.map(summary =>
            createRecord(summary, "registered", null)
          ),
          inProgress: inProgress.map(summary =>
            createRecord(summary, "inProgress", null)
          ),
          finished: recentFinished.map(summary =>
            createRecord(summary, "finished", null)
          ),
          finishedResults: finished,
          failed: 0,
          processed: 0,
          requested: 0,
          registrationCheckpointRendered: false,
          recentCheckpointRendered: false
        };
      }

      
      function p2kCreationPrioritySegment(values) {
        const prioritizeRecords =
          window.P2K_MATCH_PRIORITY?.prioritizeRecords ||
          window.P2K_API_CLIENT?.prioritizeRecords;
        return typeof prioritizeRecords === "function"
          ? prioritizeRecords(values)
          : [...(Array.isArray(values) ? values : [])];
      }
    
      function detailQueuePlan(records) {
        const chartDateKeys = new Set(dateRangeKeys(DAYS_CHART));
        const startedRecords = [
          ...records.inProgress,
          ...records.finished
        ];

        const startedLast30Days = startedRecords.filter(record =>
          chartDateKeys.has(dateKeyFromTimestamp(record.startTime))
        );
        const olderOrUndatedInProgress = records.inProgress.filter(record =>
          !chartDateKeys.has(dateKeyFromTimestamp(record.startTime))
        );
        const seenUrls = new Set();

        const uniqueSegment = segment => segment.filter(record => {
          const url = String(record.apiUrl || record.summary?.["@id"] || "");
          if (!url || seenUrls.has(url)) return false;
          seenUrls.add(url);
          return true;
        });

        /*
         * The detailed analysis is presented as three visible stages:
         * 1. registration matches;
         * 2. every match started during the latest 30 calendar days;
         * 3. older or undated in-progress matches.
         */
        const registration = p2kCreationPrioritySegment(uniqueSegment(records.registered));
        const last30 = p2kCreationPrioritySegment(uniqueSegment(startedLast30Days));
        const remaining = p2kCreationPrioritySegment(uniqueSegment(olderOrUndatedInProgress));

        return {
          registration,
          last30,
          remaining,
          recent30: last30,
          all: [
            ...registration,
            ...last30,
            ...remaining
          ]
        };
      }

      function detailQueue(records) {
        return detailQueuePlan(records).all;
      }

      async function loadDetailedRecords(records, runId, resume = false, targetStage = "all") {
        const plan = detailQueuePlan(records);
        const orderedQueue = targetStage === "registration"
          ? plan.registration
          : targetStage === "last30"
            ? [...plan.registration, ...plan.last30]
            : plan.all;

        records.requested = orderedQueue.length;
        records.processed = orderedQueue.filter(
          record => record.detailState !== "pending"
        ).length;
        records.failed = orderedQueue.filter(
          record => record.detailState === "failed"
        ).length;
        if (!resume) {
          records.registrationCheckpointRendered = false;
          records.recentCheckpointRendered = false;
        }

        const registrationRecords = new Set(plan.registration);
        const recentRecords = new Set(plan.recent30);
        const accounted = new WeakSet();
        const pendingByStage = {
          all: plan.all.filter(record => record.detailState === "pending").length,
          registration: plan.registration.filter(record => record.detailState === "pending").length,
          recent30: plan.recent30.filter(record => record.detailState === "pending").length
        };
        const accountSettledRecord = record => {
          if (!record || accounted.has(record)) return;
          accounted.add(record);
          pendingByStage.all = Math.max(0, pendingByStage.all - 1);
          if (registrationRecords.has(record)) pendingByStage.registration = Math.max(0, pendingByStage.registration - 1);
          if (recentRecords.has(record)) pendingByStage.recent30 = Math.max(0, pendingByStage.recent30 - 1);
        };

        /*
         * Recalculate and redraw only at useful checkpoints. v2.9.12 tracks
         * pending counts incrementally instead of rescanning 600+ records after
         * every completion.
         */
        const renderReachedCheckpoint = () => {
          const allComplete = pendingByStage.all === 0;
          if (allComplete) return;

          const registrationComplete = pendingByStage.registration === 0;
          const recentComplete = registrationComplete && pendingByStage.recent30 === 0;

          if (recentComplete && !records.recentCheckpointRendered) {
            records.registrationCheckpointRendered = true;
            records.recentCheckpointRendered = true;
            renderAnalysis(records, {
              phase: "recentComplete",
              processed: records.processed,
              total: records.requested
            });
            return;
          }

          if (
            registrationComplete &&
            !records.registrationCheckpointRendered
          ) {
            records.registrationCheckpointRendered = true;
            renderAnalysis(records, {
              phase: "registrationComplete",
              processed: records.processed,
              total: records.requested
            });
          }
        };

        /*
         * Loaded and failed records are preserved when resuming. Only pending
         * records are requested, in the original priority order.
         */
        const queue = orderedQueue.filter(
          record => record.detailState === "pending"
        );

        renderReachedCheckpoint();
        if (queue.length === 0) return records;

        const baseProcessed = records.processed;
        let lastProgressPaintAt = 0;
        let lastProgressStage = "";
        const detailBatch = await window.P2K_API_CLIENT.processPriority(
          queue,
          async record => {
            throwIfCancelled(runId);
            let detail = null;
            let error = null;
            try {
              detail = await loadJSON(
                record.summary["@id"],
                runId,
                activeController?.signal || null
              );
            } catch (caughtError) {
              if (caughtError instanceof CancelledError) throw caughtError;
              console.error(caughtError);
              error = caughtError;
              records.failed += 1;
            }
            Object.assign(record, createRecord(record.summary, record.status, detail, error));
            return record;
          },
          {
            signal: activeController?.signal || null,
            getKey: record => String(record?.summary?.["@id"] || record?.apiUrl || ""),
            getPriority: record => Number(record?.priority || 0),
            onProgress: progressState => {
              const current = Math.min(records.requested, baseProcessed + progressState.settled);
              records.processed = current;
              if (progressState.item && (progressState.state === "succeeded" || progressState.state === "failed")) accountSettledRecord(progressState.item);
              renderReachedCheckpoint();
              const activeStage = progressStageForRecord(progressState.item || queue[0], plan);
              const now = performance.now();
              const shouldPaint = activeStage !== lastProgressStage || current >= records.requested || now - lastProgressPaintAt >= 100;
              if (!shouldPaint) return;
              lastProgressPaintAt = now;
              lastProgressStage = activeStage;
              updateProgressStages(plan, activeStage, true);
              setStatus(
                `${progressStageLabel(activeStage)} — match ${current} of ${records.requested}`,
                "working",
                { current, total: records.requested },
                true
              );
            }
          }
        );
        records.processed = Math.min(records.requested, baseProcessed + detailBatch.succeeded.length + detailBatch.failures.length);
        if (detailBatch.cancelled || activeController?.signal?.aborted) throw new CancelledError();

        return records;
      }

      function renderMetric(value, label, subvalue = "") {
        return `
          <div class="p2k-metric">
            <div class="p2k-metric-value">${escapeHTML(value)}</div>
            <div class="p2k-metric-label">${escapeHTML(label)}</div>
            ${subvalue
              ? `<div class="p2k-metric-subvalue">${escapeHTML(subvalue)}</div>`
              : ""}
          </div>
        `;
      }

      function formatRate(value) {
        return `${(Number(value || 0) * 100).toFixed(1)}%`;
      }

      function formatPoints(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return "—";
        return number.toFixed(2).replace(/\.00$/, "");
      }

      function formatIntegerPoints(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return "—";
        return String(Math.round(number));
      }

      function scoringStats(records, analysisState) {
        const finished = Array.isArray(records.finishedResults)
          ? records.finishedResults
          : [];
        const resultCounts = finished.reduce((counts, match) => {
          const result = String(match?.result || "").trim().toLowerCase();
          if (result === "win") counts.win += 1;
          else if (result === "draw") counts.draw += 1;
          else if (result === "lose" || result === "loss") counts.lose += 1;
          else counts.unknown += 1;
          return counts;
        }, { win: 0, draw: 0, lose: 0, unknown: 0 });

        const totalFinished = finished.length;
        const denominator = totalFinished || 1;
        const winRate = resultCounts.win / denominator;
        const drawRate = resultCounts.draw / denominator;
        const loseRate = resultCounts.lose / denominator;
        const grossPerBoard = (5 * winRate) + (2 * drawRate);

        const boardSummary = sumBoards(records.inProgress);
        const pendingDetails = records.inProgress.filter(
          record => record.detailState === "pending"
        ).length;
        const failedDetails = records.inProgress.filter(
          record => record.detailState === "failed"
        ).length;
        const allOngoingRetrieved = pendingDetails === 0;
        const paused = analysisState.phase === "paused";
        const showPendingValues = allOngoingRetrieved || paused;

        let securedWinPoints = 0;
        let securedDrawPoints = 0;
        let securedWins = 0;
        let securedDraws = 0;
        let unavoidableLosses = 0;
        let estimatedBoards = 0;
        let excludedLossBoards = 0;
        let unknownScores = 0;

        records.inProgress.forEach(record => {
          if (record.boards === null) return;

          const outcome = securedOutcome(record);
          if (outcome === "win") {
            securedWinPoints += 5 * record.boards;
            securedWins += 1;
          } else if (outcome === "draw") {
            securedDrawPoints += 2 * record.boards;
            securedDraws += 1;
          } else if (outcome === "loss") {
            unavoidableLosses += 1;
            excludedLossBoards += record.boards;
          } else {
            estimatedBoards += record.boards;
            if (
              record.p2kScore === null ||
              record.opponentScore === null
            ) {
              unknownScores += 1;
            }
          }
        });

        const securedPoints =
          securedWinPoints + securedDrawPoints;
        const estimatedPendingPoints = Math.round(
          securedPoints + (grossPerBoard * estimatedBoards)
        );

        return {
          totalFinished,
          resultCounts,
          winRate,
          drawRate,
          loseRate,
          grossPerBoard,
          boardSummary,
          pendingDetails,
          failedDetails,
          showPendingValues,
          securedPoints,
          securedWinPoints,
          securedDrawPoints,
          securedWins,
          securedDraws,
          unavoidableLosses,
          estimatedBoards,
          excludedLossBoards,
          unknownScores,
          estimatedPendingPoints
        };
      }

      function renderScoring(records, analysisState) {
        const stats = scoringStats(records, analysisState);
        const finishedSubvalue = stats.resultCounts.unknown > 0
          ? `${stats.totalFinished} finished daily matches; ${stats.resultCounts.unknown} unclassified`
          : `${stats.totalFinished} finished daily matches`;

        const securedSubvalue = !stats.showPendingValues
          ? "Available after ongoing match details are retrieved"
          : `${stats.securedWinPoints} win points + ${stats.securedDrawPoints} draw points`;

        const estimatedSubvalue = !stats.showPendingValues
          ? "Available after ongoing match details are retrieved"
          : `Rounded secured total plus estimate on ${stats.estimatedBoards} other boards; ${stats.excludedLossBoards} unavoidable-loss boards excluded`;

        return `
          <div class="p2k-scoring-section">
            <div class="p2k-scoring-title">Finished daily match results</div>
            <div class="p2k-metrics">
              ${renderMetric(
                formatRate(stats.winRate),
                "Win rate",
                `${stats.resultCounts.win} wins · ${finishedSubvalue}`
              )}
              ${renderMetric(
                formatRate(stats.drawRate),
                "Draw rate",
                `${stats.resultCounts.draw} draws · ${finishedSubvalue}`
              )}
              ${renderMetric(
                formatRate(stats.loseRate),
                "Lose rate",
                `${stats.resultCounts.lose} losses · ${finishedSubvalue}`
              )}
            </div>
          </div>

          <div class="p2k-scoring-section">
            <div class="p2k-scoring-title">Secured ongoing outcomes</div>
            <div class="p2k-scoring-points">
              ${renderMetric(
                stats.showPendingValues
                  ? stats.securedWins
                  : "—",
                "Secured wins",
                stats.showPendingValues
                  ? "P2K score is greater than the board count"
                  : "Available after ongoing match details are retrieved"
              )}
              ${renderMetric(
                stats.showPendingValues
                  ? stats.securedDraws
                  : "—",
                "Secured draws",
                stats.showPendingValues
                  ? "P2K score equals the board count"
                  : "Available after ongoing match details are retrieved"
              )}
              ${renderMetric(
                stats.showPendingValues
                  ? stats.unavoidableLosses
                  : "—",
                "Unavoidable losses",
                stats.showPendingValues
                  ? "Opponent score is greater than the board count"
                  : "Available after ongoing match details are retrieved"
              )}
            </div>
          </div>

          <div class="p2k-scoring-section">
            <div class="p2k-scoring-title">Gross and pending points</div>
            <div class="p2k-scoring-points">
              ${renderMetric(
                formatPoints(stats.grossPerBoard),
                "Gross derived points/board",
                "5 × win rate + 2 × draw rate"
              )}
              ${renderMetric(
                stats.showPendingValues
                  ? formatIntegerPoints(stats.securedPoints)
                  : "—",
                "Secured points",
                securedSubvalue
              )}
              ${renderMetric(
                stats.showPendingValues
                  ? formatIntegerPoints(stats.estimatedPendingPoints)
                  : "—",
                "Estimated pending points",
                estimatedSubvalue
              )}
            </div>
            <div class="p2k-scoring-note">
              <strong>Secured-points rules:</strong>
              a secured win is worth 5 points per board when Promote to King’s
              score is greater than the board count. A secured draw is worth
              2 points when Promote to King’s score equals the board count.
              Other ongoing boards use the gross derived points-per-board
              estimate, except matches already flagged as unavoidable losses,
              which contribute no estimated points. The final estimated
              pending total is rounded to the nearest integer.
              ${stats.unknownScores > 0 && stats.showPendingValues
                ? `<br>${stats.unknownScores} loaded ongoing match${stats.unknownScores === 1 ? "" : "es"} had an incomplete current score and were treated as non-secured.`
                : ""}
            </div>
          </div>
        `;
      }

      function renderRegistrationTable(records) {
        const aggregates = aggregateByDate(records);
        const noDateRecords = records.filter(record => !record.startTime);
        const noDateBoards = sumBoards(noDateRecords);

        const rows = [...aggregates.entries()]
          .sort(([dateA], [dateB]) => dateA.localeCompare(dateB))
          .map(([date, aggregate]) => `
            <tr>
              <td class="p2k-date">${escapeHTML(formatDate(date))}</td>
              <td class="p2k-count">${aggregate.matches}</td>
              <td class="p2k-board-value">${escapeHTML(formatBoards({
                total: aggregate.boards,
                unknown: aggregate.unknownBoards
              }, true))}</td>
            </tr>
          `);

        if (noDateRecords.length > 0) {
          rows.push(`
            <tr>
              <td class="p2k-no-date">No start date</td>
              <td class="p2k-count">${noDateRecords.length}</td>
              <td class="p2k-board-value">${escapeHTML(formatBoards(noDateBoards, true))}</td>
            </tr>
          `);
        }

        if (rows.length === 0) {
          rows.push(`
            <tr>
              <td>No registered matches</td>
              <td class="p2k-count">0</td>
              <td class="p2k-board-value">0</td>
            </tr>
          `);
        }

        return `
          <div class="p2k-section">
            <div class="p2k-section-title">
              Registered matches by start date
            </div>
            <div class="p2k-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Calendar date (UTC)</th>
                    <th>Matches</th>
                    <th>Boards</th>
                  </tr>
                </thead>
                <tbody>${rows.join("")}</tbody>
              </table>
            </div>
          </div>
        `;
      }

      function buildOpponentGroups(registered, inProgress) {
        const groups = new Map();

        const addRecord = record => {
          const slug = opponentSlug(record.opponentApiUrl || record.opponentWebUrl);
          const key = slug || record.opponentName || `unknown:${record.apiUrl}`;

          if (!groups.has(key)) {
            groups.set(key, {
              opponentApiUrl: record.opponentApiUrl,
              opponentWebUrl: record.opponentWebUrl,
              opponentName: record.opponentName,
              registered: [],
              inProgress: []
            });
          }

          const group = groups.get(key);
          if (!group.opponentName || group.opponentName === "Unknown opponent") {
            group.opponentName = record.opponentName;
          }
          if (!group.opponentApiUrl) group.opponentApiUrl = record.opponentApiUrl;
          if (!group.opponentWebUrl) group.opponentWebUrl = record.opponentWebUrl;
          group[record.status].push(record);
        };

        registered.forEach(addRecord);
        inProgress.forEach(addRecord);

        return [...groups.values()].sort((groupA, groupB) => {
          const totalA = groupA.registered.length + groupA.inProgress.length;
          const totalB = groupB.registered.length + groupB.inProgress.length;
          return totalB - totalA ||
            groupB.registered.length - groupA.registered.length ||
            groupA.opponentName.localeCompare(groupB.opponentName);
        });
      }

      function renderOpponentMatch(record) {
        const dateKey = dateKeyFromTimestamp(record.startTime);
        const isRegistered = record.status === "registered";
        const dateText = dateKey
          ? `${isRegistered ? "Starts" : "Started"} ${formatDate(dateKey)}`
          : isRegistered
            ? "No start date"
            : "Start date unavailable";
        const statusLabel = isRegistered ? "Registration" : "In progress";
        const statusClass = isRegistered ? "p2k-registered" : "p2k-progress";
        const boardText = record.boards === null
          ? "Boards unavailable"
          : `${record.boards} board${record.boards === 1 ? "" : "s"}`;
        const scoreAvailable =
          record.p2kScore !== null || record.opponentScore !== null;
        const scoreText = scoreAvailable
          ? `P2K ${formatMatchScore(record.p2kScore)} – ${formatMatchScore(record.opponentScore)} Opp.`
          : isRegistered
            ? "Score not started"
            : "Score unavailable";
        const outcome = isRegistered ? null : securedOutcome(record);
        const outcomeBadge = outcome === "win"
          ? '<span class="p2k-status-pill p2k-secured-win">Secured win</span>'
          : outcome === "draw"
            ? '<span class="p2k-status-pill p2k-secured-draw">Secured draw</span>'
            : outcome === "loss"
              ? '<span class="p2k-status-pill p2k-secured-loss">Unavoidable loss</span>'
              : '<span class="p2k-status-pill p2k-boards">Not secured</span>';
        const ownPlayerText = (record.ownPlayers || []).join(", ");
        const opponentPlayerText = (record.opponentPlayers || []).join(", ");
        const playerCountText = record.playerDataLoaded
          ? `P2K ${(record.ownPlayers || []).length} · Opponent ${(record.opponentPlayers || []).length} players`
          : "Player lists unavailable until detailed data is loaded";

        return `
          <div
            class="p2k-opponent-match"
            data-status="${record.status}"
            data-board-count="${record.boards === null ? "" : record.boards}"
            data-match-search="${escapeHTML(record.name.toLowerCase())}"
            data-own-players="${escapeHTML(ownPlayerText.toLowerCase())}"
            data-opponent-players="${escapeHTML(opponentPlayerText.toLowerCase())}"
            data-player-data-loaded="${record.playerDataLoaded ? "true" : "false"}"
          >
            <span class="p2k-status-pill ${statusClass}">${statusLabel}</span>
            <div class="p2k-match-main">
              <span class="p2k-match-title-label">Match title</span>
              <a
                class="p2k-match-name"
                href="${escapeHTML(record.webUrl)}"
                target="_blank"
                rel="noopener"
                title="${escapeHTML(record.name)}"
              >${escapeHTML(record.name)}</a>
              <span
                class="p2k-match-player-counts"
                title="${escapeHTML(`P2K: ${ownPlayerText || "unavailable"} | Opponent: ${opponentPlayerText || "unavailable"}`)}"
              >${escapeHTML(playerCountText)}</span>
            </div>
            <span class="p2k-match-score">${escapeHTML(scoreText)}</span>
            <span class="p2k-match-outcome">${outcomeBadge}</span>
            <span class="p2k-match-boards">${escapeHTML(boardText)}</span>
            <span class="p2k-match-date">${escapeHTML(dateText)}</span>
          </div>
        `;
      }

      function uniquePlayerStatistics(groups) {
        const ownRegistered = new Set();
        const ownPlaying = new Set();
        const opponentRegistered = new Set();
        const opponentPlaying = new Set();

        const registrationRecords = groups.flatMap(
         group => group.registered
        );

        const progressRecords = groups.flatMap(
          group => group.inProgress
        );

        const addPlayers = (target, usernames) => {
          (usernames || []).forEach(username => {
            const normalized = String(username || "").trim().toLowerCase();
            if (normalized) target.add(normalized);
          });
        };

        registrationRecords.forEach(record => {
          addPlayers(ownRegistered, record.ownPlayers);
          addPlayers(opponentRegistered, record.opponentPlayers);
        });
        progressRecords.forEach(record => {
          addPlayers(ownPlaying, record.ownPlayers);
          addPlayers(opponentPlaying, record.opponentPlayers);
        });

        return {
          ownRegistered: ownRegistered.size,
          ownPlaying: ownPlaying.size,
          opponentRegistered: opponentRegistered.size,
          opponentPlaying: opponentPlaying.size,
          registrationLoaded: registrationRecords.filter(
            record => record.playerDataLoaded
          ).length,
          registrationTotal: registrationRecords.length,
          progressLoaded: progressRecords.filter(
            record => record.playerDataLoaded
          ).length,
          progressTotal: progressRecords.length
        };
      }

      function playerMetricValue(value, loaded, total) {
        if (total === 0) return 0;
        return loaded > 0 ? value : "—";
      }

      function playerMetricSubvalue(loaded, total, matchType) {
        if (total === 0) return `No daily ${matchType} matches`;
        if (loaded === 0) {
          return "Available after Detailed analyzis";
        }
        if (loaded >= total) {
          return `Complete across ${total} ${matchType} match${total === 1 ? "" : "es"}`;
        }
        return `Partial: ${loaded} of ${total} ${matchType} matches loaded`;
      }

      function renderPlayerStatistics(groups) {
        const stats = uniquePlayerStatistics(groups);
        const registeredNote = playerMetricSubvalue(
          stats.registrationLoaded,
          stats.registrationTotal,
          "registration"
        );
        const playingNote = playerMetricSubvalue(
          stats.progressLoaded,
          stats.progressTotal,
          "in-progress"
        );

        return `
          <div class="p2k-scoring-section" style="margin-top: 0;">
            <div class="p2k-scoring-title">Unique players across active daily matches</div>
            <div class="p2k-player-metrics">
              ${renderMetric(
                playerMetricValue(
                  stats.ownRegistered,
                  stats.registrationLoaded,
                  stats.registrationTotal
                ),
                "P2K players registered",
                registeredNote
              )}
              ${renderMetric(
                playerMetricValue(
                  stats.ownPlaying,
                  stats.progressLoaded,
                  stats.progressTotal
                ),
                "P2K players currently playing",
                playingNote
              )}
              ${renderMetric(
                playerMetricValue(
                  stats.opponentRegistered,
                  stats.registrationLoaded,
                  stats.registrationTotal
                ),
                "Opponent players registered",
                registeredNote
              )}
              ${renderMetric(
                playerMetricValue(
                  stats.opponentPlaying,
                  stats.progressLoaded,
                  stats.progressTotal
                ),
                "Opponent players currently playing",
                playingNote
              )}
            </div>
          </div>
        `;
      }

      function renderOpponents(groups) {
        const opponentsWithRegistration = groups.filter(
          group => group.registered.length > 0
        ).length;

        const cards = groups.map((group, index) => {
          const registrationBoards = sumBoards(group.registered);
          const progressBoards = sumBoards(group.inProgress);
          const totalBoards = sumBoards([
            ...group.registered,
            ...group.inProgress
          ]);
          const matches = [
            ...group.registered,
            ...group.inProgress
          ].sort((recordA, recordB) => {
            if (recordA.status !== recordB.status) {
              return recordA.status === "registered" ? -1 : 1;
            }
            return Number(recordA.startTime || Number.MAX_SAFE_INTEGER) -
              Number(recordB.startTime || Number.MAX_SAFE_INTEGER);
          });
          const slug = opponentSlug(
            group.opponentApiUrl || group.opponentWebUrl
          );

          return `
            <details
              class="p2k-opponent-card"
              data-opponent-index="${index}"
              data-opponent-key="${escapeHTML(slug || group.opponentName.toLowerCase())}"
              data-registered-count="${group.registered.length}"
              data-total-match-count="${group.registered.length + group.inProgress.length}"
              data-total-board-count="${totalBoards.total}"
              data-total-board-unknown="${totalBoards.unknown}"
              data-opponent-name="${escapeHTML(group.opponentName.toLowerCase())}"
              data-opponent-search="${escapeHTML(`${group.opponentName} ${slug}`.toLowerCase())}"
            >
              <summary>
                <span class="p2k-opponent-name">${escapeHTML(group.opponentName)}</span>
                <span class="p2k-opponent-counts">
                  <span class="p2k-status-pill p2k-registered">
                    ${group.registered.length} in registration · ${escapeHTML(formatBoards(registrationBoards, true))} boards
                  </span>
                  <span class="p2k-status-pill p2k-progress">
                    ${group.inProgress.length} in progress · ${escapeHTML(formatBoards(progressBoards, true))} boards
                  </span>
                  <span class="p2k-status-pill p2k-boards">
                    ${escapeHTML(formatBoards(totalBoards, true))} total boards
                  </span>
                </span>
              </summary>
              <div class="p2k-opponent-body">
                <a
                  class="p2k-opponent-club-link"
                  href="${escapeHTML(group.opponentWebUrl || opponentWebUrl(group.opponentApiUrl))}"
                  target="_blank"
                  rel="noopener"
                >Open opponent club ↗</a>
                <div class="p2k-opponent-matches">
                  ${matches.map(renderOpponentMatch).join("")}
                </div>
              </div>
            </details>
          `;
        }).join("");

        return `
          ${renderPlayerStatistics(groups)}
          <div class="p2k-section">
            <div class="p2k-section-title-row">
              <div class="p2k-section-title">Active teams, matches and players</div>
              <div class="p2k-note">
                ${groups.length} opposing team${groups.length === 1 ? "" : "s"},
                ${opponentsWithRegistration} with registration open
              </div>
            </div>

            <div class="p2k-opponent-toolbar">
              <label class="p2k-opponent-search-group" for="p2kOpponentSearch">
                <span class="p2k-filter-label">Search:</span>
                <input
                  id="p2kOpponentSearch"
                  class="p2k-opponent-search"
                  type="search"
                  placeholder="Team, match, or player…"
                  aria-label="Search team, match name, or player username"
                  autocomplete="off"
                  spellcheck="false"
                >
              </label>
              <div class="p2k-filter-group" role="radiogroup" aria-label="Player search team">
                <span class="p2k-filter-label">Player team:</span>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kPlayerTeamScope"
                    value="either"
                    checked
                  >
                  Either
                </label>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kPlayerTeamScope"
                    value="own"
                  >
                  Our team
                </label>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kPlayerTeamScope"
                    value="opponent"
                  >
                  Opponent team
                </label>
              </div>
              <div class="p2k-filter-group" role="radiogroup" aria-label="Match status filter">
                <span class="p2k-filter-label">Filter:</span>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kOpponentStatusFilter"
                    value="all"
                    checked
                  >
                  All active matches
                </label>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kOpponentStatusFilter"
                    value="registered"
                  >
                  Registration only
                </label>
              </div>
              <div class="p2k-filter-group" role="radiogroup" aria-label="Opponent ordering">
                <span class="p2k-filter-label">Order by:</span>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kOpponentOrder"
                    value="matches"
                    checked
                  >
                  Total matches
                </label>
                <label class="p2k-radio-label">
                  <input
                    type="radio"
                    name="p2kOpponentOrder"
                    value="boards"
                  >
                  Total boards
                </label>
              </div>
              <div class="p2k-opponent-actions">
                <button class="p2k-small-button" type="button" data-opponent-action="expand">
                  Expand visible
                </button>
                <button class="p2k-small-button" type="button" data-opponent-action="collapse">
                  Collapse all
                </button>
              </div>
            </div>

            <div class="p2k-note" style="margin: -2px 0 9px;">
              Player usernames become searchable as detailed match data is retrieved.
            </div>
            <div id="p2kOpponentVisibleSummary" class="p2k-opponent-summary"></div>
            <div class="p2k-opponent-list">
              ${cards || '<div class="p2k-empty-opponents">No active opposing teams found.</div>'}
              ${cards ? '<div id="p2kOpponentNoResults" class="p2k-empty-opponents" hidden>No teams, matches, or players match the current filters.</div>' : ''}
            </div>
          </div>
        `;
      }

      function bindOpponentControls() {
        const filters = root.querySelectorAll(
          'input[name="p2kOpponentStatusFilter"]'
        );
        const orderInputs = root.querySelectorAll(
          'input[name="p2kOpponentOrder"]'
        );
        const playerScopeInputs = root.querySelectorAll(
          'input[name="p2kPlayerTeamScope"]'
        );
        const cards = [...root.querySelectorAll(".p2k-opponent-card")];
        const opponentList = root.querySelector(".p2k-opponent-list");
        const searchInput = root.querySelector("#p2kOpponentSearch");
        const visibleSummary = root.querySelector(
          "#p2kOpponentVisibleSummary"
        );
        const noResults = root.querySelector("#p2kOpponentNoResults");

        const normalizeSearch = value => String(value || "")
          .normalize("NFD")
          .replace(/[\u0300-\u036f]/g, "")
          .toLowerCase()
          .trim();

        const sortCards = () => {
          const order = root.querySelector(
            'input[name="p2kOpponentOrder"]:checked'
          )?.value || "matches";

          cards.sort((cardA, cardB) => {
            const totalMatchesA = Number(cardA.dataset.totalMatchCount || 0);
            const totalMatchesB = Number(cardB.dataset.totalMatchCount || 0);
            const totalBoardsA = Number(cardA.dataset.totalBoardCount || 0);
            const totalBoardsB = Number(cardB.dataset.totalBoardCount || 0);
            const unknownBoardsA = Number(cardA.dataset.totalBoardUnknown || 0);
            const unknownBoardsB = Number(cardB.dataset.totalBoardUnknown || 0);
            const nameA = cardA.dataset.opponentName || "";
            const nameB = cardB.dataset.opponentName || "";

            if (order === "boards") {
              return totalBoardsB - totalBoardsA ||
                totalMatchesB - totalMatchesA ||
                unknownBoardsA - unknownBoardsB ||
                nameA.localeCompare(nameB);
            }

            return totalMatchesB - totalMatchesA ||
              totalBoardsB - totalBoardsA ||
              nameA.localeCompare(nameB);
          });

          cards.forEach(card => {
            if (opponentList) opponentList.insertBefore(card, noResults || null);
          });
        };

        const applyFilter = () => {
          sortCards();
          const selected = root.querySelector(
            'input[name="p2kOpponentStatusFilter"]:checked'
          )?.value || "all";
          const playerScope = root.querySelector(
           'ginput[name="p2kPlayerTeamScope"]:checked'
          )?.value || "either";
          const searchTerm = normalizeSearch(searchInput?.value);
          let visibleOpponents = 0;
          let visibleMatches = 0;
          let visibleBoards = 0;
          let unknownBoards = 0;

          cards.forEach(card => {
            const opponentSearch = normalizeSearch(
              card.dataset.opponentSearch ||
              card.querySelector(".p2k-opponent-name")?.textContent
            );
            const opponentMatchesSearch =
              !searchTerm || opponentSearch.includes(searchTerm);
            let cardVisibleMatches = 0;

            card.querySelectorAll(".p2k-opponent-match").forEach(matchRow => {
              const statusMatches = selected === "all" ||
                matchRow.dataset.status === "registered";
              const matchSearch = normalizeSearch(
                matchRow.dataset.matchSearch ||
                matchRow.querySelector(".p2k-match-name")?.textContent
              );
              const ownPlayers = normalizeSearch(
                matchRow.dataset.ownPlayers
              );
              const opponentPlayers = normalizeSearch(
                matchRow.dataset.opponentPlayers
              );
              const playerMatches = !searchTerm
                ? true
                : playerScope === "own"
                  ? ownPlayers.includes(searchTerm)
                  : playerScope === "opponent"
                    ? opponentPlayers.includes(searchTerm)
                    : ownPlayers.includes(searchTerm) ||
                      opponentPlayers.includes(searchTerm);
              const textMatches = !searchTerm ||
                opponentMatchesSearch ||
                matchSearch.includes(searchTerm) ||
                playerMatches;
              const showMatch = statusMatches && textMatches;

              matchRow.hidden = !showMatch;
              if (showMatch) {
                cardVisibleMatches += 1;
                const boardCount = Number(matchRow.dataset.boardCount);
                if (matchRow.dataset.boardCount === "" || !Number.isFinite(boardCount)) {
                  unknownBoards += 1;
                } else {
                  visibleBoards += boardCount;
                }
              }
            });

            const showCard = cardVisibleMatches > 0;
            card.hidden = !showCard;

            if (showCard) {
              visibleOpponents += 1;
              visibleMatches += cardVisibleMatches;
            }
          });

          if (noResults) {
            noResults.hidden = visibleOpponents !== 0;
          }

          if (visibleSummary) {
            const searchSuffix = searchTerm
              ? ` matching “${searchInput.value.trim()}”`
              : "";
            const boardText = formatBoards({
              total: visibleBoards,
              unknown: unknownBoards
            });
            visibleSummary.textContent =
              `Showing ${visibleOpponents} team${visibleOpponents === 1 ? "" : "s"}${searchSuffix}, ` +
              `${visibleMatches} match${visibleMatches === 1 ? "" : "es"} and ${boardText} boards. ` +
              "Click a team to list its matches.";
          }
        };

        filters.forEach(filter => {
          filter.addEventListener("change", applyFilter);
        });

        orderInputs.forEach(input => {
          input.addEventListener("change", applyFilter);
        });

        playerScopeInputs.forEach(input => {
          input.addEventListener("change", applyFilter);
        });

        searchInput?.addEventListener("input", applyFilter);
        searchInput?.addEventListener("search", applyFilter);

        root.querySelectorAll("[data-opponent-action]").forEach(button => {
          button.addEventListener("click", () => {
            const shouldExpand = button.dataset.opponentAction === "expand";
            cards.forEach(card => {
              if (shouldExpand && card.hidden) return;
              card.open = shouldExpand;
            });
          });
        });

        applyFilter();
      }

      function bindTabs() {
        const tabButtons = [...root.querySelectorAll(".p2k-tab-button")];
        const tabPanels = [...root.querySelectorAll(".p2k-tab-panel")];

        const activateTab = tabName => {
          tabButtons.forEach(button => {
            const isActive = button.dataset.tab === tabName;
            button.setAttribute("aria-selected", String(isActive));
            button.tabIndex = isActive ? 0 : -1;
          });

          tabPanels.forEach(panel => {
            panel.hidden = panel.dataset.tabPanel !== tabName;
          });
        };

        tabButtons.forEach((button, index) => {
          button.addEventListener("click", () => {
            activateTab(button.dataset.tab);
          });

          button.addEventListener("keydown", event => {
            if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") {
              return;
            }

            event.preventDefault();
            const direction = event.key === "ArrowRight" ? 1 : -1;
            const nextIndex =
              (index + direction + tabButtons.length) % tabButtons.length;
            tabButtons[nextIndex].focus();
            activateTab(tabButtons[nextIndex].dataset.tab);
          });
        });

        activateTab("creation");
      }

      function chartSVG(dateKeys, values, average, mode, bucketCounts = []) {
        const maxValue = Math.max(1, ...values, average);
        const tickStep = Math.max(1, Math.ceil(maxValue / 4));
        const axisMax = tickStep * 4;
        const unit = mode === "boards" ? "board" : "started match";

        const width = 960;
        const height = 340;
        const left = 52;
        const right = 18;
        const top = 24;
        const bottom = 58;
        const chartWidth = width - left - right;
        const chartHeight = height - top - bottom;
        const slotWidth = chartWidth / dateKeys.length;
        const barWidth = Math.max(5, slotWidth * .58);

        const gridLines = [];
        for (let index = 0; index <= 4; index += 1) {
          const value = tickStep * index;
          const y = top + chartHeight - (value / axisMax) * chartHeight;

          gridLines.push(`
            <line
              x1="${left}"
              y1="${y.toFixed(2)}"
              x2="${width - right}"
              y2="${y.toFixed(2)}"
              stroke="rgba(255,255,255,.12)"
              stroke-width="1"
            />
            <text
              x="${left - 8}"
              y="${(y + 4).toFixed(2)}"
              fill="#aaa198"
              font-size="11"
              text-anchor="end"
            >${value}</text>
          `);
        }

        const bars = dateKeys.map((key, index) => {
          const value = values[index];
          const bucketCount = Number(bucketCounts[index] || 0);
          const barHeight = (value / axisMax) * chartHeight;
          const x = left + index * slotWidth +
            (slotWidth - barWidth) / 2;
          const y = top + chartHeight - barHeight;
          const showLabel =
            index === 0 ||
            index === dateKeys.length - 1 ||
            index % 3 === 0;

          return `
            <g
              class="${bucketCount > 0 ? "p2k-chart-bar" : ""}"
              data-chart-scope="started"
              data-chart-date="${escapeHTML(key)}"
              data-chart-count="${bucketCount}"
              ${bucketCount > 0 ? 'role="button" tabindex="0"' : ""}
              aria-label="${escapeHTML(formatDate(key))}: ${bucketCount} corresponding match${bucketCount === 1 ? "" : "es"}${bucketCount > 0 ? ". Select to list matches." : ""}"
            >
              <title>${escapeHTML(formatDate(key))}: ${value} ${unit}${value === 1 ? "" : "s"}${bucketCount > 0 ? "; select to list matches" : ""}</title>
              ${bucketCount > 0 ? `
                <rect
                  class="p2k-chart-bar-hit"
                  x="${(left + index * slotWidth).toFixed(2)}"
                  y="${top}"
                  width="${slotWidth.toFixed(2)}"
                  height="${chartHeight.toFixed(2)}"
                  fill="transparent"
                />
              ` : ""}
              <rect
                x="${x.toFixed(2)}"
                y="${y.toFixed(2)}"
                width="${barWidth.toFixed(2)}"
                height="${Math.max(0, barHeight).toFixed(2)}"
                rx="2"
                fill="#d98d18"
              />
              ${value > 0 ? `
                <text
                  x="${(x + barWidth / 2).toFixed(2)}"
                  y="${Math.max(top + 10, y - 5).toFixed(2)}"
                  fill="#ffd078"
                  font-size="10"
                  font-weight="700"
                  text-anchor="middle"
                >${value}</text>
              ` : ""}
              ${showLabel ? `
                <text
                  x="${(x + barWidth / 2).toFixed(2)}"
                  y="${height - 28}"
                  fill="#aaa198"
                  font-size="10"
                  text-anchor="middle"
                >${escapeHTML(formatShortDate(key))}</text>
              ` : ""}
            </g>
          `;
        }).join("");

        const averageY =
          top + chartHeight - (average / axisMax) * chartHeight;

        return `
          ${gridLines.join("")}
          <line
            x1="${left}"
            y1="${top + chartHeight}"
            x2="${width - right}"
            y2="${top + chartHeight}"
            stroke="rgba(255,255,255,.28)"
            stroke-width="1"
          />
          ${bars}
          <line
            x1="${left}"
            y1="${averageY.toFixed(2)}"
            x2="${width - right}"
            y2="${averageY.toFixed(2)}"
            stroke="#91e09a"
            stroke-width="2"
            stroke-dasharray="7 5"
          />
          <text
            x="${width - right - 4}"
            y="${Math.max(top + 12, averageY - 6).toFixed(2)}"
            fill="#91e09a"
            font-size="11"
            font-weight="700"
            text-anchor="end"
          >Average: ${average.toFixed(2)}</text>
        `;
      }

      function renderChartSection(chartData) {
        return `
          <div class="p2k-section">
            <div class="p2k-section-title-row">
              <div class="p2k-chart-heading">
                <div class="p2k-section-title">
                  Started matches — last ${DAYS_CHART} days
                </div>
                <div id="p2kChartAverage" class="p2k-chart-average"></div>
              </div>
              <div class="p2k-chart-toggle" role="group" aria-label="Chart data">
                <button
                  class="p2k-chart-toggle-button"
                  type="button"
                  data-chart-mode="matches"
                  aria-pressed="true"
                >Matches</button>
                <button
                  class="p2k-chart-toggle-button"
                  type="button"
                  data-chart-mode="boards"
                  aria-pressed="false"
                >Boards</button>
              </div>
            </div>
            <div class="p2k-chart-wrap">
              <svg
                id="p2kCreationChart"
                class="p2k-chart"
                viewBox="0 0 960 340"
                role="img"
              ></svg>
            </div>
            <div class="p2k-chart-legend">
              <span><i class="p2k-swatch"></i><b id="p2kChartLegendLabel">Started matches</b></span>
              <span><i class="p2k-average-line"></i>30-day daily average</span>
            </div>
            <div class="p2k-note">
              The period includes today and days with zero started matches.
              Dates are calculated in UTC.
            </div>
          </div>
        `;
      }

      function bindChartToggle(chartData) {
        const svg = root.querySelector("#p2kCreationChart");
        const averageLabel = root.querySelector("#p2kChartAverage");
        const legendLabel = root.querySelector("#p2kChartLegendLabel");
        const buttons = [...root.querySelectorAll("[data-chart-mode]")];

        const draw = mode => {
          const isBoards = mode === "boards";
          const values = isBoards
            ? chartData.boardValues
            : chartData.matchValues;
          const average = isBoards
            ? chartData.averageBoards
            : chartData.averageMatches;
          const noun = isBoards ? "boards" : "matches";

          svg.innerHTML = chartSVG(
            chartData.dateKeys,
            values,
            average,
            mode,
            chartData.matchValues
          );
          svg.setAttribute(
            "aria-label",
            `Bar chart of ${noun} started during the last ${DAYS_CHART} days`
          );
          averageLabel.textContent =
            `Average: ${average.toFixed(2)} started ${noun} per day`;
          legendLabel.textContent = isBoards
            ? "Started-match boards"
            : "Started matches";

          buttons.forEach(button => {
            button.setAttribute(
              "aria-pressed",
              String(button.dataset.chartMode === mode)
            );
          });
        };

        buttons.forEach(button => {
          button.addEventListener("click", () => {
            draw(button.dataset.chartMode);
          });
        });

        draw("matches");
      }

      function captureViewState() {
        if (!resultsBox.innerHTML) return null;

        return {
          activeTab: root.querySelector('.p2k-tab-button[aria-selected="true"]')?.dataset.tab || "creation",
          opponentSearch: root.querySelector("#p2kOpponentSearch")?.value || "",
          opponentFilter: root.querySelector('input[name="p2kOpponentStatusFilter"]:checked')?.value || "all",
          opponentOrder: root.querySelector('input[name="p2kOpponentOrder"]:checked')?.value || "matches",
          playerTeamScope: root.querySelector('input[name="p2kPlayerTeamScope"]:checked')?.value || "either",
          chartMode: root.querySelector('[data-chart-mode][aria-pressed="true"]')?.dataset.chartMode || "matches",
          expandedOpponents: [...root.querySelectorAll(".p2k-opponent-card[open]")]
            .map(card => card.dataset.opponentKey)
            .filter(Boolean)
        };
      }

      function restoreViewState(state) {
        if (!state) return;

        const tabButton = root.querySelector(
          `.p2k-tab-button[data-tab="${state.activeTab}"]`
        );
        tabButton?.click();

        const searchInput = root.querySelector("#p2kOpponentSearch");
        if (searchInput) searchInput.value = state.opponentSearch;

        const filter = root.querySelector(
          `input[name="p2kOpponentStatusFilter"][value="${state.opponentFilter}"]`
        );
        if (filter) filter.checked = true;

        const order = root.querySelector(
          `input[name="p2kOpponentOrder"][value="${state.opponentOrder || "matches"}"]`
        );
        if (order) order.checked = true;

        const playerScope = root.querySelector(
          `input[name="p2kPlayerTeamScope"][value="${state.playerTeamScope || "either"}"]`
        );
        if (playerScope) playerScope.checked = true;

        searchInput?.dispatchEvent(new Event("input", { bubbles: true }));

        const expanded = new Set(state.expandedOpponents || []);
        root.querySelectorAll(".p2k-opponent-card").forEach(card => {
          card.open = expanded.has(card.dataset.opponentKey);
        });

        const chartButton = root.querySelector(
          `[data-chart-mode="${state.chartMode}"]`
        );
        chartButton?.click();
      }

      function renderAnalysis(records, analysisState = { phase: "basic" }) {
        const viewState = captureViewState();
        const chartKeys = dateRangeKeys(DAYS_CHART);
        const chartKeySet = new Set(chartKeys);
        const startedRecords = [
          ...records.inProgress,
          ...records.finished
        ];
        const recentStartedRecords = startedRecords.filter(record =>
          chartKeySet.has(dateKeyFromTimestamp(record.startTime))
        );
        const chartAggregates = aggregateByDate(
          recentStartedRecords,
          chartKeys
        );
        const matchValues = chartKeys.map(key =>
          chartAggregates.get(key).matches
        );
        const boardValues = chartKeys.map(key =>
          chartAggregates.get(key).boards
        );
        const startedMatchesLast30 = matchValues.reduce(
          (total, value) => total + value,
          0
        );
        const startedBoardsLast30 = boardValues.reduce(
          (total, value) => total + value,
          0
        );
        const unknownRecentBoards = chartKeys.reduce(
          (total, key) => total + chartAggregates.get(key).unknownBoards,
          0
        );
        const averageMatches = startedMatchesLast30 / DAYS_CHART;
        const averageBoards = startedBoardsLast30 / DAYS_CHART;
        const registrationBoards = sumBoards(records.registered);
        const progressBoards = sumBoards(records.inProgress);
        const opponentGroups = buildOpponentGroups(
          records.registered,
          records.inProgress
        );

        const chartData = {
          dateKeys: chartKeys,
          matchValues,
          boardValues,
          averageMatches,
          averageBoards
        };

        const chartRecord = record => ({
          name: String(record?.name || "Unnamed match"),
          webUrl: String(record?.webUrl || ""),
          opponentName: String(record?.opponentName || "Unknown opponent"),
          dateKey: dateKeyFromTimestamp(record?.startTime),
          startTime: Number(record?.startTime || 0) || null,
          boards: record?.boards === null ? null : Number(record?.boards || 0),
          status: String(record?.status || ""),
          detailState: String(record?.detailState || "pending"),
          apiUrl: String(record?.apiUrl || record?.summary?.["@id"] || "")
        });
        window.P2K_MATCH_CREATION_CHART_BUCKETS = {
          registration: records.registered.map(chartRecord),
          started: recentStartedRecords.map(chartRecord)
        };

        const allRecords = [
          ...records.registered,
          ...records.inProgress,
          ...records.finished
        ];
        const pendingCount = allRecords.filter(
          record => record.detailState === "pending"
        ).length;
        const failedCount = allRecords.filter(
          record => record.detailState === "failed"
        ).length;
        const loadedCount = allRecords.filter(
          record => record.detailState === "loaded"
        ).length;
        const knownRecentBoards = recentStartedRecords.filter(
          record => record.boards !== null
        ).length;
        const recentDetailsComplete = recentStartedRecords.every(
          record => record.detailState !== "pending"
        );
        const pausedWithRecentData =
          analysisState.phase === "paused" && knownRecentBoards > 0;

        let detailNote = "";
        if (analysisState.phase === "basic") {
          detailNote = `
            <div class="p2k-detail-note">
              <strong>Basic daily-match statistics are loaded automatically.</strong>
              Board counts are shown as unavailable until you select
              <strong>Detailed analyzis</strong>.
            </div>
          `;
        } else if (analysisState.phase === "registrationComplete") {
          detailNote = `
            <div class="p2k-detail-note">
              <strong>Registration details are complete.</strong>
              Registration board totals have been updated. The 30-day match
              creation statistics will update after all recent matches are retrieved.
            </div>
          `;
        } else if (analysisState.phase === "recentComplete") {
          detailNote = `
            <div class="p2k-detail-note">
              <strong>The 30-day match creation data is complete.</strong>
              The chart and both daily averages now cover exactly the latest
              ${DAYS_CHART} calendar days. Remaining requests complete older
              opponent statistics.
            </div>
          `;
        } else if (analysisState.phase === "loading") {
          detailNote = `
            <div class="p2k-detail-note">
              <strong>Detailed analyzis is running.</strong>
              Statistics remain unchanged until the next completed data group.
            </div>
          `;
        } else if (
          analysisState.phase === "paused" ||
          analysisState.phase === "cancelled"
        ) {
          detailNote = `
            <div class="p2k-detail-note">
              <strong>Detailed analyzis is paused.</strong>
              The ${loadedCount} successfully loaded match details remain visible;
              ${pendingCount} are still pending${failedCount ? ` and ${failedCount} failed` : ""}.
              Select <strong>Resume analyzis</strong> to continue from this point.
            </div>
          `;
        } else if (failedCount > 0 || pendingCount > 0) {
          detailNote = `
            <div class="p2k-data-warning">
              ${failedCount} match detail request${failedCount === 1 ? "" : "s"} failed
              and ${pendingCount} match detail${pendingCount === 1 ? " is" : "s are"} unavailable.
              Board totals exclude those matches.
            </div>
          `;
        }

        const showBoardAverage =
          recentDetailsComplete || pausedWithRecentData;
        const averageBoardsDisplay = showBoardAverage
          ? averageBoards.toFixed(2)
          : "—";
        const boardAverageSubvalue = !showBoardAverage
          ? `Available after the ${DAYS_CHART}-day matches are retrieved`
          : unknownRecentBoards > 0
            ? `${startedBoardsLast30} known boards in the last ${DAYS_CHART} days; ${unknownRecentBoards} match${unknownRecentBoards === 1 ? "" : "es"} pending/unavailable`
            : `${startedBoardsLast30} boards in the last ${DAYS_CHART} days`;

        resultsBox.innerHTML = `
          <div class="p2k-tabs" role="tablist" aria-label="Analyzer displays">
            <button
              class="p2k-tab-button"
              type="button"
              role="tab"
              aria-selected="true"
              aria-controls="p2kCreationTabPanel"
              data-tab="creation"
            >Match creation</button>
            <button
              class="p2k-tab-button"
              type="button"
              role="tab"
              aria-selected="false"
              aria-controls="p2kOpponentsTabPanel"
              data-tab="opponents"
              tabindex="-1"
            >Teams and players</button>
            <button
              class="p2k-tab-button"
              type="button"
              role="tab"
              aria-selected="false"
              aria-controls="p2kScoringTabPanel"
              data-tab="scoring"
              tabindex="-1"
            >Scoring</button>
          </div>

          <div
            id="p2kCreationTabPanel"
            class="p2k-tab-panel"
            role="tabpanel"
            data-tab-panel="creation"
          >
            <div class="p2k-metrics">
              ${renderMetric(
                records.registered.length,
                "Matches in registration"
              )}
              ${renderMetric(
                records.inProgress.length,
                "Matches in progress"
              )}
              ${renderMetric(
                averageMatches.toFixed(2),
                "Average started matches/day",
                `${startedMatchesLast30} during ${DAYS_CHART} days`
              )}
              ${renderMetric(
                formatBoards(registrationBoards, true),
                "Boards in registration"
              )}
              ${renderMetric(
                formatBoards(progressBoards, true),
                "Boards in progress"
              )}
              ${renderMetric(
                averageBoardsDisplay,
                "Average started boards/day",
                boardAverageSubvalue
              )}
            </div>
            ${detailNote}
            ${renderRegistrationTable(records.registered)}
            ${renderChartSection(chartData)}
          </div>

          <div
            id="p2kOpponentsTabPanel"
            class="p2k-tab-panel"
            role="tabpanel"
            data-tab-panel="opponents"
            hidden
          >
            ${renderOpponents(opponentGroups)}
            ${detailNote}
          </div>

          <div
            id="p2kScoringTabPanel"
            class="p2k-tab-panel"
            role="tabpanel"
            data-tab-panel="scoring"
            hidden
          >
            ${renderScoring(records, analysisState)}
            ${detailNote}
          </div>
        `;

        resultsBox.style.display = "block";
        bindTabs();
        bindOpponentControls();
        bindChartToggle(chartData);
        restoreViewState(viewState);
      }

      function chartBucketSourceRecords(scope, dateKey) {
        if (!currentRecords) return [];
        const source = scope === "registration"
          ? currentRecords.registered
          : [...currentRecords.inProgress, ...currentRecords.finished];
        return source.filter(record => dateKeyFromTimestamp(record.startTime) === String(dateKey || ""));
      }

      async function loadTargetedChartDetails(scope, dateKey, onProgress = null) {
        if (isDetailedAnalysisRunning) return { ok: false, busy: true, requested: 0, loaded: 0, failed: 0 };
        const bucket = chartBucketSourceRecords(scope, dateKey);
        const missing = bucket.filter(record => record.detailState !== "loaded" || record.boards === null);
        if (!missing.length) return { ok: true, requested: 0, loaded: 0, failed: 0, cached: bucket.length };
        let loaded = 0, failed = 0;
        const total = missing.length;
        onProgress?.({ current: 0, total, message: `Loading board detail 0 / ${total}` });
        const detailBatch = await window.P2K_API_CLIENT.processPriority(
          missing,
          async record => {
            if (record.detailState === "failed") Object.assign(record, createRecord(record.summary, record.status, null, null));
            const detail = await loadJSON(record.summary?.["@id"] || record.apiUrl, null, null);
            return { record, detail };
          },
          {
            getKey: (record, index) => String(record.summary?.["@id"] || record.apiUrl || index),
            onProgress: progress => onProgress?.({ current: progress.settled, total: progress.total, message: `Loading board detail ${progress.settled} / ${progress.total}` })
          }
        );
        for (const entry of detailBatch.succeeded) {
          Object.assign(entry.value.record, createRecord(entry.value.record.summary, entry.value.record.status, entry.value.detail, null));
          loaded += 1;
        }
        for (const entry of detailBatch.failures) {
          console.error(entry.error);
          Object.assign(entry.item, createRecord(entry.item.summary, entry.item.status, null, entry.error));
          failed += 1;
        }
        currentRecords.failed = [...currentRecords.registered, ...currentRecords.inProgress, ...currentRecords.finished].filter(record => record.detailState === "failed").length;
        renderAnalysis(currentRecords, { phase: "targeted", targetedScope: scope, targetedDate: dateKey });
        return { ok: failed === 0, requested: total, loaded, failed, cached: bucket.length - total };
      }

      window.P2K_MATCH_CREATION_TARGETED_DETAIL = Object.freeze({
        load: loadTargetedChartDetails,
        bucket: chartBucketSourceRecords
      });

      async function loadBasicStatistics() {
        isDetailedAnalysisPaused = false;
        analyzeButton.disabled = true;
        analyzeButton.textContent = "Detailed analyzis";
        setStatus("Loading basic daily match statistics...");

        try {
          basicData = await loadJSON(MATCH_LIST_URL);
          currentRecords = buildBasicRecords(basicData);
          renderAnalysis(currentRecords, { phase: "basic" });
          setStatus(
            "Basic daily statistics loaded. Select Detailed analyzis to load board counts and scoring projections.",
            "success"
          );
          analyzeButton.disabled = false;
        } catch (error) {
          console.error(error);
          setStatus(
            "Unable to load the Chess.com match list. Reload the page to try again.",
            "error"
          );
          attachAnalysisFailures([{ error, url: MATCH_LIST_URL, phase: "Match list", matchName: "Club match list" }], "Match Creation analysis failure");
        }
      }

      async function runDetailedAnalysis(targetStage = "all", options = {}) {
        const synchronized = options?.synchronized === true;
        if (isDetailedAnalysisRunning) return;

        if (!basicData || !currentRecords) {
          await loadBasicStatistics();
          if (!basicData || !currentRecords) return;
        }

        const resume = isDetailedAnalysisPaused;
        activeRunId += 1;
        const runId = activeRunId;
        cancelRequested = false;
        isDetailedAnalysisRunning = true;
        isDetailedAnalysisPaused = false;
        activeController = new AbortController();
        analyzeButton.disabled = true;
        analyzeButton.textContent = resume ? "Resuming..." : "Analyzing...";

        if (!resume) {
          // A new analysis starts from clean basic records.
          currentRecords = buildBasicRecords(basicData);
        }

        const initialPlan = detailQueuePlan(currentRecords);
        const initialQueue = targetStage === "registration"
          ? initialPlan.registration
          : targetStage === "last30"
            ? [...initialPlan.registration, ...initialPlan.last30]
            : initialPlan.all;
        if (resume) {
          initialQueue
            .filter(record => record.detailState === "failed")
            .forEach(record => {
              Object.assign(
                record,
                createRecord(record.summary, record.status, null, null)
              );
            });
        }
        const initialTotal = initialQueue.length;
        const initialProcessed = initialQueue.filter(
          record => record.detailState !== "pending"
        ).length;
        const firstPendingRecord = initialQueue.find(
          record => record.detailState === "pending"
        );
        const initialStage = firstPendingRecord
          ? progressStageForRecord(firstPendingRecord, initialPlan)
          : targetStage === "registration"
            ? "registration"
            : targetStage === "last30"
              ? "last30"
              : "remaining";

        updateProgressStages(initialPlan, initialStage, true);
        setStatus(
          `${progressStageLabel(initialStage)} — match ${Math.min(initialProcessed + 1, initialTotal)} of ${initialTotal}`,
          "working",
          { current: initialProcessed, total: initialTotal },
          true
        );

        try {
          await loadDetailedRecords(currentRecords, runId, resume, targetStage);
          throwIfCancelled(runId);

          if (targetStage !== "all") {
            const completedPlan = detailQueuePlan(currentRecords);
            isDetailedAnalysisPaused = completedPlan.all.some(
              record => record.detailState === "pending" || record.detailState === "failed"
            );
            updateProgressStages(completedPlan, null, true);
            const completedPhase = targetStage === "registration"
              ? "registrationComplete"
              : "recentComplete";
            renderAnalysis(currentRecords, { phase: completedPhase });
            const completedLabel = targetStage === "registration"
              ? "Step 1 — Registration"
              : "Step 2 — Last 30 days";
            setStatus(
              completedLabel + " analysis complete. Loaded data is retained; choose another step or Resume analyzis to continue.",
              "success"
            );
            return;
          }
          updateProgressStages(
            detailQueuePlan(currentRecords),
            null,
            true
          );
          renderAnalysis(currentRecords, { phase: "complete" });
          hasDetailedAnalysisCompleted = true;

          if (currentRecords.failed > 0) {
            isDetailedAnalysisPaused = true;
            setStatus(
              `Detailed analyzis complete with ${currentRecords.failed} unavailable match detail${currentRecords.failed === 1 ? "" : "s"}. Loaded results are retained; retry only the failed requests.`,
              "warning"
            );
            attachAnalysisFailures(analysisFailureItems(), "Match Creation analysis failures");
          } else {
            setStatus("Detailed analyzis complete.", "success");
          }
          window.P2K_ANALYSIS_COORDINATOR?.complete?.("creation", {
            synchronized,
            failedCount: currentRecords.failed,
            processedAt: Date.now()
          });
        } catch (error) {
          if (error instanceof CancelledError) {
            isDetailedAnalysisPaused = true;
            updateProgressStages(
              detailQueuePlan(currentRecords),
              null,
              true
            );
            renderAnalysis(currentRecords, { phase: "paused" });
            setStatus(
              `Detailed analyzis paused after ${currentRecords.processed} of ${currentRecords.requested} matches. Loaded data is retained; select Resume analyzis to continue.`,
              "warning",
              {
                current: currentRecords.processed,
                total: currentRecords.requested
              }
            );
          } else {
            console.error(error);
            isDetailedAnalysisPaused = true;
            updateProgressStages(
              detailQueuePlan(currentRecords),
              null,
              true
            );
            renderAnalysis(currentRecords, { phase: "paused" });
            setStatus(
              "Detailed match data could not be fully loaded. Partial results are retained; select Resume analyzis to retry the pending matches.",
              "error",
              {
                current: currentRecords.processed,
                total: currentRecords.requested
              }
            );
            attachAnalysisFailures([{
              error,
              apiUrl: error?.url || "",
              phase: "Detailed analysis",
              matchName: "Match Creation analysis"
            }], "Match Creation analysis failure");
          }
        } finally {
          isDetailedAnalysisRunning = false;
          activeController = null;
          processingActions.style.display = "none";
          analyzeButton.disabled = false;
          const hasFailedDetails = Boolean(
            currentRecords && detailQueuePlan(currentRecords).all.some(
              record => record.detailState === "failed"
            )
          );
          analyzeButton.textContent = isDetailedAnalysisPaused
            ? hasFailedDetails ? "Retry failed" : "Resume analyzis"
            : hasDetailedAnalysisCompleted ? "Refresh analyzis" : "Detailed analyzis";
          analyzeButton.title = isDetailedAnalysisPaused
            ? hasFailedDetails
              ? "Retry only match detail requests that failed"
              : "Continue loading the remaining match details"
            : hasDetailedAnalysisCompleted
              ? "Refresh board counts and detailed match information"
              : "Load board counts and detailed match information";
        }
      }

      function cancelDetailedAnalysis() {
        if (!isDetailedAnalysisRunning) return;
        cancelRequested = true;
        cancelButton.disabled = true;
        activeController?.abort();
        setStatus(
          `Pausing after ${currentRecords?.processed || 0} of ${currentRecords?.requested || 0} matches...`,
          "warning",
          {
            current: currentRecords?.processed || 0,
            total: currentRecords?.requested || 0
          },
          true
        );
        cancelButton.disabled = true;
      }

      function requestedStageTarget(stageName) {
        if (stageName === "registration") return "registration";
        if (stageName === "last30") return "last30";
        return "all";
      }

      progressStageElements.forEach(stageElement => {
        stageElement.setAttribute("role", "button");
        stageElement.setAttribute("tabindex", "0");
        stageElement.setAttribute(
          "title",
          "Analyze cumulatively through this step and stop when it is complete"
        );
        const activate = () => {
          if (isDetailedAnalysisRunning) return;
          void runDetailedAnalysis(
            requestedStageTarget(stageElement.dataset.progressStage)
          );
        };
        stageElement.addEventListener("click", activate);
        stageElement.addEventListener("keydown", event => {
          if (event.key !== "Enter" && event.key !== " ") return;
          event.preventDefault();
          activate();
        });
      });

      analyzeButton.addEventListener("click", () => {
        void runDetailedAnalysis("all");
      });
      window.P2K_ANALYSIS_COORDINATOR?.register?.("creation", {
        isBusy: () => isDetailedAnalysisRunning,
        canRefresh: () => true,
        refresh: ({ synchronized = false } = {}) => runDetailedAnalysis("all", { synchronized })
      });
      cancelButton.addEventListener("click", cancelDetailedAnalysis);
      loadBasicStatistics();
    })();
