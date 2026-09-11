/* v2.9.0 compatibility loader: first-party, cookieless analytics only. */
(() => {
  "use strict";
  if (window.P2K_TRAFFIC_ANALYTICS) return;
  if (navigator.globalPrivacyControl === true || navigator.doNotTrack === "1") return;
  const existing = [...document.scripts].some((node) => /traffic-analytics\.js(?:\?|$)/.test(node.src || ""));
  if (existing) return;
  const script = document.createElement("script");
  script.src = "assets/js/shared/traffic-analytics.js?v=2.11.5-bf6a828490f57";
  script.defer = true;
  document.head.appendChild(script);
})();
