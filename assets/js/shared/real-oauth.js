/* Real Chess.com OAuth adapter.
 * Real OAuth is the default on every P2K page. ?oauth=1 remains the explicit
 * simulated/serial override. ?name=<username> is display-only impersonation: it
 * never changes the real OAuth session, server credentials, CSRF identity or Bearer
 * transport. The Bearer token never leaves the P2K server.
 */
(() => {
  "use strict";
  const params = new URLSearchParams(window.location.search);
  const requestedSimulatedOAuth = params.get("oauth") === "1" || params.get("simulatedOAuth") === "1";
  const requestedRealOAuth = !requestedSimulatedOAuth;
  const requestedDisplayName = (() => {
    const value = String(params.get("name") || "").trim();
    return /^[A-Za-z0-9_-]{1,80}$/.test(value) ? value : "";
  })();
  if (requestedSimulatedOAuth) return;

  const ENDPOINT = new URL("server/team-points/public/oauth.php", window.location.href).href;
  const subscribers = new Set();
  let readyResolve = null;
  let readySettled = false;
  const readyPromise = new Promise(resolve => { readyResolve = resolve; });
  window.P2K_REAL_OAUTH_READY = readyPromise;
  let session = null;
  let csrf = "";
  let adminBootstrap = "";
  let adminBootstrapReceivedAt = 0;
  let enabled = true;
  let modal = null;
  let lastFocused = null;
  let renderQueued = false;
  let observer = null;
  let apiInstalled = false;

  const api = Object.freeze({
    enabled: true,
    mode: "real-oauth",
    realOAuth: true,
    get oauthVerified() { return Boolean(session); },
    getSession: () => session ? { ...session } : null,
    getDisplayUsername: () => session ? (requestedDisplayName || session.username) : "",
    getDisplaySession: () => session ? { ...session, username: requestedDisplayName || session.username, displayOnly: Boolean(requestedDisplayName), authenticatedUsername: session.username } : null,
    getCsrf: () => csrf,
    getAdminBootstrap: () => adminBootstrap,
    getAdminBootstrapAgeMs: () => adminBootstrap ? Math.max(0, Date.now() - adminBootstrapReceivedAt) : Number.POSITIVE_INFINITY,
    login: startLogin,
    logout,
    openLogin: startLogin,
    openProfile: () => session ? openModal() : startLogin(),
    subscribe(callback) { if (typeof callback !== "function") return () => {}; subscribers.add(callback); return () => subscribers.delete(callback); },
    refresh: refreshSession
  });
  if (requestedRealOAuth) installApi();

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initialize, { once: true });
  else initialize();

  function installApi() {
    if (apiInstalled) return;
    apiInstalled = true;
    window.P2K_AUTH = api;
    window.P2K_REAL_OAUTH_ENABLED = true;
  }

  function ensureObserver() {
    if (observer || !apiInstalled || !document.body || !("MutationObserver" in window)) return;
    observer = new MutationObserver(queueRender);
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function activateSurface() {
    installApi();
    ensureHeaderControl();
    queueRender();
    ensureObserver();
  }

  function initialize() {
    activateSurface();
    refreshSession();
    window.addEventListener("pagehide", () => observer?.disconnect(), { once: true });
  }

  async function refreshSession() {
    try {
      const response = await fetch(`${ENDPOINT}?action=session`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      const payload = await response.json();
      if (!response.ok || payload?.ok === false) throw new Error(payload?.error?.message || `HTTP ${response.status}`);
      enabled = payload.enabled !== false; csrf = String(payload.csrf || "");
      session = payload.authenticated && payload.profile ? normalizeSession(payload.profile) : null;
      adminBootstrap = session ? String(payload.admin_bootstrap || "") : "";
      adminBootstrapReceivedAt = adminBootstrap ? Date.now() : 0;
      activateSurface();
      syncApiMode();
      if (apiInstalled) { notify(); queueRender(); }
      return session;
    } catch (error) {
      enabled = false; session = null; adminBootstrap = ""; adminBootstrapReceivedAt = 0; syncApiMode();
      activateSurface();
      if (apiInstalled) { notify(); queueRender(); }
      console.warn("Real OAuth session unavailable.", error); return null;
    } finally {
      if (!readySettled) { readySettled = true; readyResolve?.(session ? { ...session } : null); }
    }
  }

  function normalizeSession(value) {
    if (!value || typeof value !== "object" || !String(value.username || "").trim()) return null;
    return {
      ...value, version: 2, authMode: "real-oauth", realOAuth: true, oauthVerified: true,
      username: String(value.username || "").trim(), avatar: safeHTTPS(value.avatar), profileURL: safeHTTPS(value.profileURL),
      playerId: numberOrNull(value.playerId), followers: numberOrNull(value.followers), joined: numberOrNull(value.joined), lastOnline: numberOrNull(value.lastOnline), apiConcurrent: true
    };
  }
  function safeHTTPS(value) { try { const u = new URL(String(value || "")); return u.protocol === "https:" ? u.href : ""; } catch (_) { return ""; } }
  function numberOrNull(value) { const n = Number(value); return Number.isFinite(n) ? Math.trunc(n) : null; }

  function syncApiMode() {
    if (typeof window.P2K_API_CLIENT?.setOAuthBearerMode === "function") window.P2K_API_CLIENT.setOAuthBearerMode(Boolean(session));
    else if (typeof window.P2K_API_CLIENT?.setConcurrentMode === "function") window.P2K_API_CLIENT.setConcurrentMode(false);
  }

  function notify() {
    const snapshot = session ? { ...session } : null;
    subscribers.forEach(fn => { try { fn(snapshot); } catch (error) { console.error(error); } });
    window.dispatchEvent(new CustomEvent("p2k-auth-change", { detail: snapshot }));
  }

  function returnPath() {
    const u = new URL(window.location.href); u.searchParams.delete("oauth"); u.searchParams.delete("oauth_result"); u.searchParams.delete("simulatedOAuth");
    return `${u.pathname}${u.search}`;
  }
  function startLogin() {
    if (!enabled) { window.alert("Chess.com OAuth is not configured on this host."); return; }
    const url = new URL(ENDPOINT); url.searchParams.set("action", "login"); url.searchParams.set("return", returnPath()); window.location.assign(url.href);
  }
  async function logout() {
    try {
      const response = await fetch(`${ENDPOINT}?action=logout`, { method: "POST", credentials: "same-origin", cache: "no-store", headers: { "Content-Type": "application/json", "X-P2K-OAuth-CSRF": csrf, Accept: "application/json" }, body: JSON.stringify({ csrf }) });
      const payload = await response.json(); if (!response.ok || payload?.ok === false) throw new Error(payload?.error?.message || `HTTP ${response.status}`);
      csrf = String(payload.csrf || csrf); session = null; adminBootstrap = ""; adminBootstrapReceivedAt = 0; syncApiMode(); notify(); closeModal(); queueRender(); clearAssistantUsername();
    } catch (error) { window.alert(error?.message || "Unable to log out."); }
  }

  function ensureHeaderControl() {
    const header = document.querySelector(".site-header"); if (!header || header.querySelector("#p2kAuthHost")) return;
    const host = document.createElement("div"); host.id = "p2kAuthHost"; host.className = "p2k-auth-host"; host.setAttribute("aria-live", "polite"); header.appendChild(host);
  }
  function queueRender() { if (!apiInstalled || renderQueued) return; renderQueued = true; requestAnimationFrame(() => { renderQueued = false; ensureHeaderControl(); renderHeader(); bindAssistant(); }); }
  function renderHeader() {
    const host = document.getElementById("p2kAuthHost"); if (!host) return;
    const sig = session ? `in:${session.username}:${session.avatar}:view:${requestedDisplayName}` : `out:${enabled}`; if (host.dataset.p2kAuthSignature === sig) return;
    host.dataset.p2kAuthSignature = sig; host.replaceChildren();
    if (!session) { const b = document.createElement("button"); b.type = "button"; b.className = "p2k-auth-login-button"; b.textContent = "Log in with Chess.com"; b.title = "Log in securely with Chess.com OAuth."; b.disabled = !enabled; b.addEventListener("click", startLogin); host.appendChild(b); return; }
    const b = document.createElement("button"); b.type = "button"; b.className = "p2k-auth-avatar-button"; b.title = `${session.username} — open account details`; b.setAttribute("aria-label", `${session.username} account details`); b.addEventListener("click", openModal); b.appendChild(createAvatar(session, "p2k-auth-avatar")); host.appendChild(b);
    if (requestedDisplayName && requestedDisplayName.toLowerCase() !== session.username.toLowerCase()) { const badge=document.createElement("span"); badge.className="p2k-auth-viewing-as"; badge.textContent=`Viewing as ${requestedDisplayName}`; badge.title=`Display-only preview. Authenticated as ${session.username}.`; host.appendChild(badge); }
  }
  function createAvatar(value, className) {
    if (value?.avatar) { const img = document.createElement("img"); img.className = className; img.src = value.avatar; img.alt = `${value.username} avatar`; img.referrerPolicy = "no-referrer"; img.addEventListener("error", () => img.replaceWith(initials(value, className)), { once: true }); return img; }
    return initials(value, className);
  }
  function initials(value, className) { const s = document.createElement("span"); s.className = `${className} p2k-auth-avatar-fallback`; s.setAttribute("aria-hidden", "true"); s.textContent = String(value?.username || "?").slice(0, 2).toUpperCase(); return s; }

  function bindAssistant() {
    const input = document.getElementById("p2kUsername"); if (!(input instanceof HTMLInputElement)) return;
    const row = input.closest(".p2k-user-search-row") || input.parentElement; if (!row) return;
    let identity = row.querySelector(".p2k-auth-assistant-user");
    if (!session) { identity?.remove(); input.hidden = false; input.removeAttribute("aria-hidden"); input.removeAttribute("tabindex"); input.removeAttribute("data-p2k-auth-filled"); row.classList.remove("p2k-auth-assistant-row-authenticated"); return; }
    const displayUsername = requestedDisplayName || session.username;
    if (input.value !== displayUsername) { input.value = displayUsername; input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); }
    input.hidden = true; input.setAttribute("aria-hidden", "true"); input.tabIndex = -1; input.dataset.p2kAuthFilled = "true"; row.classList.add("p2k-auth-assistant-row-authenticated");
    if (!identity) { identity = document.createElement("div"); identity.className = "p2k-auth-assistant-user"; const search = row.querySelector("#p2kSearchButton"); row.insertBefore(identity, search || row.firstChild); }
    const sig = `${session.username}:${session.avatar}:view:${displayUsername}`; if (identity.dataset.p2kAuthSignature !== sig) { identity.dataset.p2kAuthSignature = sig; const text = document.createElement("span"); text.className = "p2k-auth-assistant-label"; text.append(requestedDisplayName ? "Viewing as " : "Searching as "); const strong = document.createElement("strong"); strong.textContent = displayUsername; text.appendChild(strong); identity.replaceChildren(createAvatar(session, "p2k-auth-assistant-avatar"), text); }
  }
  function clearAssistantUsername() { document.querySelectorAll("#p2kUsername[data-p2k-auth-filled='true']").forEach(input => { input.value = ""; input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true })); }); }

  function ensureModal() {
    if (modal?.isConnected) return modal;
    modal = document.createElement("div"); modal.id = "p2kAuthModal"; modal.className = "p2k-auth-modal"; modal.hidden = true; modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `<div class="p2k-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kAuthTitle"><div class="p2k-auth-dialog-header"><h2 id="p2kAuthTitle">Chess.com profile</h2><button type="button" class="p2k-auth-close" aria-label="Close">×</button></div><div class="p2k-auth-dialog-body"></div></div>`;
    modal.addEventListener("click", e => { if (e.target === modal) closeModal(); }); modal.querySelector(".p2k-auth-close")?.addEventListener("click", closeModal); document.addEventListener("keydown", e => { if (e.key === "Escape" && !modal.hidden) closeModal(); }); document.body.appendChild(modal); return modal;
  }
  function openModal() { if (!session) return startLogin(); const m = ensureModal(); lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null; renderProfile(m.querySelector(".p2k-auth-dialog-body")); m.hidden = false; m.setAttribute("aria-hidden", "false"); document.body.classList.add("p2k-auth-modal-open"); requestAnimationFrame(() => m.querySelector("button,a")?.focus()); }
  function closeModal() { if (!modal) return; modal.hidden = true; modal.setAttribute("aria-hidden", "true"); document.body.classList.remove("p2k-auth-modal-open"); lastFocused?.focus?.(); lastFocused = null; }
  function renderProfile(body) {
    if (!body || !session) return; body.replaceChildren();
    const summary = document.createElement("div"); summary.className = "p2k-auth-profile-summary"; summary.appendChild(createAvatar(session, "p2k-auth-profile-avatar"));
    const identity = document.createElement("div"); identity.className = "p2k-auth-profile-identity"; const user = document.createElement("strong"); user.textContent = session.username; identity.appendChild(user); if (session.name) { const n = document.createElement("span"); n.textContent = session.name; identity.appendChild(n); } if (session.title || session.status || session.membership) { const m = document.createElement("span"); m.textContent = [session.title, session.status, session.membership].filter(Boolean).join(" · "); identity.appendChild(m); } summary.appendChild(identity);
    const details = document.createElement("dl"); details.className = "p2k-auth-profile-details"; detail(details, "Location", session.location || session.country); detail(details, "Country", session.countryCode); detail(details, "Followers", formatNumber(session.followers)); detail(details, "Joined", formatDate(session.joined)); detail(details, "Last online", formatDate(session.lastOnline, true));
    const actions = document.createElement("div"); actions.className = "p2k-auth-profile-actions"; if (session.profileURL) { const a = document.createElement("a"); a.className = "p2k-auth-secondary"; a.href = session.profileURL; a.target = "_blank"; a.rel = "noopener noreferrer"; a.textContent = "Open Chess.com profile"; actions.appendChild(a); } const out = document.createElement("button"); out.type = "button"; out.className = "p2k-auth-danger"; out.textContent = "Log off"; out.addEventListener("click", logout); actions.appendChild(out);
    body.append(summary); if (details.childElementCount) body.append(details); body.append(actions);
  }
  function detail(list, label, value) { if (value === null || value === undefined || value === "") return; const dt = document.createElement("dt"); dt.textContent = label; const dd = document.createElement("dd"); dd.textContent = String(value); list.append(dt, dd); }
  function formatNumber(value) { return Number.isFinite(Number(value)) ? new Intl.NumberFormat("en-GB").format(Number(value)) : ""; }
  function formatDate(value, time = false) { const n = Number(value); if (!Number.isFinite(n) || n <= 0) return ""; return new Intl.DateTimeFormat("en-GB", time ? { timeZone: "UTC", dateStyle: "medium", timeStyle: "short" } : { timeZone: "UTC", dateStyle: "medium" }).format(new Date(n * 1000)); }
})();
