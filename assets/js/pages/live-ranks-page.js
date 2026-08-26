(() => {
  "use strict";
  const byId = id => document.getElementById(id);
  const number = value => window.P2K_RANK_LADDER.number(value);
  const usernameKey = value => String(value || "").trim().toLowerCase();
  let payload = null;
  let selected = new URLSearchParams(location.search).get("rank") || "";

  function frameHeight() {
    if (new URLSearchParams(location.search).get("embedded") === "1" && window.parent !== window) {
      window.parent.postMessage({ type: "p2k-frame-height", height: Math.ceil(document.documentElement.scrollHeight) }, location.origin);
    }
  }

  function imageSource(rank, expanded) {
    const filename = String(expanded ? (rank?.framed_image || rank?.image || "") : (rank?.icon || "")).trim();
    const base = expanded ? "assets/images/live-ranks" : "assets/images/ranks";
    const size = expanded ? 640 : 192;
    return {
      src: filename && /\.png$/i.test(filename) ? `${base}/thumbs/${size}/${filename.replace(/\.png$/i, ".webp")}` : `${base}/${filename}`,
      fallback: `${base}/${filename}`,
      size
    };
  }

  function livePlayers(data) {
    const grouped = (Array.isArray(data?.groups) ? data.groups : []).flatMap(group => Array.isArray(group?.players) ? group.players : []);
    return [...grouped, ...(Array.isArray(data?.unranked) ? data.unranked : [])];
  }

  function view(data) {
    const allPlayers = livePlayers(data);
    const currentPlayers = allPlayers
      .filter(player => player?.current_member === true || String(player?.account_state || "") === "current_member")
      .sort((a, b) => (Number(b.points) || 0) - (Number(a.points) || 0) || String(a.username || "").localeCompare(String(b.username || "")));
    currentPlayers.forEach((player, index) => { player.team_position = index + 1; });
    const byRank = new Map();
    currentPlayers.forEach(player => {
      if (!player.rank_key || player.rank_key === "unranked") return;
      if (!byRank.has(player.rank_key)) byRank.set(player.rank_key, []);
      const members = byRank.get(player.rank_key);
      members.push({ ...player, category_position: members.length + 1 });
    });
    const thresholds = Array.isArray(data?.thresholds) && data.thresholds.length
      ? data.thresholds
      : (Array.isArray(data?.groups) ? data.groups.map(group => group.rank || {}) : []);
    const ranks = [...thresholds].reverse().map(rank => {
      const members = byRank.get(rank.key) || [];
      return { ...rank, member_count: members.length, top_member: members[0]?.username || "", top_points: members[0]?.points ?? null, members_list: members };
    });
    const rankedPlayers = currentPlayers.filter(player => player.rank_key && player.rank_key !== "unranked");
    const session = window.P2K_AUTH?.getDisplaySession?.() || window.P2K_AUTH?.getSession?.() || null;
    const personal = session?.username ? currentPlayers.find(player => usernameKey(player.username) === usernameKey(session.username)) || null : null;
    return { currentPlayers, rankedPlayers, ranks, leader: currentPlayers[0] || null, personal };
  }

  function description(rank, index, ranks) {
    const higher = index > 0 ? ranks[index - 1] : null;
    return higher
      ? `Awarded to current members with ${number(rank.minimum)} to ${number(Number(higher.minimum) - 1)} Live arena points.`
      : `The highest Promote to King Live rank, awarded from ${number(rank.minimum)} Live arena points with no upper boundary.`;
  }

  function render(data) {
    const model = view(data);
    byId("liveRanksCurrentMembers").textContent = number(model.currentPlayers.length);
    byId("liveRanksRankedMembers").textContent = number(model.rankedPlayers.length);
    byId("liveRanksLeader").textContent = model.leader ? `${model.leader.username} · ${number(model.leader.points)}` : "—";
    byId("liveRanksPersonalPosition").textContent = model.personal ? `#${number(model.personal.team_position)} · ${model.personal.rank_name || "Unranked"}` : "—";
    window.P2K_RANK_LADDER.render({
      grid: "liveRanksNativeGrid",
      ranks: model.ranks,
      selectedKey: selected,
      memberNoun: "member",
      noLeader: "No current members",
      membersForRank: rank => rank.members_list || [],
      thresholdText: rank => `${number(rank.minimum)}+ Live Points`,
      description,
      imageSource,
      columns: [
        { label: "Rank", value: member => `#${number(member.category_position)}` },
        { label: "Member", value: "username", rowHeader: true },
        { label: "Points", value: member => number(member.points) },
        { label: "Arenas", value: member => number(member.arenas) },
        { label: "Team", value: member => member.team_position ? `#${number(member.team_position)}` : "—" }
      ],
      emptyMessage: "No current member is stored in this Live rank category.",
      onToggle: (key, expanded) => {
        selected = expanded ? "" : key;
        const url = new URL(location.href);
        if (selected) url.searchParams.set("rank", selected); else url.searchParams.delete("rank");
        history.replaceState({}, "", url);
        render(payload);
        if (!expanded) requestAnimationFrame(() => document.querySelector(`[data-rank-key="${CSS.escape(key)}"]`)?.scrollIntoView({ behavior: "smooth", block: "start" }));
      },
      expandedSummary: (_rank, members) => `${number(members.length)} current member${members.length === 1 ? "" : "s"}, ordered by Live arena points.`
    });
    const summary = data.summary || {};
    const all = livePlayers(data);
    const notice = byId("liveRanksNativeStatus");
    notice.classList.remove("is-error");
    if (!all.length) notice.textContent = "No Live-rank computation has been published yet. Process MCA source data in Administration.";
    else if (!model.currentPlayers.length) notice.textContent = "The MCA data was processed, but no current Promote to King member was matched.";
    else notice.textContent = "Live ranks are up to date with the latest processed MCA data.";
    frameHeight();
  }

  async function load() {
    try {
      const response = await fetch("server/team-points/public/public.php?action=live-ranks", { credentials: "same-origin", cache: "default" });
      const data = await response.json();
      if (!response.ok || data?.ok === false) throw new Error(data?.error?.message || `HTTP ${response.status}`);
      payload = data;
      render(data);
    } catch (error) {
      const status = byId("liveRanksNativeStatus");
      status.classList.add("is-error");
      status.textContent = `Unable to load Live ranks: ${error.message || error}`;
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", load, { once: true }); else load();
  window.addEventListener("p2k-auth-change", () => payload && render(payload));
  if (window.ResizeObserver) new ResizeObserver(frameHeight).observe(document.body);
})();
