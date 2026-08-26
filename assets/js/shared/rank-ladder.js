(() => {
  "use strict";

  const number = value => {
    if (value === null || value === undefined || String(value).trim() === "") return "—";
    const numeric = Number(value);
    return Number.isFinite(numeric)
      ? numeric.toLocaleString("en-GB", { maximumFractionDigits: 2 })
      : String(value);
  };

  function setImage(image, source, { lazy = false } = {}) {
    const config = typeof source === "string" ? { src: source } : (source || {});
    image.onerror = () => {
      image.onerror = null;
      image.removeAttribute("srcset");
      if (config.fallback && image.src !== config.fallback) image.src = config.fallback;
    };
    image.src = config.src || config.fallback || "";
    const size = Number(config.size) || 0;
    if (size > 0) {
      image.width = size;
      image.height = size;
    }
    image.decoding = "async";
    image.loading = lazy ? "lazy" : "eager";
  }

  function memberTable({ members = [], highlight = "", columns = [], emptyMessage = "No current member is stored in this rank category.", onMemberClick = null } = {}) {
    const wrap = document.createElement("div");
    wrap.className = "dashboard-hall-members-wrap";
    if (!members.length) {
      wrap.className += " dashboard-hall-no-members";
      wrap.textContent = emptyMessage;
      return wrap;
    }
    const table = document.createElement("table");
    table.className = "dashboard-hall-members";
    const head = document.createElement("thead");
    const headRow = document.createElement("tr");
    columns.forEach(column => {
      const cell = document.createElement("th");
      cell.textContent = column.label;
      headRow.appendChild(cell);
    });
    head.appendChild(headRow);
    const body = document.createElement("tbody");
    const highlighted = String(highlight || "").toLowerCase();
    members.forEach((member, index) => {
      const row = document.createElement("tr");
      if (String(member.username || "").toLowerCase() === highlighted) row.className = "is-highlighted";
      columns.forEach(column => {
        const value = typeof column.value === "function" ? column.value(member, index) : member[column.value];
        const cell = document.createElement(column.rowHeader ? "th" : "td");
        if (column.rowHeader) cell.scope = "row";
        cell.textContent = value === null || value === undefined || String(value) === "" ? "—" : String(value);
        row.appendChild(cell);
      });
      if (typeof onMemberClick === "function") {
        row.classList.add("is-actionable");
        row.tabIndex = 0;
        row.setAttribute("role", "button");
        row.setAttribute("aria-label", `Open ${member.username || "member"} profile`);
        row.addEventListener("click", () => onMemberClick(member));
        row.addEventListener("keydown", event => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            onMemberClick(member);
          }
        });
      }
      body.appendChild(row);
    });
    table.append(head, body);
    wrap.appendChild(table);
    return wrap;
  }

  function render(options = {}) {
    const grid = typeof options.grid === "string" ? document.getElementById(options.grid) : options.grid;
    if (!grid) return;
    const ranks = Array.isArray(options.ranks) ? options.ranks : [];
    const selectedKey = String(options.selectedKey || "");
    const highlight = String(options.highlight || "");
    const memberNoun = options.memberNoun || "member";
    const noLeader = options.noLeader || "No current members";
    grid.replaceChildren();

    ranks.forEach((rank, rankIndex) => {
      const expanded = String(rank.key || "") === selectedKey;
      const members = typeof options.membersForRank === "function" ? (options.membersForRank(rank) || []) : (rank.members_list || []);
      const countValue = Number(rank.member_count ?? rank.members ?? members.length) || 0;
      const leaderName = rank.top_member || members[0]?.username || "";
      const leaderPoints = rank.top_points ?? members[0]?.points;

      const article = document.createElement("article");
      article.className = `dashboard-hall-rank-card${expanded ? " is-expanded" : ""}`;
      article.dataset.rankKey = String(rank.key || "");

      const opener = document.createElement("button");
      opener.className = "dashboard-hall-rank-opener";
      opener.type = "button";
      opener.setAttribute("aria-expanded", String(expanded));

      const image = document.createElement("img");
      image.className = "dashboard-hall-rank-image";
      setImage(image, options.imageSource?.(rank, expanded), { lazy: !expanded });
      image.alt = `${rank.name || "Rank"}${expanded ? " framed" : " frameless"} rank`;

      const copy = document.createElement("div");
      const name = document.createElement("strong");
      name.textContent = rank.name || "Rank";
      const threshold = document.createElement("small");
      threshold.textContent = options.thresholdText?.(rank, rankIndex, ranks) || `${number(rank.minimum)}+ points`;
      const leader = document.createElement("span");
      leader.textContent = leaderName ? `${leaderName} · ${number(leaderPoints)} points` : noLeader;

      const count = document.createElement("div");
      count.className = "dashboard-hall-rank-count";
      const countStrong = document.createElement("strong");
      countStrong.textContent = number(countValue);
      const countSmall = document.createElement("small");
      countSmall.textContent = countValue === 1 ? memberNoun : `${memberNoun}s`;
      count.append(countStrong, countSmall);

      if (expanded) {
        copy.className = "dashboard-hall-expanded-copy";
        const description = document.createElement("p");
        description.className = "dashboard-hall-expanded-description";
        description.textContent = options.description?.(rank, rankIndex, ranks) || "";
        const facts = document.createElement("div");
        facts.className = "dashboard-hall-expanded-facts";
        const playersFact = document.createElement("span");
        playersFact.append(document.createTextNode(options.countFactLabel || "Player count"));
        const playersValue = document.createElement("b");
        playersValue.textContent = number(countValue);
        playersFact.appendChild(playersValue);
        const leaderFact = document.createElement("span");
        leaderFact.append(document.createTextNode(options.leaderFactLabel || "Rank leader"));
        const leaderValue = document.createElement("b");
        leaderValue.textContent = leaderName ? `${leaderName} · ${number(leaderPoints)}` : noLeader;
        leaderFact.appendChild(leaderValue);
        facts.append(playersFact, leaderFact);
        copy.append(name, threshold, description, facts);
        opener.append(image, copy);
      } else {
        copy.append(name, threshold, leader);
        opener.append(image, copy, count);
      }

      opener.addEventListener("click", () => options.onToggle?.(String(rank.key || ""), expanded));
      article.appendChild(opener);

      if (expanded) {
        const expandedHead = document.createElement("div");
        expandedHead.className = "dashboard-hall-expanded-head";
        expandedHead.textContent = options.expandedSummary?.(rank, members) || `${number(members.length)} ${members.length === 1 ? memberNoun : `${memberNoun}s`}.`;
        article.append(expandedHead, memberTable({
          members,
          highlight,
          columns: options.columns || [],
          emptyMessage: options.emptyMessage || `No current ${memberNoun} is stored in this rank category.`,
          onMemberClick: options.onMemberClick
        }));
        const pagination = typeof options.paginationForRank === "function" ? options.paginationForRank(rank) : options.pagination;
        if (pagination && Number(pagination.total_pages || 1) > 1) {
          const controls = document.createElement("div");
          controls.className = "dashboard-hall-page-controls";
          const previous = document.createElement("button"); previous.type = "button"; previous.className = "dashboard-button"; previous.textContent = "Previous";
          const next = document.createElement("button"); next.type = "button"; next.className = "dashboard-button"; next.textContent = "Next";
          const text = document.createElement("span"); text.textContent = `Page ${number(pagination.page)} of ${number(pagination.total_pages)} · ${number(pagination.total_rows)} ${Number(pagination.total_rows) === 1 ? memberNoun : `${memberNoun}s`}`;
          previous.disabled = Number(pagination.page || 1) <= 1; next.disabled = Number(pagination.page || 1) >= Number(pagination.total_pages || 1);
          previous.addEventListener("click", () => options.onPage?.(String(rank.key || ""), Math.max(1, Number(pagination.page || 1) - 1)));
          next.addEventListener("click", () => options.onPage?.(String(rank.key || ""), Number(pagination.page || 1) + 1));
          controls.append(previous, text, next); article.appendChild(controls);
        }
      }
      grid.appendChild(article);
    });

    if (highlight) {
      window.requestAnimationFrame(() => {
        grid.querySelector("tr.is-highlighted")?.scrollIntoView({ behavior: "smooth", block: "center" });
      });
    }
  }

  window.P2K_RANK_LADDER = Object.freeze({ render, memberTable, setImage, number });
})();
