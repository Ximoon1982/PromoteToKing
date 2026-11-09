(() => {
  "use strict";

  const OAUTH = new URL("../../../server/team-points/public/oauth.php", location.href).href;
  const API = new URL("../api.php", location.href).href;
  const CFG = window.TGP_WIDGET_CONFIG || {};

  const authStatus = document.getElementById("authStatus");
  const loginButton = document.getElementById("loginButton");
  const logoutButton = document.getElementById("logoutButton");
  const embedPanel = document.getElementById("embedPanel");
  const displayUrl = document.getElementById("displayUrl");
  const iframeHtml = document.getElementById("iframeHtml");
  const copyStatus = document.getElementById("copyStatus");
  const editor = document.getElementById("editor");
  const input = document.getElementById("matchReference");
  const addButton = document.getElementById("addButton");
  const saveButton = document.getElementById("saveButton");
  const list = document.getElementById("matchList");
  const message = document.getElementById("message");
  const dirtyState = document.getElementById("dirtyState");
  const preview = document.getElementById("preview");
  const registrationList = document.getElementById("registrationList");
  const registrationStatus = document.getElementById("registrationStatus");
  const refreshRegistration = document.getElementById("refreshRegistration");

  let csrf = "";
  let revision = 0;
  let items = [];
  let registrationMatches = [];
  let dirty = false;

  const esc = value => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#039;");

  function setMessage(text, type = "") {
    message.textContent = text || "";
    message.className = `message${type ? ` ${type}` : ""}`;
  }

  function setDirty(value) {
    dirty = Boolean(value);
    dirtyState.textContent = dirty ? "Unsaved changes" : "Saved";
    saveButton.disabled = !dirty;
  }

  async function json(url, options = {}) {
    const response = await fetch(url, {
      credentials: "same-origin",
      cache: "no-store",
      ...options,
      headers: {
        Accept: "application/json",
        ...(options.body ? { "Content-Type": "application/json" } : {}),
        ...(options.headers || {}),
      },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false) {
      const error = new Error(payload?.error?.message || `HTTP ${response.status}`);
      error.status = response.status;
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function returnPath() {
    const url = new URL(location.href);
    url.searchParams.delete("oauth_result");
    return `${url.pathname}${url.search}`;
  }

  function startLogin() {
    const url = new URL(OAUTH);
    url.searchParams.set("action", "login");
    url.searchParams.set("return", returnPath());
    location.assign(url.href);
  }

  async function oauthSession() {
    return json(`${OAUTH}?action=session&_=${Date.now()}`);
  }

  async function establishAdmin(bootstrap) {
    const payload = await json(`${API}?action=admin-session`, {
      method: "POST",
      body: JSON.stringify({ bootstrap_assertion: bootstrap }),
    });
    csrf = payload.session?.csrf || "";
    return payload;
  }

  function showAdmin() {
    editor.hidden = false;
    embedPanel.hidden = false;
    logoutButton.hidden = false;
  }

  function publicWidgetUrl() {
    const url = new URL("../", location.href);
    url.search = "";
    url.hash = "";
    return url.href;
  }

  function updateEmbedFields() {
    const url = publicWidgetUrl();
    const title = `${String(CFG.teamName || "Team")} match showcase`;
    displayUrl.value = url;
    iframeHtml.value = `<iframe src="${url}" width="260" height="260" style="border:0;overflow:hidden" scrolling="no" loading="lazy" title="${title.replace(/\"/g, "&quot;")}"></iframe>`;
  }

  async function copyField(targetId) {
    const field = document.getElementById(targetId);
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return;
    const value = field.value;
    try {
      if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(value);
      else {
        field.focus();
        field.select();
        if (!document.execCommand("copy")) throw new Error("Copy failed");
      }
      copyStatus.textContent = targetId === "displayUrl" ? "Card URL copied." : "Iframe HTML copied.";
    } catch (_) {
      field.focus();
      field.select();
      copyStatus.textContent = "Select and copy the highlighted text.";
    }
  }

  async function loadAdminState() {
    const payload = await json(`${API}?action=admin-state&_=${Date.now()}`);
    revision = Number(payload.revision) || 0;
    items = (payload.items || []).map(row => ({
      matchId: String(row.matchId),
      enabled: row.enabled !== false,
      urgent: row.urgent === true,
      resolved: row.resolved !== false,
      match: row.match || {},
      error: row.error || "",
    }));
    setDirty(false);
    render();
    renderRegistration();
  }

  function publicRank(index) {
    if (!items[index]?.enabled) return "—";
    const rank = items.filter((item, i) => i <= index && item.enabled).length;
    return rank <= 4 ? String(rank) : "—";
  }

  function render() {
    list.innerHTML = items.map((item, index) => {
      const match = item.match || {};
      const title = match.name || `Match #${item.matchId}`;
      const opponent = match.opponentName || item.error || "Unresolved";
      const rank = publicRank(index);
      const shown = rank !== "—";
      return `<div class="match-row" data-index="${index}">
        <div class="match-rank">${rank}</div>
        <div class="match-title"><strong>${esc(title)}</strong><span>#${esc(item.matchId)} · ${esc(opponent)}${shown ? " · PUBLIC" : ""}</span></div>
        <div class="match-controls">
          <button type="button" data-action="up" ${index === 0 ? "disabled" : ""}>↑</button>
          <button type="button" data-action="down" ${index === items.length - 1 ? "disabled" : ""}>↓</button>
          <label><input type="checkbox" data-action="enabled" ${item.enabled ? "checked" : ""}> Enabled</label>
          <label><input type="checkbox" data-action="urgent" ${item.urgent ? "checked" : ""}> Priority</label>
          <button type="button" class="remove" data-action="remove">Remove</button>
        </div>
      </div>`;
    }).join("");
  }

  function renderRegistration() {
    const configured = new Set(items.map(item => String(item.matchId)));
    if (!registrationMatches.length) {
      registrationList.innerHTML = '<div class="registration-empty">No Daily matches are currently in registration.</div>';
      return;
    }
    registrationList.innerHTML = registrationMatches.map(match => {
      const id = String(match.matchId || "");
      const added = configured.has(id);
      return `<div class="registration-row" data-registration-id="${esc(id)}">
        <div class="registration-copy"><strong>${esc(match.name || `Match #${id}`)}</strong><span>#${esc(id)} · ${esc(match.opponentName || match.opponentSlug || "Opponent")}</span></div>
        <button type="button" data-action="add-registration" ${added ? "disabled" : ""}>${added ? "Added" : "Add"}</button>
      </div>`;
    }).join("");
  }

  async function loadRegistration(force = false) {
    refreshRegistration.disabled = true;
    registrationStatus.textContent = force ? "Refreshing registration matches…" : "Loading registration matches…";
    try {
      const payload = await json(`${API}?action=registration-list${force ? "&refresh=1" : ""}&_=${Date.now()}`);
      registrationMatches = Array.isArray(payload.matches) ? payload.matches : [];
      registrationStatus.textContent = `${registrationMatches.length} Daily match${registrationMatches.length === 1 ? "" : "es"} currently in registration.`;
      renderRegistration();
    } catch (error) {
      registrationMatches = [];
      registrationStatus.textContent = error.message;
      registrationList.innerHTML = "";
    } finally {
      refreshRegistration.disabled = false;
    }
  }

  list.addEventListener("click", event => {
    const button = event.target.closest("button[data-action]");
    if (!button) return;
    const row = button.closest("[data-index]");
    const index = Number(row?.dataset.index);
    if (!Number.isInteger(index) || !items[index]) return;
    const action = button.dataset.action;
    if (action === "up" && index > 0) [items[index - 1], items[index]] = [items[index], items[index - 1]];
    if (action === "down" && index < items.length - 1) [items[index + 1], items[index]] = [items[index], items[index + 1]];
    if (action === "remove") items.splice(index, 1);
    setDirty(true);
    render();
    renderRegistration();
  });

  list.addEventListener("change", event => {
    const element = event.target;
    if (!(element instanceof HTMLInputElement) || !element.dataset.action) return;
    const row = element.closest("[data-index]");
    const index = Number(row?.dataset.index);
    if (!Number.isInteger(index) || !items[index]) return;
    if (element.dataset.action === "enabled") items[index].enabled = element.checked;
    if (element.dataset.action === "urgent") items[index].urgent = element.checked;
    setDirty(true);
    render();
  });

  registrationList.addEventListener("click", event => {
    const button = event.target.closest('button[data-action="add-registration"]');
    if (!button) return;
    const row = button.closest("[data-registration-id]");
    const id = String(row?.dataset.registrationId || "");
    const match = registrationMatches.find(candidate => String(candidate.matchId) === id);
    if (!match || items.some(item => item.matchId === id)) return;
    items.push({
      matchId: id,
      enabled: true,
      urgent: false,
      resolved: true,
      match: {
        matchId: id,
        name: match.name || `Match #${id}`,
        opponentName: match.opponentName || match.opponentSlug || "Opponent",
        opponentSlug: match.opponentSlug || "",
        url: match.url || "",
        apiUrl: match.apiUrl || "",
      },
      error: "",
    });
    setDirty(true);
    render();
    renderRegistration();
    setMessage(`Added ${match.name || `match #${id}`} from the registration list.`, "success");
  });

  async function addMatch() {
    const reference = input.value.trim();
    if (!reference) return;
    addButton.disabled = true;
    setMessage("Resolving match…");
    try {
      const payload = await json(`${API}?action=resolve`, {
        method: "POST",
        headers: { "X-TGP-CSRF": csrf },
        body: JSON.stringify({ reference }),
      });
      const id = String(payload.match?.matchId || "");
      if (items.some(item => item.matchId === id)) {
        setMessage("That match is already configured.", "error");
        return;
      }
      items.push({ matchId: id, enabled: true, urgent: false, resolved: true, match: payload.match, error: "" });
      input.value = "";
      setDirty(true);
      render();
      renderRegistration();
      setMessage(`Added ${payload.match?.name || `match #${id}`}.`, "success");
    } catch (error) {
      setMessage(error.message, "error");
    } finally {
      addButton.disabled = false;
    }
  }

  async function save() {
    saveButton.disabled = true;
    setMessage("Saving…");
    try {
      const payload = await json(`${API}?action=save`, {
        method: "POST",
        headers: { "X-TGP-CSRF": csrf },
        body: JSON.stringify({
          revision,
          items: items.map(({ matchId, enabled, urgent }) => ({ matchId, enabled, urgent })),
        }),
      });
      revision = Number(payload.revision) || revision + 1;
      setDirty(false);
      setMessage("Configuration saved.", "success");
      preview.src = `../?_=${Date.now()}`;
      await loadAdminState();
      await loadRegistration(false);
    } catch (error) {
      if (error.status === 409) {
        setMessage("Configuration changed elsewhere. Reloading the current version.", "error");
        await loadAdminState();
      } else {
        setMessage(error.message, "error");
      }
    } finally {
      saveButton.disabled = !dirty;
    }
  }

  async function logout() {
    try {
      await json(`${API}?action=logout`, {
        method: "POST",
        headers: { "X-TGP-CSRF": csrf },
        body: "{}",
      });
    } catch (_) {}
    csrf = "";
    editor.hidden = true;
    embedPanel.hidden = true;
    logoutButton.hidden = true;
    authStatus.textContent = "Admin session ended.";
    loginButton.hidden = false;
  }

  async function enterAdmin(sessionLabel) {
    authStatus.textContent = `Administrator: ${sessionLabel}`;
    showAdmin();
    updateEmbedFields();
    await loadAdminState();
    await loadRegistration(false);
  }

  async function init() {
    loginButton.addEventListener("click", startLogin);
    logoutButton.addEventListener("click", logout);
    addButton.addEventListener("click", addMatch);
    saveButton.addEventListener("click", save);
    refreshRegistration.addEventListener("click", () => void loadRegistration(true));
    input.addEventListener("keydown", event => {
      if (event.key === "Enter") {
        event.preventDefault();
        void addMatch();
      }
    });
    document.querySelectorAll("[data-copy-target]").forEach(button => {
      button.addEventListener("click", () => void copyField(String(button.dataset.copyTarget || "")));
    });

    updateEmbedFields();

    try {
      const status = await json(`${API}?action=admin-status&_=${Date.now()}`);
      if (status.session?.authenticated) {
        csrf = status.session.csrf || "";
        await enterAdmin(status.session.username);
        return;
      }
    } catch (_) {}

    try {
      const oauth = await oauthSession();
      if (!oauth.authenticated) {
        authStatus.textContent = "Log in with Chess.com to administer this showcase.";
        loginButton.hidden = false;
        return;
      }
      authStatus.textContent = `Checking ${oauth.profile?.username || "Chess.com user"} against ${CFG.teamSlug || "configured club"} admins…`;
      const payload = await establishAdmin(String(oauth.admin_bootstrap || ""));
      await enterAdmin(payload.session?.username || oauth.profile?.username || "Chess.com user");
    } catch (error) {
      authStatus.textContent = error.message;
      loginButton.hidden = false;
    }
  }

  void init();
})();
