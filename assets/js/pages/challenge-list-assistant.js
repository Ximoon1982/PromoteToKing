/* Promote to King Challenge List Assistant. */
(async () => {
  "use strict";
  if (window.P2K_ADMIN_ACCESS_READY && !(await window.P2K_ADMIN_ACCESS_READY)) return;

  const API_ROOT = "https://api.chess.com/pub/club/";
  const WEB_ROOT = "https://www.chess.com/club/";
  const PREFLIGHT_SLUG = window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king";
  const REQUEST_ATTEMPTS = window.P2K_SITE_CONFIG?.api?.defaultAttempts || 3;
  const REQUEST_TIMEOUT_MS = window.P2K_SITE_CONFIG?.api?.requestTimeoutMs || 20000;
  const SERVER_LIST_ENDPOINT = window.P2K_SITE_CONFIG?.serverStorage?.challengeClubListEndpoint || "api/challenge-club-list";
  const SERVER_LIST_INPUT_IDS = Object.freeze({
    checker: "p2kCheckerInput",
    activity: "p2kActivityInput",
    recommendation: "p2kRecommendationInput"
  });
  const SERVER_LIST_SAVE_IDS = Object.freeze({
    checker: "p2kCheckerSaveList",
    activity: "p2kActivitySaveList",
    recommendation: "p2kRecommendationSaveList"
  });
  const serverListState = {
    available: false,
    exists: false,
    revision: 0,
    updatedAt: null,
    clubs: [],
    dirtyInputs: new Set(),
    busy: false
  };

  const tabs = Array.from(document.querySelectorAll("[data-p2k-tab]"));
  const panels = Array.from(document.querySelectorAll("[data-p2k-panel]"));
  const clubLogo = document.querySelector('img[alt="Promote to King club logo"]');
  clubLogo?.addEventListener("error", () => { clubLogo.hidden = true; });

  class StopError extends Error {
    constructor() {
      super("The run was stopped.");
      this.name = "StopError";
    }
  }

  function activeTabKey() {
    return tabs.find(tab => tab.getAttribute("aria-selected") === "true")?.dataset.p2kTab || "checker";
  }

  function serverListInput(key = activeTabKey()) {
    return document.getElementById(SERVER_LIST_INPUT_IDS[key] || SERVER_LIST_INPUT_IDS.checker);
  }

  function markServerListDirty(input) {
    if (input?.id) serverListState.dirtyInputs.add(input.id);
  }

  function selectTab(key) {
    tabs.forEach(tab => {
      const selected = tab.dataset.p2kTab === key;
      tab.setAttribute("aria-selected", String(selected));
      tab.tabIndex = selected ? 0 : -1;
    });
    panels.forEach(panel => { panel.hidden = panel.dataset.p2kPanel !== key; });
    updateServerListControls();
  }

  tabs.forEach((tab, index) => {
    tab.addEventListener("click", () => selectTab(tab.dataset.p2kTab));
    tab.addEventListener("keydown", event => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      let targetIndex = index;
      if (event.key === "Home") targetIndex = 0;
      else if (event.key === "End") targetIndex = tabs.length - 1;
      else targetIndex = (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
      tabs[targetIndex].focus();
      selectTab(tabs[targetIndex].dataset.p2kTab);
    });
  });

  function parseClubSlug(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    let decoded = raw;
    try { decoded = decodeURIComponent(raw); } catch (_) { /* Ignore. */ }

    try {
      const url = new URL(decoded, "https://www.chess.com");
      const parts = url.pathname.split("/").filter(Boolean);
      const pubIndex = parts.findIndex((part, index) =>
        part.toLowerCase() === "pub" && parts[index + 1]?.toLowerCase() === "club"
      );
      if (pubIndex >= 0 && parts[pubIndex + 2]) return parts[pubIndex + 2].toLowerCase();
      const clubIndex = parts.findIndex(part => part.toLowerCase() === "club");
      if (clubIndex >= 0 && parts[clubIndex + 1]) return parts[clubIndex + 1].toLowerCase();
    } catch (_) { /* Treat as plain slug. */ }

    const candidate = decoded
      .replace(/^@+/, "")
      .replace(/^\/+|\/+$/g, "")
      .split(/[?#]/, 1)[0]
      .trim()
      .toLowerCase();
    return candidate.length <= 128 && /^[a-z0-9-]+$/.test(candidate) && /[a-z0-9]/.test(candidate) ? candidate : "";
  }

  function parseInput(text) {
    const tokens = String(text || "")
      .split(/[\s,;"']+/)
      .map(value => value.trim())
      .filter(Boolean);
    const slugs = [];
    const invalid = [];
    const seen = new Set();

    tokens.forEach(token => {
      const slug = parseClubSlug(token);
      if (!slug) {
        invalid.push(token);
        return;
      }
      if (!seen.has(slug)) {
        seen.add(slug);
        slugs.push(slug);
      }
    });
    return { slugs, invalid };
  }

  function clubURL(slug) {
    return `${WEB_ROOT}${encodeURIComponent(slug)}`;
  }

  function apiURL(slug, suffix = "") {
    return `${API_ROOT}${encodeURIComponent(slug)}${suffix}`;
  }

  function sleep(ms, signal) {
    return new Promise((resolve, reject) => {
      if (signal?.aborted) {
        reject(new StopError());
        return;
      }
      const timer = window.setTimeout(() => {
        signal?.removeEventListener("abort", onAbort);
        resolve();
      }, ms);
      const onAbort = () => {
        window.clearTimeout(timer);
        reject(new StopError());
      };
      signal?.addEventListener("abort", onAbort, { once: true });
    });
  }

  async function fetchJSON(url, signal, options = {}) {
    if (!window.P2K_API_CLIENT) throw new Error("P2K_API_CLIENT is not loaded.");
    try {
      const data = await window.P2K_API_CLIENT.json(url, {
        signal,
        attempts: REQUEST_ATTEMPTS,
        timeoutMs: REQUEST_TIMEOUT_MS,
        cacheMode: options.networkOnly === true ? "network-only" : "default"
      });
      if (!data || typeof data !== "object") throw new Error("Invalid JSON response");
      return data;
    } catch (error) {
      if (signal?.aborted || error?.category === "cancelled") throw new StopError();
      throw error;
    }
  }

  function copyText(text) {
    if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(text);
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand("copy");
    textarea.remove();
    return copied ? Promise.resolve() : Promise.reject(new Error("Clipboard unavailable"));
  }

  function csvCell(value) {
    return `"${String(value ?? "").replace(/"/g, '""')}"`;
  }

  function downloadCSV(filename, headers, rows) {
    const content = [headers, ...rows]
      .map(row => row.map(csvCell).join(","))
      .join("\r\n");
    const blob = new Blob([content], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  function formatDate(date) {
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
      year: "numeric", month: "short", day: "2-digit"
    }).format(date);
  }

  function sixMonthCutoff() {
    const now = new Date();
    const cutoff = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
    cutoff.setUTCMonth(cutoff.getUTCMonth() - 6);
    return cutoff;
  }

  function buildRunner(prefix, classify, options = {}) {
    const input = document.getElementById(`${prefix}Input`);
    const file = document.getElementById(`${prefix}File`);
    const fileName = document.getElementById(`${prefix}FileName`);
    const start = document.getElementById(`${prefix}Start`);
    const pause = document.getElementById(`${prefix}Pause`);
    const stop = document.getElementById(`${prefix}Stop`);
    const clear = document.getElementById(`${prefix}Clear`);
    const total = document.getElementById(`${prefix}Total`);
    const checked = document.getElementById(`${prefix}Checked`);
    const matches = document.getElementById(`${prefix}Matches`);
    const invalid = document.getElementById(`${prefix}Invalid`);
    const progress = document.getElementById(`${prefix}Progress`);
    const current = document.getElementById(`${prefix}Current`);
    const status = document.getElementById(`${prefix}Status`);
    const output = document.getElementById(`${prefix}Output`);
    const details = document.getElementById(`${prefix}Details`);
    const copy = document.getElementById(`${prefix}Copy`);
    const download = document.getElementById(`${prefix}Download`);
    const cutoffBox = document.getElementById(`${prefix}Cutoff`);

    const state = {
      controller: null,
      paused: false,
      pauseWaiters: [],
      results: [],
      detailRows: [],
      invalidInputs: [],
      sourceText: "",
      temporaryFailures: []
    };

    function parsed() {
      return parseInput(input.value);
    }

    function updateStartAvailability() {
      start.disabled = Boolean(state.controller) || (start.dataset.p2kAction === "retry-temporary" ? state.temporaryFailures.length === 0 : parsed().slugs.length === 0);
    }

    function updateButtons(running) {
      start.disabled = running || (start.dataset.p2kAction === "retry-temporary" ? state.temporaryFailures.length === 0 : parsed().slugs.length === 0);
      pause.disabled = !running;
      stop.disabled = !running;
      clear.disabled = running;
      file.disabled = running;
      input.disabled = running;
      updateServerListControls();
    }

    function setStatus(message, kind = "") {
      status.textContent = message;
      status.classList.toggle("p2k-error", kind === "error");
      status.classList.toggle("p2k-success", kind === "success");
    }

    function renderOutput() {
      output.value = state.results.map(item => item.url).join("\n");
      details.value = state.detailRows.join("\n");
      copy.disabled = state.results.length === 0;
      download.disabled = state.results.length === 0;
      matches.textContent = String(state.results.length);
      invalid.textContent = String(state.invalidInputs.length + state.detailRows.filter(line => line.startsWith("INVALID DATA:")).length);
    }

    function resetResults() {
      state.results = [];
      state.detailRows = [];
      state.invalidInputs = [];
      state.temporaryFailures = [];
      start.dataset.p2kAction = "start";
      start.textContent = "Start checking";
      total.textContent = "0";
      checked.textContent = "0";
      matches.textContent = "0";
      invalid.textContent = "0";
      progress.style.width = "0%";
      current.textContent = "Ready.";
      output.value = "";
      details.value = "";
      copy.disabled = true;
      download.disabled = true;
      if (cutoffBox) cutoffBox.textContent = "";
    }

    async function waitWhilePaused(signal) {
      while (state.paused) {
        await new Promise((resolve, reject) => {
          const onAbort = () => reject(new StopError());
          signal.addEventListener("abort", onAbort, { once: true });
          state.pauseWaiters.push(() => {
            signal.removeEventListener("abort", onAbort);
            resolve();
          });
        });
      }
    }

    function releasePause() {
      state.pauseWaiters.splice(0).forEach(resolve => resolve());
    }

    async function run() {
      if (state.controller) return;
      const retryTemporary = start.dataset.p2kAction === "retry-temporary";
      const parsedInput = parsed();
      const runSlugs = retryTemporary
        ? state.temporaryFailures.map(item => item.slug)
        : parsedInput.slugs;
      if (runSlugs.length === 0) return;

      if (!retryTemporary) {
        resetResults();
        state.invalidInputs = parsedInput.invalid;
        state.invalidInputs.forEach(value => state.detailRows.push(`INVALID INPUT: ${value}`));
      } else {
        state.temporaryFailures = [];
        state.detailRows = state.detailRows.filter(line => !line.startsWith("TEMPORARY FAILURE:"));
      }
      total.textContent = String(runSlugs.length);
      invalid.textContent = String(state.invalidInputs.length);
      const controller = new AbortController();
      state.controller = controller;
      state.paused = false;
      pause.textContent = "Pause";
      updateButtons(true);

      const context = options.makeContext ? options.makeContext() : {};
      if (cutoffBox && context.cutoff) {
        cutoffBox.textContent = `Six-month cutoff: ${formatDate(context.cutoff)} (UTC)`;
      }

      try {
        setStatus("Checking Chess.com API availability…");
        current.textContent = apiURL(PREFLIGHT_SLUG);
        await fetchJSON(apiURL(PREFLIGHT_SLUG), controller.signal);

        const classificationBatch = await window.P2K_API_CLIENT.processPriority(
          runSlugs,
          async (slug, index) => {
            await waitWhilePaused(controller.signal);
            return { slug, index, result: await classify(slug, controller.signal, context) };
          },
          {
            signal: controller.signal,
            getKey: slug => slug,
            onProgress: progressState => {
              checked.textContent = String(progressState.settled);
              progress.style.width = `${Math.round((progressState.settled / runSlugs.length) * 100)}%`;
              current.textContent = `${progressState.settled} / ${runSlugs.length} checked`;
              setStatus(`Checking clubs… ${progressState.settled} of ${runSlugs.length}`);
            }
          }
        );
        classificationBatch.succeeded.sort((a,b)=>a.index-b.index).forEach(entry => {
          const { slug, result } = entry.value;
          const normalURL = clubURL(slug);
          if (result.temporary) state.temporaryFailures.push({ slug, url: normalURL, ...result });
          else if (result.match) {
            const existingIndex = state.results.findIndex(item => item.slug === slug);
            const value = { slug, url: normalURL, sourceIndex: entry.index, ...result };
            if (existingIndex >= 0) state.results[existingIndex] = value;
            else state.results.push(value);
          } else if (retryTemporary) state.results = state.results.filter(item => item.slug !== slug);
          if (result.detail) state.detailRows.push(result.detail);
        });
        classificationBatch.failures.sort((a,b)=>a.index-b.index).forEach(entry => {
          const slug = entry.item; const normalURL = clubURL(slug);
          const described = window.P2K_API_CLIENT?.describeError?.(entry.error) || { category:"unknown", retryable:true, message:entry.error?.message || String(entry.error) };
          state.temporaryFailures.push({ slug, url: normalURL, reason: described.message, category: described.category });
          state.detailRows.push(`TEMPORARY FAILURE: ${normalURL} — ${described.message}`);
        });
        state.results.sort((a,b)=>(Number(a.sourceIndex)||0)-(Number(b.sourceIndex)||0));
        renderOutput();
        if (classificationBatch.cancelled || controller.signal.aborted) throw new StopError();

        current.textContent = "Complete.";
        if (state.temporaryFailures.length) {
          setStatus(
            `Complete: ${state.results.length} confirmed result${state.results.length === 1 ? "" : "s"}; ${state.temporaryFailures.length} temporary failure${state.temporaryFailures.length === 1 ? "" : "s"} can be retried.`,
            "success"
          );
          start.dataset.p2kAction = "retry-temporary";
          start.textContent = "Retry temporary failures";
        } else {
          setStatus(`Complete: ${state.results.length} matching club${state.results.length === 1 ? "" : "s"}.`, "success");
          start.dataset.p2kAction = "start";
          start.textContent = "Start checking";
        }
      } catch (error) {
        if (error instanceof StopError) {
          current.textContent = "Stopped.";
          setStatus(`Stopped after ${checked.textContent} checked club${checked.textContent === "1" ? "" : "s"}.`);
        } else {
          console.error(error);
          current.textContent = "Preflight failed.";
          setStatus(`The batch was not classified: ${error.message || error}`, "error");
        }
      } finally {
        state.controller = null;
        state.paused = false;
        releasePause();
        updateButtons(false);
        renderOutput();
      }
    }

    input.addEventListener("input", event => {
      if (event.isTrusted) markServerListDirty(input);
      updateStartAvailability();
    });
    file.addEventListener("change", async () => {
      const selected = file.files?.[0];
      if (!selected) {
        fileName.textContent = "No file selected";
        return;
      }
      fileName.textContent = selected.name;
      try {
        input.value = await selected.text();
        markServerListDirty(input);
        updateStartAvailability();
      } catch (error) {
        setStatus(`Unable to read the file: ${error.message || error}`, "error");
      }
    });

    start.addEventListener("click", () => void run());
    pause.addEventListener("click", () => {
      if (!state.controller) return;
      state.paused = !state.paused;
      pause.textContent = state.paused ? "Resume" : "Pause";
      setStatus(state.paused ? "Paused." : "Resuming…");
      if (!state.paused) releasePause();
    });
    stop.addEventListener("click", () => state.controller?.abort());
    clear.addEventListener("click", () => {
      input.value = "";
      markServerListDirty(input);
      file.value = "";
      fileName.textContent = "No file selected";
      resetResults();
      setStatus("Load a list and start checking.");
      updateStartAvailability();
    });
    copy.addEventListener("click", async () => {
      try {
        await copyText(output.value);
        setStatus("URLs copied to the clipboard.", "success");
      } catch (error) {
        setStatus(`Unable to copy: ${error.message || error}`, "error");
      }
    });
    download.addEventListener("click", () => {
      const filename = options.filename || "challenge-list-results.csv";
      downloadCSV(filename, options.csvHeaders || ["club_url", "reason"], state.results.map(item => options.csvRow ? options.csvRow(item) : [item.url, item.reason || ""]));
    });

    resetResults();
    updateStartAvailability();
  }

  buildRunner("p2kChecker", async (slug, signal) => {
    const url = apiURL(slug);
    try {
      const data = await fetchJSON(url, signal);
      const valid = typeof data?.name === "string" || typeof data?.url === "string" || typeof data?.["@id"] === "string";
      if (!valid) {
        return {
          match: true,
          reason: "Invalid response",
          detail: `${clubURL(slug)} — invalid club response`
        };
      }
      return { match: false };
    } catch (error) {
      if (error instanceof StopError) throw error;
      const described = window.P2K_API_CLIENT?.describeError?.(error) || {
        category: "unknown",
        status: Number(error?.status) || 0,
        retryable: true,
        message: error.message || String(error)
      };
      if (described.category === "not-found" || [404, 410].includes(described.status)) {
        return {
          match: true,
          reason: `HTTP ${described.status || 404}`,
          detail: `${clubURL(slug)} — confirmed invalid (${described.status || 404})`
        };
      }
      if (described.category === "forbidden" || [401, 403].includes(described.status)) {
        return {
          match: true,
          reason: `Restricted (${described.status || 403})`,
          detail: `${clubURL(slug)} — restricted club endpoint (${described.status || 403})`
        };
      }
      return {
        match: false,
        temporary: true,
        reason: described.message || "Temporary request error",
        detail: `TEMPORARY FAILURE: ${clubURL(slug)} — ${described.message || error}`
      };
    }
  }, {
    filename: "club-url-errors.csv",
    csvHeaders: ["club_url", "reason"],
    csvRow: item => [item.url, item.reason || "Request error"]
  });

  buildRunner("p2kActivity", async (slug, signal, context) => {
    let data;
    try {
      data = await fetchJSON(apiURL(slug, "/matches"), signal);
    } catch (error) {
      if (error instanceof StopError) throw error;
      return {
        match: false,
        reason: "Request error",
        detail: `INVALID DATA: ${clubURL(slug)} — ${error.message || error}`
      };
    }

    const registered = Array.isArray(data?.registered) ? data.registered : [];
    const inProgress = [
      ...(Array.isArray(data?.in_progress) ? data.in_progress : []),
      ...(Array.isArray(data?.on_going) ? data.on_going : [])
    ];
    const finished = Array.isArray(data?.finished) ? data.finished : [];
    const timestamps = finished.map(item => Number(item?.start_time));
    const missingDates = finished.length > 0 && timestamps.some(value => !Number.isFinite(value) || value <= 0);

    if (missingDates) {
      return {
        match: false,
        reason: "Missing finished-match start_time",
        detail: `INVALID DATA: ${clubURL(slug)} — one or more finished matches have no valid start_time`
      };
    }

    const cutoffSeconds = context.cutoff.getTime() / 1000;
    const recentFinished = timestamps.some(value => value >= cutoffSeconds);
    const inactive = registered.length === 0 && inProgress.length === 0 && !recentFinished;
    return {
      match: inactive,
      reason: inactive ? "No registration, ongoing, or recent finished match" : "Active",
      registered: registered.length,
      inProgress: inProgress.length,
      recentFinished
    };
  }, {
    makeContext: () => ({ cutoff: sixMonthCutoff() }),
    filename: "inactive-clubs.csv",
    csvHeaders: ["club_url", "registered_matches", "in_progress_matches", "recent_finished_match"],
    csvRow: item => [item.url, item.registered ?? 0, item.inProgress ?? 0, item.recentFinished ? "yes" : "no"]
  });


  /* Challenge recommendation: ported from the latest standalone assistant onto the shared API client. */
  const recommendationRuntime = {
    activeRun: null,
    recommendation: null,
    highlightBatchId: 0,
    mode: "new"
  };

  function slugifyClubName(value) {
    let text = String(value || "").trim();
    try { text = decodeURIComponent(text); } catch (_) { /* Keep original. */ }
    return text.normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .replace(/[’']/g, "")
      .replace(/&/g, " and ")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  function normalizeClubReference(value) {
    const parsed = parseClubSlug(value);
    if (parsed) return parsed;
    const raw = String(value || "").trim();
    if (!raw || /^https?:/i.test(raw)) return "";
    return slugifyClubName(raw.replace(/^@+/, ""));
  }

  function compactClubKey(value) {
    return slugifyClubName(value).replace(/-/g, "");
  }

  function clubLabelFromSlug(slug) {
    return String(slug || "").split("-").filter(Boolean)
      .map(part => /^\d+$/.test(part) ? part : part.charAt(0).toUpperCase() + part.slice(1))
      .join(" ");
  }

  function recommendationTokens(rawText) {
    const rows = String(rawText || "").replace(/^\uFEFF/, "").split(/\r?\n/);
    const tokens = [];
    rows.forEach(row => {
      const trimmed = row.trim();
      if (!trimmed) return;
      const urls = trimmed.match(/https?:\/\/[^\s,";]+/gi);
      if (urls?.length) { tokens.push(...urls); return; }
      const cells = trimmed.includes("\t") ? trimmed.split("\t")
        : (trimmed.includes(",") || trimmed.includes(";")) ? trimmed.split(/[;,]/) : [trimmed];
      cells.map(cell => cell.trim().replace(/^"|"$/g, "")).filter(Boolean).forEach(cell => tokens.push(cell));
    });
    return tokens;
  }

  function parseRecommendationList(rawText) {
    const seen = new Set();
    const entries = [];
    const invalid = [];
    recommendationTokens(rawText).forEach((source, sourceIndex) => {
      const slug = normalizeClubReference(source);
      if (!slug || !/[a-z0-9]/.test(slug)) { invalid.push(source); return; }
      if (seen.has(slug)) return;
      seen.add(slug);
      entries.push({ slug, source: String(source), sourceIndex, url: clubURL(slug), fallbackName: clubLabelFromSlug(slug) });
    });
    return { entries, invalid };
  }

  function recommendationElement(id) { return document.getElementById(id); }

  function recommendationMode() { return recommendationRuntime.mode === "rematch" ? "rematch" : "new"; }

  function matchIdentifier(value) {
    const text = String(value || "").trim();
    const match = text.match(/(?:match\/|matches\/[^/]+\/|matches\/|^|[^0-9])(\d+)(?:\/?(?:[?#].*)?)?$/i);
    return match ? match[1] : "";
  }

  function summaryMatchIdentifier(match) {
    for (const value of [match?.["@id"], match?.url, match?.id, match?.match_id]) {
      const id = matchIdentifier(value);
      if (id) return id;
    }
    return "";
  }

  function rematchEntries(index, oldestReference) {
    const oldestId = matchIdentifier(oldestReference);
    if (!oldestId) throw new Error("Enter a valid finished match ID, URL, API URL, or slug containing its ID.");
    const finished = matchesArray(index, "finished")
      .map((match, originalIndex) => ({ match, originalIndex, started: matchStartTimestamp(match) }))
      .sort((left, right) => (left.started ?? Number.POSITIVE_INFINITY) - (right.started ?? Number.POSITIVE_INFINITY) || left.originalIndex - right.originalIndex);
    const startIndex = finished.findIndex(item => summaryMatchIdentifier(item.match) === oldestId);
    if (startIndex < 0) throw new Error(`Finished match ${oldestId} was not found in the club match endpoint's available history.`);
    const seen = new Set();
    const entries = [];
    finished.slice(startIndex).forEach((item, offset) => {
      const slug = opponentSlugFromMatchSummary(item.match);
      if (!slug || slug === PREFLIGHT_SLUG || seen.has(slug)) return;
      seen.add(slug);
      entries.push({
        slug, source: String(item.match?.name || item.match?.["@id"] || slug), sourceIndex: startIndex + offset,
        url: clubURL(slug), fallbackName: clubLabelFromSlug(slug),
        sourceMatchId: summaryMatchIdentifier(item.match), sourceMatchName: String(item.match?.name || `Match ${summaryMatchIdentifier(item.match)}`),
        sourceMatchStart: item.started
      });
    });
    if (!entries.length) throw new Error("No unique rematch opponents were found from the selected match onward.");
    return entries;
  }

  function applyRecommendationMode(mode, { reset = true } = {}) {
    recommendationRuntime.mode = mode === "rematch" ? "rematch" : "new";
    document.querySelectorAll("[data-recommendation-mode]").forEach(button => {
      button.setAttribute("aria-selected", String(button.dataset.recommendationMode === recommendationRuntime.mode));
    });
    recommendationElement("p2kRecommendationClubListFieldset").hidden = recommendationRuntime.mode === "rematch";
    recommendationElement("p2kLastChallengeField").hidden = recommendationRuntime.mode !== "new";
    recommendationElement("p2kOldestRematchField").hidden = recommendationRuntime.mode !== "rematch";
    if (reset && !recommendationRuntime.activeRun) resetRecommendation({ clearInputs: false });
    else updateRecommendationControls();
  }

  function numericTimestamp(value) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : null;
  }

  function matchStartTimestamp(match) { return numericTimestamp(match?.start_time); }

  function matchesArray(index, status) {
    if (status === "in_progress") {
      return [
        ...(Array.isArray(index?.in_progress) ? index.in_progress : []),
        ...(Array.isArray(index?.on_going) ? index.on_going : [])
      ];
    }
    return Array.isArray(index?.[status]) ? index[status] : [];
  }

  function opponentSlugFromMatchSummary(match) {
    const opponent = match?.opponent;
    const candidates = [
      typeof opponent === "string" ? opponent : "",
      opponent?.["@id"], opponent?.url, opponent?.name,
      match?.opponent_url, match?.opponent_name
    ];
    for (const candidate of candidates) {
      const slug = normalizeClubReference(candidate);
      if (slug) return slug;
    }
    return "";
  }

  function buildP2KRelationshipIndex(index, exclusionCutoffSeconds) {
    const registered = new Set();
    const recentStarted = new Set();
    const unresolved = [];
    matchesArray(index, "registered").forEach(match => {
      const slug = opponentSlugFromMatchSummary(match);
      if (slug) registered.add(slug); else unresolved.push("A registered Promote to King match has no recognizable opponent.");
    });
    [...matchesArray(index, "in_progress"), ...matchesArray(index, "finished")].forEach(match => {
      const started = matchStartTimestamp(match);
      if (started === null || started < exclusionCutoffSeconds) return;
      const slug = opponentSlugFromMatchSummary(match);
      if (slug) recentStarted.add(slug); else unresolved.push("A recent Promote to King match has no recognizable opponent.");
    });
    return { registered, recentStarted, unresolved };
  }

  function candidateHasP2KMatch(index, status, cutoffSeconds = null) {
    return matchesArray(index, status).some(match => {
      if (opponentSlugFromMatchSummary(match) !== PREFLIGHT_SLUG) return false;
      if (cutoffSeconds === null) return true;
      const started = matchStartTimestamp(match);
      return started !== null && started >= cutoffSeconds;
    });
  }

  function recommendationNumber(input, { minimum = 0, integer = false, label = "Value" } = {}) {
    const value = Number(input.value);
    if (!Number.isFinite(value) || value < minimum) throw new Error(`${label} must be at least ${minimum}.`);
    return integer ? Math.floor(value) : value;
  }

  function recommendationSettings() {
    const exclusionDays = recommendationNumber(recommendationElement("p2kExclusionDays"), { minimum: 0, integer: true, label: "P2K opponent exclusion" });
    const minimumBoards = recommendationNumber(recommendationElement("p2kMinimumBoards"), { minimum: 0, label: "Minimum average boards" });
    const historyDays = recommendationNumber(recommendationElement("p2kBoardHistoryDays"), { minimum: 1, integer: true, label: "Board-average history" });
    const nowSeconds = Date.now() / 1000;
    return {
      exclusionDays, minimumBoards, historyDays,
      exclusionCutoffSeconds: nowSeconds - exclusionDays * 86400,
      historyCutoffSeconds: nowSeconds - historyDays * 86400
    };
  }

  function initialRecommendationCursor(entries, lastChallengeValue) {
    const requestedSlug = normalizeClubReference(lastChallengeValue);
    if (!requestedSlug) return { cursor: 0, matchedIndex: -1 };
    const compact = compactClubKey(requestedSlug);
    const index = entries.findIndex(entry => entry.slug === requestedSlug || compactClubKey(entry.slug) === compact);
    if (index < 0) throw new Error(`Last challenge sent “${lastChallengeValue}” was not found in the current club list.`);
    return { cursor: (index + 1) % entries.length, matchedIndex: index };
  }

  function recommendationRun() {
    if (recommendationRuntime.activeRun) recommendationRuntime.activeRun.controller.abort();
    const run = { controller: new AbortController(), paused: false, pauseWaiters: [], stopped: false };
    recommendationRuntime.activeRun = run;
    return run;
  }

  function stopRecommendationRun() {
    const run = recommendationRuntime.activeRun;
    if (!run) return;
    run.stopped = true; run.paused = false;
    run.pauseWaiters.splice(0).forEach(resolve => resolve());
    run.controller.abort();
  }

  async function waitRecommendation(run) {
    while (run.paused && !run.stopped) await new Promise(resolve => run.pauseWaiters.push(resolve));
    if (run.stopped || run.controller.signal.aborted) throw new StopError();
  }

  function recommendationStatus(message, kind = "") {
    const box = recommendationElement("p2kRecommendationStatus");
    box.textContent = message;
    box.classList.toggle("p2k-error", kind === "error");
    box.classList.toggle("p2k-success", kind === "success");
  }

  function recommendationProgress(checked, total, message) {
    recommendationElement("p2kRecommendationChecked").textContent = String(checked);
    recommendationElement("p2kRecommendationProgress").style.width = `${total > 0 ? Math.round(checked / total * 100) : 0}%`;
    recommendationElement("p2kRecommendationCurrent").textContent = message || "Ready.";
  }

  function formatRecommendationDate(seconds) {
    if (!Number.isFinite(seconds)) return "Not available";
    return new Intl.DateTimeFormat("en-GB", { timeZone: "UTC", day: "2-digit", month: "short", year: "numeric" }).format(new Date(seconds * 1000));
  }

  function matchBoards(match) {
    const value = Number(match?.boards ?? match?.settings?.boards);
    return Number.isFinite(value) && value >= 0 ? value : null;
  }

  async function boardHistoryForClub(entry, index, cutoffSeconds, run) {
    const records = [...matchesArray(index, "in_progress"), ...matchesArray(index, "finished")];
    const undated = records.filter(match => matchStartTimestamp(match) === null);
    if (undated.length) throw new Error(`${undated.length} started match${undated.length === 1 ? " has" : "es have"} no start_time`);
    const recent = records.filter(match => matchStartTimestamp(match) >= cutoffSeconds);
    if (!recent.length) return { count: 0, totalBoards: 0, averageBoards: null, latestStart: null };
    let totalBoards = 0; let latestStart = null;
    const boardBatch = await window.P2K_API_CLIENT.processPriority(
      recent,
      async summary => {
        await waitRecommendation(run);
        let boards = matchBoards(summary);
        if (boards === null) {
          const detailURL = String(summary?.["@id"] || "").trim();
          if (!detailURL) throw new Error("A recent match has no @id, so its board count cannot be loaded");
          boards = matchBoards(await fetchJSON(detailURL, run.controller.signal));
        }
        if (boards === null) throw new Error("A recent match has no usable board count");
        return { boards, started: matchStartTimestamp(summary) };
      },
      { signal: run.controller.signal, getKey: summary => String(summary?.["@id"] || summary?.url || "") }
    );
    if (boardBatch.cancelled || run.controller.signal.aborted) throw new StopError();
    if (boardBatch.failures.length) throw boardBatch.failures[0].error;
    boardBatch.succeeded.forEach(entry => {
      totalBoards += Number(entry.value?.boards || 0);
      const started = entry.value?.started;
      if (started !== null && started !== undefined && (latestStart === null || started > latestStart)) latestStart = started;
    });
    return { count: recent.length, totalBoards, averageBoards: totalBoards / recent.length, latestStart };
  }

  async function evaluateRecommendationCandidate(entry, data, run) {
    const { settings, relationships } = data;
    if (entry.slug === PREFLIGHT_SLUG) return { eligible: false, reason: "Promote to King itself" };
    if (relationships.registered.has(entry.slug)) return { eligible: false, reason: "A match with Promote to King is currently in registration" };
    if (relationships.recentStarted.has(entry.slug)) return { eligible: false, reason: `A match with Promote to King started during the last ${settings.exclusionDays} days` };

    await waitRecommendation(run);
    const [profile, matchIndex] = await Promise.all([
      fetchJSON(apiURL(entry.slug), run.controller.signal),
      fetchJSON(apiURL(entry.slug, "/matches"), run.controller.signal, { networkOnly: true })
    ]);
    if (candidateHasP2KMatch(matchIndex, "registered")) return { eligible: false, reason: "A match with Promote to King is currently in registration" };
    if (candidateHasP2KMatch(matchIndex, "in_progress", settings.exclusionCutoffSeconds) || candidateHasP2KMatch(matchIndex, "finished", settings.exclusionCutoffSeconds)) {
      return { eligible: false, reason: `A match with Promote to King started during the last ${settings.exclusionDays} days` };
    }
    const history = await boardHistoryForClub(entry, matchIndex, settings.historyCutoffSeconds, run);
    if (history.averageBoards === null) return { eligible: false, reason: `No matches started during the last ${settings.historyDays} days` };
    if (history.averageBoards < settings.minimumBoards) return { eligible: false, reason: `Average ${history.averageBoards.toFixed(1)} boards is below the ${settings.minimumBoards} threshold` };
    return { eligible: true, recommendation: {
      slug: entry.slug, url: String(profile?.url || entry.url), name: String(profile?.name || entry.fallbackName),
      averageBoards: history.averageBoards, matchCount: history.count, totalBoards: history.totalBoards,
      latestStart: history.latestStart, rotationIndex: entry.sourceIndex + 1
    }};
  }

  function recommendationPlainText() {
    return (recommendationRuntime.recommendation?.recommendations || []).map((item, index) =>
      `${index + 1}. ${item.name} — ${item.url} — average ${item.averageBoards.toFixed(1)} boards across ${item.matchCount} match${item.matchCount === 1 ? "" : "es"}`
    ).join("\n");
  }

  function renderRecommendations() {
    const data = recommendationRuntime.recommendation;
    const results = recommendationElement("p2kRecommendationResults");
    const copy = recommendationElement("p2kRecommendationCopy");
    const download = recommendationElement("p2kRecommendationDownload");
    const findMore = recommendationElement("p2kRecommendationFindMore");
    const records = data?.recommendations || [];
    if (!records.length) {
      results.innerHTML = '<div class="p2k-cla-empty">No club has qualified yet.</div>';
      copy.disabled = true; download.disabled = true;
    } else {
      results.innerHTML = records.map((item, index) => `
        <article class="p2k-cla-recommendation-card${item.highlightBatchId === recommendationRuntime.highlightBatchId ? " p2k-cla-recommendation-new" : ""}">
          <div class="p2k-cla-recommendation-head">
            <a class="p2k-cla-recommendation-name" href="${item.url.replace(/&/g,"&amp;").replace(/"/g,"&quot;")}" target="_blank" rel="noopener noreferrer">${item.name.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")}</a>
            <span class="p2k-cla-recommendation-order">Recommendation ${index + 1}</span>
          </div>
          <div class="p2k-cla-recommendation-meta">
            <span><strong>Slug:</strong> ${item.slug}</span><span><strong>Average boards:</strong> ${item.averageBoards.toFixed(1)}</span>
            <span><strong>Matches measured:</strong> ${item.matchCount}</span><span><strong>Total boards:</strong> ${item.totalBoards}</span>
            <span><strong>Latest measured start:</strong> ${formatRecommendationDate(item.latestStart)}</span><span><strong>${data.mode === "rematch" ? "History position" : "Original list position"}:</strong> ${item.rotationIndex}</span>
            ${data.mode === "rematch" ? `<span><strong>Rematch source:</strong> ${item.sourceMatchName || item.sourceMatchId || "Finished match"}</span>` : ""}
          </div>
        </article>`).join("");
      copy.disabled = false; download.disabled = false;
    }
    const hasUnchecked = Boolean(data && data.evaluated.size < data.entries.length);
    findMore.hidden = !data || records.length === 0;
    findMore.disabled = !hasUnchecked || Boolean(recommendationRuntime.activeRun);
  }

  function updateRecommendationControls() {
    const hasEntries = recommendationMode() === "rematch"
      ? Boolean(matchIdentifier(recommendationElement("p2kOldestRematch").value))
      : parseRecommendationList(recommendationElement("p2kRecommendationInput").value).entries.length > 0;
    const busy = Boolean(recommendationRuntime.activeRun);
    recommendationElement("p2kRecommendationStart").disabled = busy || !hasEntries;
    recommendationElement("p2kRecommendationPause").disabled = !busy;
    recommendationElement("p2kRecommendationStop").disabled = !busy;
    recommendationElement("p2kRecommendationClear").disabled = busy;
    if (!busy) recommendationElement("p2kRecommendationPause").textContent = "Pause";
    renderRecommendations();
    updateServerListControls();
  }

  function resetRecommendation({ clearInputs = false } = {}) {
    recommendationRuntime.recommendation = null;
    if (clearInputs) {
      recommendationElement("p2kRecommendationInput").value = "";
      recommendationElement("p2kRecommendationFile").value = "";
      recommendationElement("p2kRecommendationFileName").textContent = "No file selected";
      recommendationElement("p2kLastChallenge").value = "";
      recommendationElement("p2kOldestRematch").value = "";
      recommendationElement("p2kExclusionDays").value = "30";
      recommendationElement("p2kMinimumBoards").value = "5";
      recommendationElement("p2kBoardHistoryDays").value = "90";
    }
    recommendationElement("p2kRecommendationDetails").value = "";
    recommendationElement("p2kRecommendationCutoff").textContent = "";
    ["Total","Checked","Matches","Invalid"].forEach(suffix => recommendationElement(`p2kRecommendation${suffix}`).textContent = "0");
    recommendationProgress(0, 0, "Ready.");
    recommendationStatus(recommendationMode() === "rematch" ? "Enter the oldest finished match to consider for a rematch." : "Load the ordered club list and enter the last challenge sent.");
    renderRecommendations(); updateRecommendationControls();
  }

  async function addRecommendations(targetCount, continuation) {
    let data = recommendationRuntime.recommendation;
    const run = recommendationRun();
    updateRecommendationControls();
    let added = 0;
    try {
      if (!continuation) {
        const settings = recommendationSettings();
        const mode = recommendationMode();
        let entries = [];
        let detailRows = [];
        let errors = 0;
        let cursor = 0;
        let wrapped = false;
        let relationships = null;
        if (mode === "rematch") {
          recommendationProgress(0, 1, "Loading finished Promote to King matches…");
          const p2kMatches = await fetchJSON(apiURL(PREFLIGHT_SLUG, "/matches"), run.controller.signal, { networkOnly: true });
          entries = rematchEntries(p2kMatches, recommendationElement("p2kOldestRematch").value);
          relationships = buildP2KRelationshipIndex(p2kMatches, settings.exclusionCutoffSeconds);
          relationships.unresolved.forEach(message => detailRows.push(`Promote to King\tWARNING\t${message}`));
        } else {
          const parsed = parseRecommendationList(recommendationElement("p2kRecommendationInput").value);
          if (!parsed.entries.length) throw new Error("Load at least one valid club reference.");
          const cursorInfo = initialRecommendationCursor(parsed.entries, recommendationElement("p2kLastChallenge").value);
          entries = parsed.entries; cursor = cursorInfo.cursor; wrapped = cursorInfo.cursor === 0 && cursorInfo.matchedIndex >= 0;
          detailRows = parsed.invalid.map(value => `${value}\tERROR\tInvalid club reference`); errors = parsed.invalid.length;
        }
        data = { entries, settings, mode, cursor, evaluated: new Set(), recommendations: [], detailRows, errors, relationships, wrapped };
        recommendationRuntime.recommendation = data;
        recommendationElement("p2kRecommendationTotal").textContent = String(entries.length);
        recommendationElement("p2kRecommendationInvalid").textContent = String(errors);
        recommendationElement("p2kRecommendationDetails").value = detailRows.join("\n");
        renderRecommendations();
      }
      if (!data || data.evaluated.size >= data.entries.length) {
        recommendationStatus("Every candidate has already been evaluated. Start a new run to evaluate the sequence again.", "success");
        return;
      }

      const highlightBatchId = ++recommendationRuntime.highlightBatchId;
      data.recommendations.forEach(item => { item.highlightBatchId = 0; });
      renderRecommendations();

      if (!data.relationships) {
        recommendationProgress(data.evaluated.size, data.entries.length, "Loading Promote to King match history…");
        const p2kMatches = await fetchJSON(apiURL(PREFLIGHT_SLUG, "/matches"), run.controller.signal, { networkOnly: true });
        data.relationships = buildP2KRelationshipIndex(p2kMatches, data.settings.exclusionCutoffSeconds);
        data.relationships.unresolved.forEach(message => data.detailRows.push(`Promote to King\tWARNING\t${message}`));
      }
      recommendationElement("p2kRecommendationCutoff").textContent = `${data.mode === "rematch" ? "Rematch candidates use finished opponents from the selected match onward. " : ""}P2K challenge exclusion cutoff: ${formatRecommendationDate(data.settings.exclusionCutoffSeconds)}. Board-average history cutoff: ${formatRecommendationDate(data.settings.historyCutoffSeconds)}.`;
      while (added < targetCount && data.evaluated.size < data.entries.length) {
        await waitRecommendation(run);
        const wanted = Math.max(1, targetCount - added);
        const probeEntries = [];
        let safety = 0;
        while (probeEntries.length < wanted && safety < data.entries.length) {
          while (data.evaluated.has(data.cursor) && safety < data.entries.length) {
            data.cursor = (data.cursor + 1) % data.entries.length; safety += 1;
          }
          if (safety >= data.entries.length) break;
          const currentIndex = data.cursor; const entry = data.entries[currentIndex];
          data.cursor = (currentIndex + 1) % data.entries.length; if (data.cursor === 0) data.wrapped = true;
          if (!probeEntries.some(item => item.currentIndex === currentIndex)) probeEntries.push({ currentIndex, entry });
          safety += 1;
        }
        if (!probeEntries.length) break;

        const activeTeams = new Set();
        const activeTeamStatus = settled => {
          const names = [...activeTeams];
          const shown = names.slice(0, 4).join(", ");
          const more = names.length > 4 ? ` +${names.length - 4}` : "";
          if (names.length) {
            return `Analyzing ${names.length} team${names.length === 1 ? "" : "s"}: ${shown}${more} · ${settled} of ${probeEntries.length} completed`;
          }
          return `Evaluated ${settled} of ${probeEntries.length} teams in this batch`;
        };
        const updateActiveTeamProgress = settled => recommendationProgress(
          Math.min(data.entries.length, data.evaluated.size + settled),
          data.entries.length,
          activeTeamStatus(settled)
        );
        const evaluationBatch = await window.P2K_API_CLIENT.processPriority(
          probeEntries,
          async item => {
            activeTeams.add(item.entry.slug);
            updateActiveTeamProgress(0);
            try {
              return { ...item, result: await evaluateRecommendationCandidate(item.entry, data, run) };
            } finally {
              activeTeams.delete(item.entry.slug);
            }
          },
          {
            signal: run.controller.signal,
            getKey: item => item.entry.slug,
            onProgress: progressState => updateActiveTeamProgress(progressState.settled)
          }
        );
        const settled = [
          ...evaluationBatch.succeeded.map(row => ({ index: row.index, item: row.value, result: row.value.result, error: null })),
          ...evaluationBatch.failures.map(row => ({ index: row.index, item: row.item, result: null, error: row.error }))
        ].sort((a,b)=>a.index-b.index);
        for (const row of settled) {
          const currentIndex = row.item.currentIndex; const entry = row.item.entry;
          data.evaluated.add(currentIndex);
          if (row.error) {
            data.errors += 1;
            const described = window.P2K_API_CLIENT?.describeError?.(row.error);
            data.detailRows.push(`${entry.url}\tERROR\t${described?.message || row.error?.message || row.error}`);
          } else if (row.result?.eligible) {
            data.recommendations.push({ ...row.result.recommendation, sourceMatchId: entry.sourceMatchId, sourceMatchName: entry.sourceMatchName, sourceMatchStart: entry.sourceMatchStart, highlightBatchId }); added += 1;
            data.detailRows.push(`${entry.url}\tRECOMMENDED\tAverage ${row.result.recommendation.averageBoards.toFixed(1)} boards across ${row.result.recommendation.matchCount} recent matches`);
          } else data.detailRows.push(`${entry.url}\tSKIPPED\t${row.result?.reason || "Not eligible"}`);
        }
        recommendationElement("p2kRecommendationMatches").textContent = String(data.recommendations.length);
        recommendationElement("p2kRecommendationInvalid").textContent = String(data.errors);
        recommendationElement("p2kRecommendationDetails").value = data.detailRows.join("\n");
        recommendationProgress(data.evaluated.size, data.entries.length, `Checked ${data.evaluated.size} of ${data.entries.length} candidates.`);
        renderRecommendations();
        if (evaluationBatch.cancelled || run.controller.signal.aborted) throw new StopError();
      }
      const remaining = data.entries.length - data.evaluated.size;
      const rollover = data.wrapped && data.mode === "new" ? " The scan rolled over to the beginning of the list." : "";
      if (added > 0) recommendationStatus(`Added ${added} ${data.mode === "rematch" ? "rematch " : ""}recommendation${added === 1 ? "" : "s"}.${rollover} ${remaining} candidate${remaining === 1 ? " remains" : "s remain"} unchecked.`, "success");
      else if (remaining === 0) recommendationStatus(`Every ${data.mode === "rematch" ? "rematch " : ""}candidate has been evaluated.${rollover} ${data.recommendations.length} club${data.recommendations.length === 1 ? " was" : "s were"} recommended in total.`, "success");
      else recommendationStatus(`The search ended after adding ${added} recommendations.`, "success");
    } catch (error) {
      if (error instanceof StopError) recommendationStatus("Recommendation search stopped. Existing recommendations and the saved position were kept.", "error");
      else recommendationStatus(`Unable to continue the recommendation search: ${error.message || error}`, "error");
    } finally {
      if (recommendationRuntime.activeRun === run) recommendationRuntime.activeRun = null;
      updateRecommendationControls();
    }
  }

  window.P2K_TAB_ACTIVITY?.onChange?.(active => { if (!active) stopRecommendationRun(); });

  function installRecommendation() {
    const input = recommendationElement("p2kRecommendationInput");
    const file = recommendationElement("p2kRecommendationFile");
    const invalidate = () => { if (!recommendationRuntime.activeRun) resetRecommendation({ clearInputs: false }); };
    input.addEventListener("input", event => {
      if (event.isTrusted) markServerListDirty(input);
      invalidate();
    });
    recommendationElement("p2kLastChallenge").addEventListener("input", invalidate);
    recommendationElement("p2kOldestRematch").addEventListener("input", invalidate);
    document.querySelectorAll("[data-recommendation-mode]").forEach(button => button.addEventListener("click", () => applyRecommendationMode(button.dataset.recommendationMode)));
    ["p2kExclusionDays","p2kMinimumBoards","p2kBoardHistoryDays"].forEach(id => recommendationElement(id).addEventListener("change", invalidate));
    file.addEventListener("change", async () => {
      const selected = file.files?.[0];
      recommendationElement("p2kRecommendationFileName").textContent = selected?.name || "No file selected";
      if (!selected) return;
      try { input.value = await selected.text(); markServerListDirty(input); invalidate(); }
      catch (error) { recommendationStatus(`Unable to read the file: ${error.message || error}`, "error"); }
    });
    recommendationElement("p2kRecommendationStart").addEventListener("click", () => void addRecommendations(10, false));
    recommendationElement("p2kRecommendationFindMore").addEventListener("click", () => void addRecommendations(5, true));
    recommendationElement("p2kRecommendationPause").addEventListener("click", () => {
      const run = recommendationRuntime.activeRun; if (!run) return;
      run.paused = !run.paused; recommendationElement("p2kRecommendationPause").textContent = run.paused ? "Resume" : "Pause";
      if (!run.paused) run.pauseWaiters.splice(0).forEach(resolve => resolve());
    });
    recommendationElement("p2kRecommendationStop").addEventListener("click", stopRecommendationRun);
    recommendationElement("p2kRecommendationClear").addEventListener("click", () => {
      markServerListDirty(input);
      resetRecommendation({ clearInputs: true });
    });
    recommendationElement("p2kRecommendationCopy").addEventListener("click", async () => {
      try { await copyText(recommendationPlainText()); recommendationStatus("Recommendations copied to the clipboard.", "success"); }
      catch (error) { recommendationStatus(`Unable to copy: ${error.message || error}`, "error"); }
    });
    recommendationElement("p2kRecommendationDownload").addEventListener("click", () => {
      const records = recommendationRuntime.recommendation?.recommendations || [];
      downloadCSV("challenge-recommendations.csv",
        ["recommendation","club_name","club_slug","club_url","average_boards","matches_measured","total_boards","latest_measured_start","list_position"],
        records.map((item,index) => [index+1,item.name,item.slug,item.url,item.averageBoards.toFixed(2),item.matchCount,item.totalBoards,formatRecommendationDate(item.latestStart),item.rotationIndex]));
    });
    applyRecommendationMode("new", { reset: false });
    updateRecommendationControls();
  }

  function serverStatus(message, kind = "") {
    const status = document.getElementById("p2kChallengeServerStatus");
    if (!status) return;
    status.textContent = message;
    status.classList.toggle("p2k-error", kind === "error");
    status.classList.toggle("p2k-success", kind === "success");
  }

  function formatServerUpdatedAt(value) {
    if (!value) return "unknown time";
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return "unknown time";
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
      dateStyle: "medium",
      timeStyle: "short"
    }).format(date);
  }

  function updateServerListControls() {
    const load = document.getElementById("p2kChallengeServerLoad");
    const activeKey = activeTabKey();
    const activeRun = Boolean(document.querySelector('.p2k-cla-tab-panel:not([hidden]) button[id$="Pause"]:not(:disabled)'));
    const rematchMode = activeKey === "recommendation" && recommendationMode() === "rematch";
    if (load) {
      load.disabled = rematchMode || serverListState.busy || !serverListState.available || !serverListState.exists || activeRun;
      load.title = rematchMode ? "Server club lists are not used in rematch mode." : "";
    }
    Object.entries(SERVER_LIST_SAVE_IDS).forEach(([key, id]) => {
      const save = document.getElementById(id); if (!save) return;
      const input = serverListInput(key);
      const panelRunning = key === activeKey && activeRun;
      const disabledForMode = key === "recommendation" && recommendationMode() === "rematch";
      save.disabled = disabledForMode || serverListState.busy || !serverListState.available || !String(input?.value || "").trim() || panelRunning;
      save.title = disabledForMode ? "Server club lists are not used in rematch mode." : "Save exactly the list currently loaded in this tab.";
    });
  }

  function serverListText(clubs) {
    return clubs.map(clubURL).join("\n");
  }

  function applyServerListToInput(input, clubs, { force = false } = {}) {
    if (!input || !Array.isArray(clubs) || clubs.length === 0) return false;
    if (!force && (serverListState.dirtyInputs.has(input.id) || String(input.value || "").trim())) return false;
    input.value = serverListText(clubs);
    serverListState.dirtyInputs.delete(input.id);
    input.dispatchEvent(new Event("input", { bubbles: true }));
    return true;
  }

  function normalizedServerList(key = activeTabKey()) {
    const input = serverListInput(key);
    if (!input) return { clubs: [], invalid: ["Input field unavailable"] };
    if (key === "recommendation") {
      const parsed = parseRecommendationList(input.value);
      return { clubs: parsed.entries.map(entry => entry.slug), invalid: parsed.invalid };
    }
    const parsed = parseInput(input.value);
    return { clubs: parsed.slugs, invalid: parsed.invalid };
  }

  async function readServerResponse(response) {
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.toLowerCase().includes("application/json")) {
      throw new Error("The local server storage API is not available.");
    }
    const data = await response.json();
    if (!response.ok || data?.ok === false) {
      const error = new Error(data?.error?.message || `Server storage request failed (HTTP ${response.status}).`);
      error.status = response.status;
      error.payload = data;
      throw error;
    }
    return data;
  }

  async function loadServerDefault({ currentTabOnly = false, announce = true } = {}) {
    if (serverListState.busy) return;
    serverListState.busy = true;
    updateServerListControls();
    if (announce) serverStatus("Loading the saved server list…");
    try {
      const response = await fetch(SERVER_LIST_ENDPOINT, {
        method: "GET",
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin"
      });
      const data = await readServerResponse(response);
      const clubs = Array.isArray(data.clubs) ? data.clubs.filter(value => typeof value === "string") : [];
      serverListState.available = true;
      serverListState.exists = data.exists === true && clubs.length > 0;
      serverListState.revision = Number.isInteger(data.revision) ? data.revision : 0;
      serverListState.updatedAt = typeof data.updatedAt === "string" ? data.updatedAt : null;
      serverListState.clubs = clubs;

      if (!serverListState.exists) {
        serverStatus("No server default has been saved yet.");
        return;
      }

      let populated = 0;
      let preserved = 0;
      const inputs = currentTabOnly
        ? [serverListInput()]
        : Object.values(SERVER_LIST_INPUT_IDS).map(id => document.getElementById(id));
      inputs.forEach(input => {
        if (applyServerListToInput(input, clubs, { force: currentTabOnly })) populated += 1;
        else preserved += 1;
      });
      const metadata = `${clubs.length} club${clubs.length === 1 ? "" : "s"}, revision ${serverListState.revision}, saved ${formatServerUpdatedAt(serverListState.updatedAt)}`;
      if (currentTabOnly) {
        serverStatus(`Loaded ${metadata} into the current tab.`, "success");
      } else if (preserved) {
        serverStatus(`Loaded ${metadata} into ${populated} empty tab${populated === 1 ? "" : "s"}; preserved ${preserved} edited tab${preserved === 1 ? "" : "s"}.`, "success");
      } else {
        serverStatus(`Loaded ${metadata} into all club-list tabs.`, "success");
      }
    } catch (error) {
      serverListState.available = false;
      serverListState.exists = false;
      serverStatus("Server list storage is unavailable. Upload the complete package including .htaccess and api/index.php, or start locally with serve_local.py.", "error");
      console.info("Challenge List server storage unavailable:", error);
    } finally {
      serverListState.busy = false;
      updateServerListControls();
    }
  }

  async function saveServerDefault(key = activeTabKey()) {
    if (serverListState.busy || !serverListState.available) return;
    const parsed = normalizedServerList(key);
    if (parsed.invalid.length) {
      serverStatus(`Cannot save: ${parsed.invalid.length} invalid club reference${parsed.invalid.length === 1 ? "" : "s"}.`, "error");
      return;
    }
    if (!parsed.clubs.length) {
      serverStatus("Cannot save an empty club list.", "error");
      return;
    }

    serverListState.busy = true;
    updateServerListControls();
    serverStatus(`Saving ${parsed.clubs.length} club${parsed.clubs.length === 1 ? "" : "s"} from the ${key === "recommendation" ? "Challenge recommendation" : key === "checker" ? "Club URL checker" : "Club activity checker"} tab…`);
    try {
      const data = window.P2K_TEAM_POINTS_CLIENT?.endpointRequest
        ? await window.P2K_TEAM_POINTS_CLIENT.endpointRequest(SERVER_LIST_ENDPOINT, {
            method: "PUT",
            body: { revision: serverListState.revision, clubs: parsed.clubs },
            requestKind: "challenge-club-list"
          })
        : await (async () => { throw new Error("The secured administrator client is unavailable."); })();
      serverListState.available = true;
      serverListState.exists = true;
      serverListState.revision = Number.isInteger(data.revision) ? data.revision : serverListState.revision + 1;
      serverListState.updatedAt = typeof data.updatedAt === "string" ? data.updatedAt : null;
      serverListState.clubs = Array.isArray(data.clubs) ? data.clubs : parsed.clubs;
      serverStatus(
        `Saved ${serverListState.clubs.length} club${serverListState.clubs.length === 1 ? "" : "s"} as server default (revision ${serverListState.revision}).`,
        "success"
      );
    } catch (error) {
      if (error?.status === 409) {
        const current = error.payload?.current;
        if (current && Number.isInteger(current.revision)) {
          serverListState.revision = current.revision;
          serverListState.updatedAt = current.updatedAt || null;
          serverListState.clubs = Array.isArray(current.clubs) ? current.clubs : [];
          serverListState.exists = serverListState.clubs.length > 0;
        }
        serverStatus("The server default changed in another session. Load it into the current tab before saving again.", "error");
      } else {
        serverStatus(`Unable to save the server default: ${error.message || error}`, "error");
      }
    } finally {
      serverListState.busy = false;
      updateServerListControls();
    }
  }

  function installServerDefaultList() {
    const load = document.getElementById("p2kChallengeServerLoad");
    if (!load) return;
    Object.values(SERVER_LIST_INPUT_IDS).forEach(id => {
      const input = document.getElementById(id);
      input?.addEventListener("input", updateServerListControls);
    });
    load.addEventListener("click", () => void loadServerDefault({ currentTabOnly: true }));
    Object.entries(SERVER_LIST_SAVE_IDS).forEach(([key, id]) => {
      document.getElementById(id)?.addEventListener("click", () => void saveServerDefault(key));
    });
    updateServerListControls();
    void loadServerDefault({ currentTabOnly: false, announce: false });
  }

  installRecommendation();
  installServerDefaultList();

})();
