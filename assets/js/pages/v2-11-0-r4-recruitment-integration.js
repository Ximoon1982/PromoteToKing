/* Promote to King v2.11.0 R4: Recruitment native-detail integration + secured API bridge. */
(() => {
  "use strict";

  let queued = false;

  const byId = id => document.getElementById(id);

  function recruitmentRoute() {
    if (!/\/ui-v2\.html$/i.test(location.pathname)) return false;
    const params = new URL(location.href).searchParams;
    return params.get("view") === "admin" &&
      (params.get("adminCategory") || "competitions") === "members" &&
      params.get("adminDetail") === "recruitment";
  }

  function recruitmentBase() {
    return String(window.P2K_SITE_CONFIG?.serverStorage?.recruitmentAdminEndpoint || "server/team-points/public/recruitment-admin.php");
  }

  function sameRecruitmentEndpoint(input) {
    try {
      const url = new URL(typeof input === "string" ? input : input?.url || String(input || ""), location.href);
      const expected = new URL(recruitmentBase(), location.href);
      return url.origin === expected.origin && url.pathname === expected.pathname;
    } catch (_) {
      return false;
    }
  }

  function requestHasCsrf(input, init = {}) {
    try {
      const headers = new Headers(init?.headers || (typeof Request !== "undefined" && input instanceof Request ? input.headers : undefined));
      return headers.has("X-P2K-CSRF");
    } catch (_) {
      return false;
    }
  }

  function installSecuredRecruitmentFetchBridge() {
    if (window.fetch?.__p2kRecruitmentR4) return;
    const originalFetch = window.fetch.bind(window);
    const wrapped = async function(input, init = {}) {
      const method = String(init?.method || (typeof Request !== "undefined" && input instanceof Request ? input.method : "GET") || "GET").toUpperCase();
      if (!["GET", "HEAD", "OPTIONS"].includes(method) && sameRecruitmentEndpoint(input) && !requestHasCsrf(input, init)) {
        const client = window.P2K_TEAM_POINTS_CLIENT;
        if (!client?.endpointRequest) return originalFetch(input, init);
        const url = new URL(typeof input === "string" ? input : input?.url || String(input || ""), location.href);
        const action = String(url.searchParams.get("action") || "");
        let body = null;
        if (init?.body != null) {
          if (typeof init.body === "string") {
            try { body = JSON.parse(init.body); } catch (_) { body = init.body; }
          } else body = init.body;
        }
        try {
          const payload = await client.endpointRequest(recruitmentBase(), {
            action,
            method,
            body,
            timeoutMs: 120000,
            serverTrafficClass: "foreground"
          });
          return new Response(JSON.stringify(payload), {
            status: 200,
            headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" }
          });
        } catch (error) {
          return new Response(JSON.stringify({
            ok: false,
            error: {
              code: String(error?.code || "RECRUITMENT_REQUEST_FAILED"),
              message: String(error?.message || error || "Recruitment request failed.")
            }
          }), {
            status: Math.max(400, Math.min(599, Number(error?.status) || 500)),
            headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" }
          });
        }
      }
      return originalFetch(input, init);
    };
    Object.defineProperty(wrapped, "__p2kRecruitmentR4", { value: true });
    window.fetch = wrapped;
  }

  function ensureNativeDetailMount() {
    queued = false;
    const mount = byId("dashboardAdminMainContent");
    if (!recruitmentRoute()) {
      if (mount?.dataset?.p2kRecruitmentR4 === "1") mount.hidden = true;
      return;
    }

    const adminHost = byId("adminDashboardHost");
    const detailHost = byId("adminShellDetail");
    const frame = byId("adminShellDetailFrame");
    if (!adminHost || !detailHost) return;

    detailHost.hidden = false;
    const title = byId("adminShellDetailTitle");
    if (title) title.textContent = "Recruitment";
    const breadcrumb = byId("adminShellDetailBreadcrumb");
    if (breadcrumb) breadcrumb.textContent = "Administration · Members";
    const tabs = byId("adminShellDetailTabs");
    if (tabs) {
      tabs.hidden = true;
      tabs.replaceChildren();
    }

    if (frame) {
      frame.hidden = true;
      frame.style.display = "none";
      frame.removeAttribute("src");
    }

    document.querySelectorAll("[data-admin-shell-panel]").forEach(panel => { panel.hidden = true; });

    let target = mount;
    if (!target) {
      target = document.createElement("div");
      target.id = "dashboardAdminMainContent";
      target.className = "dashboard-admin-native-detail-host";
      target.dataset.p2kRecruitmentR4 = "1";
      if (frame?.parentElement) frame.insertAdjacentElement("afterend", target);
      else detailHost.appendChild(target);
    }
    target.hidden = false;
    adminHost.hidden = false;
  }

  function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(ensureNativeDetailMount);
  }

  function instrumentHistory() {
    if (history.__p2kRecruitmentR4) return;
    Object.defineProperty(history, "__p2kRecruitmentR4", { value: true });
    ["pushState", "replaceState"].forEach(name => {
      const original = history[name];
      history[name] = function(...args) {
        const result = original.apply(this, args);
        schedule();
        return result;
      };
    });
    addEventListener("popstate", schedule);
  }

  function mount() {
    installSecuredRecruitmentFetchBridge();
    instrumentHistory();
    const observer = new MutationObserver(schedule);
    observer.observe(document.documentElement, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ["hidden", "class", "aria-pressed", "aria-selected"]
    });
    schedule();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, { once: true });
  else mount();
})();
