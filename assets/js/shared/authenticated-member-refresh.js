/* Authenticated Client-Assisted Member Refresh (ACAMR).
 *
 * ACAMR is a low-pulse, opportunistic accelerator for current-member Team Points
 * work. Browser responses are never canonical: they are forwarded through the
 * existing observation channel and only prioritize authoritative server work.
 *
 * Scope:
 * - IN: Club Points / Member Points discovery and verification support, current
 *   member match/board discovery, player archives, ratings and roster freshness.
 * - OUT: tournaments and match-registration monitoring/recruitment.
 *
 * Activation:
 * - verified real OAuth session: always active, independent of the simulated-OAuth flag;
 * - simulated login: active only when the OAuth/simulatedOAuth feature flag is enabled;
 * - no authenticated session: inactive.
 */
(() => {
  "use strict";

  const TRUE_VALUES = new Set(["", "1", "true", "yes", "on", "enabled"]);
  const LEADER_KEY = "p2k.acamr.leader.v1";
  const CURSOR_KEY = "p2k.acamr.cursor.v1";
  const CLIENT_KEY = "p2k.acamr.client.v1";
  const SESSION_KEY = "p2k.acamr.session.v1";
  const LEADER_TTL_MS = 30_000;
  const LEADER_HEARTBEAT_MS = 10_000;
  const IDLE_RECHECK_MS = 8_000;
  const PLAN_ENDPOINT = String(
    window.P2K_SITE_CONFIG?.serverStorage?.acamrPlanEndpoint ||
    "server/team-points/public/acamr-plan.php"
  );
  const API_BASE = "https://api.chess.com/pub";
  function randomId(prefix) {
    try { return `${prefix}-${crypto.randomUUID()}`; }
    catch (_) { return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`; }
  }

  function persistedId(storageName, key, prefix) {
    try {
      const storage = window[storageName];
      const existing = String(storage.getItem(key) || "").trim();
      if (existing) return existing;
      const value = randomId(prefix);
      storage.setItem(key, value);
      return value;
    } catch (_) { return randomId(prefix); }
  }

  const ownerId = randomId("acamr-owner");
  // Client identity persists in this browser profile until local storage is cleared.
  // Browsing-session identity survives reloads in this tab but not a closed session.
  // Raw values are sent same-origin and are hashed before protected telemetry is stored.
  const clientId = persistedId("localStorage", CLIENT_KEY, "acamr-client");
  const browsingSessionId = persistedId("sessionStorage", SESSION_KEY, "acamr-session");

  let timer = 0;
  let leaderHeartbeat = 0;
  let running = false;
  let lastMode = "";
  let lastUsername = "";
  let pendingResultReport = null;
  let lastPlanAt = null;
  let lastPulseAt = null;
  let lastPulseReason = "";
  let lastError = "";
  let lastPlannedClaims = 0;
  let lastPlannedTasks = 0;
  let nextPulseMs = IDLE_RECHECK_MS;

  function oauthFlagEnabled() {
    const params = new URLSearchParams(window.location.search);
    const explicit = params.get("oauth") ?? params.get("simulatedOAuth");
    if (explicit !== null) return TRUE_VALUES.has(String(explicit).trim().toLowerCase());
    return window.P2K_ENABLE_SIMULATED_OAUTH === true ||
      window.P2K_SITE_CONFIG?.features?.simulatedOAuth === true;
  }

  function realOAuthSession(session) {
    const auth = window.P2K_AUTH || {};
    const values = [
      auth.mode, auth.authMode, auth.provider,
      session?.authMode, session?.authenticationMode, session?.provider,
      session?.oauth?.provider
    ].map(value => String(value || "").trim().toLowerCase());
    return auth.realOAuth === true || auth.oauthVerified === true ||
      session?.realOAuth === true || session?.oauthVerified === true || session?.oauth?.verified === true ||
      values.some(value => ["oauth", "real-oauth", "real_oauth", "chesscom-oauth", "chess.com-oauth"].includes(value));
  }

  function authState() {
    const auth = window.P2K_AUTH;
    const session = auth?.getSession?.() || null;
    const username = String(session?.username || "").trim();
    if (!username) return { active: false, mode: "", username: "", session: null };
    if (realOAuthSession(session)) return { active: true, mode: "oauth", username, session };
    const simulated = (auth?.mode === "simulated" || session?.authMode === "simulated" || window.P2K_SIMULATED_OAUTH_ENABLED === true);
    return { active: Boolean(simulated && oauthFlagEnabled()), mode: simulated && oauthFlagEnabled() ? "simulated" : "", username, session };
  }

  function pageActive() {
    if (document.visibilityState === "hidden") return false;
    return window.P2K_TAB_ACTIVITY?.isActive?.() ?? true;
  }

  function readLeader() {
    try { return JSON.parse(localStorage.getItem(LEADER_KEY) || "null"); } catch (_) { return null; }
  }

  function writeLeader(value) {
    try { localStorage.setItem(LEADER_KEY, JSON.stringify(value)); return true; } catch (_) { return true; }
  }

  function acquireLeader() {
    const now = Date.now();
    const current = readLeader();
    if (current && current.owner !== ownerId && Number(current.expiresAt || 0) > now) return false;
    writeLeader({ owner: ownerId, expiresAt: now + LEADER_TTL_MS });
    const confirmed = readLeader();
    return !confirmed || confirmed.owner === ownerId;
  }

  function releaseLeader() {
    try {
      const current = readLeader();
      if (current?.owner === ownerId) localStorage.removeItem(LEADER_KEY);
    } catch (_) { /* storage may be unavailable */ }
  }

  function maintainLeader() {
    if (!running) return;
    const current = readLeader();
    if (current?.owner === ownerId) writeLeader({ owner: ownerId, expiresAt: Date.now() + LEADER_TTL_MS });
  }

  function cursor() {
    try { return Math.max(0, Number.parseInt(localStorage.getItem(CURSOR_KEY) || "0", 10) || 0); } catch (_) { return 0; }
  }

  function saveCursor(value) {
    try { localStorage.setItem(CURSOR_KEY, String(Math.max(0, Number(value) || 0))); } catch (_) { /* ignore */ }
  }

  function schedule(delay) {
    window.clearTimeout(timer);
    timer = window.setTimeout(tick, Math.max(1_000, Number(delay) || IDLE_RECHECK_MS));
  }

  async function requestPlan(state) {
    const response = await fetch(new URL(PLAN_ENDPOINT, window.location.href).href, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-P2K-ACAMR": "1"
      },
      body: JSON.stringify({
        actor: state.username,
        mode: state.mode,
        cursor: cursor(),
        client_id: clientId,
        session_id: browsingSessionId,
        result_report: pendingResultReport
      })
    });
    if (!response.ok) throw new Error(`ACAMR plan HTTP ${response.status}`);
    const payload = await response.json();
    // The server has durably recorded this report once the plan response succeeds.
    if (pendingResultReport) pendingResultReport = null;
    return payload;
  }

  function safeTask(task) {
    if (!task || typeof task !== "object") return false;
    const kind = String(task.kind || "").toLowerCase();
    if (["tournament", "registration", "registered", "recruitment"].includes(kind)) return false;
    let url;
    try { url = new URL(String(task.url || "")); } catch (_) { return false; }
    if (url.origin !== "https://api.chess.com" || !url.pathname.startsWith("/pub/")) return false;
    if (/\/tournament\//i.test(url.pathname)) return false;
    return true;
  }

  function emptyWorkClassReport() {
    return {
      matches: { fetched_ok: 0, fetch_failed: 0 },
      stats: { fetched_ok: 0, fetch_failed: 0 },
      archive: { fetched_ok: 0, fetch_failed: 0 },
      roster: { fetched_ok: 0, fetch_failed: 0 }
    };
  }

  async function runClaim(claim) {
    const api = window.P2K_API_CLIENT;
    const report = emptyWorkClassReport();
    if (!api?.json) return report;
    const tasks = (Array.isArray(claim?.tasks) ? claim.tasks : []).filter(safeTask);
    if (!tasks.length) return report;
    // Promise scheduling lets a future real-OAuth transport exploit authenticated
    // concurrency. The current shared API client still enforces its adaptive cap,
    // so simulated/public mode stays bounded and may serialize automatically.
    const settled = await Promise.allSettled(tasks.map(task => api.json(task.url, {
      attempts: 2,
      cacheMode: "network-only",
      priority: -140,
      trafficClass: "background",
      observationSource: "acamr",
      observationClaimToken: String(claim?.claim_token || ""),
      observationClaimKind: String(task.kind || "")
    })));
    settled.forEach((result, index) => {
      const kind = String(tasks[index]?.kind || "").toLowerCase();
      if (!report[kind]) return;
      report[kind][result.status === "fulfilled" ? "fetched_ok" : "fetch_failed"]++;
    });
    return report;
  }

  function mergeWorkClassReports(reports) {
    const merged = emptyWorkClassReport();
    reports.forEach(report => {
      Object.keys(merged).forEach(kind => {
        merged[kind].fetched_ok += Number(report?.[kind]?.fetched_ok || 0);
        merged[kind].fetch_failed += Number(report?.[kind]?.fetch_failed || 0);
      });
    });
    return merged;
  }

  async function tick() {
    const state = authState();
    lastPulseAt = new Date().toISOString();
    running = state.active;
    lastMode = state.mode;
    lastUsername = state.username;
    if (!state.active || !pageActive() || !window.P2K_API_CLIENT?.json) {
      releaseLeader();
      schedule(IDLE_RECHECK_MS);
      return;
    }
    if (!acquireLeader()) {
      schedule(IDLE_RECHECK_MS);
      return;
    }
    try {
      const plan = await requestPlan(state);
      lastPlanAt = new Date().toISOString();
      lastError = "";
      const claims = Array.isArray(plan?.claims) ? plan.claims : (plan?.claim ? [plan.claim] : []);
      lastPlannedClaims = claims.length;
      lastPlannedTasks = claims.reduce((sum, claim) => sum + (Array.isArray(claim?.tasks) ? claim.tasks.length : 0), 0);
      const nextCursor = plan?.next_cursor ?? claims.at(-1)?.next_cursor ?? plan?.claim?.next_cursor;
      if (nextCursor !== undefined) saveCursor(nextCursor);
      if (claims.length) {
        const settled = await Promise.allSettled(claims.map(runClaim));
        const reports = settled.filter(x => x.status === "fulfilled").map(x => x.value);
        const workClasses = mergeWorkClassReports(reports);
        if (Object.values(workClasses).some(x => x.fetched_ok || x.fetch_failed)) {
          pendingResultReport = { id: randomId("acamr-result"), work_classes: workClasses };
        }
      }
      nextPulseMs = Number(plan?.policy?.pulse_ms) || 20_000;
      schedule(nextPulseMs);
    } catch (error) {
      lastError = String(error?.message || error || "Planner pulse failed");
      nextPulseMs = 20_000;
      schedule(nextPulseMs);
    }
  }

  function pulse(reason = "acsr") {
    lastPulseReason = String(reason || "acsr");
    schedule(250);
  }

  function restart() {
    pulse("restart");
  }

  window.addEventListener("p2k-auth-change", restart);
  window.addEventListener("focus", restart);
  document.addEventListener("visibilitychange", restart);
  window.addEventListener("pagehide", () => {
    running = false;
    window.clearTimeout(timer);
    window.clearInterval(leaderHeartbeat);
    releaseLeader();
  }, { once: true });

  leaderHeartbeat = window.setInterval(maintainLeader, LEADER_HEARTBEAT_MS);
  window.P2K_ACAMR = Object.freeze({
    restart,
    pulse,
    active: () => authState().active,
    status: () => {
      const state = authState();
      const leader = readLeader();
      const isLeader = leader?.owner === ownerId;
      const standby = state.active && Boolean(leader?.owner) && !isLeader;
      return Object.freeze({
        active: state.active,
        mode: lastMode || state.mode,
        username: lastUsername || state.username,
        leader: isLeader,
        standby,
        controller_state: isLeader ? "Leader" : (standby ? "Standby" : (state.active ? "Waiting" : "Idle")),
        planner: Object.freeze({ last_plan_at: lastPlanAt, planned_claims: lastPlannedClaims, planned_tasks: lastPlannedTasks, next_pulse_ms: nextPulseMs }),
        last_pulse_at: lastPulseAt,
        last_pulse_reason: lastPulseReason,
        last_error: lastError,
        clientIdScope: "localStorage",
        browsingSessionScope: "sessionStorage"
      });
    }
  });
  schedule(500);
})();
