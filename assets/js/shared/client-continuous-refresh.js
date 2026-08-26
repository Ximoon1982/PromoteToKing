/* v2.9.8: operator-controlled continuous client refresh. Disabled by default. */
(() => {
  "use strict";

  const STORAGE_KEY = "p2k.clientContinuousRefresh.v1";
  const LOG_KEY = "p2k.clientContinuousRefresh.logs.v1";
  const CURSOR_KEY = "p2k.clientContinuousRefresh.cursor.v1";
  const LEADER_KEY = "p2k.clientContinuousRefresh.leader.v1";
  const LEADER_TTL_MS = 20_000;
  const LEADER_HEARTBEAT_MS = 7_000;
  const EMPTY_RETRY_MS = 4_000;
  const ERROR_RETRY_MS = 8_000;
  const EMPTY_PLAN_LOG_MIN_MS = 60_000;
  const MAX_LOGS = 100;
  const AUTHORITATIVE_PULSE_SECONDS = 34;
  const AUTHORITATIVE_PULSE_TIMEOUT_MS = 45_000;
  const AUTHORITATIVE_PULSE_MIN_MS = 45_000;
  const ACDM_MAX_CHAIN_PULSES = 6;
  const ACDM_LOW_BROWSER_TASKS = 8;
  const ACDM_MIN_CHAIN_DELAY_MS = 200;
  const TASK_KEY = "client-continuous-refresh";
  const CONTROL_API = "server/control/public/api.php";
  const ownerId = (() => {
    try { return `client-refresh-${crypto.randomUUID()}`; }
    catch (_) { return `client-refresh-${Date.now()}-${Math.random().toString(16).slice(2)}`; }
  })();

  const kinds = ["club_index", "roster", "matches", "stats", "archive"];
  const emptyKinds = () => Object.fromEntries(kinds.map(kind => [kind, {
    scheduled: 0, fetched_ok: 0, fetch_failed: 0, fetch_aborted: 0, observations_rejected: 0,
    failure_reasons: {}, last_success_at: null, last_failure_at: null, last_url: ""
  }]));

  function loadJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return fallback;
      const value = JSON.parse(raw);
      return value && typeof value === "object" ? value : fallback;
    } catch (_) { return fallback; }
  }
  function saveJSON(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (_) { /* private mode */ }
  }
  function normalizedMode(value) { return ["running", "paused"].includes(String(value)) ? String(value) : "disabled"; }
  function newState() {
    return {
      mode: "disabled", started_at: null, paused_at: null, stopped_at: null,
      last_plan_at: null, last_cycle_at: null, last_success_at: null, last_error: "", last_empty_plan_log_at: null,
      cycles: 0, plans: 0, empty_plans: 0, tasks_scheduled: 0, fetched_ok: 0, fetch_failed: 0,
      browser_claimable_now: 0, planner_snapshot: {},
      worker_pulses: 0, worker_pulse_failures: 0, last_worker_pulse_at: null, last_worker_pulse_lane: "", next_worker_lane: "club",
      canonical_drain_mode: false, canonical_drain: {}, canonical_drain_pulses: 0, canonical_drain_productive_pulses: 0,
      canonical_drain_empty_pulses: 0, canonical_drain_yields: 0, canonical_drain_last_processed: 0, canonical_drain_last_reason: "",
      last_pulse_at: null, last_pulse_reason: "", policy: {}, coverage: {}, work_classes: emptyKinds()
    };
  }
  let persisted = { ...newState(), ...loadJSON(STORAGE_KEY, {}) };
  persisted.mode = normalizedMode(persisted.mode);
  persisted.work_classes = { ...emptyKinds(), ...(persisted.work_classes || {}) };
  let logs = Array.isArray(loadJSON(LOG_KEY, [])) ? loadJSON(LOG_KEY, []).slice(0, MAX_LOGS) : [];
  let timer = 0;
  let heartbeat = 0;
  let activeController = null;
  let cycleRunning = false;

  function nowIso() { return new Date().toISOString(); }
  function sessionUsername() {
    return String(window.P2K_AUTH?.getSession?.()?.username || window.P2K_ADMIN_USERNAME || "").trim();
  }
  function persist() { saveJSON(STORAGE_KEY, persisted); }
  function emit(reason = "state") {
    try { window.dispatchEvent(new CustomEvent("p2k-client-refresh-change", { detail: { reason, status: status() } })); } catch (_) {}
  }
  function addLog(level, kind, message, detail = {}) {
    const row = { at: nowIso(), level: String(level || "info"), kind: String(kind || "controller"), message: String(message || ""), detail };
    logs.unshift(row); logs = logs.slice(0, MAX_LOGS); saveJSON(LOG_KEY, logs);
    try { window.dispatchEvent(new CustomEvent("p2k-client-refresh-log", { detail: row })); } catch (_) {}
  }
  function readLeader() {
    try { return JSON.parse(localStorage.getItem(LEADER_KEY) || "null"); } catch (_) { return null; }
  }
  function claimLeadership() {
    const now = Date.now(); const current = readLeader();
    if (current?.owner && current.owner !== ownerId && Number(current.expires || 0) > now) return false;
    try { localStorage.setItem(LEADER_KEY, JSON.stringify({ owner: ownerId, expires: now + LEADER_TTL_MS })); } catch (_) { return true; }
    return readLeader()?.owner === ownerId;
  }
  function refreshLeadership() {
    if (persisted.mode !== "running") return;
    if (readLeader()?.owner !== ownerId) return;
    try { localStorage.setItem(LEADER_KEY, JSON.stringify({ owner: ownerId, expires: Date.now() + LEADER_TTL_MS })); } catch (_) {}
  }
  function releaseLeadership() {
    try { if (readLeader()?.owner === ownerId) localStorage.removeItem(LEADER_KEY); } catch (_) {}
  }
  function schedule(ms) {
    clearTimeout(timer); timer = window.setTimeout(tick, Math.max(100, Number(ms) || 0));
  }
  function cursor() {
    try { return Math.max(0, Number(localStorage.getItem(CURSOR_KEY) || 0) || 0); } catch (_) { return 0; }
  }
  function saveCursor(value) {
    const numeric = Math.max(0, Number(value) || 0);
    try { localStorage.setItem(CURSOR_KEY, String(numeric)); } catch (_) {}
  }
  function classRow(kind) {
    if (!persisted.work_classes[kind]) persisted.work_classes[kind] = emptyKinds()[kind] || emptyKinds().matches;
    return persisted.work_classes[kind];
  }
  function addFailureReason(kind, reason) {
    const row = classRow(kind); const key = String(reason || "other");
    row.failure_reasons = row.failure_reasons && typeof row.failure_reasons === "object" ? row.failure_reasons : {};
    row.failure_reasons[key] = Number(row.failure_reasons[key] || 0) + 1;
  }
  function fetchFailureReason(described = {}, error = null) {
    const status = Number(described.status || error?.status || 0);
    if (status > 0) return `http_${status}`;
    const category = String(described.category || error?.category || "").toLowerCase();
    const code = String(described.code || error?.code || "").toLowerCase();
    const message = String(described.message || error?.message || "").toLowerCase();
    if (category === "timeout" || code.includes("timeout")) return "timeout";
    if (category === "cancelled" || code.includes("abort") || error?.name === "AbortError") return "abort";
    if (category === "cache" || code.includes("cache") || code.includes("lease") || message.includes("lease")) return "cache_or_lease";
    if (["network","rate-limit","server"].includes(category) || code.includes("gateway") || message.includes("gateway")) return "gateway_failure";
    return "other";
  }
  function interactiveProtectionActive() {
    try { return window.P2K_API_CLIENT?.diagnostics?.()?.oauthInteractiveProtection === true; } catch (_) { return false; }
  }
  function apiMode() {
    const d = window.P2K_API_CLIENT?.diagnostics?.() || {};
    const configured = Math.max(1, Number(d.configuredConcurrency || d.concurrency || 1));
    const adaptive = Math.max(1, Number(d.adaptiveConcurrency || configured));
    const oauth = d.oauthBearerMode === true;
    const rateTarget = Math.max(0, Number(d.oauthGatewayRateTarget || 0));
    const safeRate = Math.max(0, Number(d.oauthGatewaySafeRateTarget || 0));
    const unsafeRate = Math.max(0, Number(d.oauthGatewayUnsafeRateTarget || 0));
    const gatewayTarget = Math.max(1, Number(d.oauthGatewayTarget || 1));
    const label = oauth
      ? `OAuth paced · ${rateTarget.toFixed(1)}/s · cap ${gatewayTarget}`
      : configured > 1 ? `parallel · ${adaptive}/${configured}` : "serial";
    return {
      label, configured, adaptive, oauth, rateTarget, safeRate, unsafeRate, gatewayTarget,
      active: Number(d.activeRequests || 0), queued: Number(d.queuedRequests || 0), counters: d.counters || {},
      interactiveProtection: d.oauthInteractiveProtection === true,
      foregroundQueued: Number(d.oauthGatewayForegroundQueued || 0),
      backgroundQueued: Number(d.oauthGatewayBackgroundQueued || 0),
      activeForegroundPosts: Number(d.oauthGatewayActiveForegroundPosts || 0),
      activeBackgroundPosts: Number(d.oauthGatewayActiveBackgroundPosts || 0),
      foregroundReservedPosts: Number(d.oauthForegroundReservedPosts || 0),
      interactiveWaitTargetMs: Number(d.oauthInteractiveWaitTargetMs || 0),
      foregroundWaitLastMs: Number(d.oauthForegroundWaitLastMs || 0),
      foregroundWaitMaxMs: Number(d.oauthForegroundWaitMaxMs || 0),
      backgroundSuppressions: Number(d.oauthBackgroundAdmissionSuppressions || 0)
    };
  }
  function canonicalForegroundPressure() {
    const api = apiMode();
    return interactiveProtectionActive() || api.interactiveProtection || api.foregroundQueued > 0 || api.activeForegroundPosts > 0;
  }
  function boundedInt(value, fallback, low, high) {
    const n = Number(value); return Math.max(low, Math.min(high, Number.isFinite(n) ? Math.round(n) : fallback));
  }
  async function drainDelay(milliseconds) {
    const until = Date.now() + Math.max(0, Number(milliseconds) || 0);
    while (Date.now() < until) {
      if (canonicalForegroundPressure() || persisted.mode !== "running") return false;
      await new Promise(resolve => setTimeout(resolve, Math.min(150, Math.max(20, until - Date.now()))));
    }
    return true;
  }
  async function maybePulseAuthoritativeWorker(reason = "cycle", options = {}) {
    if (persisted.mode !== "running" || !window.P2K_TEAM_POINTS_CLIENT?.endpointRequest) return null;
    const chained = options.chained === true;
    if (canonicalForegroundPressure()) {
      if (chained) persisted.canonical_drain_yields = Number(persisted.canonical_drain_yields || 0) + 1;
      return { skipped: "foreground-pressure" };
    }
    const lastEpoch = persisted.last_worker_pulse_at ? Date.parse(persisted.last_worker_pulse_at) : 0;
    if (!chained && Number.isFinite(lastEpoch) && lastEpoch > 0 && Date.now() - lastEpoch < AUTHORITATIVE_PULSE_MIN_MS) return null;
    const drain = options.drain && typeof options.drain === "object" ? options.drain : (persisted.canonical_drain || {});
    const suggestedLane = String(options.lane || drain.recommended_lane || "").toLowerCase();
    const lane = ["club", "player"].includes(suggestedLane) ? suggestedLane : (persisted.next_worker_lane === "player" ? "player" : "club");
    persisted.next_worker_lane = lane === "club" ? "player" : "club";
    const canonicalQuota = boundedInt(options.canonicalQuota ?? drain.suggested_quota, 16, 1, 64);
    const maxSeconds = boundedInt(options.maxSeconds ?? drain.suggested_max_seconds, chained ? 16 : AUTHORITATIVE_PULSE_SECONDS, 8, 34);
    persisted.last_worker_pulse_at = nowIso();
    persisted.last_worker_pulse_lane = lane;
    persist(); emit("worker-pulse-start");
    try {
      const result = await window.P2K_TEAM_POINTS_CLIENT.endpointRequest(CONTROL_API, {
        action: "client-refresh-worker-pulse", method: "POST", username: sessionUsername(),
        body: { lane, max_seconds: maxSeconds, canonical_quota: canonicalQuota }, requestKind: "acsr-worker-pulse", serverTrafficClass: "background", timeoutMs: Math.max(AUTHORITATIVE_PULSE_TIMEOUT_MS, (maxSeconds + 10) * 1000)
      });
      persisted.worker_pulses++;
      const processed = Number(result.processed_items || 0);
      if (result.canonical_drain && typeof result.canonical_drain === "object") persisted.canonical_drain = result.canonical_drain;
      addLog("info", "planner", `ACSR authoritative ${lane} worker pulse: ${result.status || "completed"}.`, { reason, processed, canonical_quota: canonicalQuota, max_seconds: maxSeconds, acdm: chained, remaining_debt: Number(result.canonical_drain?.total_debt || 0) });
      persist(); emit("worker-pulse-complete");
      return result;
    } catch (error) {
      persisted.worker_pulse_failures++;
      addLog("warning", "planner", `ACSR authoritative ${lane} worker pulse failed: ${error?.message || error}.`, { reason, acdm: chained });
      persist(); emit("worker-pulse-error");
      return null;
    }
  }
  async function drainCanonicalBacklog(batch, reason = "canonical-drain") {
    let drain = batch?.canonical_drain && typeof batch.canonical_drain === "object" ? batch.canonical_drain : (persisted.canonical_drain || {});
    let debt = Math.max(0, Number(drain.total_debt || 0));
    if (persisted.mode !== "running" || debt <= 0) return { ran: false, productive: false, debt };
    persisted.canonical_drain_mode = true;
    persisted.canonical_drain = drain;
    persisted.canonical_drain_last_reason = String(reason || "canonical-drain");
    persist(); emit("canonical-drain-start");
    addLog("info", "planner", `ACSR Canonical Drain Mode entered with ${Math.round(debt)} authoritative work item(s) of debt.`, { reason, recommended_lane: drain.recommended_lane || "club", club_debt: Number(drain.club?.debt || 0), player_debt: Number(drain.player?.debt || 0) });
    let productive = false, pulses = 0;
    let quota = boundedInt(drain.suggested_quota, 16, 4, 64);
    try {
      while (persisted.mode === "running" && debt > 0 && pulses < ACDM_MAX_CHAIN_PULSES) {
        if (canonicalForegroundPressure()) {
          persisted.canonical_drain_yields = Number(persisted.canonical_drain_yields || 0) + 1;
          addLog("info", "planner", "ACDM yielded before the next authoritative pulse because foreground/interactive traffic is waiting.", { debt, pulses });
          emit("canonical-drain-yield");
          break;
        }
        const clubScore = Number(drain.club?.urgency_score ?? drain.club?.debt ?? 0);
        const playerScore = Number(drain.player?.urgency_score ?? drain.player?.debt ?? 0);
        const lane = ["club", "player"].includes(String(drain.recommended_lane || "")) ? String(drain.recommended_lane) : (playerScore > clubScore ? "player" : "club");
        const seconds = boundedInt(drain.suggested_max_seconds, quota >= 18 ? 28 : quota >= 12 ? 22 : quota >= 8 ? 16 : 10, 8, 34);
        const result = await maybePulseAuthoritativeWorker(reason, { chained: true, lane, canonicalQuota: quota, maxSeconds: seconds, drain });
        if (!result || result.skipped) break;
        pulses++;
        persisted.canonical_drain_pulses = Number(persisted.canonical_drain_pulses || 0) + 1;
        const processed = Math.max(0, Number(result.processed_items || 0));
        persisted.canonical_drain_last_processed = processed;
        if (result.canonical_drain && typeof result.canonical_drain === "object") drain = result.canonical_drain;
        debt = Math.max(0, Number(drain.total_debt || Math.max(0, debt - processed)));
        if (processed > 0) {
          productive = true;
          persisted.canonical_drain_productive_pulses = Number(persisted.canonical_drain_productive_pulses || 0) + 1;
          quota = Math.min(64, Math.max(quota, boundedInt(drain.suggested_quota, quota, 4, 64)) + (processed >= quota ? 4 : 0));
        } else {
          persisted.canonical_drain_empty_pulses = Number(persisted.canonical_drain_empty_pulses || 0) + 1;
          quota = Math.max(4, Math.floor(quota / 2));
        }
        persisted.canonical_drain = drain;
        persist(); emit("canonical-drain-pulse");
        // Chaining is explicitly productivity-gated. A zero-work pulse stops the
        // current chain instead of busy-looping a locked/temporarily empty lane.
        if (processed <= 0 || debt <= 0) break;
        if (canonicalForegroundPressure()) {
          persisted.canonical_drain_yields = Number(persisted.canonical_drain_yields || 0) + 1;
          emit("canonical-drain-yield");
          break;
        }
        const delay = Math.max(ACDM_MIN_CHAIN_DELAY_MS, Number(drain.suggested_next_delay_ms || 500));
        if (!(await drainDelay(delay))) {
          persisted.canonical_drain_yields = Number(persisted.canonical_drain_yields || 0) + 1;
          emit("canonical-drain-yield");
          break;
        }
      }
      return { ran: pulses > 0, productive, pulses, debt };
    } finally {
      persisted.canonical_drain_mode = false;
      persisted.canonical_drain = drain;
      persist(); emit("canonical-drain-settled");
    }
  }

  async function plan() {
    if (window.P2K_ADMIN_ACCESS_READY && typeof window.P2K_ADMIN_ACCESS_READY.then === "function") await window.P2K_ADMIN_ACCESS_READY;
    const username = sessionUsername();
    if (!username) throw new Error("A P2K administrator session is required to plan continuous refresh work.");
    return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(CONTROL_API, {
      action: "client-refresh-plan", method: "POST", username,
      body: { cursor: cursor(), max_tasks: apiMode().configured > 1 ? 256 : 48 }
    });
  }
  function safeTask(task) {
    return task && kinds.includes(String(task.kind || "")) && /^https:\/\/api\.chess\.com\/pub\//i.test(String(task.url || ""));
  }
  async function runTask(task, signal) {
    const kind = String(task.kind || ""); const row = classRow(kind); row.scheduled++; persisted.tasks_scheduled++;
    const url = String(task.url || ""); row.last_url = url;
    try {
      await window.P2K_API_CLIENT.jsonDetailed(url, {
        attempts: 2,
        cacheMode: "network-only",
        priority: Number(task.priority || -120),
        trafficClass: "background",
        signal,
        observationSource: "client_refresh",
        observationClaimToken: String(task.claim_token || ""),
        observationClaimKind: kind === "club_index" ? "club_index" : kind
      });
      row.fetched_ok++; row.last_success_at = nowIso(); persisted.fetched_ok++; persisted.last_success_at = row.last_success_at;
      return { ok: true, kind, url };
    } catch (error) {
      const described = window.P2K_API_CLIENT?.describeError?.(error) || { message: error?.message || String(error), code: error?.code || "FETCH_FAILED", category: error?.category || "", status: error?.status || 0 };
      const reason = fetchFailureReason(described, error); addFailureReason(kind, reason); row.last_failure_at = nowIso();
      if (reason === "abort") { row.fetch_aborted++; return { ok: false, aborted: true, kind, url, reason, code: described.code || "", message: described.message || "Aborted" }; }
      row.fetch_failed++; persisted.fetch_failed++;
      return { ok: false, kind, url, reason, code: described.code || "", status: Number(described.status || 0), message: described.message || "Fetch failed" };
    }
  }
  async function tick() {
    clearTimeout(timer); timer = 0;
    if (persisted.mode !== "running") { releaseLeadership(); emit("idle"); return; }
    if (cycleRunning) { schedule(500); return; }
    if (interactiveProtectionActive()) { schedule(500); emit("interactive-protection"); return; }
    if (!claimLeadership()) { schedule(3_000); emit("standby"); return; }
    cycleRunning = true;
    activeController = new AbortController();
    try {
      const batch = await plan();
      persisted.plans++; persisted.last_plan_at = nowIso(); persisted.coverage = batch.coverage || persisted.coverage || {}; persisted.policy = batch.policy || persisted.policy || {};
      persisted.planner_snapshot = batch.planner || {}; persisted.canonical_drain = batch.canonical_drain || persisted.canonical_drain || {}; persisted.last_error = "";
      if (batch.next_cursor !== undefined) saveCursor(batch.next_cursor);
      const tasks = (Array.isArray(batch.tasks) ? batch.tasks : []).filter(safeTask);
      persisted.browser_claimable_now = tasks.length;
      if (!tasks.length) {
        persisted.empty_plans++; persisted.last_cycle_at = nowIso(); persist(); emit("empty-plan");
        const lastEmptyLog = persisted.last_empty_plan_log_at ? Date.parse(persisted.last_empty_plan_log_at) : 0;
        if (!Number.isFinite(lastEmptyLog) || lastEmptyLog <= 0 || Date.now() - lastEmptyLog >= EMPTY_PLAN_LOG_MIN_MS) {
          persisted.last_empty_plan_log_at = nowIso();
          addLog("info", "planner", "No browser-acquisition work is currently claimable; canonical server checks may still remain.", { coverage: persisted.coverage, canonical_server_checks_due: Number(batch.planner?.canonical_server_checks_due ?? persisted.coverage?.canonical_due_now ?? 0), throttled: true });
          persist();
        }
        const drained = await drainCanonicalBacklog(batch, "empty-plan");
        if (!drained.ran && Number(batch.canonical_drain?.total_debt || 0) <= 0) await maybePulseAuthoritativeWorker("empty-plan");
        schedule(drained.productive ? Number(batch.policy?.next_batch_delay_ms || 350) : Number(batch.policy?.empty_retry_ms || EMPTY_RETRY_MS)); return;
      }
      persisted.cycles++; persisted.last_cycle_at = nowIso();
      const counts = Object.fromEntries(kinds.map(kind => [kind, tasks.filter(task => task.kind === kind).length]));
      addLog("info", "planner", `Planned ${tasks.length} refresh request${tasks.length === 1 ? "" : "s"}.`, counts);
      persist(); emit("plan");
      // Deliberately schedule every task through the shared API client. That client owns
      // serial/parallel/adaptive throughput; this task never changes concurrency itself.
      const settled = await Promise.allSettled(tasks.map(task => runTask(task, activeController.signal)));
      if (activeController.signal.aborted) { persisted.browser_claimable_now = 0; persist(); emit("cycle-aborted"); return; }
      const results = settled.map(item => item.status === "fulfilled" ? item.value : ({ ok: false, kind: "scheduler", message: item.reason?.message || String(item.reason || "Rejected") }));
      const failed = results.filter(item => item?.ok === false).length;
      const cycleByKind = Object.fromEntries(kinds.map(kind => [kind, { ok: 0, failed: 0 }]));
      for (const item of results) if (cycleByKind[item?.kind]) cycleByKind[item.kind][item.ok === false ? "failed" : "ok"]++;
      const sampleFailures = results.filter(item => item?.ok === false).slice(0, 5).map(item => ({ kind: item.kind, reason: item.reason || "other", code: item.code || "", status: Number(item.status || 0), message: item.message || "Fetch failed" }));
      addLog(failed ? "warning" : "success", "cycle", `Refresh cycle completed: ${tasks.length - failed} succeeded, ${failed} failed.`, { api_mode: apiMode().label, work_classes: cycleByKind, sample_failures: sampleFailures });
      // ISRE: one durable metrics flush and one UI notification per cycle rather
      // than one localStorage write + render event for every Chess.com request.
      persisted.browser_claimable_now = 0; persisted.last_error = "";
      persist(); emit("cycle-batch");
      const lowBrowserWork = tasks.length <= Math.max(4, Math.min(ACDM_LOW_BROWSER_TASKS, Math.ceil(Number(batch.policy?.max_tasks || 48) * .08)));
      if (lowBrowserWork && Number(batch.canonical_drain?.total_debt || 0) > 0) await drainCanonicalBacklog(batch, "low-browser-work");
      else await maybePulseAuthoritativeWorker("post-acquisition");
      persist(); emit("cycle-complete"); schedule(Number(batch.policy?.next_batch_delay_ms || 250));
    } catch (error) {
      if (error?.name === "AbortError") return;
      persisted.last_error = error?.message || String(error); persist();
      addLog("error", "controller", `Continuous refresh cycle failed: ${persisted.last_error}`);
      emit("error"); schedule(ERROR_RETRY_MS);
    } finally {
      cycleRunning = false; activeController = null;
      // FFSD last-resort liveness guard: an unexpected early return must not leave
      // an explicitly running controller without a future tick.
      if (persisted.mode === "running" && !timer) schedule(ERROR_RETRY_MS);
    }
  }
  function start() {
    if (!window.P2K_API_CLIENT?.jsonDetailed) throw new Error("Shared Chess.com API client is not loaded.");
    persisted.mode = "running"; persisted.started_at = persisted.started_at || nowIso(); persisted.paused_at = null; persisted.stopped_at = null; persisted.last_error = "";
    persist(); addLog("success", "controller", "Continuous client refresh enabled. API throughput remains controlled by the shared API client.", { api_mode: apiMode().label });
    if (!heartbeat) heartbeat = window.setInterval(refreshLeadership, LEADER_HEARTBEAT_MS);
    emit("started"); schedule(0); return status();
  }
  function pause() {
    if (persisted.mode === "disabled") return status();
    persisted.mode = "paused"; persisted.paused_at = nowIso(); persist(); activeController?.abort(); releaseLeadership();
    addLog("warning", "controller", "Continuous client refresh paused. No new Chess.com request will be scheduled."); emit("paused"); return status();
  }
  function stop() {
    persisted.mode = "disabled"; persisted.stopped_at = nowIso(); persisted.paused_at = null; persist(); activeController?.abort(); releaseLeadership();
    addLog("info", "controller", "Continuous client refresh disabled."); emit("stopped"); return status();
  }
  function resetMetrics() {
    const mode = persisted.mode, started = persisted.started_at;
    persisted = { ...newState(), mode, started_at: mode === "disabled" ? null : started };
    logs = []; saveJSON(LOG_KEY, logs); persist(); emit("reset"); return status();
  }
  function pulse(reason = "acsr") {
    if (persisted.mode !== "running") return status();
    persisted.last_pulse_at = nowIso();
    persisted.last_pulse_reason = String(reason || "acsr");
    persist(); emit("pulse"); schedule(0); return status();
  }
  function status() {
    const leader = readLeader(); const api = apiMode(); const counters = api.counters || {};
    const coverage = persisted.coverage || {};
    const matchFresh = Number(coverage.matches_operational_fresh_percent ?? coverage.matches_fresh_percent);
    const statsFresh = Number(coverage.stats_operational_fresh_percent ?? coverage.stats_fresh_percent);
    const progress = Number.isFinite(matchFresh) && Number.isFinite(statsFresh) ? Math.max(0, Math.min(100, (matchFresh + statsFresh) / 2)) : 0;
    const canonicalFresh = Number(coverage.canonical_fresh_percent ?? ((Number(coverage.matches_fresh_percent || 0) + Number(coverage.stats_fresh_percent || 0)) / 2));
    const operationalFresh = Number(coverage.operational_fresh_percent ?? progress);
    const planner = Object.freeze({
      mode: String(persisted.policy?.mode || "acsr-aggressive"),
      due_now: Number(coverage.canonical_due_now ?? ((Number(coverage.matches_due || 0) + Number(coverage.stats_due || 0)))),
      scheduled_later: Number(coverage.canonical_scheduled_later ?? 0),
      canonical_server_checks_due: Number(persisted.planner_snapshot?.canonical_server_checks_due ?? coverage.canonical_due_now ?? 0),
      canonical_total_debt: Number(persisted.canonical_drain?.total_debt ?? persisted.planner_snapshot?.canonical_total_debt ?? 0),
      canonical_recommended_lane: String(persisted.canonical_drain?.recommended_lane ?? persisted.planner_snapshot?.canonical_recommended_lane ?? ""),
      canonical_drain_mode: Boolean(persisted.canonical_drain_mode),
      browser_claimable_now: Number(persisted.browser_claimable_now || 0),
      browser_claimable_by_class: persisted.planner_snapshot?.browser_claimable_by_class || {},
      operational_due_now: Number(coverage.operational_due_now ?? ((Number(coverage.matches_operational_due || 0) + Number(coverage.stats_operational_due || 0)))),
      operational_scheduled_later: Number(coverage.operational_scheduled_later ?? 0),
      last_plan_at: persisted.last_plan_at || null,
      last_pulse_at: persisted.last_pulse_at || null,
      last_pulse_reason: persisted.last_pulse_reason || "",
      authoritative_worker_pulses: Number(persisted.worker_pulses || 0),
      authoritative_worker_failures: Number(persisted.worker_pulse_failures || 0),
      last_authoritative_worker_at: persisted.last_worker_pulse_at || null,
      last_authoritative_worker_lane: persisted.last_worker_pulse_lane || ""
    });
    const convergence = Object.freeze({
      canonical_fresh_percent: Number.isFinite(canonicalFresh) ? Math.max(0, Math.min(100, canonicalFresh)) : 0,
      operational_fresh_percent: Number.isFinite(operationalFresh) ? Math.max(0, Math.min(100, operationalFresh)) : 0,
      canonical_converged: Boolean(coverage.canonical_converged ?? planner.due_now === 0),
      operational_converged: Boolean(coverage.operational_converged ?? planner.operational_due_now === 0)
    });
    return Object.freeze({
      task_key: TASK_KEY, mode: persisted.mode, running: persisted.mode === "running", paused: persisted.mode === "paused", enabled: persisted.mode !== "disabled",
      leader: leader?.owner === ownerId, standby: persisted.mode === "running" && Boolean(leader?.owner) && leader.owner !== ownerId,
      controller_state: leader?.owner === ownerId ? "Leader" : (persisted.mode === "running" && Boolean(leader?.owner) ? "Standby" : "Idle"),
      progress, planner, convergence, ...persisted, work_classes: JSON.parse(JSON.stringify(persisted.work_classes)), logs: logs.slice(), api,
      observations: {
        queued: Number(counters.observationsQueued || 0), sent: Number(counters.observationsSent || 0), accepted: Number(counters.observationsAccepted || 0),
        work_queued: Number(counters.observationWorkQueued || 0), rows_updated: Number(counters.observationRowsUpdated || 0), delivery_failures: Number(counters.observationDeliveryFailures || 0)
      }
    });
  }

  window.addEventListener("storage", event => {
    if (event.key === STORAGE_KEY) {
      persisted = { ...newState(), ...loadJSON(STORAGE_KEY, {}) }; persisted.mode = normalizedMode(persisted.mode); persisted.work_classes = { ...emptyKinds(), ...(persisted.work_classes || {}) };
      emit("storage"); if (persisted.mode === "running") schedule(200);
    }
  });
  window.addEventListener("p2k-api-observation-results", event => {
    const rows = Array.isArray(event?.detail?.rows) ? event.detail.rows : [];
    let touched = false;
    for (const result of rows) {
      if (String(result?.source || "") !== "acamr") continue;
      let kind = String(result?.claimKind || "");
      if (!kinds.includes(kind)) {
        const path = (() => { try { return new URL(String(result?.url || "")).pathname.toLowerCase(); } catch (_) { return ""; } })();
        kind = /\/matches\/?$/.test(path) ? "matches" : /\/stats\/?$/.test(path) ? "stats" : /\/games\/20\d{2}\//.test(path) ? "archive" : /\/members\/?$/.test(path) ? "roster" : /\/matches\/?$/.test(path) ? "club_index" : "";
      }
      if (!kinds.includes(kind)) continue;
      if (result.accepted) continue;
      const row = classRow(kind); row.observations_rejected = Number(row.observations_rejected || 0) + 1;
      addFailureReason(kind, `observation_rejected:${String(result.reason || "unspecified").slice(0, 80)}`); touched = true;
    }
    if (touched) { persist(); emit("observation-diagnostics"); }
  });
  window.addEventListener("beforeunload", releaseLeadership);
  window.addEventListener("p2k-api-concurrency-change", () => emit("api-concurrency"));
  window.addEventListener("p2k-api-interactive-protection", event => {
    emit("interactive-protection");
    if (event?.detail?.active === false && persisted.mode === "running") schedule(100);
  });

  window.P2K_CLIENT_CONTINUOUS_REFRESH = Object.freeze({ start, pause, stop, pulse, resetMetrics, status, taskKey: TASK_KEY });
  // Disabled by default. A persisted explicit start/resume is honored on reload.
  if (persisted.mode === "running") { heartbeat = window.setInterval(refreshLeadership, LEADER_HEARTBEAT_MS); schedule(750); }
})();
