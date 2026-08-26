/* Persistent same-origin iframe router for the configured club site. */
(() => {
  "use strict";

  const configRoutes = window.P2K_SITE_CONFIG?.routes || {};
  const ROUTES = Object.freeze({
    find: configRoutes.find || "FindMatch.htm",
    upcoming: configRoutes.upcoming || "AnalyzeMatches.htm",
    creation: configRoutes.creation || "MatchCreationAnalyzer.htm",
    open: configRoutes.open || "AnalyzeMatch.html",
    recruit: configRoutes.recruit || "RecruitMatch.html",
    challenges: configRoutes.challenges || "ChallengeListAssistant.html",
    teamPoints: configRoutes.teamPoints || "TeamPointsAdmin.html",
    teamInsights: configRoutes.teamInsights || "TeamInsights.html",
    tournaments: configRoutes.tournaments || "Tournaments.html"
  });
  const MAIN_TITLE = window.P2K_SITE_CONFIG?.siteName || "Promote to King";
  const MAIN_SUBTITLE = window.P2K_SITE_CONFIG?.siteDescription || "Play together. Improve together. Promote to King.";
  const MAIN_LOGO = window.P2K_SITE_CONFIG?.logoPath || "assets/images/p2k-logo.jpg";
  const MAIN_LOGO_ALT = window.P2K_SITE_CONFIG?.logoAlt || "Promote to King club logo";
  const MAIN_CLUB_SLUG = window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king";
  const MAIN_CLUB_URL = window.P2K_SITE_CONFIG?.clubUrl || `https://www.chess.com/club/${encodeURIComponent(MAIN_CLUB_SLUG)}`;

  function applyBranding() {
    const title = document.querySelector(".site-header h1");
    const subtitle = document.querySelector(".site-subtitle");
    const logoLink = document.getElementById("siteClubLink");
    const logo = logoLink?.querySelector("img") || document.querySelector(".site-header > img");
    const navigation = document.querySelector(".site-tabs");
    const description = document.querySelector('meta[name="description"]');
    const favicon = document.querySelector('link[rel~="icon"]');

    if (title) title.textContent = MAIN_TITLE;
    if (subtitle) subtitle.textContent = MAIN_SUBTITLE;
    if (logo) {
      logo.src = MAIN_LOGO;
      logo.alt = MAIN_LOGO_ALT;
      logo.title = MAIN_TITLE;
    }
    if (logoLink) {
      logoLink.href = MAIN_CLUB_URL;
      logoLink.title = `Open ${MAIN_TITLE} on Chess.com`;
      logoLink.setAttribute("aria-label", `Open ${MAIN_TITLE} on Chess.com`);
    }
    if (navigation) navigation.setAttribute("aria-label", `${MAIN_TITLE} tools`);
    if (description) description.setAttribute("content", MAIN_SUBTITLE);
    if (favicon) favicon.setAttribute("href", MAIN_LOGO);
    document.title = MAIN_TITLE;
  }

  applyBranding();

  function urlFlagEnabled(name) {
    const parameters = new URLSearchParams(window.location.search);
    if (!parameters.has(name)) return false;
    const value = parameters.get(name);
    return value === "" || ["1", "true", "yes", "on", "enabled"].includes(String(value).toLowerCase());
  }

  function oauthMode() {
    const parameters = new URLSearchParams(window.location.search);
    if (parameters.get("oauth") === "1") return 1;
    if (parameters.has("simulatedOAuth") && urlFlagEnabled("simulatedOAuth")) return 1;
    return 2;
  }
  function displayName() {
    const value = String(new URLSearchParams(window.location.search).get("name") || "").trim();
    return /^[A-Za-z0-9_-]{1,80}$/.test(value) ? value : "";
  }
  function oauthEnabled() { return oauthMode() > 0; }

  function adminEntryUsername(entry) {
    if (entry && typeof entry === "object") {
      return adminEntryUsername(entry.username || entry.name || entry.url || entry["@id"] || "");
    }
    const value = String(entry || "").trim();
    if (!value) return "";
    try {
      const url = new URL(value, window.location.href);
      const parts = url.pathname.split("/").filter(Boolean);
      const playerIndex = parts.findIndex(part => ["player", "member"].includes(part.toLowerCase()));
      return decodeURIComponent(playerIndex >= 0 ? parts[playerIndex + 1] || "" : parts.at(-1) || "").toLowerCase();
    } catch (_) {
      return value.replace(/^@/, "").toLowerCase();
    }
  }

  function configuredAdminUsernames() {
    const values = window.P2K_SITE_CONFIG?.auth?.adminUsernames;
    return new Set((Array.isArray(values) ? values : []).map(adminEntryUsername).filter(Boolean));
  }

  function clubAdminUsernames(profile) {
    const values = [profile?.admin, profile?.admins, profile?.super_admin, profile?.super_admins];
    const flattened = [];
    const collect = value => {
      if (Array.isArray(value)) return value.forEach(collect);
      if (value && typeof value === "object") {
        const direct = value.username || value.name || value.url || value["@id"];
        if (direct) flattened.push(direct);
        Object.values(value).forEach(nested => { if (nested !== direct) collect(nested); });
        return;
      }
      if (value) flattened.push(value);
    };
    values.forEach(collect);
    return new Set(flattened.map(adminEntryUsername).filter(Boolean));
  }

  function oauthSessionClaimsAdmin(session) {
    if (!session || typeof session !== "object") return false;
    if (session.isAdmin === true || session.admin === true || session.superAdmin === true || session.super_admin === true) return true;
    const roles = [session.roles, session.permissions, session.authorization?.roles, session.authorization?.permissions]
      .flatMap(value => Array.isArray(value) ? value : value ? [value] : [])
      .map(value => String(value || "").trim().toLowerCase());
    return roles.some(value => ["admin", "administrator", "super_admin", "super-admin", "superadmin"].includes(value));
  }

  function validLocalAdminMarker(username) {
    try {
      const marker = JSON.parse(sessionStorage.getItem(`club-tools-admin:${MAIN_CLUB_SLUG}`) || "null");
      return Boolean(marker && marker.username === username && Number(marker.expiresAt) > Date.now());
    } catch (_) {
      return false;
    }
  }

  async function authenticatedClubAdmin() {
    const ready = window.P2K_REAL_OAUTH_READY;
    if (ready && typeof ready.then === "function") {
      try { await ready; } catch (_) { /* Fall through to the currently available provider. */ }
    }
    if (!oauthEnabled() || window.P2K_AUTH?.enabled !== true) return false;
    const session = window.P2K_AUTH?.getDisplaySession?.() || window.P2K_AUTH?.getSession?.();
    const username = adminEntryUsername(session?.username);
    if (oauthMode() !== 1 && session?.displayOnly !== true && window.P2K_TEAM_POINTS_CLIENT?.connect) {
      try {
        const secured = await window.P2K_TEAM_POINTS_CLIENT.connect();
        if (adminEntryUsername(secured?.username)) return true;
      } catch (error) {
        if (["ADMIN_AUTH_FAILED", "OAUTH_SESSION_REQUIRED"].includes(String(error?.code || ""))) return false;
      }
    }
    if (!username) return false;
    if (session.displayOnly !== true && oauthSessionClaimsAdmin(session)) return true;
    // A configured/local administrator is already sufficiently authorized; do not
    // put an external Chess.com request in front of the site router. Unknown users
    // can still be verified against the public club admin list below.
    if (configuredAdminUsernames().has(username) || (session.displayOnly !== true && validLocalAdminMarker(username))) return true;
    try {
      const clubApiUrl = `https://api.chess.com/pub/club/${encodeURIComponent(MAIN_CLUB_SLUG)}`;
      let profile;
      if (window.P2K_API_CLIENT?.json) {
        profile = await window.P2K_API_CLIENT.json(clubApiUrl, { forceNetwork: true });
      } else {
        const response = await fetch(clubApiUrl, { cache: "no-store" });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        profile = await response.json();
      }
      const hasAdminFields = [profile?.admin, profile?.admins, profile?.super_admin, profile?.super_admins]
        .some(value => value !== undefined && value !== null);
      if (hasAdminFields) return clubAdminUsernames(profile).has(username);
    } catch (_) {
      /* API outage cannot authorize an otherwise unknown user. */
    }
    return false;
  }

  async function initializeRouter() {
    const verifiedClubAdmin = await authenticatedClubAdmin();
    const adminEnabled = verifiedClubAdmin;
    window.P2K_ADMIN_MODE = adminEnabled;
    try {
      const markerKey = `club-tools-admin:${MAIN_CLUB_SLUG}`;
      const username = adminEntryUsername((window.P2K_AUTH?.getDisplaySession?.() || window.P2K_AUTH?.getSession?.())?.username);
      if (verifiedClubAdmin && username) sessionStorage.setItem(markerKey, JSON.stringify({ username, expiresAt: Date.now() + 30 * 60 * 1000 }));
      else sessionStorage.removeItem(markerKey);
    } catch (_) { /* Storage can be unavailable. */ }
    document.querySelectorAll("[data-admin-only]").forEach(element => {
      element.hidden = !adminEnabled;
    });
    window.dispatchEvent(new CustomEvent("club-admin-access-ready", {
      detail: { enabled: adminEnabled, source: adminEnabled ? "display-identity-admin" : "public" }
    }));

  const tabs = Array.from(document.querySelectorAll(".site-tab[data-key]")).filter(tab => !tab.hidden);
  const visibleKeys = new Set(tabs.map(tab => tab.dataset.key));
  const content = document.getElementById("tool-content");
  const loading = document.getElementById("tool-loading");
  const frames = new Map();
  const observers = new Map();
  let activeKey = "find";

  window.P2K_TOOL_FRAMES = frames;


  function pageURL(key) {
    const url = new URL(ROUTES[key] || ROUTES.find, window.location.href);
    url.searchParams.set("v", window.P2K_SITE_CONFIG?.version || "2.8.11");
    url.searchParams.set("embedded", "1");
    url.searchParams.set("active", "1");
    const mode = oauthMode();
    if (mode === 1) url.searchParams.set("oauth", "1"); else url.searchParams.delete("oauth");
    const viewed = displayName(); if (viewed) url.searchParams.set("name", viewed); else url.searchParams.delete("name");
    if (key === "recruit") {
      url.searchParams.set("team", MAIN_CLUB_SLUG || MAIN_CLUB_URL);
      url.searchParams.set("lockTeam", "1");
    }
    return url;
  }

  function selectedKey() {
    const key = window.location.hash.replace(/^#/, "").trim();
    return visibleKeys.has(key) ? key : "find";
  }

  function showLocalInstructions() {
    content.innerHTML = `
      <div class="tool-local-help">
        <strong>Local tabs need a web server</strong>
        <p>Browsers cannot load the packaged pages into the tab interface from a file:// address.</p>
        <p>Open a terminal in the extracted package and run:</p>
        <code>py -m http.server 8000</code>
        <p>Then open http://localhost:8000/ in your browser.</p>
      </div>`;
  }

  function embeddedStyle(key) {
    const headerRule = key === "find"
      ? ".finder > .header"
      : key === "upcoming"
        ? "#p2kUpcomingAnalyzer > .p2k-header"
        : key === "creation"
          ? "#p2kCreationAnalyzer > .p2k-header"
          : key === "teamPoints"
            ? "#p2kTeamPoints > .p2k-tp-header"
            : ["open", "recruit", "challenges"].includes(key)
              ? "#p2kUpcomingAnalyzer > .p2k-header h1, #p2kUpcomingAnalyzer > .p2k-header h2, #p2kDirectClubLogo, #p2kUpcomingAnalyzer > .p2k-header img[src*=\"p2k-logo\"], #p2kUpcomingAnalyzer > .p2k-header img[alt*=\"Promote to King\"]"
              : "";
    return `
      html, body { min-height:0 !important; margin:0 !important; padding:0 !important; overflow:hidden !important; background:transparent !important; }
      .finder, #p2kCreationAnalyzer, #p2kUpcomingAnalyzer, .p2k-analyzer, .p2k-tp {
        width:100% !important; max-width:none !important; min-height:0 !important; margin:0 !important; padding:0 !important;
        border:0 !important; border-radius:0 !important; background:transparent !important; background-image:none !important; box-shadow:none !important;
      }
      ${headerRule ? `${headerRule} { display:none !important; }` : ""}
      ${key === "open" ? "#p2kUpcomingAnalyzer > .p2k-header { min-height:0 !important; justify-content:flex-end !important; margin:0 0 10px !important; }" : ""}
    `;
  }

  function updateHeight(key) {
    const frame = frames.get(key);
    if (!frame) return;
    try {
      const doc = frame.contentDocument;
      if (!doc) return;
      const height = Math.max(
        180,
        doc.documentElement?.scrollHeight || 0,
        doc.body?.scrollHeight || 0
      );
      frame.style.height = `${height}px`;
    } catch (_) { /* same-origin page may still be loading */ }
  }

  function prepareFrame(key, frame) {
    try {
      const doc = frame.contentDocument;
      if (!doc) return;
      let style = doc.getElementById("p2k-index-embedded-style");
      if (!style) {
        style = doc.createElement("style");
        style.id = "p2k-index-embedded-style";
        style.textContent = embeddedStyle(key);
        (doc.head || doc.documentElement).appendChild(style);
      }
      observers.get(key)?.disconnect();
      if ("ResizeObserver" in frame.contentWindow) {
        const observer = new frame.contentWindow.ResizeObserver(() => updateHeight(key));
        observer.observe(doc.body);
        observers.set(key, observer);
      }
      updateHeight(key);
      return true;
    } catch (error) {
      console.warn(`Unable to prepare embedded tool ${key}`, error);
      return false;
    }
  }

  function notifyFrame(key, active) {
    const frame = frames.get(key);
    try { frame?.contentWindow?.postMessage({ type: "p2k-tool-activity", active: Boolean(active) }, window.location.origin); } catch (_) {}
  }

  function ensureFrame(key) {
    if (frames.has(key)) return frames.get(key);
    const frame = document.createElement("iframe");
    frame.className = "tool-seamless-runtime";
    frame.dataset.toolKey = key;
    frame.title = tabs.find(tab => tab.dataset.key === key)?.textContent.trim() || key;
    frame.hidden = true;
    frame.setAttribute("scrolling", "no");
    frame.addEventListener("load", () => {
      if (!prepareFrame(key, frame)) return;
      frame.dataset.p2kReady = "true";
      notifyFrame(key, activeKey === key);
      if (activeKey === key) {
        frame.hidden = false;
        loading.hidden = true;
      }
    });
    frame.addEventListener("error", () => {
      if (activeKey === key) {
        frame.hidden = true;
        loading.hidden = false;
        loading.classList.add("p2k-error");
        loading.textContent = `Unable to load ${ROUTES[key]}.`;
      }
    });
    frame.src = pageURL(key).href;
    frames.set(key, frame);
    content.appendChild(frame);
    return frame;
  }

  function select(key, { updateHash = true } = {}) {
    if (!visibleKeys.has(key)) key = "find";
    const previousKey = activeKey;
    if (previousKey && previousKey !== key) notifyFrame(previousKey, false);
    activeKey = key;
    tabs.forEach(tab => {
      const active = tab.dataset.key === key;
      tab.setAttribute("aria-selected", String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    content.setAttribute("aria-labelledby", `tab-${key}`);
    loading.hidden = false;
    loading.classList.remove("p2k-error");
    loading.textContent = `Loading ${tabs.find(tab => tab.dataset.key === key)?.textContent.trim() || "tool"}…`;
    for (const [frameKey, frame] of frames) frame.hidden = frameKey !== key || frame.dataset.p2kReady !== "true";
    const frame = ensureFrame(key);
    notifyFrame(key, true);
    if (frame.dataset.p2kReady === "true") {
      prepareFrame(key, frame);
      frame.hidden = false;
      loading.hidden = true;
    } else {
      frame.hidden = true;
    }
    if (updateHash) history.replaceState(null, "", `#${key}`);
    document.title = MAIN_TITLE;
  }

  function openStandalone(tab) {
    const key = tab.dataset.key;
    const url = new URL(ROUTES[key] || tab.dataset.route, window.location.href);
    url.searchParams.delete("v");
    url.searchParams.delete("embedded");
    const mode = oauthMode();
    if (mode === 1) url.searchParams.set("oauth", "1"); else url.searchParams.delete("oauth");
    const viewed = displayName(); if (viewed) url.searchParams.set("name", viewed); else url.searchParams.delete("name");
    const popup = window.open(url.href, "_blank", "noopener");
    if (popup) popup.opener = null;
  }

  tabs.forEach((tab, index) => {
    const key = tab.dataset.key;
    if (ROUTES[key] !== tab.dataset.route) console.warn(`Route mismatch for ${key}; configuration route is used.`);
    tab.addEventListener("click", event => {
      if (event.ctrlKey || event.metaKey) { event.preventDefault(); openStandalone(tab); return; }
      select(key);
    });
    tab.addEventListener("auxclick", event => {
      if (event.button !== 1) return;
      event.preventDefault();
      openStandalone(tab);
    });
    tab.addEventListener("keydown", event => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      let targetIndex = index;
      if (event.key === "Home") targetIndex = 0;
      else if (event.key === "End") targetIndex = tabs.length - 1;
      else targetIndex = (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
      tabs[targetIndex].focus();
      select(tabs[targetIndex].dataset.key);
    });
  });

  window.addEventListener("hashchange", () => select(selectedKey(), { updateHash: false }));
  window.addEventListener("message", event => {
    if (event.origin !== window.location.origin || event.data?.type !== "p2k-match-finder-height") return;
    const frame = frames.get("find");
    if (frame) frame.style.height = `${Math.max(180, Number(event.data.height) || 0)}px`;
  });
  window.addEventListener("pagehide", () => observers.forEach(observer => observer.disconnect()), { once: true });

  if (window.location.protocol === "file:") {
    showLocalInstructions();
    return;
  }

  select(selectedKey(), { updateHash: false });

  {
    window.P2K_AUTH?.subscribe?.(() => window.location.reload());
  }
  }

  initializeRouter().catch(error => {
    console.error("Unable to initialize the site router.", error);
    window.P2K_ADMIN_MODE = false;
  });
})();
