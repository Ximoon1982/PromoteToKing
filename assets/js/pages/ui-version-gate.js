/* Strict selector for the optional v2 dashboard UI. */
(() => {
  "use strict";
  const params = new URLSearchParams(window.location.search);
  const selected = String(params.get("ui") || "").trim().toLowerCase();
  const page = (window.location.pathname.split("/").pop() || "index.html").toLowerCase();
  const onDashboard = page === "ui-v2.html";

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
