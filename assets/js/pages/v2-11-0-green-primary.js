/* Promote to King v2.11.0: Green-primary + legacy Administration cleanup. */
(() => {
  "use strict";

  const REDIRECTS = new Map([
    ["migration", ["maintenance", "tasks", "control"]],
  ]);

  function normalizeRoute() {
    if (!/\/ui-v2\.html$/i.test(location.pathname)) return;
    const url = new URL(location.href);
    if (url.searchParams.get("view") !== "admin") return;
    const detail = String(url.searchParams.get("adminDetail") || "").toLowerCase();
    const target = REDIRECTS.get(detail);
    if (!target) return;
    url.searchParams.set("adminCategory", target[0]);
    url.searchParams.set("adminDetail", target[1]);
    url.searchParams.set("adminDetailTab", target[2]);
    history.replaceState(history.state, "", url.href);
  }

  function removeLegacyAdministration() {
    const legacyTab = document.getElementById("dashboardAdministrationTab");
    if (legacyTab) legacyTab.hidden = true;
    const legacyPage = document.getElementById("administrationPage");
    if (legacyPage) legacyPage.hidden = true;

    document.querySelectorAll('[data-admin-shell-detail="migration"], [data-admin-detail="migration"]').forEach(node => node.remove());
    document.querySelectorAll('iframe[src*="TeamPointsMigration.html"], iframe[data-src*="TeamPointsMigration.html"]').forEach(frame => frame.closest("section,article,div")?.remove?.() || frame.remove());
  }

  function removeMcaMigrationControls(root = document) {
    const ids = ["liveRanksBlueGreen", "liveRanksBlueGreenStatus"];
    ids.forEach(id => root.getElementById?.(id)?.remove());
    root.querySelectorAll?.('[data-action="sync_blue_to_green"], [data-blue-green-sync]').forEach(node => node.remove());
  }

  function normalizeFrames() {
    document.querySelectorAll("iframe.dashboard-integrated-frame, iframe.dashboard-admin-detail-frame").forEach(frame => {
      frame.setAttribute("scrolling", "no");
      frame.style.overflow = "hidden";
      frame.style.maxHeight = "none";
      frame.style.width = "100%";
    });
  }

  function observe() {
    const observer = new MutationObserver(() => {
      removeLegacyAdministration();
      removeMcaMigrationControls();
      normalizeFrames();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  normalizeRoute();
  const mount = () => {
    document.body?.classList.add("p2k-green-primary");
    removeLegacyAdministration();
    removeMcaMigrationControls();
    normalizeFrames();
    observe();
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, { once: true });
  else mount();
})();
