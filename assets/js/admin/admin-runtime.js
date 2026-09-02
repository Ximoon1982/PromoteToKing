/* Shared Administration transport and rendering primitives. */
(() => {
"use strict";
window.P2K_ADMIN_FEATURE_MODULES = window.P2K_ADMIN_FEATURE_MODULES || {};
window.P2K_ADMIN_FEATURE_MODULES.runtime = Object.freeze({
create(context) {
const { byId, escapeHTML } = context;
  async function fetchJSON(url, options = {}) {
    const { headers = {}, ...requestOptions } = options;
    const method = String(requestOptions.method || "GET").toUpperCase();
    if (method !== "GET" && window.P2K_TEAM_POINTS_CLIENT?.endpointRequest) {
      let body = requestOptions.body ?? null;
      if (typeof body === "string" && body) { try { body = JSON.parse(body); } catch (_) {} }
      return window.P2K_TEAM_POINTS_CLIENT.endpointRequest(url, { method, body });
    }
    const response = await fetch(url, {
      ...requestOptions,
      cache: "no-store",
      headers: { Accept: "application/json", ...headers }
    });
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      throw new Error(`Server returned non-JSON content (HTTP ${response.status}).`);
    }
    if (!response.ok || data?.ok === false) {
      throw new Error(data?.error?.message || `HTTP ${response.status}`);
    }
    if (!data || typeof data !== "object" || Array.isArray(data)) {
      throw new Error("Server returned an empty or invalid JSON object.");
    }
    return data;
  }

  function metric(label, value) {
    return `<div class="metric"><strong>${escapeHTML(label)}</strong><span>${escapeHTML(value)}</span></div>`;
  }

  function feedback(id, message, type = "") {
    const element = byId(id);
    element.textContent = message;
    element.className = `feedback${type ? ` ${type}` : ""}`;
  }


return Object.freeze({ fetchJSON, metric, feedback });
}
});
})();
