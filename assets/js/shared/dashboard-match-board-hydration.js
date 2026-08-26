/* v2.9.20 DMBHF: hydrate current club-match index rows from authoritative match detail. */
(() => {
  "use strict";
  const detailUrl = match => {
    const direct = String(match?.["@id"] || match?.api_url || match?.apiUrl || "").trim();
    if (/^https:\/\/api\.chess\.com\/pub\/match\/\d+\/?$/i.test(direct)) return direct.replace(/\/$/, "");
    const id = Number(match?.match_id ?? match?.matchId ?? match?.id ?? direct.match(/\/match\/(\d+)/i)?.[1]);
    return Number.isFinite(id) && id > 0 ? `https://api.chess.com/pub/match/${Math.trunc(id)}` : "";
  };
  const boardCount = match => {
    const boards = Number(match?.boards);
    return Number.isFinite(boards) && boards >= 0 ? Math.round(boards) : null;
  };
  const totals = list => {
    let boards = 0, unresolved = 0;
    (Array.isArray(list) ? list : []).forEach(match => {
      const count = boardCount(match);
      if (count === null) unresolved += 1; else boards += count;
    });
    return { boards, games: boards * 2, unresolved };
  };
  async function hydrate(lists, options = {}) {
    const client = options.client || window.P2K_API_CLIENT, loadJSON = options.loadJSON;
    if (!client?.processPriority || typeof loadJSON !== "function") throw new Error("Shared Chess.com API transport is unavailable for DMBHF.");
    const targets = [];
    ["registered", "ongoing"].forEach(status => (Array.isArray(lists?.[status]) ? lists[status] : []).forEach(match => {
      const url = detailUrl(match); if (url) targets.push({ status, match, url });
    }));
    const unique = [], seen = new Set();
    targets.forEach(entry => { if (!seen.has(entry.url)) { seen.add(entry.url); unique.push(entry); } });
    if (!unique.length) return { requested: 0, loaded: 0, failed: 0 };
    const result = await client.processPriority(unique, async entry => ({
      entry,
      detail: await loadJSON(entry.url, { attempts: 2, priority: -20, trafficClass: "background" })
    }), { getKey: entry => entry.url, getPriority: entry => entry.status === "ongoing" ? 20 : 10 });
    result.succeeded.forEach(row => {
      const entry = row.value?.entry, detail = row.value?.detail;
      if (entry?.match && detail && typeof detail === "object") Object.assign(entry.match, detail);
    });
    return { requested: unique.length, loaded: result.succeeded.length, failed: result.failures.length };
  }
  window.P2K_DASHBOARD_MATCH_BOARD_HYDRATION = Object.freeze({ detailUrl, boardCount, totals, hydrate });
})();
