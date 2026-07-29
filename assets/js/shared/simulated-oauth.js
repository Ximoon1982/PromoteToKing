/* Optional simulated Chess.com OAuth for Promote to King tools.
 * Enabled only with ?oauth=1 (or an equivalent explicit feature flag).
 * This is deliberately a simulation: it never asks for a Chess.com password
 * and only reads the public Chess.com player profile endpoint.
 */
(() => {
  "use strict";

  const SESSION_KEY = "p2k.simulatedOAuth.session.v1";
  const CHANNEL_NAME = "p2k-simulated-oauth";
  const PLAYER_API = "https://api.chess.com/pub/player/";
  const TRUE_VALUES = new Set(["1", "true", "yes", "on", "enabled"]);
  const subscribers = new Set();

  let session = readStoredSession();
  let channel = null;
  let modal = null;
  let lastFocusedElement = null;
  let mutationObserver = null;
  let renderQueued = false;

  function flagEnabled() {
    const params = new URLSearchParams(window.location.search);
    const explicitValue = params.get("oauth") ?? params.get("simulatedOAuth");
    if (explicitValue !== null) {
      return TRUE_VALUES.has(String(explicitValue).trim().toLowerCase());
    }

    return window.P2K_ENABLE_SIMULATED_OAUTH === true ||
      window.P2K_SITE_CONFIG?.features?.simulatedOAuth === true;
  }

  const enabled = flagEnabled();

  const authAPI = Object.freeze({
    enabled,
    getSession: () => cloneSession(session),
    login,
    logout,
    openLogin: () => enabled && openModal("login"),
    openProfile: () => enabled && openModal(session ? "profile" : "login"),
    subscribe(callback) {
      if (typeof callback !== "function") return () => {};
      subscribers.add(callback);
      return () => subscribers.delete(callback);
    },
    refresh: queueRender
  });

  window.P2K_AUTH = authAPI;
  window.P2K_SIMULATED_OAUTH_ENABLED = enabled;

  if (!enabled) return;

  document.documentElement.classList.add("p2k-simulated-oauth-enabled");

  if ("BroadcastChannel" in window) {
    try {
      channel = new BroadcastChannel(CHANNEL_NAME);
      channel.addEventListener("message", event => {
        if (event?.data?.type !== "session") return;
        applyExternalSession(event.data.session);
      });
    } catch (error) {
      console.warn("Simulated OAuth channel unavailable.", error);
    }
  }

  window.addEventListener("storage", event => {
    if (event.key !== SESSION_KEY) return;
    applyExternalSession(parseSession(event.newValue));
  });

  window.addEventListener("pagehide", () => {
    mutationObserver?.disconnect();
    mutationObserver = null;
    channel?.close();
    channel = null;
  }, { once: true });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }

  function initialize() {
    ensureHeaderControl();
    queueRender();

    if (document.body && "MutationObserver" in window) {
      mutationObserver = new MutationObserver(queueRender);
      mutationObserver.observe(document.body, {
        childList: true,
        subtree: true
      });
    }
  }

  function normalizeUsername(value) {
    return String(value || "").trim();
  }

  function validateUsername(value) {
    const username = normalizeUsername(value);
    if (!username) {
      throw new Error("Enter a Chess.com username.");
    }
    if (username.length > 50 || /[\s/\\?#]/.test(username)) {
      throw new Error("Enter a valid Chess.com username.");
    }
    return username;
  }

  function safeText(value) {
    return typeof value === "string" ? value.trim() : "";
  }

  function safeInteger(value) {
    const number = Number(value);
    return Number.isFinite(number) ? Math.trunc(number) : null;
  }

  function safeHTTPSURL(value) {
    if (!value) return "";
    try {
      const url = new URL(String(value));
      return url.protocol === "https:" ? url.href : "";
    } catch (_) {
      return "";
    }
  }

  function profileToSession(profile, requestedUsername) {
    const username = safeText(profile?.username) || requestedUsername;
    return {
      version: 1,
      username,
      avatar: safeHTTPSURL(profile?.avatar),
      profileURL: safeHTTPSURL(profile?.url) ||
        `https://www.chess.com/member/${encodeURIComponent(username)}`,
      playerId: safeInteger(profile?.player_id),
      title: safeText(profile?.title),
      name: safeText(profile?.name),
      status: safeText(profile?.status),
      location: safeText(profile?.location),
      followers: safeInteger(profile?.followers),
      joined: safeInteger(profile?.joined),
      lastOnline: safeInteger(profile?.last_online),
      fetchedAt: Date.now()
    };
  }

  function validSession(candidate) {
    return Boolean(
      candidate &&
      typeof candidate === "object" &&
      safeText(candidate.username)
    );
  }

  function normalizeSession(candidate) {
    if (!validSession(candidate)) return null;
    return {
      version: 1,
      username: safeText(candidate.username),
      avatar: safeHTTPSURL(candidate.avatar),
      profileURL: safeHTTPSURL(candidate.profileURL),
      playerId: safeInteger(candidate.playerId),
      title: safeText(candidate.title),
      name: safeText(candidate.name),
      status: safeText(candidate.status),
      location: safeText(candidate.location),
      followers: safeInteger(candidate.followers),
      joined: safeInteger(candidate.joined),
      lastOnline: safeInteger(candidate.lastOnline),
      fetchedAt: safeInteger(candidate.fetchedAt) || Date.now()
    };
  }

  function cloneSession(value) {
    return value ? { ...value } : null;
  }

  function parseSession(raw) {
    if (!raw) return null;
    try {
      return normalizeSession(JSON.parse(raw));
    } catch (_) {
      return null;
    }
  }

  function readStoredSession() {
    try {
      return parseSession(window.localStorage.getItem(SESSION_KEY));
    } catch (_) {
      return null;
    }
  }

  function persistSession(value) {
    try {
      if (value) {
        window.localStorage.setItem(SESSION_KEY, JSON.stringify(value));
      } else {
        window.localStorage.removeItem(SESSION_KEY);
      }
    } catch (error) {
      console.warn("Unable to persist the simulated OAuth session.", error);
    }
  }

  function broadcastSession(value) {
    try {
      channel?.postMessage({ type: "session", session: cloneSession(value) });
    } catch (error) {
      console.warn("Unable to broadcast the simulated OAuth session.", error);
    }
  }

  function notifySubscribers() {
    const snapshot = cloneSession(session);
    subscribers.forEach(callback => {
      try {
        callback(snapshot);
      } catch (error) {
        console.error("Simulated OAuth subscriber failed.", error);
      }
    });
    window.dispatchEvent(new CustomEvent("p2k-auth-change", {
      detail: snapshot
    }));
  }

  function setSession(nextSession, options = {}) {
    session = normalizeSession(nextSession);

    if (options.persist !== false) persistSession(session);
    if (options.broadcast !== false) broadcastSession(session);

    notifySubscribers();
    queueRender();
    return cloneSession(session);
  }

  function applyExternalSession(nextSession) {
    const normalized = normalizeSession(nextSession);
    const currentSerialized = session ? JSON.stringify(session) : "";
    const nextSerialized = normalized ? JSON.stringify(normalized) : "";
    if (currentSerialized === nextSerialized) return;

    session = normalized;
    notifySubscribers();
    queueRender();
  }

  async function login(rawUsername) {
    if (!enabled) throw new Error("Simulated OAuth is disabled.");
    const username = validateUsername(rawUsername);

    let response;
    try {
      response = await fetch(PLAYER_API + encodeURIComponent(username.toLowerCase()), {
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
    } catch (_) {
      throw new Error("Unable to contact Chess.com. Check your connection and try again.");
    }

    if (response.status === 404) {
      throw new Error(`Chess.com player “${username}” was not found.`);
    }
    if (!response.ok) {
      throw new Error(`Chess.com profile request failed (HTTP ${response.status}).`);
    }

    const profile = await response.json();
    return setSession(profileToSession(profile, username));
  }

  function logout() {
    const previousUsername = session?.username || "";
    setSession(null);
    closeModal();

    document.querySelectorAll("#p2kUsername[data-p2k-auth-filled='true']")
      .forEach(input => {
        if (!previousUsername || input.value === previousUsername) input.value = "";
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
  }

  function queueRender() {
    if (renderQueued) return;
    renderQueued = true;
    window.requestAnimationFrame(() => {
      renderQueued = false;
      ensureHeaderControl();
      renderHeaderControl();
      bindMatchAssistant();
    });
  }

  function ensureHeaderControl() {
    const header = document.querySelector(".site-header");
    if (!header || header.querySelector("#p2kAuthHost")) return;

    const host = document.createElement("div");
    host.id = "p2kAuthHost";
    host.className = "p2k-auth-host";
    host.setAttribute("aria-live", "polite");
    header.appendChild(host);
  }

  function renderHeaderControl() {
    const host = document.getElementById("p2kAuthHost");
    if (!host) return;

    const signature = session
      ? `in:${session.username}:${session.avatar}`
      : "out";
    if (host.dataset.p2kAuthSignature === signature) return;
    host.dataset.p2kAuthSignature = signature;
    host.replaceChildren();

    if (!session) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "p2k-auth-login-button";
      button.textContent = "Log in with Chess.com";
      button.title = "Simulate Chess.com OAuth using a public player profile.";
      button.addEventListener("click", () => openModal("login"));
      host.appendChild(button);
      return;
    }

    const button = document.createElement("button");
    button.type = "button";
    button.className = "p2k-auth-avatar-button";
    button.title = `${session.username} — open account details`;
    button.setAttribute("aria-label", `${session.username} account details`);
    button.addEventListener("click", () => openModal("profile"));
    button.appendChild(createAvatar(session, "p2k-auth-avatar"));
    host.appendChild(button);
  }

  function createAvatar(value, className) {
    if (value?.avatar) {
      const image = document.createElement("img");
      image.className = className;
      image.src = value.avatar;
      image.alt = `${value.username} avatar`;
      image.referrerPolicy = "no-referrer";
      image.addEventListener("error", () => {
        image.replaceWith(createInitialsAvatar(value, className));
      }, { once: true });
      return image;
    }
    return createInitialsAvatar(value, className);
  }

  function createInitialsAvatar(value, className) {
    const fallback = document.createElement("span");
    fallback.className = `${className} p2k-auth-avatar-fallback`;
    fallback.setAttribute("aria-hidden", "true");
    fallback.textContent = safeText(value?.username).slice(0, 2).toUpperCase() || "?";
    return fallback;
  }

  function bindMatchAssistant() {
    const input = document.getElementById("p2kUsername");
    if (!(input instanceof HTMLInputElement)) return;

    const row = input.closest(".p2k-user-search-row") || input.parentElement;
    if (!row) return;

    let identity = row.querySelector(".p2k-auth-assistant-user");

    if (!session) {
      identity?.remove();
      input.hidden = false;
      input.removeAttribute("aria-hidden");
      input.removeAttribute("tabindex");
      input.removeAttribute("data-p2k-auth-filled");
      row.classList.remove("p2k-auth-assistant-row-authenticated");
      return;
    }

    if (input.value !== session.username) {
      input.value = session.username;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }

    input.hidden = true;
    input.setAttribute("aria-hidden", "true");
    input.tabIndex = -1;
    input.dataset.p2kAuthFilled = "true";
    row.classList.add("p2k-auth-assistant-row-authenticated");

    if (!identity) {
      identity = document.createElement("div");
      identity.className = "p2k-auth-assistant-user";
      const searchButton = row.querySelector("#p2kSearchButton");
      row.insertBefore(identity, searchButton || row.firstChild);
    }

    const identitySignature = `${session.username}:${session.avatar}`;
    if (identity.dataset.p2kAuthSignature !== identitySignature) {
      identity.dataset.p2kAuthSignature = identitySignature;
      identity.replaceChildren(
        createAvatar(session, "p2k-auth-assistant-avatar"),
        buildAssistantIdentityText(session)
      );
    }
  }

  function buildAssistantIdentityText(value) {
    const text = document.createElement("span");
    text.className = "p2k-auth-assistant-label";
    text.append("Searching as ");
    const strong = document.createElement("strong");
    strong.textContent = value.username;
    text.appendChild(strong);
    return text;
  }

  function ensureModal() {
    if (modal?.isConnected) return modal;

    modal = document.createElement("div");
    modal.id = "p2kAuthModal";
    modal.className = "p2k-auth-modal";
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    modal.innerHTML = `
      <div class="p2k-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kAuthTitle">
        <div class="p2k-auth-dialog-header">
          <h2 id="p2kAuthTitle"></h2>
          <button type="button" class="p2k-auth-close" aria-label="Close">×</button>
        </div>
        <div class="p2k-auth-dialog-body"></div>
      </div>`;

    modal.addEventListener("click", event => {
      if (event.target === modal) closeModal();
    });
    modal.querySelector(".p2k-auth-close")?.addEventListener("click", closeModal);
    document.addEventListener("keydown", handleModalKeydown);
    document.body.appendChild(modal);
    return modal;
  }

  function handleModalKeydown(event) {
    if (event.key === "Escape" && modal && !modal.hidden) closeModal();
  }

  function openModal(mode) {
    const overlay = ensureModal();
    lastFocusedElement = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;

    renderModal(mode === "profile" && session ? "profile" : "login");
    overlay.hidden = false;
    overlay.setAttribute("aria-hidden", "false");
    document.body.classList.add("p2k-auth-modal-open");

    window.requestAnimationFrame(() => {
      overlay.querySelector("input, button, a")?.focus();
    });
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("p2k-auth-modal-open");
    lastFocusedElement?.focus?.();
    lastFocusedElement = null;
  }

  function renderModal(mode) {
    const overlay = ensureModal();
    const title = overlay.querySelector("#p2kAuthTitle");
    const body = overlay.querySelector(".p2k-auth-dialog-body");
    if (!title || !body) return;

    body.replaceChildren();
    if (mode === "profile" && session) {
      title.textContent = "Chess.com profile";
      renderProfileBody(body);
    } else {
      title.textContent = "Log in with Chess.com";
      renderLoginBody(body);
    }
  }

  function renderLoginBody(body) {
    const note = document.createElement("p");
    note.className = "p2k-auth-note";
    note.textContent = "Simulation only: enter a Chess.com username. No password or access token is requested.";

    const form = document.createElement("form");
    form.className = "p2k-auth-login-form";

    const label = document.createElement("label");
    label.htmlFor = "p2kAuthUsername";
    label.textContent = "Chess.com username";

    const input = document.createElement("input");
    input.id = "p2kAuthUsername";
    input.name = "username";
    input.type = "text";
    input.autocomplete = "username";
    input.spellcheck = false;
    input.maxLength = 50;
    input.placeholder = "e.g. Ximoon";
    input.value = session?.username || "";

    const error = document.createElement("div");
    error.className = "p2k-auth-error";
    error.setAttribute("role", "alert");

    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "p2k-auth-primary";
    submit.textContent = "Log in";

    form.addEventListener("submit", async event => {
      event.preventDefault();
      error.textContent = "";
      submit.disabled = true;
      input.disabled = true;
      submit.textContent = "Checking profile…";

      try {
        await login(input.value);
        closeModal();
      } catch (loginError) {
        error.textContent = loginError?.message || String(loginError);
        submit.disabled = false;
        input.disabled = false;
        submit.textContent = "Log in";
        input.focus();
      }
    });

    form.append(label, input, error, submit);
    body.append(note, form);
  }

  function renderProfileBody(body) {
    const summary = document.createElement("div");
    summary.className = "p2k-auth-profile-summary";
    summary.appendChild(createAvatar(session, "p2k-auth-profile-avatar"));

    const identity = document.createElement("div");
    identity.className = "p2k-auth-profile-identity";
    const username = document.createElement("strong");
    username.textContent = session.username;
    identity.appendChild(username);
    if (session.name) {
      const name = document.createElement("span");
      name.textContent = session.name;
      identity.appendChild(name);
    }
    if (session.title || session.status) {
      const meta = document.createElement("span");
      meta.textContent = [session.title, session.status].filter(Boolean).join(" · ");
      identity.appendChild(meta);
    }
    summary.appendChild(identity);

    const details = document.createElement("dl");
    details.className = "p2k-auth-profile-details";
    appendProfileDetail(details, "Location", session.location);
    appendProfileDetail(details, "Followers", formatNumber(session.followers));
    appendProfileDetail(details, "Joined", formatUnixDate(session.joined));
    appendProfileDetail(details, "Last online", formatUnixDate(session.lastOnline, true));

    const actions = document.createElement("div");
    actions.className = "p2k-auth-profile-actions";

    if (session.profileURL) {
      const profileLink = document.createElement("a");
      profileLink.className = "p2k-auth-secondary";
      profileLink.href = session.profileURL;
      profileLink.target = "_blank";
      profileLink.rel = "noopener noreferrer";
      profileLink.textContent = "Open Chess.com profile";
      actions.appendChild(profileLink);
    }

    const logoutButton = document.createElement("button");
    logoutButton.type = "button";
    logoutButton.className = "p2k-auth-danger";
    logoutButton.textContent = "Log off";
    logoutButton.addEventListener("click", logout);
    actions.appendChild(logoutButton);

    body.append(summary);
    if (details.childElementCount) body.append(details);
    body.append(actions);
  }

  function appendProfileDetail(list, label, value) {
    if (!value) return;
    const term = document.createElement("dt");
    term.textContent = label;
    const description = document.createElement("dd");
    description.textContent = value;
    list.append(term, description);
  }

  function formatNumber(value) {
    return Number.isFinite(value)
      ? new Intl.NumberFormat(undefined).format(value)
      : "";
  }

  function formatUnixDate(value, includeTime = false) {
    if (!Number.isFinite(value) || value <= 0) return "";
    const date = new Date(value * 1000);
    if (Number.isNaN(date.getTime())) return "";
    return new Intl.DateTimeFormat(undefined, includeTime ? {
      dateStyle: "medium",
      timeStyle: "short"
    } : {
      dateStyle: "medium"
    }).format(date);
  }
})();
