/* Visible-parent host for Match Assistant detailed analysis. */
(() => {
  "use strict";

  function install() {
    let modal = document.getElementById("p2kShellDetailedAnalysisModal");
    if (modal) return modal;
    modal = document.createElement("div");
    modal.id = "p2kShellDetailedAnalysisModal";
    modal.className = "p2k-shell-analysis-modal";
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = '<div class="p2k-shell-analysis-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kShellDetailedAnalysisTitle">' +
      '<div class="p2k-shell-analysis-header">' +
        '<div id="p2kShellDetailedAnalysisTitle" class="p2k-shell-analysis-title">Detailed match analysis</div>' +
        '<button type="button" class="p2k-shell-analysis-close" aria-label="Close detailed match analysis">Close</button>' +
      '</div>' +
      '<div class="p2k-shell-analysis-frame-wrap">' +
        '<div class="p2k-shell-analysis-loading">Loading detailed match analysis…</div>' +
        '<iframe class="p2k-shell-analysis-frame" title="Detailed match analysis" src="about:blank"></iframe>' +
      '</div>' +
    '</div>';
    modal.addEventListener("click", event => { if (event.target === modal) close(); });
    modal.querySelector(".p2k-shell-analysis-close")?.addEventListener("click", close);
    modal.querySelector(".p2k-shell-analysis-frame")?.addEventListener("load", () => {
      const loading = modal.querySelector(".p2k-shell-analysis-loading");
      if (loading) loading.hidden = true;
    });
    document.body.appendChild(modal);
    return modal;
  }

  function normalizedReference(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const match = raw.match(/(?:daily|match)\/(\d+)(?:\/|$)/i) || raw.match(/(\d+)(?:\/)?$/);
    return match?.[1] || (/^\d+$/.test(raw) ? raw : "");
  }

  function open(matchReference) {
    const match = normalizedReference(matchReference);
    if (!match) return false;
    const modal = install();
    const frame = modal.querySelector(".p2k-shell-analysis-frame");
    const loading = modal.querySelector(".p2k-shell-analysis-loading");
    const route = window.P2K_SITE_CONFIG?.routes?.analyzeMatchModal || "AnalyzeMatchModal.html";
    const url = new URL(route, window.location.href);
    url.searchParams.set("match", match);
    if (loading) loading.hidden = false;
    frame.src = url.href;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("p2k-shell-analysis-open");
    requestAnimationFrame(() => modal.querySelector(".p2k-shell-analysis-close")?.focus());
    return true;
  }

  function close() {
    const modal = document.getElementById("p2kShellDetailedAnalysisModal");
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("p2k-shell-analysis-open");
    const frame = modal.querySelector(".p2k-shell-analysis-frame");
    if (frame) frame.src = "about:blank";
  }

  window.addEventListener("message", event => {
    if (event.origin !== window.location.origin || event.data?.type !== "p2k-open-detailed-analysis") return;
    open(event.data.matchReference);
  });
  window.addEventListener("keydown", event => { if (event.key === "Escape") close(); });
  window.P2K_DETAILED_ANALYSIS_HOST = Object.freeze({ open, close });
})();
