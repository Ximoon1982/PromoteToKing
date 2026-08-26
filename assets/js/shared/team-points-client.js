/* Same-origin Team Points client. Administrator credentials remain server-side. */
(() => {
  "use strict";

  const config = window.P2K_SITE_CONFIG || {};
  const storage = config.serverStorage || {};
  const apiEndpoint = storage.teamPointsEndpoint || "server/team-points/public/api.php";
  const sessionEndpoint = storage.teamPointsSessionEndpoint || "server/team-points/public/session.php";
  const publicEndpoint = storage.teamPointsPublicEndpoint || "server/team-points/public/public.php";
  const state = { csrf: "", username: "", connecting: null, connectedAt: 0, lastError: "" };
  const CONNECT_TIMEOUT_MS = 35000;
  const REQUEST_TIMEOUT_MS = 30000;

  function serverForegroundBegin(reason = "admin-request") {
    const next = Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0)) + 1;
    window.P2K_SERVER_FOREGROUND_REQUESTS = next;
    try { window.dispatchEvent(new CustomEvent("p2k-server-foreground-pressure", { detail: { active:true, count:next, reason } })); } catch (_) {}
  }
  function serverForegroundEnd(reason = "admin-request") {
    const next = Math.max(0, Number(window.P2K_SERVER_FOREGROUND_REQUESTS || 0) - 1);
    window.P2K_SERVER_FOREGROUND_REQUESTS = next;
    try { window.dispatchEvent(new CustomEvent("p2k-server-foreground-pressure", { detail: { active:next>0, count:next, reason } })); } catch (_) {}
  }
  async function withServerTraffic(trafficClass, reason, fn) {
    if (String(trafficClass || "foreground") === "background") return fn();
    serverForegroundBegin(reason);
    try { return await fn(); } finally { serverForegroundEnd(reason); }
  }

  async function fetchWithTimeout(url, options, timeoutMs, timeoutMessage) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
      if (error?.name === "AbortError") {
        const timeoutError = new Error(timeoutMessage);
        timeoutError.code = "REQUEST_TIMEOUT";
        throw timeoutError;
      }
      throw error;
    } finally {
      window.clearTimeout(timer);
    }
  }

  function endpoint(base, action, params = {}) {
    const url = new URL(base, window.location.href);
    if (action) url.searchParams.set("action", action);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && String(value) !== "") url.searchParams.set(key, String(value));
    });
    return url.href;
  }

  async function parse(response) {
    let payload = null;
    try { payload = await response.json(); } catch (_) { /* handled below */ }
    if (!response.ok || !payload?.ok) {
      const error = new Error(payload?.error?.message || `HTTP ${response.status}`);
      error.code = payload?.error?.code || "HTTP_ERROR";
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function normalizedKindHeader(kind = "") {
    const value = String(kind || "").trim();
    return value ? { "X-Club-Tools-Request": value } : {};
  }

  async function oauthBootstrapAssertion() {
    const read = () => String(window.P2K_AUTH?.getAdminBootstrap?.() || "").trim();
    const age = () => Number(window.P2K_AUTH?.getAdminBootstrapAgeMs?.() ?? Number.POSITIVE_INFINITY);
    let assertion = read();
    if (assertion && age() < 45_000) return assertion;

    // If the OAuth adapter is already present, refresh its authoritative session
    // before re-establishing Team Points after a long-lived page/session expiry.
    if (typeof window.P2K_AUTH?.refresh === "function") {
      try { await window.P2K_AUTH.refresh(); } catch (_) {}
      assertion = read();
      if (assertion) return assertion;
    }

    const ready = window.P2K_REAL_OAUTH_READY;
    if (ready && typeof ready.then === "function") { try { await ready; } catch (_) {} }
    assertion = read();
    if (assertion) return assertion;

    // Load-order tolerance for legacy frames where the real OAuth adapter is deferred.
    const deadline = Date.now() + 1800;
    while (Date.now() < deadline) {
      assertion = read();
      if (assertion) return assertion;
      await new Promise(resolve => window.setTimeout(resolve, 25));
    }
    return "";
  }

  async function connect(_username = "") {
    // v2.10.4.2: the browser does not assert a username. oauth.php?action=session
    // returns a short-lived server-signed assertion for the real OAuth identity;
    // session.php verifies it and then independently checks current club-admin status.
    if (state.csrf && state.username) return { ok: true, username: state.username, csrf: state.csrf };
    if (state.connecting) return state.connecting;
    state.connecting = oauthBootstrapAssertion().then(bootstrapAssertion => withServerTraffic("foreground", "team-points-session", () => fetchWithTimeout(endpoint(sessionEndpoint), {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ bootstrap_assertion: bootstrapAssertion })
    }, CONNECT_TIMEOUT_MS, "The secured Team Points connection did not complete within 35 seconds. Check MariaDB availability and any stale database lock, then try again."))).then(parse).then(payload => {
      state.csrf = String(payload.csrf || "");
      state.username = String(payload.username || "").trim().toLowerCase();
      if (!state.username) throw new Error("The server did not return the authenticated administrator identity.");
      state.connectedAt = Date.now();
      state.lastError = "";
      return payload;
    }).catch(error => {
      state.csrf = "";
      state.username = "";
      state.lastError = error.message || String(error);
      throw error;
    }).finally(() => { state.connecting = null; });
    return state.connecting;
  }

  async function request(action, { method = "GET", body = null, params = {}, raw = false, username = "", serverTrafficClass = "foreground" } = {}) {
    if (!state.csrf) await connect();
    const response = await withServerTraffic(serverTrafficClass, `team-points:${action||"request"}`, () => fetchWithTimeout(endpoint(apiEndpoint, action, params), {
      method,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: raw ? "text/csv,application/json" : "application/json",
        ...(method.toUpperCase() !== "GET" ? { "X-P2K-CSRF": state.csrf } : {}),
        ...(body ? { "Content-Type": "application/json" } : {})
      },
      body: body ? JSON.stringify(body) : undefined
    }, REQUEST_TIMEOUT_MS, "The Team Points request timed out after 30 seconds. The server may be busy; retry shortly."));
    if (raw && response.ok) return response;
    try { return await parse(response); }
    catch (error) {
      if ([401, 403].includes(Number(error.status)) && error.code !== "ADMIN_AUTH_FAILED") {
        state.csrf = "";
        await connect();
        return request(action, { method, body, params, raw, username, serverTrafficClass });
      }
      throw error;
    }
  }

  async function endpointRequest(base, { action = "", method = "GET", body = null, params = {}, raw = false, username = "", requestKind = "", timeoutMs = REQUEST_TIMEOUT_MS, serverTrafficClass = "foreground" } = {}) {
    if (!state.csrf) await connect();
    const upper = method.toUpperCase();
    const isForm = typeof FormData !== "undefined" && body instanceof FormData;
    const normalizedBase = String(base || "").toLowerCase();
    const inferredKind = String(requestKind || (upper === "GET" ? "" : (
      normalizedBase.includes("server/tournaments/public/tournaments.php") ? "tournaments-update" :
      normalizedBase.includes("api/tracked-match-data/") ? "tracked-match-data" :
      normalizedBase.includes("api/record-league-match/") ? "record-league-match" :
      normalizedBase.includes("api/track-upcoming-league-matches/") ? "track-upcoming-league-matches" :
      normalizedBase.includes("api/scheduled-task-log/") ? "scheduled-task-log" :
      normalizedBase.includes("api/challenge-club-list/") ? "challenge-club-list" :
      normalizedBase.includes("api/match-assistant-log/") ? "match-assistant-log" : ""
    ))).trim();
    const response = await withServerTraffic(serverTrafficClass, `team-points-admin:${action||inferredKind||"request"}`, () => fetchWithTimeout(endpoint(base, action, params), {
      method: upper,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: raw ? "text/csv,application/json" : "application/json",
        ...(upper !== "GET" ? { "X-P2K-CSRF": state.csrf } : {}),
        ...(normalizedKindHeader(inferredKind)),
        ...(!isForm && body ? { "Content-Type": "application/json" } : {})
      },
      body: body ? (isForm ? body : JSON.stringify(body)) : undefined
    }, Math.max(5_000, Math.min(120_000, Number(timeoutMs) || REQUEST_TIMEOUT_MS)), "The Team Points administration request timed out before the server-side operation completed."));
    if (raw && response.ok) return response;
    try { return await parse(response); }
    catch (error) {
      if ([401, 403].includes(Number(error.status)) && error.code !== "ADMIN_AUTH_FAILED") {
        state.csrf = "";
        await connect();
        return endpointRequest(base, { action, method, body, params, raw, username, requestKind, timeoutMs, serverTrafficClass });
      }
      throw error;
    }
  }

  async function publicRequest(action, params = {}) {
    return parse(await fetchWithTimeout(endpoint(publicEndpoint, action, params), {
      method: "GET", credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json", "Cache-Control": "no-cache" }
    }, REQUEST_TIMEOUT_MS, "The Team Points public request timed out after 30 seconds."));
  }

  window.addEventListener("p2k-auth-change", event => {
    const nextUsername = String(event?.detail?.username || "").trim().toLowerCase();
    if (!nextUsername || (state.username && nextUsername !== state.username)) {
      state.csrf = "";
      state.username = "";
      state.connectedAt = 0;
    }
  });

  window.P2K_TEAM_POINTS_CLIENT = Object.freeze({
    connect,
    request,
    publicRequest,
    endpointRequest,
    diagnostics: () => ({
      loaded: true,
      authenticated: Boolean(state.csrf),
      username: state.username || null,
      connectedAt: state.connectedAt || null,
      lastError: state.lastError || null,
      apiEndpoint,
      authentication: "HttpOnly same-origin session + CSRF"
    })
  });
})();
