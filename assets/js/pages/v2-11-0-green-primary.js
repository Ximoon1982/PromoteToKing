/* Promote to King v2.11.0: Green-primary + retired migration Administration cleanup. */
(() => {
  "use strict";

  const REDIRECTS = new Map([["migration", ["maintenance", "tasks", "control"]]]);

  function normalizeRoute() {
    if (!/\/ui-v2\.html$/i.test(location.pathname)) return;
    const url = new URL(location.href);
    if (url.searchParams.get("view") !== "admin") return;
    let changed=false;
    const detail = String(url.searchParams.get("adminDetail") || "").toLowerCase();
    const target = REDIRECTS.get(detail);
    if (target) {
      url.searchParams.set("adminCategory", target[0]);
      url.searchParams.set("adminDetail", target[1]);
      url.searchParams.set("adminDetailTab", target[2]);
      changed=true;
    }
    if (String(url.searchParams.get("adminDetailTab") || "").toLowerCase() === "reconciliation") {
      url.searchParams.set("adminCategory", "maintenance");
      url.searchParams.set("adminDetail", "diagnostics");
      url.searchParams.set("adminDetailTab", "health");
      changed=true;
    }
    if(changed)history.replaceState(history.state, "", url.href);
  }

  function removeRetiredAdministration() {
    const legacyPage = document.getElementById("administrationPage"); if (legacyPage) legacyPage.hidden = true;
    document.querySelectorAll('[data-admin-shell-detail="migration"], [data-admin-detail="migration"], [data-admin-detail-tab="reconciliation"], [href*="adminDetailTab=reconciliation"]').forEach(node => node.remove());
    document.querySelectorAll('iframe[src*="TeamPointsMigration.html"], iframe[data-src*="TeamPointsMigration.html"]').forEach(frame => frame.closest("section,article,div")?.remove?.() || frame.remove());
    document.querySelectorAll("a,button").forEach(node=>{const value=String(node.textContent||"").trim().toLowerCase();if(value==="data reconciliation"||value==="production migration")node.remove();});
  }

  function removeMcaMigrationControls(root = document) {
    ["liveRanksBlueGreen", "liveRanksBlueGreenStatus"].forEach(id => root.getElementById?.(id)?.remove());
    root.querySelectorAll?.('[data-action="sync_blue_to_green"], [data-blue-green-sync]').forEach(node => node.remove());
  }


  function normalizeFrames() {
    document.querySelectorAll("iframe.dashboard-integrated-frame, iframe.dashboard-admin-detail-frame").forEach(frame => {
      frame.setAttribute("scrolling", "no"); frame.style.overflow = "hidden"; frame.style.maxHeight = "none"; frame.style.width = "100%";
    });
  }

  function observe() {
    const observer = new MutationObserver(() => { normalizeRoute(); removeRetiredAdministration(); removeMcaMigrationControls(); normalizeFrames(); });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  normalizeRoute();
  const mount = () => {
    document.body?.classList.add("p2k-green-primary");
    removeRetiredAdministration(); removeMcaMigrationControls(); normalizeFrames(); observe();
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, { once: true }); else mount();
})();
