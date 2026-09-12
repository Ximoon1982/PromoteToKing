(function (root, factory) {
  const api = factory();
  if (typeof module === "object" && module.exports) module.exports = api;
  if (root) root.P2K_RECRUITMENT_V2 = api;
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
  "use strict";
  const number = value => value === "" || value == null || !Number.isFinite(Number(value)) ? null : Number(value);
  function parseMatchReference(input, base = "https://www.promotetoking.org/") {
    const value = String(input || "").trim();
    if (!value) throw new Error("Enter a match URL, slug or numeric ID.");
    if (/^\d+$/.test(value)) return value;
    let decoded = value; try { decoded = decodeURIComponent(value); } catch (_) {}
    try {
      const url = new URL(decoded, base);
      const nested = url.searchParams.get("match") || url.searchParams.get("id");
      if (nested) return parseMatchReference(nested, base);
      const numbers = url.pathname.match(/\d+/g);
      if (numbers?.length) return numbers[numbers.length - 1];
    } catch (_) {}
    const numbers = decoded.match(/\d+/g);
    if (numbers?.length) return numbers[numbers.length - 1];
    throw new Error("That match slug does not contain a numeric Chess.com match ID.");
  }
  function ratingCategory(rules) { return String(rules || "").toLowerCase().replace(/[\s_-]+/g, "").includes("960") ? "daily_chess960" : "daily_standard"; }
  function preselect(rows, criteria) {
    const registered = criteria.registered || new Set(), opponent = criteria.opponent || new Set();
    const counts = { total: rows.length, unrated: 0, outsideRating: 0, registered: 0, opponent: 0 };
    const candidates = [];
    for (const row of rows) {
      const key = String(row.username_key || row.username || "").trim().toLowerCase(), rating = number(row.rating);
      if (!key) continue;
      if (registered.has(key)) { counts.registered++; continue; }
      if (rating === null || rating <= 0) { counts.unrated++; continue; }
      if (rating < criteria.min || rating > criteria.max) { counts.outsideRating++; continue; }
      if (opponent.has(key)) { counts.opponent++; continue; }
      candidates.push({ ...row, username_key: key, rating });
    }
    return { candidates, counts };
  }
  function verify(row, live, criteria, nowSeconds = Date.now() / 1000) {
    if (live?.error) return { ...row, decision: "unverified", reason: live.error, live };
    const lastOnline = number(live?.last_online), timeout = number(live?.timeout_percent);
    if (lastOnline === null) return { ...row, decision: "unverified", reason: "Last-online data unavailable.", live };
    if (timeout === null) return { ...row, decision: "unverified", reason: "Timeout rate unavailable.", live };
    const ageHours = Math.max(0, (nowSeconds - lastOnline) / 3600);
    if (ageHours > criteria.onlineDays * 24) return { ...row, decision: "excluded", reason: "Last online is outside the configured window.", last_online_age_hours: ageHours, live };
    if (timeout > criteria.maxTimeout) return { ...row, decision: "excluded", reason: "Timeout rate exceeds the configured threshold.", last_online_age_hours: ageHours, live };
    return { ...row, decision: "eligible", reason: "All hard eligibility filters passed.", last_online_age_hours: ageHours, live };
  }
  function eta(completionTimes, remaining, elapsedMilliseconds) {
    if (remaining <= 0) return 0;
    const elapsed = Number(elapsedMilliseconds);
    const completed = completionTimes.filter(n => Number.isFinite(n) && n >= 0).sort((a, b) => a - b);
    if (completed.length < 2 || !Number.isFinite(elapsed) || elapsed <= 0) return null;
    const windowSize = Math.min(20, completed.length);
    const first = completed.length - windowSize;
    const anchor = first > 0 ? completed[first - 1] : 0;
    const throughput = windowSize / Math.max(1, elapsed - anchor);
    return Math.max(0, Math.ceil(remaining / throughput / 1000));
  }
  function csv(rows, match) {
    const fields = ["username","profile_url","rating","rating_category","rating_updated_at","last_online","last_online_age_hours","timeout_rate","current_match_load","match_id","match_name","rating_min","rating_max"];
    const quote = value => `"${String(value ?? "").replace(/"/g, '""')}"`;
    const lines = [fields.map(quote).join(",")];
    rows.forEach(row => lines.push([
      row.username, `https://www.chess.com/member/${encodeURIComponent(row.username)}`, row.rating, match.ratingCategory,
      row.rating_updated_at, row.live?.last_online ? new Date(row.live.last_online * 1000).toISOString() : "", row.last_online_age_hours,
      row.live?.timeout_percent, row.live?.current_match_load ?? "", match.id, match.name, match.min, Number.isFinite(match.max) ? match.max : ""
    ].map(quote).join(",")));
    return `\uFEFF${lines.join("\r\n")}\r\n`;
  }
  return { parseMatchReference, ratingCategory, preselect, verify, eta, csv };
});
