/* P2K v2.12.1 Match Recruitment bootstrap: deterministic core/controller binding. */
(() => {
  "use strict";
  if (window.__P2K_RECRUIT_MATCH_V2121_BOOTSTRAP__) return;
  window.__P2K_RECRUIT_MATCH_V2121_BOOTSTRAP__ = true;

  const CORE = "assets/js/pages/recruit-match-v2-core.js?v=p2k-2.12.1-recruit-core-0869b3cd-v2121a";
  const CONTROLLER = "assets/js/pages/recruit-match.js?v=p2k-2.12.1-recruit-controller-0869b3cd-v2121a";

  function showFailure(message) {
    const box = document.getElementById("p2kStatus");
    const text = document.getElementById("p2kStatusText");
    const load = document.getElementById("p2kLoadButton");
    const scan = document.getElementById("p2kScanButton");
    if (box) {
      box.style.display = "block";
      box.classList.add("p2k-error");
    }
    if (text) text.textContent = message;
    if (load) load.disabled = true;
    if (scan) scan.disabled = true;
    console.error(`P2K Match Recruitment: ${message}`);
  }

  function loadScript(id, src) {
    const existing = document.getElementById(id);
    if (existing) {
      if (existing.dataset.loaded === "1") return Promise.resolve();
      return new Promise((resolve, reject) => {
        existing.addEventListener("load", resolve, { once: true });
        existing.addEventListener("error", () => reject(new Error(`Unable to load ${src}`)), { once: true });
      });
    }
    return new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.id = id;
      script.src = src;
      script.async = false;
      script.addEventListener("load", () => { script.dataset.loaded = "1"; resolve(); }, { once: true });
      script.addEventListener("error", () => reject(new Error(`Unable to load ${src}`)), { once: true });
      document.head.appendChild(script);
    });
  }

  async function boot() {
    try {
      if (!window.P2K_RECRUITMENT_V2) {
        await loadScript("p2kRecruitmentV2Core", CORE);
      }
      if (!window.P2K_RECRUITMENT_V2) throw new Error("Recruitment core did not initialize.");
      await loadScript("p2kRecruitmentV2121Controller", CONTROLLER);
      await Promise.resolve();
      const form = document.getElementById("p2kMatchForm");
      const load = document.getElementById("p2kLoadButton");
      if (!form || !load || typeof form.onsubmit !== "function") {
        throw new Error("Load match did not bind to the recruitment controller.");
      }
      document.documentElement.dataset.p2kRecruitmentReady = "1";
    } catch (error) {
      showFailure(error?.message || "Match Recruitment failed to initialize. Reload the page.");
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot, { once: true });
  else void boot();
})();
