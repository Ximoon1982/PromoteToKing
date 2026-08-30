/* Members Insights period, team-position, evolution, and full CSV enhancement. */
(() => {
  "use strict";

  const FACTORY_FLAG = "__p2kMembersInsightsEnhanced";
  const TABLE_FLAG = "__p2kMembersInsightsTableEnhanced";
  const EVOLUTION_VALUES = new Set(["1w", "1m", "3m", "1y"]);

  function ensureStyles() {
    if (document.querySelector('link[data-p2k-members-insights-css]')) return;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = "assets/css/members-insights-enhancement.css?v=2.10.9.5-members-ranking-1";
    link.dataset.p2kMembersInsightsCss = "1";
    document.head.appendChild(link);
  }

  function readInitialState(state) {
    const params = new URLSearchParams(window.location.search);
    if (state.membersStart === undefined) state.membersStart = String(params.get("memberStart") || "");
    if (state.membersEnd === undefined) state.membersEnd = String(params.get("memberEnd") || "");
    if (state.membersEvolution === undefined) {
      const requested = String(params.get("memberEvolution") || "1m").toLowerCase();
      state.membersEvolution = EVOLUTION_VALUES.has(requested) ? requested : "1m";
    }
  }

  function syncNavigation(state) {
    const url = new URL(window.location.href);
    const setOrDelete = (key, value) => value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
    setOrDelete("memberStart", state.membersStart || "");
    setOrDelete("memberEnd", state.membersEnd || "");
    if (state.membersEvolution && state.membersEvolution !== "1m") url.searchParams.set("memberEvolution", state.membersEvolution);
    else url.searchParams.delete("memberEvolution");
    window.history.replaceState(window.history.state, "", `${url.pathname}${url.search}${url.hash}`);
  }

  function memberRequestURL(url, state) {
    const resolved = new URL(String(url || ""), window.location.href);
    if (!/\/server\/team-points\/public\/members-insights\.php$/i.test(resolved.pathname)) return resolved;
    // Members owns its date state. Do not inherit Team Insights' teamStart/teamEnd.
    resolved.searchParams.delete("start");
    resolved.searchParams.delete("end");
    resolved.searchParams.delete("evolution");
    if (state.membersStart) resolved.searchParams.set("start", state.membersStart);
    if (state.membersEnd) resolved.searchParams.set("end", state.membersEnd);
    resolved.searchParams.set("evolution", EVOLUTION_VALUES.has(state.membersEvolution) ? state.membersEvolution : "1m");
    return resolved;
  }

  function positionCell(row) {
    const position = Number(row?.team_position);
    if (!Number.isFinite(position) || position <= 0) return "—";
    const node = document.createElement("span");
    node.className = "p2k-member-team-position";

    const rank = document.createElement("strong");
    rank.textContent = `#${Math.trunc(position)}`;
    node.appendChild(rank);

    const movement = document.createElement("span");
    const previous = Number(row?.previous_position);
    const change = Number(row?.position_change);
    if (row?.position_new === true) {
      movement.className = "p2k-member-position-change is-new";
      movement.textContent = "NEW";
      movement.title = "No team position at the selected comparison date";
    } else if (!Number.isFinite(previous) || previous <= 0) {
      movement.className = "p2k-member-position-change is-flat";
      movement.textContent = "—";
      movement.title = "No comparable earlier position in the selected range";
    } else if (Number.isFinite(change) && change > 0) {
      movement.className = "p2k-member-position-change is-up";
      movement.textContent = `↑ ${Math.trunc(change)}`;
      movement.title = `Up from #${Math.trunc(previous)}`;
    } else if (Number.isFinite(change) && change < 0) {
      movement.className = "p2k-member-position-change is-down";
      movement.textContent = `↓ ${Math.abs(Math.trunc(change))}`;
      movement.title = `Down from #${Math.trunc(previous)}`;
    } else {
      movement.className = "p2k-member-position-change is-flat";
      movement.textContent = "—";
      movement.title = `Unchanged from #${Math.trunc(previous)}`;
    }
    node.appendChild(movement);
    return node;
  }

  function ensurePositionHeader(root) {
    if (!root || root.querySelector('th[data-key="team_position"]')) return;
    const row = root.querySelector("thead tr");
    if (!row) return;
    const header = document.createElement("th");
    header.dataset.key = "team_position";
    header.textContent = "Team position";
    header.title = "Daily Points rank · net wins break ties";
    const first = row.children[0];
    if (first?.nextSibling) row.insertBefore(header, first.nextSibling);
    else row.appendChild(header);
  }

  function patchDataTable() {
    const Base = window.P2KDataTable;
    if (typeof Base !== "function" || Base[TABLE_FLAG]) return;

    class MembersAwareDataTable extends Base {
      constructor(options = {}) {
        if (options?.root?.id === "membersDataTable") {
          ensurePositionHeader(options.root);
          const columns = Array.isArray(options.columns) ? options.columns.map(column => ({ ...column })) : [];
          if (!columns.some(column => column.key === "team_position")) {
            const usernameIndex = Math.max(0, columns.findIndex(column => column.key === "username"));
            columns.splice(usernameIndex + 1, 0, {
              key: "team_position",
              label: "Team position",
              type: "number",
              defaultDirection: "asc",
              render: row => positionCell(row),
            });
          }
          const winRate = columns.find(column => column.key === "win_rate");
          if (winRate) {
            winRate.render = row => row?.win_rate === null || row?.win_rate === undefined || row?.win_rate === ""
              ? "—"
              : `${Number(row.win_rate).toFixed(1)}%`;
          }
          options = { ...options, columns };
        }
        super(options);
      }
    }
    Object.defineProperty(MembersAwareDataTable, TABLE_FLAG, { value: true });
    window.P2KDataTable = MembersAwareDataTable;
  }

  function activityStatuses() {
    return [...document.querySelectorAll('#membersActivityStatusFilter input[type="checkbox"]:checked')]
      .map(input => String(input.value || "").trim())
      .filter(Boolean);
  }

  function exportURL(state) {
    const url = new URL("server/team-points/public/members-insights-export.php", window.location.href);
    const tableState = state.membersTable?.state || state.membersTableState || {};
    const query = String(tableState.query || document.getElementById("membersTableSearch")?.value || "").trim();
    const filter = String(tableState.filter || document.getElementById("membersTableFilter")?.value || "current").trim();
    if (query) url.searchParams.set("search", query);
    url.searchParams.set("filter", filter || "current");
    if (tableState.sort) url.searchParams.set("sort", String(tableState.sort));
    url.searchParams.set("direction", tableState.direction === "asc" ? "asc" : "desc");
    const statuses = activityStatuses();
    if (statuses.length) url.searchParams.set("activity_status", statuses.join(","));
    if (state.membersStart) url.searchParams.set("start", state.membersStart);
    if (state.membersEnd) url.searchParams.set("end", state.membersEnd);
    url.searchParams.set("evolution", EVOLUTION_VALUES.has(state.membersEvolution) ? state.membersEvolution : "1m");
    return url;
  }

  function triggerExport(state) {
    const anchor = document.createElement("a");
    anchor.href = exportURL(state).href;
    anchor.hidden = true;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  }

  function setControlStatus(message, isError = false) {
    const node = document.getElementById("membersPeriodStatus");
    if (!node) return;
    node.textContent = message || "";
    node.classList.toggle("is-error", Boolean(isError));
  }

  function ensureControls(ctx, state, reload) {
    if (document.getElementById("membersPeriodControls")) return;
    const table = ctx.byId?.("membersDataTable") || document.getElementById("membersDataTable");
    if (!table) return;
    const tableWrap = table.closest(".p2k-table-wrap") || table;
    const controls = document.createElement("div");
    controls.id = "membersPeriodControls";
    controls.className = "p2k-members-period-controls";
    controls.innerHTML = `
      <div class="p2k-members-period-fields">
        <label><span>From</span><input id="membersPeriodStart" type="date"></label>
        <label><span>To</span><input id="membersPeriodEnd" type="date"></label>
        <label class="p2k-members-evolution-field"><span>Position evolution</span>
          <select id="membersEvolutionPeriod">
            <option value="1w">1 week</option>
            <option value="1m">1 month</option>
            <option value="3m">3 months</option>
            <option value="1y">1 year</option>
          </select>
        </label>
      </div>
      <div class="p2k-members-period-actions">
        <span id="membersPeriodStatus" class="p2k-members-period-status" aria-live="polite"></span>
        <button id="membersExportCsv" type="button" class="p2k-members-export-button">Export CSV</button>
      </div>`;
    tableWrap.parentNode?.insertBefore(controls, tableWrap);

    const from = controls.querySelector("#membersPeriodStart");
    const to = controls.querySelector("#membersPeriodEnd");
    const evolution = controls.querySelector("#membersEvolutionPeriod");
    const exportButton = controls.querySelector("#membersExportCsv");
    from.value = state.membersStart || "";
    to.value = state.membersEnd || "";
    evolution.value = EVOLUTION_VALUES.has(state.membersEvolution) ? state.membersEvolution : "1m";

    const apply = () => {
      const nextStart = String(from.value || "");
      const nextEnd = String(to.value || "");
      if (nextStart && nextEnd && nextStart > nextEnd) {
        setControlStatus("From date cannot be after To date.", true);
        return;
      }
      setControlStatus("");
      state.membersStart = nextStart;
      state.membersEnd = nextEnd;
      state.membersEvolution = EVOLUTION_VALUES.has(evolution.value) ? evolution.value : "1m";
      state.membersTableState = { ...(state.membersTableState || {}), page: 1 };
      if (state.membersTable?.state) state.membersTable.state.page = 1;
      state.membersTableProgressiveLoaded = false;
      state.membersLoaded = false;
      syncNavigation(state);
      reload();
    };

    from.addEventListener("change", apply);
    to.addEventListener("change", apply);
    evolution.addEventListener("change", apply);
    exportButton.addEventListener("click", () => triggerExport(state));
  }

  function wrapFactory(factory) {
    if (typeof factory !== "function" || factory[FACTORY_FLAG]) return factory;
    const enhancedFactory = function (ctx) {
      ensureStyles();
      const state = ctx?.state || {};
      readInitialState(state);
      patchDataTable();

      const originalLoadJSON = ctx.loadJSON;
      const enhancedContext = {
        ...ctx,
        loadJSON: (url, options = {}) => originalLoadJSON(memberRequestURL(url, state).href, options),
      };
      const api = factory(enhancedContext);
      const originalLoadMemberInsights = api.loadMemberInsights;
      let enhancedApi = null;
      const loadMemberInsights = async (options = {}) => {
        patchDataTable();
        ensureControls(ctx, state, () => {
          void loadMemberInsights({ force: true });
        });
        return originalLoadMemberInsights(options);
      };
      enhancedApi = Object.freeze({ ...api, loadMemberInsights });
      return enhancedApi;
    };
    Object.defineProperty(enhancedFactory, FACTORY_FLAG, { value: true });
    return enhancedFactory;
  }

  const existingFactory = window.P2K_CREATE_DASHBOARD_INSIGHTS;
  if (typeof existingFactory === "function") {
    window.P2K_CREATE_DASHBOARD_INSIGHTS = wrapFactory(existingFactory);
    return;
  }

  let storedFactory = existingFactory;
  try {
    Object.defineProperty(window, "P2K_CREATE_DASHBOARD_INSIGHTS", {
      configurable: true,
      enumerable: true,
      get() { return storedFactory; },
      set(value) { storedFactory = typeof value === "function" ? wrapFactory(value) : value; },
    });
  } catch (_) {
    // Very old browsers: poll briefly for the lazy Insights factory instead.
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      if (typeof window.P2K_CREATE_DASHBOARD_INSIGHTS === "function") {
        window.P2K_CREATE_DASHBOARD_INSIGHTS = wrapFactory(window.P2K_CREATE_DASHBOARD_INSIGHTS);
        window.clearInterval(timer);
      } else if (attempts >= 100) window.clearInterval(timer);
    }, 100);
  }
})();
