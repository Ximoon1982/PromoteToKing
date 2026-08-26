(() => {
  "use strict";

  if (window.P2K_INFO_POPOVER) return;

  const GAP = 9;
  const MARGIN = 10;
  const CLOSE_DELAY_MS = 125;
  const HOVER_CLOSE_DELAY_MS = 110;
  const COMPACT_POPOVER_ID = "p2kSharedInfoPopover";
  const TRIGGER_SELECTOR = "[data-p2k-info-trigger], [data-p2k-info-message]";
  const finePointer = window.matchMedia?.("(hover: hover) and (pointer: fine)");

  let activeTrigger = null;
  let activePopover = null;
  let closeTimer = 0;
  let hoverCloseTimer = 0;

  function ensureCompactPopover() {
    let popover = document.getElementById(COMPACT_POPOVER_ID);
    if (popover) return popover;
    popover = document.createElement("div");
    popover.id = COMPACT_POPOVER_ID;
    popover.className = "p2k-info-popover";
    popover.dataset.p2kInfoPopover = "compact";
    popover.setAttribute("role", "tooltip");
    popover.hidden = true;
    document.body.appendChild(popover);
    return popover;
  }

  function visibleRect() {
    let left = 0;
    let top = 0;
    let right = window.innerWidth;
    let bottom = window.innerHeight;

    const viewport = window.visualViewport;
    if (viewport) {
      left = viewport.offsetLeft;
      top = viewport.offsetTop;
      right = left + viewport.width;
      bottom = top + viewport.height;
    }

    try {
      if (window.parent !== window && window.frameElement) {
        const frameRect = window.frameElement.getBoundingClientRect();
        const parentViewport = window.parent.visualViewport;
        const parentLeft = parentViewport ? parentViewport.offsetLeft : 0;
        const parentTop = parentViewport ? parentViewport.offsetTop : 0;
        const parentRight = parentLeft + (parentViewport ? parentViewport.width : window.parent.innerWidth);
        const parentBottom = parentTop + (parentViewport ? parentViewport.height : window.parent.innerHeight);

        left = Math.max(0, parentLeft - frameRect.left);
        top = Math.max(0, parentTop - frameRect.top);
        right = Math.min(window.innerWidth, parentRight - frameRect.left);
        bottom = Math.min(window.innerHeight, parentBottom - frameRect.top);
      }
    } catch (_) {
      // Cross-origin embedding cannot expose the parent viewport.
    }

    if (right <= left || bottom <= top) {
      return { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight, width: window.innerWidth, height: window.innerHeight };
    }

    return { left, top, right, bottom, width: right - left, height: bottom - top };
  }

  function position(trigger = activeTrigger, popover = activePopover) {
    if (!trigger || !popover || popover.hidden || !trigger.isConnected || !popover.isConnected) return;

    const viewport = visibleRect();
    const triggerRect = trigger.getBoundingClientRect();
    popover.style.left = `${viewport.left + MARGIN}px`;
    popover.style.top = `${viewport.top + MARGIN}px`;
    popover.style.maxHeight = `${Math.max(120, viewport.height - MARGIN * 2)}px`;

    const popoverRect = popover.getBoundingClientRect();
    const roomAbove = triggerRect.top - viewport.top;
    const roomBelow = viewport.bottom - triggerRect.bottom;
    const opensAbove = roomAbove >= popoverRect.height + GAP || roomAbove > roomBelow;

    let top = opensAbove
      ? triggerRect.top - popoverRect.height - GAP
      : triggerRect.bottom + GAP;
    top = Math.max(viewport.top + MARGIN, Math.min(top, viewport.bottom - popoverRect.height - MARGIN));

    const preferredLeft = triggerRect.left + triggerRect.width / 2 - popoverRect.width / 2;
    const left = Math.max(
      viewport.left + MARGIN,
      Math.min(preferredLeft, viewport.right - popoverRect.width - MARGIN)
    );

    const triggerCenter = triggerRect.left + triggerRect.width / 2;
    const arrowX = Math.max(14, Math.min(triggerCenter - left, popoverRect.width - 14));

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(top)}px`;
    popover.style.setProperty("--p2k-info-arrow-x", `${Math.round(arrowX)}px`);
    popover.dataset.placement = opensAbove ? "above" : "below";
  }

  function close({ restoreFocus = false, immediate = false } = {}) {
    window.clearTimeout(hoverCloseTimer);
    window.clearTimeout(closeTimer);
    if (!activePopover || !activeTrigger) return;

    const trigger = activeTrigger;
    const popover = activePopover;
    popover.classList.remove("is-open");
    trigger.setAttribute("aria-expanded", "false");
    activeTrigger = null;
    activePopover = null;

    const hide = () => {
      if (!popover.classList.contains("is-open")) popover.hidden = true;
    };
    if (immediate) hide();
    else closeTimer = window.setTimeout(hide, CLOSE_DELAY_MS);

    if (restoreFocus && trigger.isConnected) {
      trigger.focus({ preventScroll: true });
    }
  }

  function resolvePopover(trigger) {
    const explicitId = trigger.dataset.p2kInfoTrigger || trigger.getAttribute("aria-controls");
    if (trigger.dataset.p2kInfoMessage !== undefined) {
      const popover = ensureCompactPopover();
      popover.textContent = trigger.dataset.p2kInfoMessage || "";
      return popover;
    }
    return explicitId ? document.getElementById(explicitId) : null;
  }

  function open(trigger) {
    const popover = resolvePopover(trigger);
    if (!popover) return;

    if (activeTrigger === trigger && activePopover === popover) {
      close();
      return;
    }

    close({ immediate: true });
    activeTrigger = trigger;
    activePopover = popover;
    trigger.setAttribute("aria-expanded", "true");
    popover.hidden = false;
    position(trigger, popover);
    window.requestAnimationFrame(() => {
      if (activePopover === popover) popover.classList.add("is-open");
    });
  }

  function scheduleHoverClose() {
    window.clearTimeout(hoverCloseTimer);
    hoverCloseTimer = window.setTimeout(() => {
      const triggerHovered = activeTrigger?.matches(":hover");
      const popoverHovered = activePopover?.matches(":hover");
      if (!triggerHovered && !popoverHovered) close();
    }, HOVER_CLOSE_DELAY_MS);
  }

  function triggerFromEvent(event) {
    return event.target instanceof Element ? event.target.closest(TRIGGER_SELECTOR) : null;
  }

  document.addEventListener("click", event => {
    const trigger = triggerFromEvent(event);
    if (!trigger) return;
    event.preventDefault();
    event.stopPropagation();
    open(trigger);
  });

  document.addEventListener("pointerdown", event => {
    if (!activePopover || !activeTrigger) return;
    if (activePopover.contains(event.target) || activeTrigger.contains(event.target)) return;
    close();
  }, true);

  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && activePopover) {
      event.preventDefault();
      close({ restoreFocus: true });
    }
  });

  document.addEventListener("pointerover", event => {
    if (!finePointer?.matches || event.pointerType === "touch") return;
    const trigger = triggerFromEvent(event);
    if (trigger && activeTrigger !== trigger) {
      window.clearTimeout(hoverCloseTimer);
      open(trigger);
      return;
    }
    if (activePopover && event.target instanceof Node && activePopover.contains(event.target)) {
      window.clearTimeout(hoverCloseTimer);
    }
  });

  document.addEventListener("pointerout", event => {
    if (!finePointer?.matches || event.pointerType === "touch" || !activePopover) return;
    const leavingTrigger = triggerFromEvent(event);
    const leavingPopover = event.target instanceof Node && activePopover.contains(event.target);
    if (leavingTrigger || leavingPopover) scheduleHoverClose();
  });

  function reposition() {
    if (!activeTrigger || !activePopover) return;
    if (!activeTrigger.isConnected || !activePopover.isConnected) {
      close({ immediate: true });
      return;
    }
    position(activeTrigger, activePopover);
  }

  window.addEventListener("resize", reposition, { passive: true });
  window.addEventListener("scroll", reposition, { passive: true });
  window.visualViewport?.addEventListener("resize", reposition, { passive: true });
  window.visualViewport?.addEventListener("scroll", reposition, { passive: true });

  try {
    if (window.parent !== window) {
      window.parent.addEventListener("scroll", reposition, { passive: true });
      window.parent.addEventListener("resize", reposition, { passive: true });
      window.parent.visualViewport?.addEventListener("scroll", reposition, { passive: true });
      window.parent.visualViewport?.addEventListener("resize", reposition, { passive: true });
      window.parent.document.addEventListener("pointerdown", () => close(), true);
      window.parent.document.addEventListener("keydown", event => {
        if (event.key === "Escape" && activePopover) close({ restoreFocus: true });
      });
    }
  } catch (_) {
    // Cross-origin parents cannot be observed; local document behavior remains available.
  }

  const observer = new MutationObserver(() => {
    if (activeTrigger && (!activeTrigger.isConnected || !activePopover?.isConnected)) {
      close({ immediate: true });
    }
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });

  window.P2K_INFO_POPOVER = Object.freeze({
    open,
    close,
    reposition,
    visibleRect
  });
})();
