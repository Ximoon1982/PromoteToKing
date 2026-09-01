/* Strict selector for the optional v2 dashboard UI. */
(() => {
  "use strict";
  const params = new URLSearchParams(window.location.search);
  const selected = String(params.get("ui") || "").trim().toLowerCase();
  const page = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();
  const onDashboard = page === "ui-v2.html";

  // v2.11.0: Recruitment is a native Administration detail. Mark and suppress the
  // ordinary category panel before first paint so the Members dashboard/iframe cannot
  // flash underneath Recruitment while deferred dashboard scripts initialize.
  const recruitmentRoute = onDashboard
    && String(params.get("view") || "").toLowerCase() === "admin"
    && String(params.get("adminCategory") || "competitions").toLowerCase() === "members"
    && String(params.get("adminDetail") || "").toLowerCase() === "recruitment";
  if (recruitmentRoute) {
    document.documentElement.classList.add("p2k-recruitment-route");
    const style = document.createElement("style");
    style.id = "p2kRecruitmentFirstPaint";
    style.textContent = "html.p2k-recruitment-route #adminShellDetailFrame{display:none!important;height:0!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important}html.p2k-recruitment-route .dashboard-admin-shell-panel{display:none!important}";
    document.head.appendChild(style);
  }

  if (!onDashboard && selected !== "v2") return;
  if (onDashboard && selected === "v2") return;

  const target = new URL(onDashboard ? "index.html" : "ui-v2.html", window.location.href);
  target.search = "";
  for (const [key, value] of params) {
    if (onDashboard && key === "ui") continue;
    target.searchParams.append(key, value);
  }
  if (!onDashboard) target.searchParams.set("ui", "v2");
  window.location.replace(target.href);
})();
