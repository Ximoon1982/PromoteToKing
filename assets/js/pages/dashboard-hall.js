/* Lazy Hall of Fame controller. Integrated in v2.8.8. */
(() => {
  "use strict";
  window.P2K_CREATE_DASHBOARD_HALL = function createDashboardHall(ctx) {
    const { state, byId, number, escapeHTML, openUnifiedPlayerProfile, setText, loadJSON, writeNavigationState, rankThumbnailAsset, originalRankAsset, adminEntryUsername, selectHallSubtab, openAchievementCatalog, loadPublicCachedJSON, showPublicPage } = ctx;
function hallImage(rank, framed = false) {
    const filename = framed ? rank.framed_image : rank.image;
    return rankThumbnailAsset(filename, framed ? 640 : 192);
  }

  function setHallStatus(message, error = false) {
    const status = byId("hallStatus");
    status.textContent = message;
    status.hidden = !String(message || "").trim();
    status.classList.toggle("is-error", error);
  }

  function renderHallSummary(hall) {
    setText("hallTotalMembers", number(hall?.total_members));
    setText("hallRankedMembers", number((hall?.ranks || []).reduce((sum, rank) => sum + (Number(rank?.members) || 0), 0)));
    setText("hallLeader", hall?.leader?.username ? `${hall.leader.username} · ${number(hall.leader.points)}` : "—");
    const position = Number(state.playerPoints?.team_position);
    setText("hallPersonalPosition", Number.isFinite(position) && position > 0 ? `#${number(position)} · ${state.playerPoints?.rank?.name || ""}` : "—");
  }

  function rankDescription(rank) {
    if (rank?.description) return String(rank.description);
    const hasMaximum = rank?.maximum !== null && rank?.maximum !== undefined && String(rank.maximum).trim() !== "";
    const maximum = hasMaximum ? Number(rank.maximum) : Number.NaN;
    return Number.isFinite(maximum)
      ? `Awarded to members with ${number(rank.minimum)} to ${number(maximum - 1)} Team Points.`
      : `The highest Promote to King rank, awarded from ${number(rank?.minimum)} Team Points with no upper boundary.`;
  }

  function dailyRankImageSource(rank, expanded) {
    const filename = expanded ? rank.framed_image : rank.image;
    return {
      src: rankThumbnailAsset(filename, expanded ? 640 : 192),
      fallback: originalRankAsset(filename),
      size: expanded ? 640 : 192
    };
  }

  function renderHallRanks(hall) {
    const selected = hall?.selected_rank?.key || "";
    const ranksToRender = state.hallSearchActive && selected
      ? (hall.ranks || []).filter(rank => rank.key === selected)
      : (hall.ranks || []);
    window.P2K_RANK_LADDER.render({
      grid: "hallRankGrid",
      ranks: ranksToRender,
      selectedKey: selected,
      highlight: state.hallHighlight,
      memberNoun: "member",
      noLeader: "No current members",
      membersForRank: rank => rank.key === selected ? (hall.members || []) : [],
      thresholdText: rank => `${number(rank.minimum)}+ Team Points`,
      description: rank => rankDescription(rank),
      imageSource: dailyRankImageSource,
      countFactLabel: "Player count",
      leaderFactLabel: "Rank leader",
      columns: [
        { label: "Rank", value: member => `#${number(member.category_position)}` },
        { label: "Member", value: "username", rowHeader: true },
        { label: "Points", value: member => number(member.points) },
        { label: "Matches", value: member => number(member.matches) },
        { label: "Games", value: member => number(member.games) },
        { label: "W / D / L", value: member => `${number(member.wins)} / ${number(member.draws)} / ${number(member.losses)}` },
        { label: "Team", value: member => member.team_position ? `#${number(member.team_position)}` : "—" }
      ],
      onToggle: (key, expanded) => expanded ? resetHallRanks() : loadHall({ rank: key, page: 1, page_size: 25 }),
      pagination: hall?.pagination || null,
      onPage: (key, page) => loadHall({ rank: key, page, page_size: 25 }),
      onMemberClick: member => openUnifiedPlayerProfile(member?.username),
      expandedSummary: rank => `${number(rank?.members || hall?.pagination?.total_rows || 0)} member${Number(rank?.members || hall?.pagination?.total_rows || 0) === 1 ? "" : "s"}, ordered by Team Points.`
    });
  }

  function tournamentMedalsFor(archive, username) {
    const needle = adminEntryUsername(username);
    const medals = { gold: 0, silver: 0, bronze: 0, tournaments: [], played: [] };
    for (const tournament of Array.isArray(archive?.tournaments) ? archive.tournaments : []) {
      const podiumNames = [];
      for (const medal of ["gold", "silver", "bronze"]) {
        const names = Array.isArray(tournament?.podium?.[medal]) ? tournament.podium[medal] : [];
        podiumNames.push(...names);
        if (names.some(name => adminEntryUsername(name) === needle)) {
          medals[medal] += 1;
          medals.tournaments.push({ name: tournament.name || tournament.slug || "Tournament", period: tournament.period || "", medal });
        }
      }
      const participants = Array.isArray(tournament?.participants) ? tournament.participants : [];
      if ([...participants, ...podiumNames].some(name => adminEntryUsername(name) === needle)) {
        medals.played.push({ name: tournament.name || tournament.slug || "Tournament", period: tournament.period || "", slug: tournament.slug || "" });
      }
    }
    return medals;
  }

  function openTournamentView(username, { podium = false } = {}) {
    const frame = byId("tournamentsFrame");
    const url = new URL("Tournaments.html", window.location.href);
    url.searchParams.set("embedded", "1");
    url.searchParams.set(podium ? "podium" : "player", username);
    if (podium) url.searchParams.set("panel", "ranking");
    if (frame) frame.src = url.href;
    selectHallSubtab("tournaments");
  }

  function hallResultCard(title, result, actions = null) {
    const card = document.createElement("article");
    card.className = `p2k-hall-result${result ? "" : " is-empty"}`;
    const label = document.createElement("span"); label.textContent = title;
    const strong = document.createElement("strong"); strong.textContent = result?.title || "No matching record";
    const small = document.createElement("small"); small.textContent = result?.detail || "This player is not present in the current dataset.";
    card.append(label, strong, small);
    const list = Array.isArray(actions) ? actions : actions ? [actions] : [];
    if (result && list.length) {
      const host = document.createElement("div");
      host.className = "p2k-hall-result-actions";
      list.forEach(action => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "dashboard-button";
        button.textContent = action.label;
        button.addEventListener("click", action.run);
        host.appendChild(button);
      });
      card.appendChild(host);
    }
    return card;
  }

  function liveRankImageSource(rank, expanded) {
    const filename = String(expanded ? (rank?.framed_image || rank?.image || "") : (rank?.icon || "")).trim();
    const base = expanded ? "assets/images/live-ranks" : "assets/images/ranks";
    const size = expanded ? 640 : 192;
    const thumbnail = filename && /\.png$/i.test(filename)
      ? `${base}/thumbs/${size}/${filename.replace(/\.png$/i, ".webp")}`
      : `${base}/${filename}`;
    return { src: thumbnail, fallback: `${base}/${filename}`, size };
  }

  function liveRankDescription(rank, rankIndex, ranks) {
    const higherRank = rankIndex > 0 ? ranks[rankIndex - 1] : null;
    return higherRank
      ? `Awarded to current members with ${number(rank?.minimum)} to ${number(Number(higherRank.minimum) - 1)} Live arena points.`
      : `The highest Promote to King Live rank, awarded from ${number(rank?.minimum)} Live arena points with no upper boundary.`;
  }

  function liveRankView(payload) {
    const selected = payload?.selected_rank?.key || state.liveRank || "";
    const selectedMembers = Array.isArray(payload?.members) ? payload.members : [];
    const ranks = (Array.isArray(payload?.groups) ? payload.groups : []).slice().reverse().map(group => ({
      ...(group?.rank || {}),
      member_count: Number(group?.member_count || 0),
      top_member: group?.top_member || "",
      top_points: group?.top_points ?? null,
      members_list: String(group?.rank?.key || "") === selected ? selectedMembers : []
    }));
    return { ranks, leader: payload?.leader || null, summary: payload?.summary || {}, selectedMembers };
  }

  function renderLiveRanksNative(payload) {
    const view = liveRankView(payload);
    if (payload?.selected_rank?.key) state.liveRank = payload.selected_rank.key;
    byId("hallResetSearch").hidden = !(state.hallSearchActive || state.liveRank);
    window.P2K_RANK_LADDER.render({
      grid: "liveRanksNativeGrid",
      ranks: view.ranks,
      selectedKey: state.liveRank,
      highlight: state.hallSearch,
      memberNoun: "member",
      noLeader: "No current members",
      membersForRank: rank => rank.members_list || [],
      thresholdText: rank => `${number(rank.minimum)}+ Live Points`,
      description: liveRankDescription,
      imageSource: liveRankImageSource,
      countFactLabel: "Player count",
      leaderFactLabel: "Rank leader",
      columns: [
        { label: "Rank", value: member => `#${number(member.category_position)}` },
        { label: "Member", value: "username", rowHeader: true },
        { label: "Points", value: member => number(member.points) },
        { label: "Arenas", value: member => number(member.arenas) },
        { label: "Team", value: member => member.team_position ? `#${number(member.team_position)}` : "—" }
      ],
      emptyMessage: "No current member is stored in this Live rank category.",
      onToggle: (key, expanded) => {
        if (expanded) {
          state.liveRank = "";
          writeNavigationState({ replace: true });
          loadLiveRanksNative({ force: true });
        } else {
          state.liveRank = key;
          writeNavigationState({ replace: true });
          loadLiveRanksNative({ force: true, rank: key, page: 1 });
        }
      },
      pagination: payload?.pagination || null,
      onPage: (key, page) => loadLiveRanksNative({ force: true, rank: key, page }),
      onMemberClick: member => openUnifiedPlayerProfile(member?.username),
      expandedSummary: rank => `${number(rank?.member_count || payload?.pagination?.total_rows || 0)} current member${Number(rank?.member_count || payload?.pagination?.total_rows || 0) === 1 ? "" : "s"}, ordered by Live arena points.`
    });
    return view;
  }

  async function loadLiveRanksNative({ force = false, rank = "", page = 1 } = {}) {
    if (state.liveRanksLoaded && !force && state.liveRanksPayload && !rank) { renderLiveRanksNative(state.liveRanksPayload); return; }
    const status = byId("liveRanksNativeStatus"), grid = byId("liveRanksNativeGrid");
    if (!status || !grid) return;
    status.classList.remove("is-error"); status.textContent = rank ? "Loading rank members…" : "Loading Live ranks…";
    if (!rank) {
      const cached = window.P2K_PROGRESSIVE?.snapshotGet?.("hall-live-summary", 6 * 3600000);
      if (cached?.payload) { state.liveRanksPayload = cached.payload; const view=renderLiveRanksNative(cached.payload), summary=cached.payload.summary||{}; setText("liveRanksCurrentMembers",number(summary.current_members||summary.players||0));setText("liveRanksRankedMembers",number(summary.ranked_players||0));setText("liveRanksLeader",view.leader?.username?`${view.leader.username} · ${number(view.leader.points)}`:"—");status.textContent="Cached Live ranks · refreshing…"; }
    }
    try {
      const query = new URLSearchParams({ action: "live-ranks" });
      if (rank) { query.set("rank", rank); query.set("page", String(page)); query.set("page_size", "25"); }
      const payload = await loadJSON(`server/team-points/public/public.php?${query}`, { credentials: "same-origin" });
      if (payload?.ok === false) throw new Error(payload?.error?.message || "Live ranks are unavailable.");
      state.liveRanksPayload = payload;
      const summary = payload.summary || {}, view = renderLiveRanksNative(payload);
      setText("liveRanksCurrentMembers", number(summary.current_members || summary.players || 0));
      setText("liveRanksRankedMembers", number(summary.ranked_players || 0));
      setText("liveRanksLeader", view.leader?.username ? `${view.leader.username} · ${number(view.leader.points)}` : "—");
      const personal = state.playerLive?.available ? state.playerLive : null;
      setText("liveRanksPersonalPosition", personal?.team_position ? `#${number(personal.team_position)} · ${personal.rank_name || personal.rank?.name || "Unranked"}` : "—");
      const pending = Number(summary.pending_checks || 0);
      if (!Number(summary.players || 0)) status.textContent = "No Live-rank computation has been published yet. Process MCA source data in Administration.";
      else if (pending) status.textContent = `Live ranks loaded while ${pending} former-player profile check${pending === 1 ? "" : "s"} remain pending.`;
      else status.textContent = "Live ranks are up to date with the latest processed MCA data.";
      state.liveRanksLoaded = true;
      if (!rank) window.P2K_PROGRESSIVE?.snapshotSet?.("hall-live-summary", payload);
      if (rank) requestAnimationFrame(() => document.querySelector(`[data-rank-key="${CSS.escape(rank)}"]`)?.scrollIntoView({ behavior: "smooth", block: "start" }));
    } catch (error) {
      status.classList.add("is-error"); status.textContent = `Unable to load Live ranks: ${error.message || error}`; grid.innerHTML = "";
    }
  }

  async function searchHallUnified(username, { updateHistory = true } = {}) {
    const queryName = String(username || "").trim(); if (!queryName) return;
    state.hallSearch=queryName;state.hallSearchActive=true;byId("hallMemberSearch").value=queryName;byId("hallResetSearch").hidden=false;
    const host=byId("hallUnifiedResults");host.hidden=false;host.innerHTML='<div class="p2k-section-loader">Searching Hall of Fame…</div>';if(updateHistory)writeNavigationState({replace:true});
    try {
      const result=await loadPublicCachedJSON(`server/team-points/public/hall-search.php?username=${encodeURIComponent(queryName)}`,{ttl:120000,credentials:"same-origin"});
      const daily=result?.daily||null,live=result?.live||null,medals=result?.tournaments||{},achievement=result?.achievements||{};
      const tournamentFound=Number(medals.participations||0)>0||Number(medals.podiums||0)>0,podiumFound=Number(medals.podiums||0)>0,achievementFound=Number(achievement.total||0)>0;
      const unified=document.createElement("article");unified.className="p2k-hall-result p2k-hall-result-unified";
      const unifiedHead=document.createElement("div");unifiedHead.className="p2k-hall-unified-head";unifiedHead.innerHTML=`<div><span>Player</span><strong>${escapeHTML(result?.username||queryName)}</strong><small>Daily ranks, Live ranks, tournament record and achievements</small></div>`;
      const actions=document.createElement("div");actions.className="p2k-hall-result-actions";const profileButton=document.createElement("button");profileButton.type="button";profileButton.className="dashboard-button dashboard-button-primary";profileButton.textContent="Open profile";profileButton.addEventListener("click",()=>openUnifiedPlayerProfile(queryName));actions.appendChild(profileButton);unifiedHead.appendChild(actions);
      const grid=document.createElement("div");grid.className="p2k-hall-unified-grid";grid.append(
        hallResultCard("Daily ranks",daily?{title:`${daily.username} · ${daily.rank?.name||"Unranked"}`,detail:`${number(daily.points)} Team Points · team #${number(daily.team_position)}`} : null,daily?{label:"Open Daily rank",run:()=>{selectHallSubtab("daily");loadHall({member:daily.username,page_size:25})}}:null),
        hallResultCard("Live ranks",live?{title:`${live.username} · ${live.rank_name||"Unranked"}`,detail:`${number(live.points)} arena points · ${number(live.arenas)} arenas`} : null,live?{label:"Open Live ranks",run:()=>{state.liveRank=live.rank_key||"";selectHallSubtab("live");loadLiveRanksNative({force:true,rank:state.liveRank,page:1})}}:null),
        hallResultCard("Tournaments",tournamentFound?{title:podiumFound?`${number(medals.gold)} gold · ${number(medals.silver)} silver · ${number(medals.bronze)} bronze`:`${number(medals.participations)} recorded tournament${Number(medals.participations)===1?"":"s"}`,detail:`${number(medals.participations)} participation${Number(medals.participations)===1?"":"s"}${podiumFound?` · ${number(medals.podiums)} podium placement${Number(medals.podiums)===1?"":"s"}`:""}`} : null,tournamentFound?[{label:"Open tournaments",run:()=>openTournamentView(queryName)},...(podiumFound?[{label:"Open podiums",run:()=>openTournamentView(queryName,{podium:true})}]:[])]:null),
        hallResultCard("Achievements",achievementFound?{title:`${number(achievement.earned||0)} of ${number(achievement.total||0)} achievements`,detail:Number(achievement.earned||0)?"Open the complete player achievement catalogue.":"No earned achievement is recorded yet."}:null,achievementFound?{label:"Open achievements",run:()=>openAchievementCatalog(queryName)}:null)
      );unified.append(unifiedHead,grid);host.replaceChildren(unified);setHallStatus(daily||live||tournamentFound||achievementFound?`Hall of Fame results for ${queryName}.`:`No Hall of Fame record matched “${queryName}”.`,!(daily||live||tournamentFound||achievementFound));
    } catch(error){host.innerHTML=`<p class="p2k-table-status is-error">${escapeHTML(error.message||error)}</p>`;setHallStatus(`Unable to search Hall of Fame for ${queryName}.`,true)}
  }

  async function loadHall(params = {}) {
    if (params.rank !== undefined) state.hallRank = String(params.rank || "");
    const run = ++state.hallRequest;
    setHallStatus(params.member ? `Searching for ${params.member}…` : params.rank ? "Loading rank members…" : "Loading Hall of Fame…");
    if (!params.rank && !params.member) {
      const cached=window.P2K_PROGRESSIVE?.snapshotGet?.("hall-daily-summary",6*3600000);
      const hall=cached?.payload?.hall; if(hall){state.hallData=hall;renderHallSummary(hall);renderHallRanks(hall);setHallStatus("Cached Hall of Fame · refreshing…");}
    }
    try {
      const hallParams = { ...params }; if (hallParams.rank && !hallParams.page_size) hallParams.page_size = 25;
      const payload = await window.P2K_TEAM_POINTS_CLIENT.publicRequest("hall", hallParams);
      if (run !== state.hallRequest) return;
      const hall = payload?.hall || {};
      state.hallData = hall;
      state.hallRank = hall.selected_rank?.key || (params.rank ? String(params.rank) : "");
      if (state.publicPage === "hall" && state.hallSubtab === "daily") writeNavigationState({ replace: true });
      state.hallSearchActive = Boolean(params.member && hall.search?.found);
      state.hallHighlight = hall.search?.member?.username || "";
      renderHallSummary(hall);
      renderHallRanks(hall);
      if (!params.rank && !params.member) window.P2K_PROGRESSIVE?.snapshotSet?.("hall-daily-summary", { hall });
      byId("hallResetSearch").hidden = !(state.hallSearchActive || hall.selected_rank);
      if (params.member) {
        setHallStatus(hall.search?.found
          ? `${hall.search.member.username} is ${hall.search.member.rank.name}, team #${number(hall.search.member.team_position)}.`
          : `No current Team Points member matched “${params.member}”.`, !hall.search?.found);
      } else if (hall.selected_rank) {
        setHallStatus(`${hall.selected_rank.name}: ${number(hall.selected_rank.members || hall.pagination?.total_rows || hall.members?.length || 0)} current members.`);
      } else {
        setHallStatus("");
      }
      if (params.rank) {
        window.requestAnimationFrame(() => {
          byId("hallRankGrid")?.querySelector(".dashboard-hall-rank-card.is-expanded")?.scrollIntoView({ behavior: "smooth", block: "start" });
        });
      }
    } catch (error) {
      console.warn("Unable to load Hall of Fame.", error);
      setHallStatus(error.message || "Hall of Fame data is unavailable.", true);
    }
  }

  function openHallOfFame({ focusCurrentMember = false, hallSubtab = "" } = {}) {
    const username = focusCurrentMember ? String(state.session?.username || "").trim() : "";
    const target = hallSubtab || (focusCurrentMember ? (state.personalMode === "live" ? "live" : "daily") : "achievements");
    if (username) {
      state.hallSearch = username;
      state.hallSearchActive = true;
      const search = byId("hallMemberSearch");
      if (search) search.value = username;
      if (target === "live") {
        state.pendingLiveFocus = username;
        const knownRank = String(state.playerLive?.rank_key || state.playerLive?.rank?.key || "").trim();
        if (knownRank && knownRank !== "unranked") state.liveRank = knownRank;
      }
    }
    showPublicPage("hall", { member: target === "daily" ? username : "", reset: !username, hallSubtab: target });
  }

  function resetHallRanks() {
    state.hallSearchActive = false;
    state.hallHighlight = "";
    state.hallSearch = "";
    state.hallRank = "";
    state.liveRank = "";
    state.pendingLiveFocus = "";
    byId("hallMemberSearch").value = "";
    byId("hallResetSearch").hidden = true;
    const results = byId("hallUnifiedResults");
    if (results) { results.hidden = true; results.replaceChildren(); }
    writeNavigationState({ replace: true });
    if (state.hallSubtab === "live") {
      if (state.liveRanksPayload) renderLiveRanksNative(state.liveRanksPayload);
      else loadLiveRanksNative();
    } else loadHall();
  }

  
    return Object.freeze({ renderHallSummary, renderHallRanks, renderLiveRanksNative, loadLiveRanksNative, searchHallUnified, loadHall, openHallOfFame, resetHallRanks });
  };
})();
