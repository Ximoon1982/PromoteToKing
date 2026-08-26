/* Shared failure-detail modal for analyzer workflows. */
(() => {
  "use strict";
  if (window.CLUB_ANALYSIS_FAILURE_UI) return;

  let modal = null;
  let titleNode = null;
  let bodyNode = null;
  let closeButton = null;
  let copyButton = null;
  let lastTrigger = null;
  let currentText = "";
  let viewportListenersInstalled = false;

  function escapeHTML(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function normalize(input, defaults = {}) {
    const error = input?.error || input?.cause || null;
    const reference = input?.reference || input?.item || input?.match || null;
    const apiUrl = String(
      input?.apiUrl || input?.url || error?.url || reference?.["@id"] || defaults.apiUrl || defaults.url || ""
    );
    const matchName = String(
      input?.matchName || input?.name || reference?.name || defaults.matchName || "Unknown match or request"
    );
    const explicitWebUrl = String(input?.webUrl || reference?.url || defaults.webUrl || "");
    const matchId = String(input?.matchId || reference?.matchId || apiUrl.match(/\/match\/(\d+)/i)?.[1] || apiUrl.match(/\/matches\/(\d+)/i)?.[1] || "");
    const webUrl = explicitWebUrl || (matchId ? `https://www.chess.com/club/matches/${encodeURIComponent(matchId)}` : "");
    const status = Number(input?.status ?? error?.status ?? 0) || 0;
    return {
      matchName,
      apiUrl,
      webUrl,
      phase: String(input?.phase || defaults.phase || "Analysis"),
      category: String(input?.category || error?.category || error?.code || defaults.category || "unknown"),
      status,
      message: String(input?.message || error?.message || defaults.message || "The request failed.")
    };
  }

  function visibleModalRegion() {
    const ownHeight = Math.max(
      document.documentElement?.scrollHeight || 0,
      document.body?.scrollHeight || 0,
      window.innerHeight || 0
    );
    let top = window.visualViewport?.offsetTop || 0;
    let bottom = top + (window.visualViewport?.height || window.innerHeight || ownHeight);

    try {
      if (window.parent !== window && window.frameElement) {
        const frameRect = window.frameElement.getBoundingClientRect();
        const parentViewport = window.parent.visualViewport;
        const parentTop = parentViewport?.offsetTop || 0;
        const parentHeight = parentViewport?.height || window.parent.innerHeight || frameRect.height;
        const parentBottom = parentTop + parentHeight;
        top = Math.max(0, parentTop - frameRect.top);
        bottom = Math.min(ownHeight, parentBottom - frameRect.top);
      }
    } catch (_) {
      /* Cross-origin embeds fall back to their local viewport. */
    }

    if (!Number.isFinite(top)) top = 0;
    if (!Number.isFinite(bottom) || bottom <= top) {
      bottom = Math.min(ownHeight, top + (window.innerHeight || 600));
    }
    return {
      top: Math.max(0, top),
      bottom: Math.max(top + 1, bottom),
      height: Math.max(1, bottom - top),
      ownHeight
    };
  }

  function positionModalInVisibleViewport() {
    if (!modal || modal.hidden) return;
    const region = visibleModalRegion();
    const margin = Math.min(18, Math.max(6, Math.floor(region.height * 0.035)));
    const dialog = modal.querySelector(".club-analysis-failure-dialog");

    modal.style.alignItems = "flex-start";
    modal.style.paddingTop = `${region.top + margin}px`;
    modal.style.paddingBottom = `${Math.max(margin, region.ownHeight - region.bottom + margin)}px`;
    if (dialog) {
      dialog.style.maxHeight = `${Math.max(150, region.height - margin * 2)}px`;
    }
  }

  function installViewportListeners() {
    if (viewportListenersInstalled) return;
    viewportListenersInstalled = true;
    const schedule = () => window.requestAnimationFrame(positionModalInVisibleViewport);
    window.addEventListener("resize", schedule, { passive: true });
    window.addEventListener("scroll", schedule, { passive: true });
    window.visualViewport?.addEventListener("resize", schedule, { passive: true });
    window.visualViewport?.addEventListener("scroll", schedule, { passive: true });
    try {
      if (window.parent !== window) {
        window.parent.addEventListener("resize", schedule, { passive: true });
        window.parent.addEventListener("scroll", schedule, { passive: true });
        window.parent.visualViewport?.addEventListener("resize", schedule, { passive: true });
        window.parent.visualViewport?.addEventListener("scroll", schedule, { passive: true });
      }
    } catch (_) {
      /* Cross-origin parent cannot be observed. */
    }
  }

  function ensureModal() {
    if (modal) return;
    modal = document.createElement("div");
    modal.className = "club-analysis-failure-modal";
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `
      <div class="club-analysis-failure-dialog" role="dialog" aria-modal="true" aria-labelledby="clubAnalysisFailureTitle">
        <div class="club-analysis-failure-header">
          <div class="club-analysis-failure-title" id="clubAnalysisFailureTitle">Analysis failures</div>
          <button class="club-analysis-failure-close" type="button">Close</button>
        </div>
        <div class="club-analysis-failure-body">
          <p class="club-analysis-failure-intro"></p>
          <div class="club-analysis-failure-table-wrap"></div>
          <div class="club-analysis-failure-actions">
            <button class="club-analysis-failure-copy" type="button">Copy plain text</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
    titleNode = modal.querySelector(".club-analysis-failure-title");
    bodyNode = modal.querySelector(".club-analysis-failure-body");
    closeButton = modal.querySelector(".club-analysis-failure-close");
    copyButton = modal.querySelector(".club-analysis-failure-copy");
    closeButton.addEventListener("click", close);
    modal.addEventListener("click", event => { if (event.target === modal) close(); });
    copyButton.addEventListener("click", async () => {
      let copied = false;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(currentText);
          copied = true;
        }
      } catch (_) { copied = false; }
      if (!copied) {
        const area = document.createElement("textarea");
        area.value = currentText;
        area.style.position = "fixed";
        area.style.left = "-10000px";
        document.body.appendChild(area);
        area.select();
        try { copied = document.execCommand("copy") === true; } catch (_) { copied = false; }
        area.remove();
      }
      const original = copyButton.textContent;
      copyButton.textContent = copied ? "Copied" : "Copy failed";
      window.setTimeout(() => { copyButton.textContent = original; }, 1400);
    });
    document.addEventListener("keydown", event => {
      if (event.key === "Escape" && modal && !modal.hidden) close();
    });
    installViewportListeners();
  }

  function plainText(items, title) {
    const lines = [title, ""];
    items.forEach((item, index) => {
      lines.push(`${index + 1}. ${item.matchName}`);
      lines.push(`Phase: ${item.phase}`);
      if (item.webUrl) lines.push(`Match: ${item.webUrl}`);
      if (item.apiUrl) lines.push(`API call: ${item.apiUrl}`);
      lines.push(`Error: ${item.category}${item.status ? ` (HTTP ${item.status})` : ""}`);
      lines.push(`Message: ${item.message}`, "");
    });
    return lines.join("\n").trim();
  }

  function open(failures, options = {}) {
    ensureModal();
    const items = (Array.isArray(failures) ? failures : [failures]).filter(Boolean).map(item => normalize(item, options));
    if (!items.length) return false;
    const title = String(options.title || `Analysis failures (${items.length})`);
    titleNode.textContent = title;
    bodyNode.querySelector(".club-analysis-failure-intro").textContent =
      String(options.intro || "The successful results were retained. The requests below could not be completed.");
    bodyNode.querySelector(".club-analysis-failure-table-wrap").innerHTML = `
      <table class="club-analysis-failure-table">
        <thead><tr><th>Match / request</th><th>API call</th><th>Phase</th><th>Error</th><th>Message</th></tr></thead>
        <tbody>${items.map(item => `<tr>
          <td>${item.webUrl ? `<a href="${escapeHTML(item.webUrl)}" target="_blank" rel="noopener noreferrer">${escapeHTML(item.matchName)}</a>` : escapeHTML(item.matchName)}</td>
          <td>${item.apiUrl ? `<a href="${escapeHTML(item.apiUrl)}" target="_blank" rel="noopener noreferrer">${escapeHTML(item.apiUrl)}</a>` : "—"}</td>
          <td>${escapeHTML(item.phase)}</td>
          <td>${escapeHTML(item.category)}${item.status ? `<br>HTTP ${item.status}` : ""}</td>
          <td class="club-analysis-failure-message">${escapeHTML(item.message)}</td>
        </tr>`).join("")}</tbody>
      </table>`;
    currentText = plainText(items, title);
    lastTrigger = options.trigger || document.activeElement;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    bodyNode.scrollTop = 0;
    const tableWrap = bodyNode.querySelector(".club-analysis-failure-table-wrap");
    if (tableWrap) tableWrap.scrollLeft = 0;
    positionModalInVisibleViewport();
    window.requestAnimationFrame(positionModalInVisibleViewport);
    closeButton.focus({ preventScroll: true });
    return true;
  }

  function close() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    lastTrigger?.focus?.({ preventScroll: true });
    lastTrigger = null;
  }

  function attach(container, failures, options = {}) {
    if (!container) return null;
    container.querySelectorAll(".club-analysis-failure-trigger").forEach(button => button.remove());
    const list = (Array.isArray(failures) ? failures : [failures]).filter(Boolean);
    if (!list.length) return null;
    const button = document.createElement("button");
    button.type = "button";
    button.className = "club-analysis-failure-trigger";
    button.textContent = options.label || `View ${list.length} failure${list.length === 1 ? "" : "s"}`;
    button.addEventListener("click", () => open(list, { ...options, trigger: button }));
    container.appendChild(button);
    return button;
  }

  window.CLUB_ANALYSIS_FAILURE_UI = Object.freeze({ normalize, open, close, attach });
})();
