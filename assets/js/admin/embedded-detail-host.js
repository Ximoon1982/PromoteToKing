/* Canonical standalone/iframe activation, sizing and failure lifecycle. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.embeddedHost = Object.freeze({create(context) {
const { state, byId, applyOAuthContext } = context;
function integratedFrames() {
    return Array.from(document.querySelectorAll("iframe.dashboard-integrated-frame[id]"));
  }
  function setIntegratedFrameActivity(activeId = "") {
    integratedFrames().forEach(frame => {
      if (!frame?.contentWindow) return;
      try { frame.contentWindow.postMessage({ type: "p2k-tool-activity", active: frame.id === activeId }, window.location.origin); } catch (_) {}
    });
  }
  function ensureIntegratedFrame(id) {
    const frame = byId(id);
    if (!frame) return;
    const activate = () => {
      setIntegratedFrameActivity(id);
      try { frame.contentWindow?.postMessage({ type: "p2k-admin-ready", allowed: window.P2K_ADMIN_MODE === true }, window.location.origin); } catch (_) {}
    };
    if (!frame.dataset.p2kActivityBound) {
      frame.dataset.p2kActivityBound = "1";
      frame.addEventListener("load", () => {
        if (frame._p2kLoadTimer) clearTimeout(frame._p2kLoadTimer);
        frame.dataset.p2kLoaded = "1";
        activate();
        window.setTimeout(activate, 50);
      });
    }
    const armLoadTimeout = () => {
      if (frame._p2kLoadTimer) clearTimeout(frame._p2kLoadTimer);
      frame.dataset.p2kLoaded = "0";
      frame._p2kLoadTimer = window.setTimeout(() => {
        if (frame.dataset.p2kLoaded === "1" || frame.dataset.p2kRetried === "1") return;
        frame.dataset.p2kRetried = "1";
        try {
          const retry = applyOAuthContext(new URL(frame.src || frame.dataset.src || "about:blank", window.location.href));
          retry.searchParams.set("hotfixRetry", String(Date.now()));
          frame.src = retry.href;
        } catch (error) { console.warn(`Unable to retry integrated frame ${id}.`, error); }
      }, 8000);
    };
    if (!frame.src) {
      const url = applyOAuthContext(new URL(frame.dataset.src || "about:blank", window.location.href));
      url.searchParams.set("active", "1");
      if (id === "teamInsightsFrame") {
        if (state.teamStart) url.searchParams.set("start", state.teamStart);
        if (state.teamEnd) url.searchParams.set("end", state.teamEnd);
      }
      if (id === "adminIntelligenceFrame" && state.adminContext) url.searchParams.set("tab", state.adminContext);
      if (id === "adminTasksFrame" && ["scheduled","green"].includes(state.adminToolTab)) url.searchParams.set("tab", state.adminToolTab);
      if (id === "adminOpenFrame") {
        const parentParams = new URLSearchParams(window.location.search);
        for (const key of ["match", "club", "team", "scope"]) { const value = parentParams.get(key); if (value) url.searchParams.set(key, value); }
      }
      frame.src = url.href;
      armLoadTimeout();
    } else {
      if (id === "adminIntelligenceFrame" && state.adminContext) {
        try {
          const current = applyOAuthContext(new URL(frame.src, window.location.href));
          if (current.searchParams.get("tab") !== state.adminContext) {
            current.searchParams.set("tab", state.adminContext);
            current.searchParams.set("active", "1");
            frame.dataset.p2kLoaded = "0";
            frame.src = current.href;
            armLoadTimeout();
            return;
          }
        } catch (_) {}
      }
      if (id === "adminTasksFrame" && ["scheduled","green"].includes(state.adminToolTab)) {
        try {
          const current = applyOAuthContext(new URL(frame.src, window.location.href));
          if (current.searchParams.get("tab") !== state.adminToolTab) {
            current.searchParams.set("tab", state.adminToolTab);
            current.searchParams.set("active", "1");
            frame.dataset.p2kLoaded = "0";
            frame.src = current.href;
            armLoadTimeout();
            return;
          }
        } catch (_) {}
      }
      activate();
      if (frame.dataset.p2kLoaded !== "1") armLoadTimeout();
    }
  }

return Object.freeze({ integratedFrames, setIntegratedFrameActivity, ensureIntegratedFrame });
}});
})();
