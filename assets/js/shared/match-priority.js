/* Shared league-first match/reference ordering independent of the API transport. */
(() => {
  "use strict";

  const RUNTIME_VERSION = "1.0.0";
  const existing = window.P2K_MATCH_PRIORITY;
  if (
    existing?.runtimeVersion === RUNTIME_VERSION &&
    typeof existing.prioritizeMatchReferences === "function" &&
    typeof existing.prioritizeRecords === "function"
  ) return;

  function leagueCodes() {
    const configured = window.P2K_SITE_CONFIG?.leagueAcronyms;
    return Array.isArray(configured)
      ? configured.map(value => String(value || "").trim()).filter(Boolean)
      : [];
  }

  function searchableText(value) {
    return String(
      value?.name ||
      value?.title ||
      value?.url ||
      value?.["@id"] ||
      value?.apiUrl ||
      value?.summary?.name ||
      ""
    ).toUpperCase();
  }

  function isLeagueMatch(value) {
    const text = searchableText(value);
    return leagueCodes().some(code =>
      new RegExp(`(^|[^A-Z0-9])${code.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}([^A-Z0-9]|$)`, "i").test(text)
    );
  }

  function startTimestamp(value) {
    const raw = value?.start_time ?? value?.startTime ?? value?.summary?.start_time;
    const numeric = Number(raw);
    return Number.isFinite(numeric) && numeric > 0
      ? numeric
      : Number.POSITIVE_INFINITY;
  }

  function stableLabel(value) {
    return String(
      value?.name ||
      value?.summary?.name ||
      value?.["@id"] ||
      value?.apiUrl ||
      ""
    );
  }

  function compare(a, b) {
    const leagueDifference = Number(isLeagueMatch(b)) - Number(isLeagueMatch(a));
    if (leagueDifference !== 0) return leagueDifference;
    const dateDifference = startTimestamp(a) - startTimestamp(b);
    if (dateDifference !== 0) return dateDifference;
    return stableLabel(a).localeCompare(stableLabel(b));
  }

  function prioritize(values) {
    return [...(Array.isArray(values) ? values : [])].sort(compare);
  }

  window.P2K_MATCH_PRIORITY = Object.freeze({
    runtimeVersion: RUNTIME_VERSION,
    isLeagueMatch,
    compare,
    prioritizeMatchReferences: prioritize,
    prioritizeRecords: prioritize
  });
})();
