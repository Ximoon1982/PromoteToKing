/* Existing recommendations and Match Assistant iframe lifecycle. */
(() => {
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {};
window.P2K_DASHBOARD_MODULES.matchAssistant = Object.freeze({
create(context) {
const { state, byId, viewed, preservedURL, config, escapeHTML, number, setText, writeNavigationState, adminDetailDefinition, renderAdminPriorityCard } = context;
  let recommendationInfoSequence = 0;
  function stopRecommendationTimer() {
    window.clearTimeout(state.recommendationTimer);
    state.recommendationTimer = 0;
  }
  function stopAssistantTimer() {
    window.clearTimeout(state.assistantTimer);
    state.assistantTimer = 0;
  }
  function disposeRecommendationFrame() {
    stopRecommendationTimer();
    stopAssistantTimer();
    state.recommendationObserver?.disconnect?.();
    state.recommendationObserver = null;
    state.recommendationFrame?.remove();
    state.assistantFrame?.remove();
    state.recommendationFrame = null;
    state.assistantFrame = null;
    state.recommendationReady = false;
    state.assistantReady = false;
    state.assistantFullReady = false;
    state.assistantDedicated = false;
  }
  function prepareEmbeddedMatchAssistant(frame) {
    try {
      const doc = frame.contentDocument;
      if (!doc) return false;
      let style = doc.getElementById("p2k-dashboard-embedded-style");
      if (!style) {
        style = doc.createElement("style");
        style.id = "p2k-dashboard-embedded-style";
        style.textContent = `
          html,body{min-height:0!important;margin:0!important;padding:0!important;background:transparent!important;overflow:hidden!important}
          .finder{width:100%!important;max-width:none!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;background-image:none!important;box-shadow:none!important}
          .finder>.header,.p2k-user-search-row{display:none!important}
          html.p2k-dashboard-assistant-hydrating .finder{visibility:hidden!important}
        `;
        (doc.head || doc.documentElement).appendChild(style);
      }
      state.recommendationObserver?.disconnect?.();
      if ("ResizeObserver" in frame.contentWindow) {
        state.recommendationObserver = new frame.contentWindow.ResizeObserver(() => resizeRecommendationFrame(frame));
        state.recommendationObserver.observe(doc.body);
      }
      resizeRecommendationFrame(frame);
      return true;
    } catch (error) {
      console.warn("Unable to prepare embedded Match Assistant.", error);
      return false;
    }
  }
  function resizeRecommendationFrame(frame = state.recommendationFrame, reportedHeight = 0) {
    if (!frame || frame.hidden) return;
    let height = Number(reportedHeight) || 0;
    try {
      height = Math.max(height, frame.contentDocument?.documentElement?.scrollHeight || 0, frame.contentDocument?.body?.scrollHeight || 0);
    } catch (_) { /* same-origin frame may still be loading */ }
    frame.style.height = `${Math.max(520, height)}px`;
  }
  function recommendationError(message, { preserveExisting = false } = {}) {
    const text = String(message || "Recommendation analysis unavailable.");
    state.adminPriorityError = preserveExisting ? "" : text;
    renderAdminPriorityCard();
    const loading = byId("recommendationsLoading");
    if (loading) loading.hidden = true;
    const list = byId("recommendationsList");
    const empty = byId("recommendationsEmpty");
    if (!preserveExisting) {
      list?.replaceChildren();
      if (empty) { empty.textContent = text; empty.hidden = false; }
    } else {
      if (empty) empty.hidden = true;
      setText("recommendationsSubtitle", text);
    }
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) findMoreButton.disabled = false;
  }
  function loadRecommendations() {
    const run = ++state.recommendationRun;
    state.adminPriorityData = null;
    state.adminPriorityError = "";
    state.adminPriorityFailures = 0;
    state.recommendationFallbackVisible = false;
    renderAdminPriorityCard();
    disposeRecommendationFrame();
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) findMoreButton.disabled = true;
    byId("recommendationsList").replaceChildren();
    byId("recommendationsEmpty").hidden = true;
    const username = viewed();
    if (!username) {
      recommendationError("Log in to load the Match Assistant's personalized top five results.");
      setText("recommendationsSubtitle", "Recommendations use your live Daily and Chess960 ratings and the Match Assistant's default filters.");
      renderTeamIndicators(null);
      return;
    }
    byId("recommendationsLoading").hidden = false;
    setText("recommendationsSubtitle", `Analyzing current opportunities for ${username}…`);
    const frame = document.createElement("iframe");
    frame.dataset.username = username;
    frame.title = "Promote to King Match Assistant recommendation engine";
    frame.className = "dashboard-recommendation-engine";
    frame.hidden = true;
    frame.setAttribute("aria-hidden", "true");
    frame.tabIndex = -1;
    const url = preservedURL(config.routes?.find || "FindMatch.htm");
    url.searchParams.set("dashboardRecommendations", "1");
    url.searchParams.set("username", username);
    frame.src = url.href;
    state.recommendationFrame = frame;
    state.assistantFullReady = false;
    byId("dashboardMatchAssistantHost")?.appendChild(frame);
    state.recommendationTimer = window.setTimeout(() => {
      if (run !== state.recommendationRun) return;
      const fallback = preservedURL(config.routes?.find || "FindMatch.htm");
      fallback.searchParams.set("dashboardAssistant", "1");
      fallback.searchParams.set("username", username);
      disposeRecommendationFrame();
      const button = byId("findMoreMatchesLink");
      if (button) button.dataset.fallbackHref = fallback.href;
      recommendationError(
        state.recommendationFallbackVisible
          ? "Live refresh timed out; cached recommendations shown."
          : "The Match Assistant recommendation analysis took too long. Open the full assistant to retry.",
        { preserveExisting: state.recommendationFallbackVisible }
      );
    }, 90000);
  }
  function displayRatingRange(value) {
    const text = String(value || "—").trim();
    return /^0\s*\+$/.test(text) ? "Open rating" : text;
  }
  function recommendationCard(item) {
    const article = document.createElement("article");
    article.className = `dashboard-recommendation${item.priority ? " is-priority" : ""}`;
    const line = document.createElement("div");
    line.className = "dashboard-recommendation-line";
    const tags = document.createElement("div");
    tags.className = "dashboard-recommendation-tags";
    const tag = (label, className = "") => {
      const element = document.createElement("span");
      element.className = `dashboard-tag${className ? ` ${className}` : ""}`;
      element.textContent = label;
      return element;
    };
    tags.append(
      tag(item.league || "Open", item.priority ? "priority" : ""),
      tag(`${item.scoreLabel || "Recommended"} ${Number(item.score || 0)}`, "recommended"),
      tag(displayRatingRange(item.ratingRange), "rating"),
      tag(item.rules || "Daily", "rules")
    );
    const title = document.createElement("a");
    title.className = "dashboard-recommendation-title";
    title.textContent = item.name || "Unnamed match";
    title.href = item.url || preservedURL(config.routes?.find || "FindMatch.htm").href;
    if (item.url) { title.target = "_blank"; title.rel = "noopener noreferrer"; }
    const date = document.createElement("span");
    date.className = "dashboard-recommendation-date";
    date.textContent = formatDateOnly(item.startTime);
    const info = document.createElement("span");
    info.className = "dashboard-recommendation-info-wrap";
    const infoId = `dashboardRecommendationInfo${++recommendationInfoSequence}`;
    const infoButton = document.createElement("button");
    infoButton.type = "button";
    infoButton.className = "dashboard-recommendation-info-button p2k-info-button";
    infoButton.setAttribute("aria-label", "Show recommendation justification");
    infoButton.setAttribute("aria-expanded", "false");
    infoButton.setAttribute("aria-controls", infoId);
    infoButton.dataset.p2kInfoTrigger = infoId;
    infoButton.title = "Recommendation justification";
    infoButton.textContent = "i";
    const content = document.createElement("span");
    content.id = infoId;
    content.className = "dashboard-recommendation-popover p2k-info-popover";
    content.dataset.kind = "dialog";
    content.dataset.p2kInfoPopover = "dialog";
    content.setAttribute("role", "dialog");
    content.setAttribute("aria-label", "Recommendation justification");
    content.hidden = true;
    const reasons = Array.isArray(item.reasons) && item.reasons.length ? item.reasons.join(" ") : "The Match Assistant recommendation score passed its default threshold.";
    const reason = document.createElement("p");
    reason.textContent = reasons;
    content.appendChild(reason);
    if (item.scoreExplanation) {
      const calculation = document.createElement("p");
      const calculationLabel = document.createElement("strong");
      calculationLabel.textContent = "Score calculation: ";
      calculation.append(calculationLabel, document.createTextNode(String(item.scoreExplanation)));
      content.appendChild(calculation);
    }
    info.append(infoButton, content);
    line.append(tags, title, date, info);
    const view = document.createElement("a");
    view.className = "dashboard-button dashboard-recommendation-view";
    view.textContent = "View";
    view.href = item.url || preservedURL(config.routes?.find || "FindMatch.htm").href;
    if (item.url) { view.target = "_blank"; view.rel = "noopener noreferrer"; }
    article.append(line, view);
    return article;
  }
  function ensureDedicatedMatchAssistant(filter = state.pendingAssistantFilter) {
    const normalized = ["next7", "priority"].includes(String(filter || "")) ? String(filter) : "";
    const username = viewed();
    if (!username) return null;
    let frame = state.assistantDedicated ? state.assistantFrame : null;
    if (frame && String(frame.dataset.username || "").toLowerCase() === username.toLowerCase()) {
      frame.dataset.dashboardFilter = normalized;
      if (state.assistantReady) frame.contentWindow?.postMessage({ type: "p2k-dashboard-apply-filter", filter: normalized }, window.location.origin);
      return frame;
    }
    if (state.assistantDedicated && state.assistantFrame) state.assistantFrame.remove();
    frame = document.createElement("iframe");
    frame.dataset.username = username;
    frame.dataset.dashboardFilter = normalized;
    frame.title = "Promote to King Match Assistant";
    frame.className = "dashboard-assistant-frame";
    frame.hidden = true;
    frame.setAttribute("aria-hidden", "true");
    frame.tabIndex = -1;
    const url = preservedURL(config.routes?.find || "FindMatch.htm");
    url.searchParams.delete("dashboardRecommendations");
    url.searchParams.set("dashboardAssistant", "1");
    url.searchParams.set("username", username);
    if (normalized) url.searchParams.set("dashboardFilter", normalized); else url.searchParams.delete("dashboardFilter");
    url.hash = "";
    frame.src = url.href;
    state.assistantFrame = frame;
    state.assistantDedicated = true;
    state.assistantReady = false;
    state.assistantFullReady = false;
    byId("dashboardMatchAssistantHost")?.appendChild(frame);
    stopAssistantTimer();
    state.assistantTimer = window.setTimeout(() => {
      if (!state.assistantDedicated || state.assistantFrame !== frame || state.assistantReady) return;
      const preparing = byId("dashboardMatchAssistantPreparing");
      if (preparing && !preparing.hidden && state.assistantOpen) preparing.textContent = "The Match Assistant is still loading. You can close this panel and retry without losing Dashboard state.";
    }, 30000);
    return frame;
  }

  function promoteMatchAssistantFrame(frame = state.assistantFrame) {
    if (!frame) return;
    frame.classList.remove("dashboard-recommendation-engine");
    frame.classList.add("dashboard-assistant-frame");
    frame.title = "Promote to King Match Assistant";
  }
  function syncMatchAssistantLoadingState() {
    // v2.10.6.10: one authority for assistant/loader visibility. A ready,
    // visible assistant must never coexist with a stale preparation layer.
    const panel = byId("dashboardMatchAssistant");
    const frame = state.assistantFrame;
    const assistantVisible = !!(state.assistantOpen && panel && !panel.hidden);
    const frameVisible = !!(assistantVisible && state.assistantFullReady && frame && !frame.hidden);
    const preparing = byId("dashboardMatchAssistantPreparing");
    if (preparing) {
      preparing.hidden = !assistantVisible || frameVisible;
      if (preparing.hidden) preparing.textContent = "Preparing the full Match Assistant…";
    }
    const recommendationsLoading = byId("recommendationsLoading");
    if (recommendationsLoading && assistantVisible) recommendationsLoading.hidden = true;
  }
  function revealMatchAssistantFrame(frame = state.assistantFrame) {
    if (!frame || !state.assistantOpen || !state.assistantFullReady) return;
    const card=byId("recommendationsCard");
    state.recommendationCardHeight=Math.max(card?.getBoundingClientRect().height||0,1);
    card?.classList.add("dashboard-no-transition");
    if(card)card.style.minHeight=`${Math.ceil(state.recommendationCardHeight)}px`;
    promoteMatchAssistantFrame(frame);
    if(byId("recommendationsDefaultView"))byId("recommendationsDefaultView").hidden=true;
    if(byId("dashboardMatchAssistant"))byId("dashboardMatchAssistant").hidden=false;
    card?.classList.add("is-assistant-open");
    frame.removeAttribute("hidden");frame.hidden=false;frame.removeAttribute("aria-hidden");frame.tabIndex=0;
    resizeRecommendationFrame(frame);syncMatchAssistantLoadingState();
    requestAnimationFrame(()=>requestAnimationFrame(()=>{card?.classList.remove("dashboard-no-transition");if(card)card.style.minHeight="";}));
  }
  function handleAdminEmbeddedNavigation(event) {
    if(event.origin!==window.location.origin||state.view!=="admin"||!state.adminDetail)return false;
    if(event.data?.type!=="p2k-embedded-tab-change")return false;
    const frame=byId("adminShellDetailFrame");if(!frame||event.source!==frame.contentWindow)return true;
    const tab=String(event.data.tab||"").toLowerCase();if(!tab)return true;
    const def=adminDetailDefinition();
    if(def?.tabs?.some(item=>item.key===tab)){state.adminDetailTab=tab;state.adminToolTab="";byId("adminShellDetailTabs")?.querySelectorAll("[data-admin-detail-tab]").forEach(link=>{const active=link.dataset.adminDetailTab===tab;link.classList.toggle("is-active",active);if(active)link.setAttribute("aria-current","page");else link.removeAttribute("aria-current");});}
    else state.adminToolTab=tab;
    writeNavigationState();return true;
  }
  function handleRecommendationMessage(event) {
    if (handleAdminEmbeddedNavigation(event)) return;
    if (event.origin !== window.location.origin) return;
    const expectedUsername = viewed().toLowerCase();
    if (event.data?.type === "p2k-dashboard-full-assistant-ready") {
      const frame = state.assistantFrame;
      if (!frame || event.source !== frame.contentWindow) return;
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      state.assistantFullReady = true;
      revealMatchAssistantFrame(frame);
      if (state.pendingAssistantFilter) frame.contentWindow?.postMessage({ type: "p2k-dashboard-apply-filter", filter: state.pendingAssistantFilter }, window.location.origin);
      return;
    }
    if (event.data?.type === "p2k-dashboard-assistant-ready") {
      const frame = state.assistantFrame;
      if (!frame || event.source !== frame.contentWindow) return;
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      stopAssistantTimer();
      state.assistantReady = true;
      state.assistantFullReady = false;
      prepareEmbeddedMatchAssistant(frame);
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
      const button = byId("findMoreMatchesLink");
      if (button) { button.disabled = false; delete button.dataset.fallbackHref; }
      if (state.assistantOpen && state.publicPage === "dashboard") {
        frame.contentWindow?.postMessage({ type: "p2k-dashboard-show-full-assistant" }, window.location.origin);
        syncMatchAssistantLoadingState();
      }
      return;
    }
    if (!state.recommendationReady && event.data?.type === "p2k-dashboard-recommendations-progress" && event.source === state.recommendationFrame?.contentWindow) {
      if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
      const message = String(event.data.message || "Analyzing recommended matches…");
      setText("recommendationsSubtitle", message);
      return;
    }
    if (event.data?.type === "p2k-match-finder-height") {
      if (event.source === state.assistantFrame?.contentWindow) resizeRecommendationFrame(state.assistantFrame, event.data.height);
      return;
    }
    if (event.data?.type !== "p2k-dashboard-recommendations") return;
    const frame = state.recommendationFrame;
    if (!frame || event.source !== frame.contentWindow) return;
    if (String(event.data.username || "").trim().toLowerCase() !== expectedUsername) return;
    const cached=!!event.data.cached,terminal=!!event.data.terminal,warning=String(event.data.warning||"").trim();
    const recommendations = Array.isArray(event.data.recommendations) ? event.data.recommendations.slice(0, 5) : [];
    if (cached && recommendations.length) {
      state.recommendationFallbackVisible = true;
      state.adminPriorityData = event.data.adminQueue || state.adminPriorityData || null;
      state.adminPriorityError = "";
      state.adminPriorityFailures = Math.max(0, Number(event.data.failedCount) || 0);
      renderAdminPriorityCard();
      renderTeamIndicators(event.data.teamIndicators || null);
      const loading = byId("recommendationsLoading");
      if (loading) loading.hidden = terminal;
      const list = byId("recommendationsList");
      list?.replaceChildren(...recommendations.map(recommendationCard));
      const empty = byId("recommendationsEmpty");
      if (empty) empty.hidden = true;
      setText("recommendationsSubtitle", warning || "Showing cached recommendations; refreshing live data.");
      if (!terminal) return;
      stopRecommendationTimer();
      state.recommendationReady = true;
      if (!state.assistantDedicated) {
        state.assistantFrame = frame;
        state.assistantReady = true;
        state.assistantFullReady = false;
      }
      if (!state.assistantDedicated) {
        prepareEmbeddedMatchAssistant(frame);
        frame.hidden = true;
        frame.setAttribute("aria-hidden", "true");
        frame.tabIndex = -1;
      }
      const cachedButton = byId("findMoreMatchesLink");
      if (cachedButton) { cachedButton.disabled = false; delete cachedButton.dataset.fallbackHref; }
      // v2.10.6: opening intent survives recommendation hydration. Re-enter the
      // assistant opener once the cached terminal frame is promotable; it will
      // request full-assistant mode and keep the pending next7/priority filter.
      if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });
      return;
    }
    if (event.data.error) {
      stopRecommendationTimer();
      recommendationError(event.data.error, { preserveExisting: state.recommendationFallbackVisible });
      return;
    }
    stopRecommendationTimer();
    state.recommendationFallbackVisible = false;
    state.recommendationReady = true;
    if (!state.assistantDedicated) {
      state.assistantFrame = frame;
      state.assistantReady = true;
      state.assistantFullReady = false;
    }
    if (!state.assistantDedicated) {
      prepareEmbeddedMatchAssistant(frame);
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
    }
    const findMoreButton = byId("findMoreMatchesLink");
    if (findMoreButton) { findMoreButton.disabled = false; delete findMoreButton.dataset.fallbackHref; }
    const loading = byId("recommendationsLoading");
    if (loading) loading.hidden = true;
    state.adminPriorityData = event.data.adminQueue || null;
    state.adminPriorityError = "";
    state.adminPriorityFailures = Math.max(0, Number(event.data.failedCount) || 0);
    renderAdminPriorityCard();
    renderTeamIndicators(event.data.teamIndicators || null);
    if (!recommendations.length) {
      recommendationError("The Match Assistant found no match matching its default recommended, unregistered and rating-eligible filters.");
      return;
    }
    const list = byId("recommendationsList");
    list?.replaceChildren(...recommendations.map(recommendationCard));
    const empty = byId("recommendationsEmpty");
    if (empty) empty.hidden = true;
    setText("recommendationsSubtitle", `${recommendations.length} personalized recommendation${recommendations.length === 1 ? "" : "s"} for ${viewed()}.`);
    // v2.10.6: do not let the recommendation terminal render hide an already
    // requested Match Assistant. The opener is authoritative and will reveal
    // the iframe as soon as full-assistant-ready arrives.
    if (state.assistantOpen && state.publicPage === "dashboard") openMatchAssistant({ updateHistory: false });
  }
  function openMatchAssistant({ updateHistory = true } = {}) {
    const button=byId("findMoreMatchesLink");
    state.assistantOpen=true;
    state.recommendationReturnScroll={x:window.scrollX,y:window.scrollY};
    const frame=ensureDedicatedMatchAssistant(state.pendingAssistantFilter);
    if(!frame){
      if(button?.dataset.fallbackHref&&!state.pendingAssistantFilter){window.location.href=button.dataset.fallbackHref;return;}
      if(updateHistory)writeNavigationState();return;
    }
    if(state.assistantFullReady)revealMatchAssistantFrame(frame);
    else if(state.assistantReady)frame.contentWindow?.postMessage({type:"p2k-dashboard-show-full-assistant"},window.location.origin);
    syncMatchAssistantLoadingState();
    if(updateHistory)writeNavigationState();
  }
  function openMatchAssistantWithFilter(filter, { updateHistory = true } = {}) {
    const normalized=["next7","priority"].includes(String(filter||""))?String(filter):"";
    state.pendingAssistantFilter = normalized;
    ensureDedicatedMatchAssistant(normalized);
    openMatchAssistant({ updateHistory });
  }
  function closeMatchAssistant({ updateHistory = true } = {}) {
    const card = byId("recommendationsCard");
    const frame = state.assistantFrame;
    card?.classList.add("dashboard-no-transition");
    if (card) card.style.minHeight = `${Math.ceil(Math.max(state.recommendationCardHeight, card.getBoundingClientRect().height))}px`;
    if (frame) {
      frame.hidden = true;
      frame.setAttribute("aria-hidden", "true");
      frame.tabIndex = -1;
    }
    byId("dashboardMatchAssistant").hidden = true;
    byId("recommendationsDefaultView").hidden = false;
    byId("recommendationsCard").classList.remove("is-assistant-open");
    state.assistantOpen = false;
    syncMatchAssistantLoadingState();
    if (updateHistory) writeNavigationState();
    const position = state.recommendationReturnScroll;
    if (position) window.scrollTo(position.x, position.y);
    requestAnimationFrame(() => requestAnimationFrame(() => {
      card?.classList.remove("dashboard-no-transition");
      if (card) card.style.minHeight = "";
      if (position) window.scrollTo(position.x, position.y);
    }));
  }

return Object.freeze({ stopRecommendationTimer, stopAssistantTimer, disposeRecommendationFrame, prepareEmbeddedMatchAssistant, resizeRecommendationFrame, recommendationError, loadRecommendations, displayRatingRange, recommendationCard, ensureDedicatedMatchAssistant, promoteMatchAssistantFrame, syncMatchAssistantLoadingState, revealMatchAssistantFrame, handleAdminEmbeddedNavigation, handleRecommendationMessage, openMatchAssistant, openMatchAssistantWithFilter, closeMatchAssistant });
}
});
})();
