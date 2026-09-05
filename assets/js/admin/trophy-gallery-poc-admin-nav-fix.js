(() => {
"use strict";

const PANEL_ID = "p2kTrophyAdminPanel";

function adminRoot() {
  return document.getElementById("adminDashboardPanel") || document.getElementById("administrationPage");
}

function teamPanel() {
  return adminRoot()?.querySelector("[data-admin-shell-panel='team']") || document.querySelector("[data-admin-shell-panel='team']");
}

function positionPanel() {
  const panel = document.getElementById(PANEL_ID);
  const target = teamPanel();
  if (!panel || !target) return false;
  if (panel.parentElement !== target) target.appendChild(panel);
  return true;
}

function openPanel() {
  document.getElementById("dashboardAdministrationTab")?.click();
  window.setTimeout(() => {
    const root = adminRoot();
    const teamButton = root?.querySelector("[data-admin-category='team']");
    teamButton?.click();
    window.setTimeout(() => {
      positionPanel();
      const panel = document.getElementById(PANEL_ID);
      if (!panel) return;
      panel.hidden = false;
      panel.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 60);
  }, 40);
}

document.addEventListener("click", event => {
  const button = event.target.closest?.("[data-trophy-admin-card] button");
  if (!button) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  openPanel();
}, true);

window.addEventListener("p2k-admin-shell-route", () => window.setTimeout(positionPanel, 0));

const observer = new MutationObserver(() => {
  if (document.getElementById(PANEL_ID)) positionPanel();
});
if (document.body) observer.observe(document.body, { childList: true, subtree: true });
else document.addEventListener("DOMContentLoaded", () => observer.observe(document.body, { childList: true, subtree: true }), { once: true });

window.P2K_TROPHY_GALLERY_ADMIN_NAV_FIX = Object.freeze({ openPanel, positionPanel });
})();
