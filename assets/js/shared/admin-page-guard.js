/* Client-side access gate for administration-only standalone tools.
 * v2.8.8: OAuth identity + the public Promote to King club admin list is primary.
 * The local allow-list remains an outage fallback, and MariaDB is never consulted for page admission.
 */
(() => {
  "use strict";
  const parameters = new URLSearchParams(window.location.search);
  const truthy = value => value === "" || ["1", "true", "yes", "on", "enabled"].includes(String(value).toLowerCase());
  const flag = name => parameters.has(name) && truthy(parameters.get(name));
  const embedded = parameters.get("embedded") === "1";
  const clubSlug = window.P2K_SITE_CONFIG?.clubSlug || "promote-to-king";
  const storageKey = `club-tools-admin:${clubSlug}`;

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

  async function awaitRealOAuthReady() {
    // Some legacy admin pages load this non-deferred guard before the deferred
    // real-oauth adapter. Wait briefly for that adapter instead of interpreting
    // script load order as a logged-out user.
    const deadline = Date.now() + 1800;
    while (Date.now() < deadline) {
      const ready = window.P2K_REAL_OAUTH_READY;
      if (ready && typeof ready.then === "function") {
        try { await ready; } catch (_) { /* Continue with the available auth provider. */ }
        return;
      }
      if (window.P2K_AUTH) return;
      await new Promise(resolve => window.setTimeout(resolve, 25));
    }
  }

  async function oauthAdminAuthorized() {
    const viewed = String(parameters.get("name") || "").trim();
    const displayOverride = /^[A-Za-z0-9_-]{1,80}$/.test(viewed);
    const simulatedOverride = parameters.get("oauth") === "1" || parameters.get("simulatedOAuth") === "1";

    // v2.10.4.1: for the ordinary real-account view, browser-local auth state is
    // not a prerequisite. Embedded/early-loading pages can ask the secured server
    // bootstrap directly; session.php resolves Ximoon (or any real user) solely from
    // the HttpOnly P2KOAUTH session and verifies current club-admin membership.
    // Never use this shortcut for ?name= because UI privilege projection must follow
    // the viewed identity rather than the real account.
    if (!displayOverride && !simulatedOverride && window.P2K_TEAM_POINTS_CLIENT?.connect) {
      try {
        const secured = await window.P2K_TEAM_POINTS_CLIENT.connect();
        const realUsername = adminEntryUsername(secured?.username);
        if (realUsername) { window.P2K_ADMIN_USERNAME = realUsername; return true; }
      } catch (error) {
        if (["ADMIN_AUTH_FAILED", "OAUTH_SESSION_REQUIRED"].includes(String(error?.code || ""))) return false;
        // Availability failures can still fall through to UI-only checks.
      }
    }

    await awaitRealOAuthReady();
    if (window.P2K_AUTH?.enabled !== true) return false;
    const session = window.P2K_AUTH?.getDisplaySession?.() || window.P2K_AUTH?.getSession?.();
    const username = adminEntryUsername(session?.username);
    if (!username) return false;
    if (session.displayOnly !== true && oauthSessionClaimsAdmin(session)) { window.P2K_ADMIN_USERNAME = username; return true; }

    // v2.8.8.2: do not make an already configured/local administrator wait for
    // the public club-profile endpoint. This is especially important inside the
    // integrated Administration iframes, where a hidden access gate otherwise looks
    // like an indefinitely loading panel.
    const fallbackAllowed = configuredAdminUsernames().has(username) || (session.displayOnly !== true && validMarker(username));
    if (fallbackAllowed) { window.P2K_ADMIN_USERNAME = username; return true; }

    try {
      const endpoint = `https://api.chess.com/pub/club/${encodeURIComponent(clubSlug)}`;
      let profile;
      let apiClient = window.P2K_API_CLIENT;
      try { if (!apiClient && window.parent !== window) apiClient = window.parent.P2K_API_CLIENT; } catch (_) {}
      if (apiClient?.json) profile = await apiClient.json(endpoint, { forceNetwork: true });
      else {
        const response = await fetch(endpoint, { cache: "no-store" });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        profile = await response.json();
      }
      const hasAdminFields = [profile?.admin, profile?.admins, profile?.super_admin, profile?.super_admins]
        .some(value => value !== undefined && value !== null);
      if (hasAdminFields) {
        const allowed = clubAdminUsernames(profile).has(username);
        if (allowed) window.P2K_ADMIN_USERNAME = username;
        return allowed;
      }
    } catch (_) {
      /* API outage cannot authorize an otherwise unknown user. */
    }
    return false;
  }

  function oauthSessionClaimsAdmin(session) {
    if (!session || typeof session !== "object") return false;
    if (session.isAdmin === true || session.admin === true || session.superAdmin === true || session.super_admin === true) return true;
    const roles = [session.roles, session.permissions, session.authorization?.roles, session.authorization?.permissions]
      .flatMap(value => Array.isArray(value) ? value : value ? [value] : [])
      .map(value => String(value || "").trim().toLowerCase());
    return roles.some(value => ["admin", "administrator", "super_admin", "super-admin", "superadmin"].includes(value));
  }

  function validMarker(username) {
    try {
      const marker = JSON.parse(sessionStorage.getItem(storageKey) || "null");
      return Boolean(marker && marker.username === username && Number(marker.expiresAt) > Date.now());
    } catch (_) {
      return false;
    }
  }


  function deny() {
    document.documentElement.classList.remove("admin-access-pending");
    document.body.innerHTML = `<main style="max-width:680px;margin:48px auto;padding:22px;border:1px solid #d89420;border-radius:10px;background:#171513;color:#eee;font-family:Arial,sans-serif"><h1 style="margin:0 0 12px;color:#f4b23e;font-size:24px">Administrator access required</h1><p>This tool is visible only when the currently viewed identity is a Promote to King administrator. Server actions still require the real authenticated administrator session.</p><p>The public Promote to King administrator list is used when available. The local safety allow-list is used if that API is unavailable; MariaDB is never required for page admission.</p><p><a href="index.html" style="color:#ffd078">Return to Match Assistant</a></p></main>`;
    return false;
  }

  async function verifyEmbedded() {
    try {
      if (window.parent !== window && window.parent.P2K_ADMIN_MODE === true) {
        window.P2K_ADMIN_USERNAME = adminEntryUsername((window.parent.P2K_AUTH?.getDisplaySession?.() || window.parent.P2K_AUTH?.getSession?.())?.username);
        return true;
      }
      return await new Promise(resolve => {
        let finished = false;
        let poll = 0;
        let timer = 0;
        const done = allowed => {
          if (finished) return;
          finished = true;
          window.removeEventListener("message", onMessage);
          clearInterval(poll);
          clearTimeout(timer);
          resolve(Boolean(allowed));
        };
        const onMessage = event => {
          if (event.source === window.parent && event.data?.type === "p2k-admin-ready") done(event.data.allowed === true);
        };
        window.addEventListener("message", onMessage);
        // Parent and child scripts can initialize in either order. Poll briefly so a
        // missed CustomEvent/postMessage cannot leave the embedded tool invisible.
        poll = window.setInterval(() => {
          try { if (window.parent?.P2K_ADMIN_MODE === true) done(true); } catch (_) {}
        }, 50);
        timer = window.setTimeout(() => {
          try { done(window.parent?.P2K_ADMIN_MODE === true); } catch (_) { done(false); }
        }, 1200);
        try { window.parent.postMessage({ type: "p2k-admin-status-request" }, "*"); } catch (_) {}
      });
    } catch (_) {
      return false;
    }
  }

  async function verify() {
    await awaitRealOAuthReady();
    if (embedded) return verifyEmbedded();
    return oauthAdminAuthorized();
  }

  document.documentElement.classList.add("admin-access-pending");
  const ready = verify().then(allowed => {
    if (!allowed) return deny();
    document.documentElement.classList.remove("admin-access-pending");
    return true;
  }).catch(() => deny());
  window.P2K_ADMIN_ACCESS_READY = ready;
})();
