/* P2K first-party, cookieless activity tracking.
 * The ephemeral tab key exists only in sessionStorage; no cross-day identity is sent.
 * Anonymous widget activity is aggregate-only. Search targets are logged separately.
 */
(() => {
  "use strict";
  if (window.__P2K_TRAFFIC_ANALYTICS_LOADED__) return;
  window.__P2K_TRAFFIC_ANALYTICS_LOADED__ = true;
  const script = document.currentScript;
  const embedded = !!script?.hasAttribute("data-p2k-widget-collector");
  const cfg = window.P2K_SITE_CONFIG || {};
  const featureDisabled = cfg.features?.trafficAnalytics === false;
  const gpc = navigator.globalPrivacyControl === true;
  const dnt = String(navigator.doNotTrack || window.doNotTrack || "") === "1";
  const suppressedBy = featureDisabled ? "feature_disabled" : gpc ? "gpc" : dnt ? "dnt" : null;
  const endpoint = embedded
    ? new URL("../../../api/traffic/", script.src).href
    : new URL(cfg.serverStorage?.trafficAnalyticsEndpoint || "api/traffic/", location.href).href;
  const eligible = !suppressedBy;
  const randomKey = () => {
    if (!window.crypto?.getRandomValues) return "";
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), x => x.toString(16).padStart(2, "0")).join("");
  };
  let tabKey = "";
  if (eligible) {
    try {
      tabKey = sessionStorage.getItem("p2k-traffic-tab-v1") || "";
      if (!/^[a-f0-9]{32}$/.test(tabKey)) {
        tabKey = randomKey();
        if (tabKey) sessionStorage.setItem("p2k-traffic-tab-v1", tabKey);
      }
    } catch { tabKey = randomKey(); }
  }
  let pageKey = eligible ? randomKey() : "";
  const cleanPath = value => {
    try { return new URL(value || location.href, location.href).pathname.replace(/\/{2,}/g, "/").slice(0, 180) || "/"; }
    catch { return "/"; }
  };
  const send = (event, options = {}) => {
    if (!eligible) return;
    const body = JSON.stringify({
      event, path: cleanPath(options.path || location.href),
      referrer: document.referrer || "", visit_key: tabKey, view_id: pageKey,
      widget: options.widget || "", action: options.action || ""
    });
    try {
      fetch(endpoint, {
        method: "POST", credentials: "same-origin", cache: "no-store",
        keepalive: true,
        headers: { "Content-Type": "application/json", "X-Club-Tools-Request": "traffic-analytics" },
        body
      }).catch(() => {});
    } catch { /* Never affect the page or widget. */ }
  };
  let closed = false;
  const pageview = path => {
    closed = false;
    pageKey = randomKey();
    send("pageview", {path});
  };
  const widget = embedded ? String(document.body?.dataset.widget || "showcase").toLowerCase() : "";
  const competitionChild = embedded && new URL(location.href).searchParams.get("via") === "competition";
  const widgetTab = name => {
    if (embedded && widget === "competition" && ["showcase", "match-calls", "rivals"].includes(name))
      send("widgettab", {widget, action:name});
  };
  const widgetAction = name => {
    if (embedded && widget === "competition" && name === "open-match-assistant")
      send("widgetaction", {widget, action:name});
  };
  const diagnostics = () => Object.freeze({
    active:eligible, privacyOptOut:gpc || dnt, suppressedBy, dnt, gpc,
    featureEnabled:!featureDisabled, endpoint:new URL(endpoint).pathname,
    mode:"first-party cookieless; ephemeral per-tab visits"
  });
  window.P2K_TRAFFIC_ANALYTICS = Object.freeze({
    pageview, widgetTab, widgetAction, privacyOptOut:gpc || dnt,
    suppressionReason:suppressedBy, diagnostics, mode:"first-party-cookieless"
  });
  if (!eligible) return;
  const start = () => {
    if (embedded) {
      // The Competition parent records tab views; do not double-count child frames.
      if (!competitionChild) send("widgetview", {widget});
    } else pageview();
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, {once:true});
  else start();
  window.addEventListener("pagehide", () => {
    if (closed) return;
    closed = true;
    send("pagehide", {widget});
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") send("visibility", {widget});
    else if (document.visibilityState === "visible") send("resume", {widget});
  });
  window.addEventListener("pageshow", e => {
    if (e.persisted) {
      closed = false;
      pageKey = randomKey();
      if (!embedded) send("pageview"); else if (!competitionChild) send("widgetview", {widget});
    }
  });
})();