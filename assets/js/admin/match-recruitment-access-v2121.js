/* P2K v2.12.2: first-class Match Recruitment access under Administration > Competitions. */
(() => {
  "use strict";
  if (window.__P2K_MATCH_RECRUITMENT_ACCESS_V2121__) return;
  window.__P2K_MATCH_RECRUITMENT_ACCESS_V2121__ = true;

  const CUSTOM_DETAIL = "match-recruitment";

  function recruitPageHref() {
    const url = new URL("RecruitMatch.html", document.baseURI);
    url.searchParams.set("embedded", "1");
    url.searchParams.set("active", "1");
    for (const key of ["oauth_token", "token", "access_token", "match", "id", "team", "lockTeam", "autorun"]) {
      const value = new URL(location.href).searchParams.get(key);
      if (value) url.searchParams.set(key, value);
    }
    return url.href;
  }

  function signalFrame(frame, active = true) {
    if (!frame?.contentWindow) return;
    try { frame.contentWindow.postMessage({ type: "p2k-tool-activity", active }, location.origin); } catch (_) {}
    try { frame.contentWindow.postMessage({ type: "p2k-admin-ready", allowed: window.P2K_ADMIN_MODE === true }, location.origin); } catch (_) {}
  }

  function closeRecruitment() {
    const detail = document.getElementById("adminShellDetail");
    const frame = document.getElementById("adminShellDetailFrame");
    if (detail?.dataset.p2kCustomDetail !== CUSTOM_DETAIL) return false;
    signalFrame(frame, false);
    detail.hidden = true;
    delete detail.dataset.p2kCustomDetail;
    document.querySelectorAll("[data-admin-shell-panel]").forEach(panel => {
      panel.hidden = panel.dataset.adminShellPanel !== "competitions";
    });
    return true;
  }

  function openRecruitment(event) {
    event?.preventDefault?.();
    const detail = document.getElementById("adminShellDetail");
    const frameWrap = document.getElementById("adminShellDetailFrameWrap");
    const frame = document.getElementById("adminShellDetailFrame");
    const nativeHost = document.getElementById("adminShellNativeDetailHost");
    const tabs = document.getElementById("adminShellDetailTabs");
    const title = document.getElementById("adminShellDetailTitle");
    const breadcrumb = document.getElementById("adminShellDetailBreadcrumb");
    if (!detail || !frameWrap || !frame) {
      location.href = recruitPageHref();
      return;
    }

    document.querySelectorAll("[data-admin-shell-panel]").forEach(panel => { panel.hidden = true; });
    detail.hidden = false;
    detail.dataset.p2kCustomDetail = CUSTOM_DETAIL;
    if (title) title.textContent = "Match Recruitment";
    if (breadcrumb) breadcrumb.textContent = "Administration · Competitions";
    if (tabs) { tabs.hidden = true; tabs.replaceChildren(); }
    if (nativeHost) { nativeHost.hidden = true; nativeHost.replaceChildren(); nativeHost.removeAttribute("data-native-detail"); }
    frameWrap.hidden = false;
    frame.hidden = false;
    frame.title = "Match Recruitment";

    if (!frame.dataset.p2kRecruitmentDetailBound) {
      frame.dataset.p2kRecruitmentDetailBound = "1";
      frame.addEventListener("load", () => {
        if (document.getElementById("adminShellDetail")?.dataset.p2kCustomDetail !== CUSTOM_DETAIL) return;
        signalFrame(frame, true);
        setTimeout(() => signalFrame(frame, true), 50);
      });
    }

    const target = recruitPageHref();
    if (frame.src !== target) frame.src = target;
    else signalFrame(frame, true);
  }

  function bindBackButton() {
    const back = document.getElementById("adminShellDetailBack");
    if (!back || back.dataset.p2kRecruitmentBackBound === "1") return;
    back.dataset.p2kRecruitmentBackBound = "1";
    back.addEventListener("click", event => {
      if (document.getElementById("adminShellDetail")?.dataset.p2kCustomDetail !== CUSTOM_DETAIL) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      closeRecruitment();
    }, true);
  }

  function install() {
    const grid = document.querySelector('[data-admin-shell-panel="competitions"] .dashboard-admin-shell-grid');
    if (!grid) return false;
    bindBackButton();
    if (grid.querySelector('[data-v2121-match-recruitment]')) return true;

    const card = document.createElement("article");
    card.className = "dashboard-admin-shell-card";
    card.dataset.v2121MatchRecruitment = "1";
    card.dataset.adminShellCard = "matchRecruitment";
    card.setAttribute("role", "link");
    card.tabIndex = 0;
    card.setAttribute("aria-label", "Open Match Recruitment");
    card.innerHTML = `
      <header class="dashboard-admin-shell-card-head">
        <div><span class="dashboard-admin-shell-eyebrow">Daily matches</span><h3>Match Recruitment</h3></div>
        <span class="dashboard-admin-shell-status is-good">Ready</span>
      </header>
      <p>Find eligible P2K players for a specific Chess.com Daily team match using the v2.12 DB-first recruitment workflow.</p>
      <div class="dashboard-admin-shell-metrics"><div class="dashboard-admin-shell-metric"><span>Workflow</span><strong>DB first</strong><small>Live verification second</small></div></div>
      <div class="dashboard-admin-shell-meta"><span><b>Access</b><em>Match-specific assistant</em></span><span><b>Source</b><em>Green Core + Chess.com</em></span></div>
      <footer class="dashboard-admin-shell-actions"><button class="dashboard-button" type="button" data-v2121-match-recruitment-open>Open Match Recruitment</button></footer>`;
    const button = card.querySelector("[data-v2121-match-recruitment-open]");
    button.addEventListener("click", openRecruitment);
    card.addEventListener("click", event => {
      if (event.target.closest("button,a") && !event.target.closest("[data-v2121-match-recruitment-open]")) return;
      openRecruitment(event);
    });
    card.addEventListener("keydown", event => {
      if (!["Enter", " "].includes(event.key)) return;
      openRecruitment(event);
    });

    const daily = grid.querySelector('[data-admin-shell-card="daily"]');
    if (daily?.nextSibling) grid.insertBefore(card, daily.nextSibling);
    else if (daily) grid.appendChild(card);
    else grid.prepend(card);
    return true;
  }

  if (install()) return;
  const observer = new MutationObserver(() => {
    if (install()) observer.disconnect();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
