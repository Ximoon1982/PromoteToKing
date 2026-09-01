/* Promote to King v2.11.0 R2: Admin SPA stability + post-Green cleanup. */
(() => {
  "use strict";

  const RELEASE = "2.11.0-r2";
  let reconcileQueued = false;
  let recruitmentLoading = false;
  let lastHeight = 0;

  const byId = id => document.getElementById(id);
  const esc = value => String(value ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

  function currentUrl() { return new URL(window.location.href); }
  function isDashboardPage() { return /\/ui-v2\.html$/i.test(location.pathname); }
  function dashboardAdminVisible() {
    const host = byId("adminDashboardHost");
    if (host && !host.hidden) return true;
    return isDashboardPage() && currentUrl().searchParams.get("view") === "admin";
  }
  function activeAdminCategory() {
    const active = document.querySelector('[data-admin-category][aria-pressed="true"], [data-admin-category].is-active');
    return String(active?.dataset?.adminCategory || currentUrl().searchParams.get("adminCategory") || "").toLowerCase();
  }
  function adminDetailVisible() {
    const host = byId("adminShellDetail");
    return Boolean(host && !host.hidden);
  }

  function installR2Styles() {
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

  function recruitmentEndpoint(action = "state") {
    const configured = window.P2K_SITE_CONFIG?.serverStorage?.recruitmentAdminEndpoint || "server/team-points/public/recruitment-admin.php";
    const url = new URL(configured, location.href);
    url.searchParams.set("action", action);
    return url;
  }

  function recruitmentHref() {
    const url = new URL("ui-v2.html", location.href);
    ["page","adminTool","adminContext","hall","insights","assistant","assistantFilter"].forEach(key => url.searchParams.delete(key));
    url.searchParams.set("ui", "v2");
    url.searchParams.set("view", "admin");
    url.searchParams.set("adminCategory", "members");
    url.searchParams.set("adminDetail", "recruitment");
    url.searchParams.delete("adminDetailTab");
    url.searchParams.delete("adminToolTab");
    return url.href;
  }

  async function refreshRecruitmentOverview(card) {
    if (recruitmentLoading || !card?.isConnected) return;
    recruitmentLoading = true;
    try {
      const response = await fetch(recruitmentEndpoint("state"), {credentials:"same-origin", cache:"no-store", headers:{Accept:"application/json"}});
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload?.ok === false) throw new Error(payload?.error?.message || `HTTP ${response.status}`);
      const pool = Array.isArray(payload?.pool?.candidates) ? payload.pool.candidates.length : 0;
      const summary = payload?.run?.summary || {};
      const set = (id, value) => { const node = byId(id); if (node) node.textContent = String(value); };
      set("adminRecruitmentCandidates", pool);
      set("adminRecruitmentChecked", Number(summary.checked || 0));
      set("adminRecruitmentSelected", Number(summary.selected || 0));
      const badge = byId("adminShellStatus_recruitment");
      if (badge) { badge.textContent = payload?.run?.status ? String(payload.run.status).replace(/^./, c => c.toUpperCase()) : "Ready"; badge.className = "dashboard-admin-shell-status is-good"; }
      const fresh = byId("adminShellFresh_recruitment"); if (fresh) fresh.textContent = "Server state · live";
    } catch (error) {
      const badge = byId("adminShellStatus_recruitment");
      if (badge) { badge.textContent = "Unavailable"; badge.className = "dashboard-admin-shell-status is-bad"; }
      const fresh = byId("adminShellFresh_recruitment"); if (fresh) fresh.textContent = error?.message || "Unable to read state";
    } finally { recruitmentLoading = false; }
  }

  function ensureRecruitmentCard() {
    if (!isDashboardPage() || !dashboardAdminVisible() || activeAdminCategory() !== "members" || adminDetailVisible()) return;
    const panel = document.querySelector('[data-admin-shell-panel="members"]');
    const grid = panel?.querySelector(".dashboard-admin-shell-grid");
    if (!panel || panel.hidden || !grid) return;
    let card = grid.querySelector('[data-admin-shell-card="recruitment"]');
    if (!card) {
      card = document.createElement("article");
      card.className = "dashboard-admin-shell-card p2k-recruitment-overview-card";
      card.dataset.adminShellCard = "recruitment";
      card.innerHTML = `
        <header class="dashboard-admin-shell-card-head"><div><span class="dashboard-admin-shell-eyebrow">Members</span><h3>Recruitment</h3></div><span class="dashboard-admin-shell-status is-loading" id="adminShellStatus_recruitment">Loading</span></header>
        <p>Maintain the candidate pool and evaluate prospective members against Daily activity, reliability and membership criteria.</p>
        <div class="dashboard-admin-shell-metrics">
          <div class="dashboard-admin-shell-metric"><span>Candidates</span><strong id="adminRecruitmentCandidates">—</strong><small>Saved pool</small></div>
          <div class="dashboard-admin-shell-metric"><span>Checked</span><strong id="adminRecruitmentChecked">—</strong><small>Current checkpoint</small></div>
          <div class="dashboard-admin-shell-metric"><span>Selected</span><strong id="adminRecruitmentSelected">—</strong><small>Current checkpoint</small></div>
        </div>
        <div class="dashboard-admin-shell-meta"><span><b>Freshness</b><em id="adminShellFresh_recruitment">Checking…</em></span><span><b>Source</b><em>Team Points + Chess.com</em></span></div>
        <footer class="dashboard-admin-shell-actions"><a class="dashboard-button" href="${esc(recruitmentHref())}">Open Recruitment</a></footer>`;
      grid.appendChild(card);
    }
    void refreshRecruitmentOverview(card);
  }

  function replaceExactText(root, from, to) {
    root.querySelectorAll?.("a,button,h1,h2,h3,h4,p,span,strong,small,em,option").forEach(node => {
      if (String(node.textContent || "").trim() === from) node.textContent = to;
    });
  }

  function cleanupTaskControl() {
    const host = byId("p2kTaskControl");
    if (!host) return;

    ["greenCutoverMetrics", "greenGabMetrics", "greenHeatmapMetrics"].forEach(id => {
      const node = byId(id);
      const card = node?.closest("section.control-card, .control-card");
      if (card) card.remove();
    });
    byId("greenValidate")?.remove();

    const tab = host.querySelector('[data-task-tab="green"]');
    if (tab) tab.textContent = "Team Points";
    const overview = byId("greenSchedulerControl")?.querySelector(".green-overview-card");
    if (overview) {
      const eyebrow = overview.querySelector(".eyebrow"); if (eyebrow) eyebrow.textContent = "Production runtime";
      const title = overview.querySelector("h2"); if (title) title.textContent = "Team Points control";
      const description = overview.querySelector("h2 + p");
      if (description) description.textContent = "Operational worker, freshness, queue, cycle and browser-accelerator controls for the production Team Points databases.";
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
      const value = String(p.textContent || "");
      if (value.includes("current Green cycle")) p.textContent = value.replaceAll("current Green cycle", "current Team Points cycle");
      if (value.includes("Green invocations")) p.textContent = value.replaceAll("Green invocations", "Team Points invocations");
      if (value.includes("ordinary Green work")) p.textContent = value.replaceAll("ordinary Green work", "ordinary Team Points work");
    });
  }

  function cleanupParentAdminTerminology() {
    if (!isDashboardPage()) return;
    const host = byId("adminDashboardHost");
    if (!host) return;
    replaceExactText(host, "Green Team Points", "Team Points");
    replaceExactText(host, "Green Team Points control", "Team Points control");
    host.querySelectorAll("p,small,em").forEach(node => {
      const value = String(node.textContent || "");
      if (value.includes("Green accelerator control")) node.textContent = value.replace("Green accelerator control", "Team Points accelerator control");
    });
  }

  function stabilizeAdminFrame() {
    if (!isDashboardPage() || !dashboardAdminVisible()) return;
    const detail = byId("adminShellDetail");
    const frame = byId("adminShellDetailFrame");
    if (!detail || detail.hidden || !frame) return;
    frame.dataset.p2kR2Stable = "1";
    frame.hidden = false;
    frame.removeAttribute("aria-hidden");
    frame.style.display = "block";
    frame.style.width = "100%";
    frame.style.maxHeight = "none";
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
      const height = Math.ceil(measure());
      if (Math.abs(height - lastHeight) < 2) return;
      lastHeight = height;
      try { parent.postMessage({type:"p2k-frame-height", height, source:RELEASE}, location.origin); } catch (_) {}
    };
    const schedule = () => requestAnimationFrame(send);
    schedule();
    addEventListener("load", schedule, {once:true});
    addEventListener("resize", schedule, {passive:true});
    const ro = new ResizeObserver(schedule);
    ro.observe(document.documentElement);
    if (document.body) ro.observe(document.body);
    const mo = new MutationObserver(schedule);
    if (document.body) mo.observe(document.body, {subtree:true, childList:true, attributes:true, characterData:true});
  }

  function reconcile() {
    reconcileQueued = false;
    installR2Styles();
    syncAdminAccent();
    ensureRecruitmentCard();
    cleanupParentAdminTerminology();
    cleanupTaskControl();
    stabilizeAdminFrame();
  }
  function scheduleReconcile() {
    if (reconcileQueued) return;
    reconcileQueued = true;
    requestAnimationFrame(reconcile);
  }

  function instrumentHistory() {
    if (history.__p2kR2Instrumented) return;
    Object.defineProperty(history, "__p2kR2Instrumented", {value:true});
    ["pushState","replaceState"].forEach(name => {
      const original = history[name];
      history[name] = function(...args) {
        const result = original.apply(this, args);
        scheduleReconcile();
        return result;
      };
    });
    addEventListener("popstate", scheduleReconcile);
  }

  function mount() {
    instrumentHistory();
    embeddedHeightSender();
    const observer = new MutationObserver(scheduleReconcile);
    observer.observe(document.documentElement, {subtree:true, childList:true, attributes:true, attributeFilter:["hidden","class","aria-pressed","aria-selected","style","src"]});
    scheduleReconcile();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount, {once:true});
  else mount();
})();
