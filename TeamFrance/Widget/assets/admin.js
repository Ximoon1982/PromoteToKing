(() => {
  "use strict";

  const OAUTH = new URL("../../../server/team-points/public/oauth.php", location.href).href;
  const API = new URL("../api.php", location.href).href;
  const CFG = window.TEAM_FRANCE_WIDGET_CONFIG || {};

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


  const arenaList = document.getElementById("arenaList");
  const addArenaButton = document.getElementById("addArenaButton");
  const arenaModal = document.getElementById("arenaModal");
  const arenaForm = document.getElementById("arenaForm");
  const arenaModalTitle = document.getElementById("arenaModalTitle");
  const arenaModalError = document.getElementById("arenaModalError");
  const arenaModalSave = document.getElementById("arenaModalSave");
  let arenas = [];
  let persistedItems = [];
  let layout = {arenaRows: 1, dailyRows: 2};
  const arenaRowsControl = document.getElementById("arenaRows");
  const dailyRowsControl = document.getElementById("dailyRows");
  const toLayout = raw => ({arenaRows:Math.max(1,Math.min(2,Number(raw?.arenaRows)||1)),dailyRows:Math.max(1,Math.min(3,Number(raw?.dailyRows)||2))});
  let arenaSaving = false;
  let dailySaving = false;
  let editingArenaIndex = null;

  const arenaFields = ["name", "link", "date", "time", "duration", "timeControl"];
  const toArena = a => ({
    active: a.active === true, urgent: a.urgent === true,
    name: String(a.name || ""), link: String(a.link || ""),
    date: String(a.date || ""), time: String(a.time || ""),
    duration: String(a.duration || ""), timeControl: String(a.timeControl || ""),
    endAt: String(a.endAt || ""),
  });
  const compactItems = rows => rows.map(({matchId, enabled, urgent}) => ({matchId:String(matchId), enabled:enabled === true, urgent:urgent === true}));
  const arenaUrlKey = text => {
    try {
      const url = new URL(text);
      return `${url.hostname.toLowerCase().replace(/^www\./, "")}${url.pathname.replace(/\/+$/, "").toLowerCase()}${url.search}`;
    } catch (_) { return String(text || "").trim().toLowerCase(); }
  };

  function formatArenaDate(date) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return date || "Date unavailable";
    const value = new Date(`${date}T12:00:00Z`);
    return new Intl.DateTimeFormat("en-GB", {day:"numeric", month:"short", year:"numeric", timeZone:"UTC"}).format(value);
  }
  function renderArenas() {
    arenaList.innerHTML = arenas.map((a, index) => `<div class="arena-row${a.active ? "" : " is-disabled"}" data-arena-index="${index}">
      <div class="arena-summary">
        <strong><a href="${esc(a.link)}" target="_blank" rel="noopener noreferrer">${esc(a.name || "Unnamed arena")}</a></strong>
        <span>${esc(formatArenaDate(a.date))} · ${esc(a.time || "—")} · ${esc(a.timeControl || "—")} · ${esc(a.duration || "—")}</span>
      </div>
      <div class="arena-row-actions">
        <label><input type="checkbox" data-arena-action="enabled" ${a.active ? "checked" : ""} ${(arenaSaving || dailySaving) ? "disabled" : ""}> Enabled</label><label><input type="checkbox" data-arena-action="priority" ${a.urgent ? "checked" : ""} ${(arenaSaving || dailySaving) ? "disabled" : ""}> Priority</label>
        <button type="button" data-arena-action="edit" class="secondary" ${(arenaSaving || dailySaving) ? "disabled" : ""}>Edit</button>
        <button type="button" data-arena-action="remove" class="remove-arena" ${(arenaSaving || dailySaving) ? "disabled" : ""}>Remove</button>
      </div>
    </div>`).join("") || '<div class="registration-empty">No arena configured.</div>';
    addArenaButton.disabled = arenaSaving || dailySaving;
  }
  function closeArenaModal() {
    if (arenaModal.open) arenaModal.close();
    editingArenaIndex = null;
    arenaModalError.textContent = "";
  }
  function openArenaModal(index = null) {
    if (arenaSaving || dailySaving) return;
    editingArenaIndex = Number.isInteger(index) && index >= 0 && index < arenas.length ? index : null;
    const a = editingArenaIndex === null ? toArena({}) : arenas[editingArenaIndex];
    arenaModalTitle.textContent = editingArenaIndex === null ? "Add arena" : "Edit arena";
    arenaFields.forEach(field => {
      const input = arenaForm.elements.namedItem(field);
      if (input) input.value = a[field];
    });
    arenaModalError.textContent = "";
    arenaModalSave.disabled = false;
    if (typeof arenaModal.showModal === "function") arenaModal.showModal();
  }
  function readArenaDraft() {
    const value = {active: editingArenaIndex === null ? false : arenas[editingArenaIndex].active};
    arenaFields.forEach(field => {value[field] = String(arenaForm.elements.namedItem(field)?.value || "").trim();});
    return value;
  }
  function validateArena(a) {
    if (!a.name || a.name.length > 120) return "Provide an arena name (maximum 120 characters).";
    let url;
    try {url = new URL(a.link);} catch (_) {return "Provide a valid Chess.com arena URL.";}
    if (url.protocol !== "https:" || !["chess.com", "www.chess.com"].includes(url.hostname.toLowerCase()) ||
      !/^\/(?:play\/)?(?:tournament|arena|live)(?:\/|$)/i.test(url.pathname) || url.username || url.password || url.port) {
      return "Use an HTTPS Chess.com arena URL.";
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(a.date) || !/^\d{2}:\d{2}$/.test(a.time)) return "Provide an arena date and start time.";
    if (!/^\d+\s*(?:m|min|mins|minutes?|h|hr|hours?)?(?:\s*\d{1,2}\s*m?)?$|^\d+:[0-5]\d$/i.test(a.duration) || !a.duration) return "Duration must be 90m, 2h or H:MM.";
    if (a.timeControl.length > 40) return "Time control is too long.";
    const key = arenaUrlKey(a.link);
    if (arenas.some((item, index) => index !== editingArenaIndex && arenaUrlKey(item.link) === key)) return "This Chess.com arena is already configured.";
    return "";
  }
  async function saveArenaConfiguration(next, successText) {
    if (arenaSaving || dailySaving) return false;
    arenaSaving = true;
    renderArenas();
    arenaModalSave.disabled = true;
    try {
      const payload = await json(`${API}?action=save`, {
        method: "POST", headers: { ["X-" + (CFG.teamSlug === "team-france" ? "TF" : "TGP") + "-CSRF"]: csrf },
        body: JSON.stringify({revision, items: persistedItems, arenas: next.map(toArena), layout}),
      });
      revision = Number(payload.revision);
      if (!Number.isInteger(revision) || revision < 0) throw new Error("Invalid revision returned by server.");
      arenas = (payload.arenas || []).map(toArena);
      persistedItems = compactItems(payload.items || persistedItems);
      updateEmbedFields();
      preview.src = `../?_=${Date.now()}`;
      setMessage(successText, "success");
      // Never discard unsaved Daily-match edits during an independent arena save.
      return true;
    } catch (error) {
      const explanation = error.status === 409
        ? "The configuration changed in another session. Reload the page before saving. Unsaved Daily-match edits were not discarded."
        : error.message;
      setMessage(explanation, "error");
      arenaModalError.textContent = explanation;
      return false;
    } finally {
      arenaSaving = false;
      arenaModalSave.disabled = false;
      renderArenas();
      saveButton.disabled = !dirty;
    }
  }
  addArenaButton.addEventListener("click", () => {
    if (arenas.length >= 100) {setMessage("Maximum 100 configured arenas.", "error"); return;}
    openArenaModal();
  });
  arenaForm.addEventListener("submit", async event => {
    event.preventDefault();
    if (arenaSaving) return;
    const draft = readArenaDraft();
    const validationError = validateArena(draft);
    if (validationError) {arenaModalError.textContent = validationError; return;}
    const next = arenas.map(toArena);
    if (editingArenaIndex === null) next.push(draft);
    else next[editingArenaIndex] = draft;
    if (await saveArenaConfiguration(next, "Arena saved.")) closeArenaModal();
  });
  document.getElementById("arenaModalCancel").addEventListener("click", closeArenaModal);
  arenaModal.addEventListener("close", () => {editingArenaIndex = null; arenaModalError.textContent = "";});
  arenaList.addEventListener("click", async event => {
    const button = event.target.closest("button[data-arena-action]");
    const row = button?.closest("[data-arena-index]");
    const index = Number(row?.dataset.arenaIndex);
    if (arenaSaving || dailySaving || !button || !Number.isInteger(index) || !arenas[index]) return;
    if (button.dataset.arenaAction === "edit") {openArenaModal(index); return;}
    if (button.dataset.arenaAction !== "remove") return;
    if (!window.confirm(`Remove arena “${arenas[index].name}”?`)) return;
    const next = arenas.map(toArena);
    next.splice(index, 1);
    await saveArenaConfiguration(next, "Arena removed.");
  });
  arenaList.addEventListener("change", async event => {
    const input = event.target;
    if (arenaSaving || dailySaving || !["enabled","priority"].includes(input.dataset.arenaAction)) return;
    const index = Number(input.closest("[data-arena-index]")?.dataset.arenaIndex);
    if (!Number.isInteger(index) || !arenas[index]) return;
    const next = arenas.map(toArena);
    if (input.dataset.arenaAction === "priority") next[index].urgent = input.checked;
    else next[index].active = input.checked;
    await saveArenaConfiguration(next, input.dataset.arenaAction === "priority" ? "Arena priority updated." : (input.checked ? "Arena enabled." : "Arena disabled."));
  });

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
    // The configured row counts are maxima; don't leave blank space for empty rows.
    const today = Date.now();
    const activeArenas = arenas.filter(a => a.active && a.name && a.link && (!a.endAt || Date.parse(a.endAt) > today)).length;
    const arenaLines = Math.ceil(Math.min(activeArenas, layout.arenaRows * 2) / 2);
    const dailyLines = Math.ceil(Math.min(persistedItems.filter(i => i.enabled).length, layout.dailyRows * 2) / 2);
    // Card content is ~130-140px per row at 260px, with section titles and spacing.
    const height = 24 + 132 * (arenaLines + dailyLines) + (arenaLines > 0 ? 17 : 0);
    preview.style.height = `${height}px`;
    iframeHtml.value = `<iframe src="${url}" width="260" height="${height}" style="border:0;overflow:hidden" scrolling="no" loading="lazy" title="${title.replace(/\"/g, "&quot;")}"></iframe>`;
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
    arenas = (payload.arenas || []).map(toArena);
    persistedItems = compactItems(payload.items || []);
    layout = toLayout(payload.layout);
    arenaRowsControl.value = String(layout.arenaRows);
    dailyRowsControl.value = String(layout.dailyRows);
    renderArenas();
    updateEmbedFields();
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
    return rank <= layout.dailyRows * 2 ? String(rank) : "—";
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
        headers: { "X-TF-CSRF": csrf },
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
    if (arenaSaving || dailySaving) return;
    dailySaving = true;
    renderArenas();
    saveButton.disabled = true;
    setMessage("Saving…");
    try {
      const payload = await json(`${API}?action=save`, {
        method: "POST",
        headers: { "X-TF-CSRF": csrf },
        body: JSON.stringify({
          revision,
          layout,
          items: items.map(({ matchId, enabled, urgent }) => ({ matchId, enabled, urgent })),
          arenas: arenas.map(toArena),
        }),
      });
      revision = Number(payload.revision) || revision + 1;
      persistedItems = compactItems(payload.items || items);
      setDirty(false);
      setMessage("Configuration saved.", "success");
      preview.src = `../?_=${Date.now()}`;
      await loadAdminState();
      await loadRegistration(false);
    } catch (error) {
      if (error.status === 409) {
        setMessage("Configuration changed elsewhere. Reload the page before retrying; unsaved edits remain on this page.", "error");
      } else {
        setMessage(error.message, "error");
      }
    } finally {
      dailySaving = false;
      renderArenas();
      saveButton.disabled = !dirty;
    }
  }

  async function saveLayoutConfiguration() {
    if (arenaSaving || dailySaving) return;
    const next = toLayout({arenaRows:arenaRowsControl.value, dailyRows:dailyRowsControl.value});
    const previous = {...layout};
    if (next.arenaRows === previous.arenaRows && next.dailyRows === previous.dailyRows) return;
    arenaSaving = true;
    renderArenas();
    arenaRowsControl.disabled = true;
    dailyRowsControl.disabled = true;
    try {
      const payload = await json(`${API}?action=save`, {
        method:"POST",headers:{["X-"+(CFG.teamSlug === "team-france" ? "TF" : "TGP")+"-CSRF"]:csrf},
        body:JSON.stringify({revision, items:persistedItems, arenas:arenas.map(toArena), layout:next}),
      });
      revision = Number(payload.revision);
      layout = toLayout(payload.layout);
      arenaRowsControl.value = String(layout.arenaRows);
      dailyRowsControl.value = String(layout.dailyRows);
      updateEmbedFields();
      preview.src = `../?_=${Date.now()}`;
      setMessage("Card rows saved. Copy the updated iframe HTML if the widget is embedded externally.","success");
    } catch (error) {
      arenaRowsControl.value = String(previous.arenaRows);
      dailyRowsControl.value = String(previous.dailyRows);
      setMessage(error.status === 409 ? "Configuration changed in another session. Reload before retrying; unsaved Daily edits remain." : error.message,"error");
    } finally {
      arenaSaving = false;
      arenaRowsControl.disabled = false;
      dailyRowsControl.disabled = false;
      renderArenas();
    }
  }
  arenaRowsControl.addEventListener("change", () => void saveLayoutConfiguration());
  dailyRowsControl.addEventListener("change", () => void saveLayoutConfiguration());

  async function logout() {
    try {
      await json(`${API}?action=logout`, {
        method: "POST",
        headers: { "X-TF-CSRF": csrf },
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
