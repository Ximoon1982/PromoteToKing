(() => {
  "use strict";
  if (window.__P2K_TRAFFIC_ANALYTICS_LOADED__) return;
  window.__P2K_TRAFFIC_ANALYTICS_LOADED__ = true;
  const config = window.P2K_SITE_CONFIG || {};
  const featureDisabled = config.features?.trafficAnalytics === false;
  const gpc = navigator.globalPrivacyControl === true;
  const dnt = String(navigator.doNotTrack || window.doNotTrack || "") === "1";
  const privacyOptOut = gpc || dnt;
  const suppressionReason = featureDisabled ? "feature_disabled" : gpc ? "gpc" : dnt ? "dnt" : null;
  const endpoint = new URL(config.serverStorage?.trafficAnalyticsEndpoint || "api/traffic/", window.location.href).href;
  let hiddenSent = false;
  const cleanPath = value => { try { const url = new URL(value || location.href, location.href); return String(url.pathname || "/").replace(/\/{2,}/g, "/").slice(0, 180) || "/"; } catch { return "/"; } };
  const diagnostics = () => Object.freeze({ active: !featureDisabled && !privacyOptOut, privacyOptOut, suppressedBy: suppressionReason, dnt, gpc, featureEnabled: !featureDisabled, endpoint: new URL(endpoint).pathname });
  const send = (event, path = cleanPath(location.href)) => {
    if (featureDisabled || privacyOptOut) return;
    const body = JSON.stringify({ event, path: cleanPath(path), referrer: document.referrer || "" });
    try { fetch(endpoint, { method: "POST", credentials: "same-origin", cache: "no-store", keepalive: true, headers: { "Content-Type": "application/json", "X-Club-Tools-Request": "traffic-analytics" }, body }).catch(() => {}); } catch { /* Analytics must never affect the application. */ }
  };
  const pageview = path => { hiddenSent = false; send("pageview", path); };
  const hide = event => { if (hiddenSent || featureDisabled || privacyOptOut) return; hiddenSent = true; send(event || "pagehide"); };
  window.P2K_TRAFFIC_ANALYTICS = Object.freeze({ pageview, privacyOptOut, suppressionReason, diagnostics, mode: "first-party-cookieless" });
  if (featureDisabled || privacyOptOut) return;
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => pageview(), { once: true }); else pageview();
  window.addEventListener("pagehide", () => hide("pagehide"));
  document.addEventListener("visibilitychange", () => { if (document.visibilityState === "hidden") hide("visibility"); else hiddenSent = false; });
})();
