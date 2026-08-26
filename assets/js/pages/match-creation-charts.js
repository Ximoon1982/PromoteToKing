(() => {
  "use strict";

  const UPCOMING_DAYS = 15;
  const DEFAULT_REGISTRATION_ROWS = 5;
  const MATCH_BAR_COLOR = "#d98d18";
  const MATCH_LABEL_COLOR = "#ffd078";
  const BOARD_BAR_COLOR = "#9fd8ff";
  const BOARD_LABEL_COLOR = "#b6e3ff";
  const AVERAGE_COLOR = "#91e09a";

  const root = document.getElementById("p2kCreationAnalyzer");
  const resultsBox = document.getElementById("p2kCreationResults");
  if (!root || !resultsBox) return;

  let registrationExpanded = false;
  let upcomingChartMode = "matches";
  let scheduled = false;
  let applying = false;
  let observer = null;
  let chartOverlayTrigger = null;

  function escapeHTML(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function utcToday() {
    const now = new Date();
    return new Date(Date.UTC(
      now.getUTCFullYear(),
      now.getUTCMonth(),
      now.getUTCDate()
    ));
  }

  function dateKeyFromDate(date) {
    return [
      date.getUTCFullYear(),
      String(date.getUTCMonth() + 1).padStart(2, "0"),
      String(date.getUTCDate()).padStart(2, "0")
    ].join("-");
  }

  function upcomingDateKeys(numberOfDays = UPCOMING_DAYS) {
    const today = utcToday();
    const keys = [];
    for (let offset = 0; offset < numberOfDays; offset += 1) {
      const date = new Date(today);
      date.setUTCDate(today.getUTCDate() + offset);
      keys.push(dateKeyFromDate(date));
    }
    return keys;
  }

  function dateFromKey(key) {
    return new Date(`${key}T00:00:00Z`);
  }

  function formatDate(key) {
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
      day: "2-digit",
      month: "short",
      year: "numeric"
    }).format(dateFromKey(key));
  }

  function formatShortDate(key) {
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
      day: "2-digit",
      month: "short"
    }).format(dateFromKey(key));
  }

  function findSectionByTitle(test) {
    return [...root.querySelectorAll(".p2k-section")].find(section => {
      const title = section.querySelector(".p2k-section-title");
      return title && test(title.textContent.trim());
    }) || null;
  }

  function registrationSection() {
    return findSectionByTitle(title => title === "Registered matches by start date");
  }

  function startedChartSection() {
    return findSectionByTitle(title => title.startsWith("Started matches"));
  }

  function registrationRows(section) {
    return [...(section?.querySelectorAll("tbody tr") || [])];
  }

  function readUpcomingRegistrationData() {
    // v2.10.6.10: derive the chart from the same canonical records that feed
    // "Registered matches by start date". Never parse localized table text.
    const buckets = window.P2K_MATCH_CREATION_CHART_BUCKETS || {};
    const records = Array.isArray(buckets.registration) ? buckets.registration : [];
    const byDate = new Map();
    records.forEach(record => {
      const key = String(record?.dateKey || "");
      if (!key) return;
      const aggregate = byDate.get(key) || { matches: 0, boards: 0, unknownBoards: 0 };
      aggregate.matches += 1;
      const boards = Number(record?.boards);
      if (record?.boards === null || record?.boards === undefined || record?.boards === "" || !Number.isFinite(boards)) {
        aggregate.unknownBoards += 1;
      } else {
        aggregate.boards += boards;
      }
      byDate.set(key, aggregate);
    });

    const dateKeys = upcomingDateKeys();
    return {
      dateKeys,
      matchValues: dateKeys.map(key => byDate.get(key)?.matches || 0),
      boardValues: dateKeys.map(key => byDate.get(key)?.boards || 0),
      unknownBoardValues: dateKeys.map(key => byDate.get(key)?.unknownBoards || 0)
    };
  }

  function average(values) {
    if (!values.length) return 0;
    return values.reduce((sum, value) => sum + value, 0) / values.length;
  }

  function chartSVG(dateKeys, values, averageValue, mode, unknownValues = [], bucketCounts = []) {
    const maxValue = Math.max(1, ...values, averageValue);
    const tickStep = Math.max(1, Math.ceil(maxValue / 4));
    const axisMax = tickStep * 4;
    const unit = mode === "boards" ? "board" : "registration match";
    const barColor = mode === "boards" ? BOARD_BAR_COLOR : MATCH_BAR_COLOR;
    const labelColor = mode === "boards" ? BOARD_LABEL_COLOR : MATCH_LABEL_COLOR;

    const width = 960;
    const height = 340;
    const left = 52;
    const right = 18;
    const top = 24;
    const bottom = 58;
    const chartWidth = width - left - right;
    const chartHeight = height - top - bottom;
    const slotWidth = chartWidth / dateKeys.length;
    const barWidth = Math.max(8, slotWidth * .58);

    const gridLines = [];
    for (let index = 0; index <= 4; index += 1) {
      const value = tickStep * index;
      const y = top + chartHeight - (value / axisMax) * chartHeight;
      gridLines.push(`
        <line
          x1="${left}"
          y1="${y.toFixed(2)}"
          x2="${width - right}"
          y2="${y.toFixed(2)}"
          stroke="rgba(255,255,255,.12)"
          stroke-width="1"
        />
        <text
          x="${left - 8}"
          y="${(y + 4).toFixed(2)}"
          fill="#aaa198"
          font-size="11"
          text-anchor="end"
        >${value}</text>
      `);
    }

    const bars = dateKeys.map((key, index) => {
      const value = values[index];
      const bucketCount = Number(bucketCounts[index] || 0);
      const unknown = Number(unknownValues[index] || 0);
      const barHeight = (value / axisMax) * chartHeight;
      const x = left + index * slotWidth + (slotWidth - barWidth) / 2;
      const y = top + chartHeight - barHeight;
      const suffix = unknown > 0
        ? `; board count unavailable for ${unknown} match${unknown === 1 ? "" : "es"}`
        : "";

      return `
        <g
          class="${bucketCount > 0 ? "p2k-chart-bar" : ""}"
          data-chart-scope="registration"
          data-chart-date="${escapeHTML(key)}"
          data-chart-count="${bucketCount}"
          ${bucketCount > 0 ? 'role="button" tabindex="0"' : ""}
          aria-label="${escapeHTML(formatDate(key))}: ${bucketCount} corresponding match${bucketCount === 1 ? "" : "es"}${bucketCount > 0 ? ". Select to list matches." : ""}"
        >
          <title>${escapeHTML(formatDate(key))}: ${value} ${unit}${value === 1 ? "" : "s"}${escapeHTML(suffix)}${bucketCount > 0 ? "; select to list matches" : ""}</title>
          ${bucketCount > 0 ? `
            <rect
              class="p2k-chart-bar-hit"
              x="${(left + index * slotWidth).toFixed(2)}"
              y="${top}"
              width="${slotWidth.toFixed(2)}"
              height="${chartHeight.toFixed(2)}"
              fill="transparent"
            />
          ` : ""}
          <rect
            x="${x.toFixed(2)}"
            y="${y.toFixed(2)}"
            width="${barWidth.toFixed(2)}"
            height="${Math.max(0, barHeight).toFixed(2)}"
            rx="2"
            fill="${barColor}"
          />
          ${value > 0 ? `
            <text
              x="${(x + barWidth / 2).toFixed(2)}"
              y="${Math.max(top + 10, y - 5).toFixed(2)}"
              fill="${labelColor}"
              font-size="10"
              font-weight="700"
              text-anchor="middle"
            >${value}${unknown && mode === "boards" ? "+?" : ""}</text>
          ` : unknown && mode === "boards" ? `
            <text
              x="${(x + barWidth / 2).toFixed(2)}"
              y="${top + chartHeight - 6}"
              fill="${labelColor}"
              font-size="10"
              font-weight="700"
              text-anchor="middle"
            >?</text>
          ` : ""}
          <text
            x="${(x + barWidth / 2).toFixed(2)}"
            y="${height - 28}"
            fill="#aaa198"
            font-size="10"
            text-anchor="middle"
          >${escapeHTML(formatShortDate(key))}</text>
        </g>
      `;
    }).join("");

    const averageY = top + chartHeight - (averageValue / axisMax) * chartHeight;

    return `
      ${gridLines.join("")}
      <line
        x1="${left}"
        y1="${top + chartHeight}"
        x2="${width - right}"
        y2="${top + chartHeight}"
        stroke="rgba(255,255,255,.28)"
        stroke-width="1"
      />
      ${bars}
      <line
        x1="${left}"
        y1="${averageY.toFixed(2)}"
        x2="${width - right}"
        y2="${averageY.toFixed(2)}"
        stroke="${AVERAGE_COLOR}"
        stroke-width="2"
        stroke-dasharray="7 5"
      />
      <text
        x="${width - right - 4}"
        y="${Math.max(top + 12, averageY - 6).toFixed(2)}"
        fill="${AVERAGE_COLOR}"
        font-size="11"
        font-weight="700"
        text-anchor="end"
      >Average: ${averageValue.toFixed(2)}</text>
    `;
  }

  function updateUpcomingChart(section, data) {
    const values = upcomingChartMode === "boards"
      ? data.boardValues
      : data.matchValues;
    const unknownValues = upcomingChartMode === "boards"
      ? data.unknownBoardValues
      : [];
    const averageValue = average(values);
    const svg = section.querySelector(".p2k-chart");
    const averageLabel = section.querySelector(".p2k-chart-average");
    const swatch = section.querySelector(".p2k-swatch");

    if (svg) {
      svg.innerHTML = chartSVG(
        data.dateKeys,
        values,
        averageValue,
        upcomingChartMode,
        unknownValues,
        data.matchValues
      );
    }
    if (averageLabel) {
      averageLabel.textContent = upcomingChartMode === "boards"
        ? `Average: ${averageValue.toFixed(2)} boards per day`
        : `Average: ${averageValue.toFixed(2)} matches per day`;
      averageLabel.style.color = upcomingChartMode === "boards"
        ? BOARD_LABEL_COLOR
        : AVERAGE_COLOR;
    }
    if (swatch) {
      swatch.style.background = upcomingChartMode === "boards"
        ? BOARD_BAR_COLOR
        : MATCH_BAR_COLOR;
    }

    section.querySelectorAll("[data-upcoming-chart-mode]").forEach(button => {
      button.setAttribute(
        "aria-pressed",
        String(button.dataset.upcomingChartMode === upcomingChartMode)
      );
    });
  }

  function buildUpcomingChartSection(data) {
    const section = document.createElement("div");
    section.id = "p2kUpcomingRegistrationChart";
    section.className = "p2k-section p2k-upcoming-chart-section";
    section.innerHTML = `
      <div class="p2k-section-title-row">
        <div class="p2k-chart-heading">
          <div class="p2k-section-title">
            Registration matches — next ${UPCOMING_DAYS} days
          </div>
          <div class="p2k-chart-average"></div>
        </div>
        <div class="p2k-chart-toggle" role="group" aria-label="Upcoming registration chart data">
          <button
            class="p2k-chart-toggle-button"
            type="button"
            data-upcoming-chart-mode="matches"
            aria-pressed="true"
          >Matches</button>
          <button
            class="p2k-chart-toggle-button"
            type="button"
            data-upcoming-chart-mode="boards"
            aria-pressed="false"
          >Boards</button>
        </div>
      </div>
      <div class="p2k-chart-wrap">
        <svg
          class="p2k-chart"
          viewBox="0 0 960 340"
          role="img"
          aria-label="Registration matches starting during the upcoming 15 days"
        ></svg>
      </div>
      <div class="p2k-chart-legend">
        <span><i class="p2k-swatch"></i><span class="p2k-upcoming-legend-label">Daily total</span></span>
        <span><i class="p2k-average-line"></i>Daily average</span>
      </div>
      <div class="p2k-note">
        Includes registration matches whose scheduled start date falls between today and the next 14 days, in UTC.
      </div>
    `;

    section.querySelectorAll("[data-upcoming-chart-mode]").forEach(button => {
      button.addEventListener("click", () => {
        upcomingChartMode = button.dataset.upcomingChartMode || "matches";
        const legendLabel = section.querySelector(".p2k-upcoming-legend-label");
        if (legendLabel) {
          legendLabel.textContent = upcomingChartMode === "boards"
            ? "Boards"
            : "Matches";
        }
        updateUpcomingChart(section, data);
      });
    });

    updateUpcomingChart(section, data);
    return section;
  }

  function applyRegistrationCollapse(section) {
    const rows = registrationRows(section);
    if (!rows.length) return;

    rows.forEach((row, index) => {
      row.classList.toggle(
        "p2k-registration-extra-row",
        !registrationExpanded && index >= DEFAULT_REGISTRATION_ROWS
      );
    });

    const existingRow = section.querySelector(".p2k-registration-toggle-row");
    const originalTitle = section.querySelector(":scope > .p2k-section-title");
    let titleRow = existingRow;

    if (!titleRow && originalTitle) {
      titleRow = document.createElement("div");
      titleRow.className = "p2k-registration-toggle-row";
      originalTitle.before(titleRow);
      titleRow.appendChild(originalTitle);
    }

    if (!titleRow) return;

    let button = titleRow.querySelector("[data-registration-list-toggle]");
    if (rows.length <= DEFAULT_REGISTRATION_ROWS) {
      button?.remove();
      return;
    }

    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "p2k-small-button";
      button.dataset.registrationListToggle = "true";
      button.addEventListener("click", () => {
        registrationExpanded = !registrationExpanded;
        applyRegistrationCollapse(section);
      });
      titleRow.appendChild(button);
    }

    button.textContent = registrationExpanded
      ? "Show fewer dates"
      : `Show all ${rows.length} dates`;
    button.setAttribute("aria-expanded", String(registrationExpanded));
  }

  function styleStartedChart(section) {
    if (!section) return;

    section.querySelectorAll("[data-chart-mode]").forEach(button => {
      if (button.dataset.v19BoardColorBound === "true") return;
      button.dataset.v19BoardColorBound = "true";
      button.addEventListener("click", () => {
        window.setTimeout(() => styleStartedChart(section), 0);
      });
    });

    const boardButton = section.querySelector('[data-chart-mode="boards"]');
    const isBoardMode = boardButton?.getAttribute("aria-pressed") === "true";
    const barColor = isBoardMode ? BOARD_BAR_COLOR : MATCH_BAR_COLOR;
    const labelColor = isBoardMode ? BOARD_LABEL_COLOR : MATCH_LABEL_COLOR;

    section.querySelectorAll("svg rect:not(.p2k-chart-bar-hit)").forEach(rect => {
      rect.setAttribute("fill", barColor);
    });
    section.querySelectorAll('svg g > text[font-weight="700"][text-anchor="middle"]').forEach(text => {
      text.setAttribute("fill", labelColor);
    });

    const swatch = section.querySelector(".p2k-swatch");
    if (swatch) {
      swatch.style.background = barColor;
      swatch.classList.toggle("p2k-board-chart-swatch", isBoardMode);
    }

    const averageLabel = section.querySelector(".p2k-chart-average");
    if (averageLabel && isBoardMode) {
      averageLabel.style.color = BOARD_LABEL_COLOR;
    } else if (averageLabel) {
      averageLabel.style.color = AVERAGE_COLOR;
    }
  }


  function chartBucketRecords(scope, dateKey) {
    const buckets = window.P2K_MATCH_CREATION_CHART_BUCKETS || {};
    const records = Array.isArray(buckets[scope]) ? buckets[scope] : [];
    return records
      .filter(record => String(record?.dateKey || "") === String(dateKey || ""))
      .sort((recordA, recordB) =>
        Number(recordA?.startTime || 0) - Number(recordB?.startTime || 0) ||
        String(recordA?.name || "").localeCompare(String(recordB?.name || ""))
      );
  }

  function statusLabel(status) {
    if (status === "registered") return "Registration";
    if (status === "inProgress") return "In progress";
    if (status === "finished") return "Finished";
    return "Match";
  }

  function boardLabel(boards) {
    if (boards === null || boards === undefined || boards === "") return "—";
    const value = Number(boards);
    if (!Number.isFinite(value)) return "—";
    return String(value);
  }

  function startTimeLabel(timestamp) {
    const value = Number(timestamp);
    if (!Number.isFinite(value) || value <= 0) return "Start time unavailable";
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: "UTC",
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    }).format(new Date(value * 1000)) + " UTC";
  }

  function closeChartMatchOverlay(returnFocus = true) {
    const overlay = root.querySelector(".p2k-chart-match-overlay");
    overlay?.remove();
    if (returnFocus && chartOverlayTrigger?.focus) chartOverlayTrigger.focus();
    chartOverlayTrigger = null;
  }

  async function openChartMatchOverlay(trigger, { acquire = true } = {}) {
    const scope = String(trigger?.dataset?.chartScope || "");
    const dateKey = String(trigger?.dataset?.chartDate || "");
    const chartWrap = trigger?.closest?.(".p2k-chart-wrap");
    if (!chartWrap || !scope || !dateKey) return;

    closeChartMatchOverlay(false);
    chartOverlayTrigger = trigger;
    const records = chartBucketRecords(scope, dateKey);
    const knownBoards = records.reduce((sum, record) => {
      if (record?.boards === null || record?.boards === undefined || record?.boards === "") return sum;
      const boards = Number(record.boards);
      return Number.isFinite(boards) ? sum + boards : sum;
    }, 0);
    const unknownBoards = records.filter(record =>
      record?.boards === null || record?.boards === undefined || record?.boards === "" || !Number.isFinite(Number(record.boards))
    ).length;
    const missingDetail = records.filter(record => record?.detailState !== "loaded" || record?.boards === null).length;
    const scopeLabel = scope === "registration" ? "Registration matches" : "Started matches";
    const boardSummary = unknownBoards > 0
      ? `${knownBoards}${knownBoards ? "+" : ""}? boards`
      : `${knownBoards} board${knownBoards === 1 ? "" : "s"}`;

    const overlay = document.createElement("div");
    overlay.className = "p2k-chart-match-overlay";
    overlay.innerHTML = `
      <section class="p2k-chart-match-dialog" role="dialog" aria-modal="true" aria-labelledby="p2kChartMatchOverlayTitle">
        <div class="p2k-chart-match-dialog-header">
          <div>
            <div id="p2kChartMatchOverlayTitle" class="p2k-chart-match-dialog-title">${escapeHTML(scopeLabel)} — ${escapeHTML(formatDate(dateKey))}</div>
            <div class="p2k-chart-match-dialog-summary">${records.length} match${records.length === 1 ? "" : "es"} · ${escapeHTML(boardSummary)}</div>
            <div class="p2k-chart-match-dialog-summary" data-targeted-detail-progress aria-live="polite">${missingDetail && acquire ? `Loading board detail 0 / ${missingDetail} for this chart bucket only…` : missingDetail ? `${missingDetail} match detail request${missingDetail === 1 ? "" : "s"} still unavailable.` : "All contributing match details are cached."}</div>
          </div>
          <button class="p2k-chart-match-close" type="button" aria-label="Close match list">Close</button>
        </div>
        <div class="p2k-chart-match-table-wrap">
          <table class="p2k-chart-match-table">
            <thead><tr><th>Match</th><th>Opponent</th><th>Status</th><th>Boards</th></tr></thead>
            <tbody>
              ${records.length ? records.map(record => `
                <tr>
                  <td>${record.webUrl
                    ? `<a href="${escapeHTML(record.webUrl)}" target="_blank" rel="noopener" title="${escapeHTML(record.name)}">${escapeHTML(record.name)}</a>`
                    : `<strong title="${escapeHTML(record.name)}">${escapeHTML(record.name)}</strong>`
                  }<span>${escapeHTML(startTimeLabel(record.startTime))}</span></td>
                  <td title="${escapeHTML(record.opponentName)}">${escapeHTML(record.opponentName)}</td>
                  <td>${escapeHTML(statusLabel(record.status))}</td>
                  <td>${escapeHTML(boardLabel(record.boards))}</td>
                </tr>
              `).join("") : '<tr><td colspan="4">No matching records are available.</td></tr>'}
            </tbody>
          </table>
        </div>
      </section>`;

    chartWrap.appendChild(overlay);
    overlay.querySelector(".p2k-chart-match-close")?.addEventListener("click", () => closeChartMatchOverlay());
    overlay.addEventListener("click", event => {
      if (event.target === overlay) closeChartMatchOverlay();
    });
    overlay.querySelector(".p2k-chart-match-close")?.focus({ preventScroll: true });

    if (!acquire || missingDetail === 0 || typeof window.P2K_MATCH_CREATION_TARGETED_DETAIL?.load !== "function") return;
    const progress = overlay.querySelector("[data-targeted-detail-progress]");
    try {
      const result = await window.P2K_MATCH_CREATION_TARGETED_DETAIL.load(scope, dateKey, info => {
        if (progress?.isConnected) progress.textContent = `${info.message} · only contributing matches are requested.`;
      });
      if (progress?.isConnected) {
        progress.textContent = result.busy
          ? "Detailed analysis is already running; this bucket was not re-requested."
          : `Bucket refresh complete · ${result.loaded || 0} loaded · ${result.cached || 0} reused from cache · ${result.failed || 0} failed.`;
      }
      // renderAnalysis replaces the chart DOM. Reopen the same bucket on the newly
      // rendered bar without starting another request, so the board values are fresh.
      const freshTrigger = root.querySelector(`.p2k-chart-bar[data-chart-scope="${CSS.escape(scope)}"][data-chart-date="${CSS.escape(dateKey)}"]`);
      if (freshTrigger && !result.busy) openChartMatchOverlay(freshTrigger, { acquire: false });
    } catch (error) {
      if (progress?.isConnected) progress.textContent = `Targeted board-detail refresh failed: ${error?.message || error}`;
    }
  }

  root.addEventListener("click", event => {
    const trigger = event.target.closest?.(".p2k-chart-bar[data-chart-count]");
    if (trigger) openChartMatchOverlay(trigger);
  });

  root.addEventListener("keydown", event => {
    if (event.key === "Escape" && root.querySelector(".p2k-chart-match-overlay")) {
      event.preventDefault();
      closeChartMatchOverlay();
      return;
    }
    if (!['Enter', ' '].includes(event.key)) return;
    const trigger = event.target.closest?.(".p2k-chart-bar[data-chart-count]");
    if (!trigger) return;
    event.preventDefault();
    openChartMatchOverlay(trigger);
  });

  function applyPatch() {
    if (applying) return;
    applying = true;
    observer?.disconnect();
    try {
      const registration = registrationSection();
      const started = startedChartSection();

      if (registration) {
        const data = readUpcomingRegistrationData();
        let upcoming = document.getElementById("p2kUpcomingRegistrationChart");
        if (!upcoming && started) {
          upcoming = buildUpcomingChartSection(data);
          started.before(upcoming);
        } else if (upcoming) {
          updateUpcomingChart(upcoming, data);
        }
        applyRegistrationCollapse(registration);
      }

      styleStartedChart(started);
    } finally {
      observer?.observe(resultsBox, { childList: true, subtree: true });
      applying = false;
    }
  }

  function schedulePatch() {
    if (scheduled) return;
    scheduled = true;
    window.requestAnimationFrame(() => {
      scheduled = false;
      applyPatch();
    });
  }

  observer = new MutationObserver(schedulePatch);
  observer.observe(resultsBox, { childList: true, subtree: true });
  schedulePatch();
})();
