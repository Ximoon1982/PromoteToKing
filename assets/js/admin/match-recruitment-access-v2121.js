/* P2K v2.12.1: first-class Match Recruitment access under Administration > Competitions. */
(() => {
  "use strict";
  if (window.__P2K_MATCH_RECRUITMENT_ACCESS_V2121__) return;
  window.__P2K_MATCH_RECRUITMENT_ACCESS_V2121__ = true;

  function recruitHref() {
    const url = new URL("RecruitMatch.html", document.baseURI);
    for (const key of ["oauth_token", "token", "access_token"]) {
      const value = new URL(location.href).searchParams.get(key);
      if (value) url.searchParams.set(key, value);
    }
    return url.href;
  }

  function install() {
    const grid = document.querySelector('[data-admin-shell-panel="competitions"] .dashboard-admin-shell-grid');
    if (!grid) return false;
    if (grid.querySelector('[data-v2121-match-recruitment]')) return true;

    const card = document.createElement("article");
    card.className = "dashboard-admin-shell-card";
    card.dataset.v2121MatchRecruitment = "1";
    card.innerHTML = `
      <header class="dashboard-admin-shell-card-head">
        <div><span class="dashboard-admin-shell-eyebrow">Daily matches</span><h3>Match Recruitment</h3></div>
        <span class="dashboard-admin-shell-status is-good">Ready</span>
      </header>
      <p>Find eligible P2K players for a specific Chess.com Daily team match using the v2.12 DB-first recruitment workflow.</p>
      <div class="dashboard-admin-shell-metrics"><div class="dashboard-admin-shell-metric"><span>Workflow</span><strong>DB first</strong><small>Live verification second</small></div></div>
      <div class="dashboard-admin-shell-meta"><span><b>Access</b><em>Match-specific assistant</em></span><span><b>Source</b><em>Green Core + Chess.com</em></span></div>
      <footer class="dashboard-admin-shell-actions"><a class="dashboard-button" data-v2121-match-recruitment-open>Open Match Recruitment</a></footer>`;
    const link = card.querySelector("[data-v2121-match-recruitment-open]");
    link.href = recruitHref();
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
