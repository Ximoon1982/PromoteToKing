/* Promote to King v2.11.0: Admin SPA/frame stability + production cleanup. */
(() => {
  "use strict";

  const RELEASE = "2.11.0-r5";
  let reconcileQueued = false;
  let lastHeight = 0;
  const byId = id => document.getElementById(id);

  function currentUrl() { return new URL(window.location.href); }
  function isDashboardPage() { return /\/ui-v2\.html$/i.test(location.pathname); }
  function dashboardAdminVisible() {
    const host = byId("adminDashboardHost");
    if (host && !host.hidden) return true;
    return isDashboardPage() && currentUrl().searchParams.get("view") === "admin";
  }
  function recruitmentRoute() {
    if (!isDashboardPage()) return false;
    const params = currentUrl().searchParams;
    return params.get("view") === "admin" && (params.get("adminCategory") || "competitions") === "members" && params.get("adminDetail") === "recruitment";
  }

  function installStyles() {
    if (byId("p2kV2110R2Styles")) return;
    const style = document.createElement("style");
    style.id = "p2kV2110R2Styles";
    style.textContent = `
      body.p2k-admin-live #dashboardAdminToggleHost button[aria-pressed="true"],
      body.p2k-admin-live [data-admin-category][aria-pressed="true"],
      body.p2k-admin-live [data-admin-category].is-active,
      body.p2k-admin-live .dashboard-admin-detail-tab.is-active{
        border-color:#d45b50!important;color:#ffd9d5!important;background:rgba(177,48,39,.18)!important;
      }
      body.p2k-admin-live [data-admin-category][aria-pressed="true"] svg,
      body.p2k-admin-live [data-admin-category].is-active svg{stroke:#ff8e82!important}
      body.p2k-admin-live #dashboardAdminToggleHost button[aria-pressed="true"]{box-shadow:0 0 0 1px rgba(212,91,80,.25) inset!important}
      .dashboard-admin-detail-frame[data-p2k-r2-stable="1"]{display:block!important;width:100%!important;min-height:520px!important;max-height:none!important;overflow:hidden!important}
    `;
    document.head.appendChild(style);
  }

  function syncAdminAccent() {
    if (!document.body) return;
    const active = dashboardAdminVisible();
    document.body.classList.toggle("p2k-admin-live", active);
    document.body.classList.toggle("p2k-admin-canonical", active);
  }

  function replaceExactText(root, from, to) {
    root.querySelectorAll?.("a,button,h1,h2,h3,h4,p,span,strong,small,em,option").forEach(node => {
      if (String(node.textContent || "").trim() === from) node.textContent = to;
    });
  }

  function removeRetiredLinks(root = document) {
    root.querySelectorAll?.('[href*="adminDetailTab=reconciliation"], [data-admin-detail-tab="reconciliation"], [data-task-tab="migration"], [data-admin-shell-detail="migration"], [data-admin-detail="migration"]').forEach(node => node.remove());
    root.querySelectorAll?.("a,button").forEach(node => {
      const value=String(node.textContent||"").trim().toLowerCase();
      if(value==="data reconciliation"||value==="production migration")node.remove();
    });
  }

  function cleanupTaskControl() {
    const host = byId("p2kTaskControl");
    if (!host) return;
    ["greenCutoverMetrics", "greenGabMetrics", "greenHeatmapMetrics"].forEach(id => {
      const node = byId(id); const card = node?.closest("section.control-card, .control-card"); if (card) card.remove();
    });
    byId("greenValidate")?.remove();
    removeRetiredLinks(host);

    const tab = host.querySelector('[data-task-tab="green"]'); if (tab) tab.textContent = "Team Points";
    const overview = byId("greenSchedulerControl")?.querySelector(".green-overview-card");
    if (overview) {
      const eyebrow = overview.querySelector(".eyebrow"); if (eyebrow) eyebrow.textContent = "Production runtime";
      const title = overview.querySelector("h2"); if (title) title.textContent = "Team Points control";
      const description = overview.querySelector("h2 + p"); if (description) description.textContent = "Operational worker, freshness, debt, cycle and browser-accelerator controls for the production Team Points databases.";
    }
    const refresh = byId("greenSchedulerRefresh"); if (refresh) refresh.textContent = "Refresh";
    const run = byId("greenRunNow"); if (run) run.textContent = "Run one Team Points slice";
    const message = byId("greenSchedulerMessage"); if (message && /Green scheduler status/i.test(message.textContent || "")) message.textContent = "Team Points runtime status is loading.";
    replaceExactText(host, "Green Team Points", "Team Points");
    replaceExactText(host, "Green Team Points control", "Team Points control");
    replaceExactText(host, "Green Accelerator", "Team Points Accelerator");
    replaceExactText(host, "Recent Green worker runs", "Recent Team Points worker runs");
    replaceExactText(host, "Refresh Green", "Refresh");
    replaceExactText(host, "Run one Green slice", "Run one Team Points slice");
    host.querySelectorAll("p").forEach(p => {
      let value = String(p.textContent || "");
      value = value.replaceAll("current Green cycle", "current Team Points cycle").replaceAll("Green invocations", "Team Points invocations").replaceAll("ordinary Green work", "ordinary Team Points work");
      if (value !== p.textContent) p.textContent = value;
    });
  }

  function labelForMetric(id, label) {
    const metric=byId(id)?.closest?.(".dashboard-admin-shell-metric");
    const span=metric?.querySelector?.("span");
    if(span)span.textContent=label;
  }

  function cleanupParentAdmin() {
    if (!isDashboardPage()) return;
    const host = byId("adminDashboardHost");
    if (!host) return;
    removeRetiredLinks(host);
    replaceExactText(host, "Green Team Points", "Team Points");
    replaceExactText(host, "Green Team Points control", "Team Points control");
    host.querySelectorAll("p,small,em").forEach(node => {
      const value = String(node.textContent || "");
      if (value.includes("Green accelerator control")) node.textContent = value.replace("Green accelerator control", "Team Points accelerator control");
    });

    const taskCard=host.querySelector('[data-admin-shell-card="tasks"]');
    const taskDescription=taskCard?.querySelector(":scope > p");
    if(taskDescription)taskDescription.textContent="CRON-compatible production Team Points worker, cycle, GFFL and accelerator state.";
    labelForMetric("adminShellTaskPending","GFFL due");
    labelForMetric("adminShellTaskFailed","Work errors");
    labelForMetric("adminShellTaskGeneration","Cycle");
    const taskSource=byId("adminShellSource_tasks");if(taskSource)taskSource.textContent="Green production runtime";

    const diagnosticCard=host.querySelector('[data-admin-shell-card="diagnostics"]');
    const diagnosticDescription=diagnosticCard?.querySelector(":scope > p");
    if(diagnosticDescription)diagnosticDescription.textContent="Runtime, API and Green production integrity health.";
    labelForMetric("adminShellDiagFailed","Work errors");
    labelForMetric("adminShellDiagBoards","Finished unresolved");

    labelForMetric("adminShellFreshRatings","Fresh ratings");
    labelForMetric("adminShellFreshMatches","Current matches fresh");
    labelForMetric("adminShellFreshBoards","Boards due now");
    const source=byId("adminShellSource_freshness");if(source)source.textContent="Green Core / Analytics freshness";

    labelForMetric("adminShellStorageLag","Analytics rebuild age");
  }

  function stabilizeAdminFrame() {
    if (!isDashboardPage() || !dashboardAdminVisible()) return;
    const frame = byId("adminShellDetailFrame");
    if (!frame) return;
    const frameWrap = byId("adminShellDetailFrameWrap") || frame.parentElement;
    if (frame.hidden || frameWrap?.hidden) { frame.removeAttribute("data-p2k-r2-stable"); return; }
    const detail = byId("adminShellDetail");
    if (!detail || detail.hidden) return;
    if (frame.dataset.p2kR2Stable !== "1") frame.dataset.p2kR2Stable = "1";
    if (frame.hidden) frame.hidden = false;
    frame.removeAttribute("aria-hidden");
    if (frame.style.display !== "block") frame.style.display = "block";
    if (frame.style.width !== "100%") frame.style.width = "100%";
    if (frame.style.maxHeight !== "none") frame.style.maxHeight = "none";
    if (!frame.style.height || Number.parseFloat(frame.style.height) < 360) frame.style.height = "520px";
  }

  function embeddedHeightSender() {
    if (window.parent === window || new URLSearchParams(location.search).get("embedded") !== "1") return;
    const measure = () => {
      const html = document.documentElement, body = document.body;
      const candidates = [html?.scrollHeight, html?.offsetHeight, body?.scrollHeight, body?.offsetHeight, body?.getBoundingClientRect?.().height].map(Number).filter(Number.isFinite);
      return Math.max(360, ...candidates, 0);
    };
    const send = () => {
      const height = Math.ceil(measure()); if (Math.abs(height - lastHeight) < 2) return; lastHeight = height;
      try { parent.postMessage({type:"p2k-frame-height", height, source:RELEASE}, location.origin); } catch (_) {}
    };
    const schedule = () => requestAnimationFrame(send);
    schedule(); addEventListener("load", schedule, {once:true}); addEventListener("resize", schedule, {passive:true});
    if("ResizeObserver" in window){const ro = new ResizeObserver(schedule); ro.observe(document.documentElement); if (document.body) ro.observe(document.body);}
    const mo = new MutationObserver(schedule); if (document.body) mo.observe(document.body, {subtree:true, childList:true, attributes:true, characterData:true});
  }

  function reconcile() {
    reconcileQueued = false; installStyles(); syncAdminAccent(); cleanupParentAdmin(); cleanupTaskControl(); stabilizeAdminFrame(); removeRetiredLinks();
  }
  function scheduleReconcile() { if (reconcileQueued) return; reconcileQueued = true; requestAnimationFrame(reconcile); }

  function instrumentHistory() {
    if (history.__p2kR2Instrumented) return; Object.defineProperty(history, "__p2kR2Instrumented", {value:true});
    ["pushState","replaceState"].forEach(name => { const original = history[name]; history[name] = function(...args) { const result = original.apply(this, args); scheduleReconcile(); return result; }; });
    addEventListener("popstate", scheduleReconcile);
  }

  function mount() {
    instrumentHistory(); embeddedHeightSender();
    const observer = new MutationObserver(scheduleReconcile);
    observer.observe(document.documentElement, {subtree:true, childList:true, attributes:true, attributeFilter:["hidden","class","aria-pressed","aria-selected","style","src"]});
    scheduleReconcile();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, {once:true}); else mount();
})();
