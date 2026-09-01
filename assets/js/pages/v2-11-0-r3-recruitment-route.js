/* Promote to King v2.11.0 R3: native Recruitment route mount bridge. */
(() => {
  "use strict";

  let queued = false;

  function isRecruitmentRoute() {
    if (!/\/ui-v2\.html$/i.test(location.pathname)) return false;
    const params = new URL(location.href).searchParams;
    return params.get("view") === "admin" &&
      (params.get("adminCategory") || "competitions") === "members" &&
      params.get("adminDetail") === "recruitment";
  }

  function ensureRecruitmentMountHost() {
    queued = false;
    if (!isRecruitmentRoute()) return;
    const adminHost = document.getElementById("adminDashboardHost");
    if (!adminHost) return;
    if (document.getElementById("dashboardAdminMainContent")) return;

    const mount = document.createElement("div");
    mount.id = "dashboardAdminMainContent";
    mount.className = "dashboard-admin-native-detail-host";
    adminHost.replaceChildren(mount);
    adminHost.hidden = false;
  }

  function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(ensureRecruitmentMountHost);
  }

  ["pushState", "replaceState"].forEach(name => {
    const original = history[name];
    if (!original || original.__p2kRecruitmentR3) return;
    const wrapped = function(...args) {
      const result = original.apply(this, args);
      schedule();
      return result;
    };
    Object.defineProperty(wrapped, "__p2kRecruitmentR3", { value: true });
    history[name] = wrapped;
  });

  addEventListener("popstate", schedule);
  const observer = new MutationObserver(schedule);
  const mountObserver = () => {
    observer.observe(document.documentElement, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ["hidden", "class", "aria-pressed"]
    });
    schedule();
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mountObserver, { once: true });
  else mountObserver();
})();
