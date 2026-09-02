/* Administration, logs, manual tracking progress, and match-data management.
   Built locally on the v2.1.2 self-contained baseline. */
(() => {
  "use strict";
  const byId = id => document.getElementById(id);
  const escapeHTML = value => String(value ?? "").replace(/[&<>"']/g, character => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
  })[character]);
  const writeHeaders = value => ({ "X-Club-Tools-Request": value });
  const adminModal = byId("adminModal");
  const adminOpen = byId("adminOpen");
  if (!adminModal || !adminOpen) return;
  let adminFeaturesInitialized = false;

  function initializeAdminFeatures() {
    if (adminFeaturesInitialized || window.P2K_ADMIN_MODE !== true) return;
    adminFeaturesInitialized = true;
    adminOpen.hidden = false;
    const { fetchJSON, metric, feedback } = window.P2K_ADMIN_FEATURE_MODULES.runtime.create({ byId, escapeHTML });
    const { loadMatchLogs, loadTaskLogs } = window.P2K_ADMIN_FEATURE_MODULES.logs.create({ byId, escapeHTML, fetchJSON, metric, feedback, formatDateTime });
    const { refreshDiagnostics } = window.P2K_ADMIN_FEATURE_MODULES.diagnostics.create({ byId, escapeHTML, fetchJSON, feedback });
    const { openHistory } = window.P2K_ADMIN_FEATURE_MODULES.history_controller.create({ byId, escapeHTML, fetchJSON, feedback, formatDateTime, adminModal, closeAdmin });
    const { loadMatchManagement } = window.P2K_ADMIN_FEATURE_MODULES.match_management.create({ byId, escapeHTML, fetchJSON, feedback, writeHeaders, formatDateTime, formatEpoch, statusLabel, openHistory });
    const { recordMatchData } = window.P2K_ADMIN_FEATURE_MODULES.recording_controller.create({ byId, fetchJSON, writeHeaders, activeAdminTab, activeLogTab, loadTaskLogs, loadMatchManagement });

  function activeAdminTab() {
    return document.querySelector('.admin-tab[aria-selected="true"]')?.dataset.adminTab || "logs";
  }

  function activeLogTab() {
    return document.querySelector('.subtab[aria-selected="true"]')?.dataset.logTab || "match";
  }

  function refreshActiveLogs() {
    return activeLogTab() === "scheduled" ? loadTaskLogs() : loadMatchLogs();
  }

  function openAdmin() {
    adminModal.hidden = false;
    adminModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    refreshActiveLogs();
  }

  function closeAdmin() {
    adminModal.hidden = true;
    adminModal.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }

  adminOpen.addEventListener("click", openAdmin);
  byId("adminClose").addEventListener("click", closeAdmin);
  adminModal.addEventListener("click", event => {
    if (event.target === adminModal) closeAdmin();
  });

  function installTabs(buttonSelector, panelSelector, buttonKey, panelKey) {
    const buttons = Array.from(document.querySelectorAll(buttonSelector));
    const panels = Array.from(document.querySelectorAll(panelSelector));
    const select = (button, focus = false) => {
      const selectedValue = button.dataset[buttonKey];
      buttons.forEach(candidate => {
        const active = candidate === button;
        candidate.setAttribute("aria-selected", String(active));
        candidate.tabIndex = active ? 0 : -1;
      });
      panels.forEach(panel => { panel.hidden = panel.dataset[panelKey] !== selectedValue; });
      if (selectedValue === "logs") refreshActiveLogs();
      if (selectedValue === "match") loadMatchLogs();
      if (selectedValue === "scheduled") loadTaskLogs();
      if (selectedValue === "diagnostics") refreshDiagnostics();
      if (selectedValue === "management") loadMatchManagement();
      if (focus) button.focus();
    };
    buttons.forEach((button, index) => {
      button.addEventListener("click", () => select(button));
      button.addEventListener("keydown", event => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        let target = index;
        if (event.key === "ArrowRight") target = (index + 1) % buttons.length;
        else if (event.key === "ArrowLeft") target = (index - 1 + buttons.length) % buttons.length;
        else if (event.key === "Home") target = 0;
        else if (event.key === "End") target = buttons.length - 1;
        select(buttons[target], true);
      });
    });
  }
  installTabs(".admin-tab", ".admin-panel", "adminTab", "adminPanel");
  window.addEventListener("message", event => {
    if (event.origin !== window.location.origin || event.data?.type !== "p2k-frame-height") return;
    const frame = byId("tournamentManagementFrame");
    if (frame?.contentWindow !== event.source) return;
    frame.style.height = `${Math.max(420, Math.min(12000, Number(event.data.height) || 0))}px`;
  });
  installTabs(".subtab", ".subpanel", "logTab", "logPanel");
  const initialAdminParams = new URLSearchParams(window.location.search);
  const requestedAdminTab = initialAdminParams.get("adminTab");
  const requestedLogTab = initialAdminParams.get("logTab");
  if (requestedAdminTab) document.querySelector(`.admin-tab[data-admin-tab="${CSS.escape(requestedAdminTab)}"]`)?.click();
  if (requestedLogTab) document.querySelector(`.subtab[data-log-tab="${CSS.escape(requestedLogTab)}"]`)?.click();

  function formatDateTime(value) {
    if (!value) return "—";
    try {
      return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
        dateStyle: "medium",
        timeStyle: "short"
      }).format(new Date(value)) + " UTC";
    } catch {
      return String(value);
    }
  }

  function formatEpoch(value) {
    const numeric = Number(value || 0);
    return numeric > 0 ? formatDateTime(new Date(numeric * 1000).toISOString()) : "—";
  }

  function statusLabel(status) {
    return ({ registration: "Registration", ongoing: "Ongoing", finished: "Finished" })[status] || "Unknown";
  }



  }

  if (window.P2K_ADMIN_MODE === true) initializeAdminFeatures();
  window.addEventListener("club-admin-access-ready", event => {
    if (event.detail?.enabled) initializeAdminFeatures();
  });

})();
