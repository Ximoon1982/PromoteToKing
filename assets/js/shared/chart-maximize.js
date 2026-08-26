/* Promote to King v2.9.0 shared live-chart maximize/restore controller. */
(() => {
  "use strict";
  if (window.P2K_CHART_MAXIMIZE) return;

  const selector = [
    ".chart",
    ".p2k-native-chart",
    ".p2k-chart-wrap",
    ".p2k-chart-host",
    "[data-p2k-chart]"
  ].join(",");
  const enhanced = new WeakSet();
  let active = null;

  const style = document.createElement("style");
  style.textContent = `
    .p2k-global-chart-actions{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin:4px 0 6px}
    .p2k-global-chart-expand{border:1px solid #6a5945;background:#26211d;color:#f1e5d2;border-radius:8px;padding:6px 9px;font:inherit;cursor:pointer}
    .p2k-global-chart-expand:hover,.p2k-global-chart-expand:focus-visible{border-color:#f6b73c;color:#ffd078;outline:none}
    .p2k-global-chart-modal[hidden]{display:none!important}.p2k-global-chart-modal{position:fixed;inset:0;z-index:2147483000;background:#000b;display:grid;place-items:center;padding:clamp(4px,2vmin,18px);overflow:hidden}
    .p2k-global-chart-dialog{width:min(1500px,calc(100dvw - 2*clamp(4px,2vmin,18px)));height:min(900px,calc(100dvh - 2*clamp(4px,2vmin,18px)));max-width:100%;max-height:100%;background:#151311;border:1px solid #756247;border-radius:14px;box-shadow:0 20px 70px #000c;display:grid;grid-template-rows:auto minmax(0,1fr);overflow:hidden}
    .p2k-global-chart-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid #ffffff18}
    .p2k-global-chart-title{margin:0;color:#ffd078;font:700 17px/1.2 Inter,Segoe UI,Arial,sans-serif}
    .p2k-global-chart-close{border:1px solid #6a5945;background:#26211d;color:#f1e5d2;border-radius:8px;padding:7px 10px;cursor:pointer}
    .p2k-global-chart-body{min-width:0;min-height:0;padding:12px;overflow:auto;display:flex;flex-direction:column;gap:10px}
    .p2k-global-chart-body>.p2k-chart-is-maximized{width:100%!important;height:min(72dvh,760px)!important;min-height:min(480px,62dvh)!important;max-width:none!important;margin:0!important;flex:1 1 auto}
    .p2k-global-chart-body>.legend,.p2k-global-chart-body>.p2k-chart-legend{display:flex!important;flex-wrap:wrap!important;gap:8px 14px!important;flex:0 0 auto!important;max-width:100%!important;overflow:auto!important}
    body.p2k-chart-modal-open{overflow:hidden!important}
    @media(max-width:700px){.p2k-global-chart-modal{padding:6px}.p2k-global-chart-dialog{width:99vw;height:96vh}.p2k-global-chart-body{padding:7px}.p2k-global-chart-body>.p2k-chart-is-maximized{height:82vh!important;min-height:320px!important}}
  `;
  document.head.append(style);

  const modal = document.createElement("div");
  modal.className = "p2k-global-chart-modal";
  modal.hidden = true;
  modal.innerHTML = `<div class="p2k-global-chart-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kGlobalChartTitle"><div class="p2k-global-chart-head"><h2 class="p2k-global-chart-title" id="p2kGlobalChartTitle">Chart</h2><button type="button" class="p2k-global-chart-close" aria-label="Close enlarged chart">Close</button></div><div class="p2k-global-chart-body"></div></div>`;
  document.body.append(modal);
  const body = modal.querySelector(".p2k-global-chart-body");
  const title = modal.querySelector(".p2k-global-chart-title");
  const closeButton = modal.querySelector(".p2k-global-chart-close");

  function chartTitle(target) {
    const card = target.closest("article,section,.card,.panel");
    return card?.querySelector("h1,h2,h3")?.textContent?.trim() || target.getAttribute("aria-label") || "Chart";
  }
  function associatedLegend(target) {
    const direct = target.nextElementSibling;
    if (direct?.matches?.(".legend,.p2k-chart-legend")) return direct;
    const parent = target.parentElement;
    if (!parent) return null;
    const candidates = [...parent.children].filter(node => node !== target && node.matches?.(".legend,.p2k-chart-legend"));
    return candidates.length === 1 ? candidates[0] : null;
  }
  function redraw(target) {
    requestAnimationFrame(() => {
      try { target._rerender?.(); } catch (_) {}
      window.dispatchEvent(new Event("resize"));
      try { target.dispatchEvent(new CustomEvent("p2k:chart-resize", { bubbles:true })); } catch (_) {}
    });
  }
  function open(target, trigger) {
    if (active) close();
    const placeholder = document.createElement("span");
    placeholder.hidden = true;
    placeholder.dataset.p2kChartPlaceholder = "1";
    target.parentNode?.insertBefore(placeholder,target);
    title.textContent = chartTitle(target);
    const legend = associatedLegend(target);
    let legendPlaceholder = null;
    if (legend) { legendPlaceholder = document.createElement("span"); legendPlaceholder.hidden = true; legend.parentNode?.insertBefore(legendPlaceholder, legend); }
    active = { target, placeholder, legend, legendPlaceholder, trigger, scrollX: window.scrollX, scrollY: window.scrollY };
    body.append(target); if (legend) body.append(legend);
    target.classList.add("p2k-chart-is-maximized");
    modal.hidden = false;
    document.body.classList.add("p2k-chart-modal-open");
    redraw(target);
    requestAnimationFrame(() => document.querySelector(".p2k-global-chart-dialog")?.scrollIntoView?.({block:"center",inline:"center",behavior:"instant"}));
    closeButton.focus({preventScroll:true});
  }
  function close() {
    if (!active) return;
    const { target, placeholder, legend, legendPlaceholder, trigger, scrollX, scrollY } = active;
    target.classList.remove("p2k-chart-is-maximized");
    if (placeholder.isConnected) placeholder.replaceWith(target);
    if (legend && legendPlaceholder?.isConnected) legendPlaceholder.replaceWith(legend);
    modal.hidden = true;
    document.body.classList.remove("p2k-chart-modal-open");
    active = null;
    redraw(target);
    trigger?.focus?.({preventScroll:true});
    requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(scrollX, scrollY)));
  }
  closeButton.addEventListener("click",close);
  modal.addEventListener("pointerdown",event=>{if(event.target===modal)close();});
  document.addEventListener("keydown",event=>{if(event.key==="Escape"&&active){event.preventDefault();close();}});

  function enhance(target) {
    if (!(target instanceof HTMLElement) || enhanced.has(target) || target.closest(".p2k-global-chart-modal,.p2k-chart-modal")) return;
    if (target.querySelector(".p2k-chart-expand,[data-p2k-history-expand]") || target.parentElement?.querySelector(":scope > .p2k-chart-expand,:scope > [data-p2k-history-expand]")) { enhanced.add(target); return; }
    enhanced.add(target);
    target.dataset.p2kMaximizableChart = "1";
    const actions=document.createElement("div");actions.className="p2k-global-chart-actions";
    const button=document.createElement("button");button.type="button";button.className="p2k-global-chart-expand";button.textContent="Maximize";button.setAttribute("aria-label",`Maximize ${chartTitle(target)}`);
    button.addEventListener("click",()=>open(target,button));actions.append(button);
    target.parentNode?.insertBefore(actions,target);
  }
  function scan(root=document) {
    if (root instanceof HTMLElement && root.matches(selector)) enhance(root);
    root.querySelectorAll?.(selector).forEach(enhance);
  }
  scan();
  new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{if(node.nodeType===1)scan(node);}))).observe(document.documentElement,{childList:true,subtree:true});
  window.P2K_CHART_MAXIMIZE=Object.freeze({scan,open,close});
})();
