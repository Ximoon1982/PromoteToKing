/* Same-origin tab activity lifecycle. Stops API work when an embedded tool loses focus. */
(() => {
  "use strict";
  const params = new URLSearchParams(window.location.search);
  const embedded = params.get("embedded") === "1";
  let active = !embedded || params.get("active") === "1";
  let controller = new AbortController();
  const listeners = new Set();

  function resetController() {
    if (!controller.signal.aborted) controller.abort();
    controller = new AbortController();
  }

  function setActive(value) {
    const next = Boolean(value);
    if (next === active) return;
    active = next;
    if (active) {
      controller = new AbortController();
    } else if (!controller.signal.aborted) {
      controller.abort();
    }
    listeners.forEach(listener => {
      try { listener(active); } catch (error) { console.warn("Tool activity listener failed.", error); }
    });
    window.dispatchEvent(new CustomEvent("p2k-tool-activity-change", { detail: { active } }));
  }

  window.addEventListener("message", event => {
    if (event.origin !== window.location.origin || event.data?.type !== "p2k-tool-activity") return;
    setActive(event.data.active === true);
  });
  document.addEventListener("visibilitychange", () => {
    if (!embedded && document.visibilityState === "hidden") setActive(false);
    else if (!embedded && document.visibilityState === "visible") setActive(true);
  });

  window.P2K_TAB_ACTIVITY = Object.freeze({
    isActive: () => active,
    signal: () => controller.signal,
    onChange(listener) {
      if (typeof listener !== "function") return () => {};
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    assertActive() {
      if (!active) {
        const error = new DOMException("The tool is no longer active.", "AbortError");
        throw error;
      }
    }
  });
})();
