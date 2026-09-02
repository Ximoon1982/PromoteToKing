/* Existing Administration diagnostics behavior, isolated behind a feature controller. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.diagnostics = Object.freeze({
create(context) {
const { byId, escapeHTML, fetchJSON, feedback } = context;
  let latestDiagnosticSnapshot = null;

  function diagnosticContexts() {
    const routes = window.P2K_SITE_CONFIG?.routes || {};
    const known = [
      ["index", "index.html"], ["find", routes.find || "FindMatch.htm"],
      ["upcoming", routes.upcoming || "AnalyzeMatches.htm"], ["creation", routes.creation || "MatchCreationAnalyzer.htm"],
      ["open", routes.open || "AnalyzeMatch.html"], ["recruit", routes.recruit || "RecruitMatch.html"],
      ["challenges", routes.challenges || "ChallengeListAssistant.html"], ["teamPoints", routes.teamPoints || "TeamPointsAdmin.html"]
    ];
    const frames = window.P2K_TOOL_FRAMES;
    return known.map(([key, route]) => {
      if (key === "index") return { key, route, loaded: true, win: window };
      const frame = frames?.get?.(key);
      try {
        if (frame?.contentWindow) return { key, route, loaded: true, win: frame.contentWindow };
      } catch { /* Same-origin frame not ready. */ }
      return { key, route, loaded: false, win: null };
    });
  }

  function formatBytes(value) {
    const bytes = Math.max(0, Number(value) || 0);
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MiB`;
  }

  async function collectDiagnostics() {
    const contexts = [];
    for (const entry of diagnosticContexts()) {
      if (!entry.loaded || !entry.win) {
        contexts.push({ key: entry.key, route: entry.route, loaded: false });
        continue;
      }
      try {
        const client = entry.win.P2K_API_CLIENT;
        const cache = entry.win.P2K_API_CACHE;
        const coordinator = entry.win.P2K_ANALYSIS_COORDINATOR;
        contexts.push({
          key: entry.key,
          route: entry.route,
          loaded: true,
          client: client?.diagnostics?.() || { loaded: false },
          cache: cache?.diagnostics?.() || null,
          storage: await cache?.inspect?.() || null,
          coordinator: coordinator?.diagnostics?.() || null
        });
      } catch (error) {
        contexts.push({ key: entry.key, route: entry.route, loaded: true, error: error.message || String(error) });
      }
    }
    let backend = null;
    try { backend = await fetchJSON("api/diagnostics/"); }
    catch (error) { backend = { ok: false, error: error.message }; }
    let teamPoints = { client: window.P2K_TEAM_POINTS_CLIENT?.diagnostics?.() || { loaded: false } };
    try {
      if (window.P2K_TEAM_POINTS_CLIENT && window.P2K_AUTH?.getSession?.()?.username) {
        const status = await window.P2K_TEAM_POINTS_CLIENT.request("status");
        teamPoints = { ...teamPoints, status };
      }
    } catch (error) {
      teamPoints = { ...teamPoints, error: error.message || String(error) };
    }
    return {
      generatedAt: new Date().toISOString(),
      version: window.P2K_SITE_CONFIG?.version || "unknown",
      builtAt: window.P2K_SITE_CONFIG?.builtAt || null,
      schemaVersion: window.P2K_SITE_CONFIG?.schemaVersion || null,
      modelVersions: window.P2K_SITE_CONFIG?.modelVersions || {},
      userAgent: navigator.userAgent,
      online: navigator.onLine,
      origin: location.origin,
      backend,
      teamPoints,
      contexts
    };
  }

  function diagnosticCard(item) {
    if (item.loaded === false) return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list"><li><strong>Context:</strong> not loaded</li><li><strong>Route:</strong> ${escapeHTML(item.route || "—")}</li><li>Open this tool once to initialize its runtime diagnostics.</li></ul></section>`;
    if (item.error) return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list"><li><strong>Error:</strong> ${escapeHTML(item.error)}</li></ul></section>`;
    const client = item.client || {};
    const clientCounters = client.counters || {};
    const cache = item.cache || {};
    const cacheCounters = cache.counters || {};
    const storage = item.storage || {};
    const coordinator = item.coordinator || {};
    return `<section class="site-diagnostics-card"><h3>${escapeHTML(item.key)}</h3><ul class="site-diagnostics-list">
      <li><strong>API client:</strong> ${client.loaded ? "loaded" : "not loaded"}</li>
      <li><strong>Requests:</strong> ${client.activeRequests || 0} active; ${client.queuedRequests || 0} queued; concurrency ${client.adaptiveConcurrency || 0} / ${client.configuredConcurrency || 0}</li>
      <li><strong>Transport:</strong> ${clientCounters.fetches || 0} fetches; ${clientCounters.retries || 0} retries; ${clientCounters.rateLimits || 0} rate limits; ${clientCounters.jsonp || 0} JSONP</li>
      <li><strong>Cache:</strong> ${storage.indexedDbEntries || 0} IndexedDB (${formatBytes(storage.indexedDbApproximateBytes)}); ${cache.memoryEntries || 0} memory (${formatBytes(cache.memoryApproximateBytes)})</li>
      <li><strong>Cache activity:</strong> ${clientCounters.cacheHits || 0} hits; ${clientCounters.cacheMisses || 0} misses; ${cacheCounters.writeFailures || 0} write failures</li>
      <li><strong>Coordination:</strong> BroadcastChannel ${cache.broadcastChannelAvailable ? "available" : "unavailable"}; ${(coordinator.registeredTools || []).join(", ") || "no registered analysis tool"}</li>
    </ul></section>`;
  }

  function renderDiagnostics(snapshot) {
    latestDiagnosticSnapshot = snapshot;
    const backend = snapshot.backend || {};
    const backendCard = `<section class="site-diagnostics-card"><h3>Server</h3><ul class="site-diagnostics-list">
      <li><strong>Backend:</strong> ${escapeHTML(backend.backend || backend.error || "unavailable")}</li>
      <li><strong>Writable storage:</strong> ${backend.writable === true ? "yes" : backend.writable === false ? "no" : "unknown"}</li>
      <li><strong>CRON token:</strong> ${backend.cronConfigured === true ? "configured" : backend.cronConfigured === false ? "not configured" : "unknown"}</li>
      <li><strong>Tracking index:</strong> ${backend.followRegistryEntries ?? "—"}</li>
      <li><strong>Legacy migration:</strong> ${backend.trackingMigration?.completedAt ? `${backend.trackingMigration.convertedMatches || 0} match(es), ${backend.trackingMigration.convertedSnapshots || 0} snapshot(s)` : "not required on this request"}</li>
    </ul></section>`;
    const releaseCard = `<section class="site-diagnostics-card"><h3>Release</h3><ul class="site-diagnostics-list">
      <li><strong>Package:</strong> ${escapeHTML(snapshot.version)}</li><li><strong>Built:</strong> ${escapeHTML(snapshot.builtAt || "—")}</li><li><strong>Package schema:</strong> ${escapeHTML(snapshot.schemaVersion ?? "—")}</li><li><strong>Baseline shell:</strong> v2.1.2 preserved</li>
    </ul></section>`;
    const tp = snapshot.teamPoints || {};
    const tpStatus = tp.status || {};
    const tpCron = tpStatus.cron_state || {};
    const tpJob = tpStatus.job || {};
    const teamPointsCard = `<section class="site-diagnostics-card"><h3>Team Points scheduler</h3><ul class="site-diagnostics-list">
      <li><strong>Client:</strong> ${tp.client?.loaded ? (tp.client.authenticated ? "secured session active" : "loaded, not connected") : "not loaded"}</li>
      <li><strong>Database schema:</strong> ${escapeHTML(tpStatus.schema_version ?? "—")}</li>
      <li><strong>Job:</strong> ${escapeHTML(tpJob.status || "no status")}${tpJob.id ? ` · ${escapeHTML(tpJob.id)}` : ""}</li>
      <li><strong>Queue:</strong> ${escapeHTML(tpJob.queue?.pending ?? "—")} pending; ${escapeHTML(tpJob.queue?.retry ?? "—")} retry; ${escapeHTML(tpJob.queue?.failed ?? "—")} failed</li>
      <li><strong>CRON chain:</strong> ${escapeHTML(tpCron.last_status || "not started")}; next ${escapeHTML(tpCron.next_run_at || "—")}</li>
      <li><strong>Last message:</strong> ${escapeHTML(tpCron.last_message || tp.error || "—")}</li>
    </ul></section>`;
    byId("runtimeDiagnosticsGrid").innerHTML = releaseCard + backendCard + teamPointsCard + snapshot.contexts.map(diagnosticCard).join("");
    const events = snapshot.contexts.flatMap(item => [
      ...(item.client?.events || []).map(event => ({ context: item.key, source: "client", ...event })),
      ...(item.cache?.events || []).map(event => ({ context: item.key, source: "cache", ...event }))
    ]).sort((a, b) => Number(b.at || 0) - Number(a.at || 0)).slice(0, 80);
    byId("runtimeDiagnosticsLog").textContent = events.length ? events.map(event => `${new Date(event.at || Date.now()).toISOString()} [${event.context}/${event.source}] ${event.type || event.message || JSON.stringify(event)}`).join("\n") : "No recent diagnostic events.";
  }

  async function refreshDiagnostics() {
    feedback("diagnosticFeedback", "Collecting diagnostics…");
    try {
      renderDiagnostics(await collectDiagnostics());
      feedback("diagnosticFeedback", "Diagnostics refreshed.", "success");
    } catch (error) {
      feedback("diagnosticFeedback", error.message, "error");
    }
  }

  async function copyDiagnostics() {
    latestDiagnosticSnapshot = await collectDiagnostics();
    const text = JSON.stringify(latestDiagnosticSnapshot, null, 2);
    try { await navigator.clipboard.writeText(text); }
    catch {
      const area = document.createElement("textarea"); area.value = text; document.body.appendChild(area); area.select(); document.execCommand("copy"); area.remove();
    }
    feedback("diagnosticFeedback", "Diagnostics copied.", "success");
  }

  async function clearDiagnosticsCache() {
    const seen = new Set();
    for (const entry of diagnosticContexts()) {
      if (!entry.loaded || !entry.win) continue;
      const cache = entry.win.P2K_API_CACHE;
      if (!cache || seen.has(cache)) continue;
      seen.add(cache);
      await cache.clear?.();
    }
    await refreshDiagnostics();
    feedback("diagnosticFeedback", "Shared cache cleared.", "success");
  }

  byId("refreshDiagnostics").addEventListener("click", refreshDiagnostics);
  byId("copyDiagnostics").addEventListener("click", copyDiagnostics);
  byId("clearDiagnosticsCache").addEventListener("click", clearDiagnosticsCache);

return Object.freeze({ refreshDiagnostics });
}
});
})();
