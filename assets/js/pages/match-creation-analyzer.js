/* Match Creation Analyzer compatibility bootstrap and current feature patches. */
(() => {
  "use strict";
  const baseURL = window.P2K_SITE_CONFIG?.legacySources?.matchCreation || "https://raw.githubusercontent.com/Ximoon1982/P2KMatchFinder/1eb2d6b717cc2de3cfeebc2300a41399d3cca4ed/MatchCreationAnalyzer_v1.htm";
  const patchCSS = "\n#p2kCreationAnalyzer .p2k-registration-extra-row {\n  display: none !important;\n}\n\n#p2kCreationAnalyzer .p2k-registration-toggle-row {\n  display: flex;\n  align-items: center;\n  justify-content: space-between;\n  gap: 12px;\n  margin-bottom: 9px;\n}\n\n#p2kCreationAnalyzer .p2k-registration-toggle-row .p2k-section-title {\n  margin: 0;\n}\n\n#p2kCreationAnalyzer .p2k-upcoming-chart-section {\n  border-color: rgba(159, 216, 255, .18);\n}\n\n#p2kCreationAnalyzer .p2k-upcoming-chart-section .p2k-chart-average {\n  color: #91e09a;\n}\n\n#p2kCreationAnalyzer .p2k-board-chart-swatch {\n  background: #9fd8ff !important;\n}\n\n@media (max-width: 680px) {\n  #p2kCreationAnalyzer .p2k-registration-toggle-row {\n    align-items: stretch;\n    flex-direction: column;\n  }\n\n  #p2kCreationAnalyzer .p2k-registration-toggle-row .p2k-small-button {\n    align-self: flex-start;\n  }\n}\n";
  const patchJS = "\n(() => {\n  \"use strict\";\n\n  const UPCOMING_DAYS = 15;\n  const DEFAULT_REGISTRATION_ROWS = 5;\n  const MATCH_BAR_COLOR = \"#d98d18\";\n  const MATCH_LABEL_COLOR = \"#ffd078\";\n  const BOARD_BAR_COLOR = \"#9fd8ff\";\n  const BOARD_LABEL_COLOR = \"#b6e3ff\";\n  const AVERAGE_COLOR = \"#91e09a\";\n\n  const root = document.getElementById(\"p2kCreationAnalyzer\");\n  const resultsBox = document.getElementById(\"p2kCreationResults\");\n  if (!root || !resultsBox) return;\n\n  let registrationExpanded = false;\n  let upcomingChartMode = \"matches\";\n  let scheduled = false;\n  let applying = false;\n  let observer = null;\n\n  const monthNumbers = {\n    Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5,\n    Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11\n  };\n\n  function escapeHTML(value) {\n    return String(value ?? \"\")\n      .replace(/&/g, \"&amp;\")\n      .replace(/</g, \"&lt;\")\n      .replace(/>/g, \"&gt;\")\n      .replace(/\"/g, \"&quot;\")\n      .replace(/'/g, \"&#039;\");\n  }\n\n  function utcToday() {\n    const now = new Date();\n    return new Date(Date.UTC(\n      now.getUTCFullYear(),\n      now.getUTCMonth(),\n      now.getUTCDate()\n    ));\n  }\n\n  function dateKeyFromDate(date) {\n    return [\n      date.getUTCFullYear(),\n      String(date.getUTCMonth() + 1).padStart(2, \"0\"),\n      String(date.getUTCDate()).padStart(2, \"0\")\n    ].join(\"-\");\n  }\n\n  function upcomingDateKeys(numberOfDays = UPCOMING_DAYS) {\n    const today = utcToday();\n    const keys = [];\n    for (let offset = 0; offset < numberOfDays; offset += 1) {\n      const date = new Date(today);\n      date.setUTCDate(today.getUTCDate() + offset);\n      keys.push(dateKeyFromDate(date));\n    }\n    return keys;\n  }\n\n  function dateFromKey(key) {\n    return new Date(`${key}T00:00:00Z`);\n  }\n\n  function formatDate(key) {\n    return new Intl.DateTimeFormat(\"en-GB\", {\n      timeZone: \"UTC\",\n      day: \"2-digit\",\n      month: \"short\",\n      year: \"numeric\"\n    }).format(dateFromKey(key));\n  }\n\n  function formatShortDate(key) {\n    return new Intl.DateTimeFormat(\"en-GB\", {\n      timeZone: \"UTC\",\n      day: \"2-digit\",\n      month: \"short\"\n    }).format(dateFromKey(key));\n  }\n\n  function parseDisplayedDate(text) {\n    const normalized = String(text || \"\")\n      .replace(/\\(today\\)/gi, \"\")\n      .trim();\n    const match = normalized.match(/(\\d{1,2})\\s+([A-Za-z]{3})\\s+(\\d{4})/);\n    if (!match || !(match[2] in monthNumbers)) return null;\n    const date = new Date(Date.UTC(\n      Number(match[3]),\n      monthNumbers[match[2]],\n      Number(match[1])\n    ));\n    return Number.isFinite(date.getTime()) ? dateKeyFromDate(date) : null;\n  }\n\n  function parseInteger(text) {\n    const match = String(text || \"\").replace(/,/g, \"\").match(/-?\\d+/);\n    return match ? Number(match[0]) : 0;\n  }\n\n  function parseBoardValue(text) {\n    const value = String(text || \"\").trim();\n    const match = value.replace(/,/g, \"\").match(/\\d+/);\n    return {\n      boards: match ? Number(match[0]) : 0,\n      unknown: /\\?|unavailable/i.test(value)\n    };\n  }\n\n  function findSectionByTitle(test) {\n    return [...root.querySelectorAll(\".p2k-section\")].find(section => {\n      const title = section.querySelector(\".p2k-section-title\");\n      return title && test(title.textContent.trim());\n    }) || null;\n  }\n\n  function registrationSection() {\n    return findSectionByTitle(title => title === \"Registered matches by start date\");\n  }\n\n  function startedChartSection() {\n    return findSectionByTitle(title => title.startsWith(\"Started matches\"));\n  }\n\n  function registrationRows(section) {\n    return [...(section?.querySelectorAll(\"tbody tr\") || [])];\n  }\n\n  function readUpcomingRegistrationData(section) {\n    const byDate = new Map();\n    registrationRows(section).forEach(row => {\n      const cells = row.querySelectorAll(\"td\");\n      if (cells.length < 2) return;\n      const key = parseDisplayedDate(cells[0].textContent);\n      if (!key) return;\n      const boardValue = parseBoardValue(cells[2]?.textContent || \"\");\n      byDate.set(key, {\n        matches: parseInteger(cells[1].textContent),\n        boards: boardValue.boards,\n        unknownBoards: boardValue.unknown ? 1 : 0\n      });\n    });\n\n    const dateKeys = upcomingDateKeys();\n    return {\n      dateKeys,\n      matchValues: dateKeys.map(key => byDate.get(key)?.matches || 0),\n      boardValues: dateKeys.map(key => byDate.get(key)?.boards || 0),\n      unknownBoardValues: dateKeys.map(key => byDate.get(key)?.unknownBoards || 0)\n    };\n  }\n\n  function average(values) {\n    if (!values.length) return 0;\n    return values.reduce((sum, value) => sum + value, 0) / values.length;\n  }\n\n  function chartSVG(dateKeys, values, averageValue, mode, unknownValues = []) {\n    const maxValue = Math.max(1, ...values, averageValue);\n    const tickStep = Math.max(1, Math.ceil(maxValue / 4));\n    const axisMax = tickStep * 4;\n    const unit = mode === \"boards\" ? \"board\" : \"registration match\";\n    const barColor = mode === \"boards\" ? BOARD_BAR_COLOR : MATCH_BAR_COLOR;\n    const labelColor = mode === \"boards\" ? BOARD_LABEL_COLOR : MATCH_LABEL_COLOR;\n\n    const width = 960;\n    const height = 340;\n    const left = 52;\n    const right = 18;\n    const top = 24;\n    const bottom = 58;\n    const chartWidth = width - left - right;\n    const chartHeight = height - top - bottom;\n    const slotWidth = chartWidth / dateKeys.length;\n    const barWidth = Math.max(8, slotWidth * .58);\n\n    const gridLines = [];\n    for (let index = 0; index <= 4; index += 1) {\n      const value = tickStep * index;\n      const y = top + chartHeight - (value / axisMax) * chartHeight;\n      gridLines.push(`\n        <line\n          x1=\"${left}\"\n          y1=\"${y.toFixed(2)}\"\n          x2=\"${width - right}\"\n          y2=\"${y.toFixed(2)}\"\n          stroke=\"rgba(255,255,255,.12)\"\n          stroke-width=\"1\"\n        />\n        <text\n          x=\"${left - 8}\"\n          y=\"${(y + 4).toFixed(2)}\"\n          fill=\"#aaa198\"\n          font-size=\"11\"\n          text-anchor=\"end\"\n        >${value}</text>\n      `);\n    }\n\n    const bars = dateKeys.map((key, index) => {\n      const value = values[index];\n      const unknown = Number(unknownValues[index] || 0);\n      const barHeight = (value / axisMax) * chartHeight;\n      const x = left + index * slotWidth + (slotWidth - barWidth) / 2;\n      const y = top + chartHeight - barHeight;\n      const suffix = unknown > 0\n        ? `; board count unavailable for ${unknown} match${unknown === 1 ? \"\" : \"es\"}`\n        : \"\";\n\n      return `\n        <g>\n          <title>${escapeHTML(formatDate(key))}: ${value} ${unit}${value === 1 ? \"\" : \"s\"}${escapeHTML(suffix)}</title>\n          <rect\n            x=\"${x.toFixed(2)}\"\n            y=\"${y.toFixed(2)}\"\n            width=\"${barWidth.toFixed(2)}\"\n            height=\"${Math.max(0, barHeight).toFixed(2)}\"\n            rx=\"2\"\n            fill=\"${barColor}\"\n          />\n          ${value > 0 ? `\n            <text\n              x=\"${(x + barWidth / 2).toFixed(2)}\"\n              y=\"${Math.max(top + 10, y - 5).toFixed(2)}\"\n              fill=\"${labelColor}\"\n              font-size=\"10\"\n              font-weight=\"700\"\n              text-anchor=\"middle\"\n            >${value}${unknown && mode === \"boards\" ? \"+?\" : \"\"}</text>\n          ` : unknown && mode === \"boards\" ? `\n            <text\n              x=\"${(x + barWidth / 2).toFixed(2)}\"\n              y=\"${top + chartHeight - 6}\"\n              fill=\"${labelColor}\"\n              font-size=\"10\"\n              font-weight=\"700\"\n              text-anchor=\"middle\"\n            >?</text>\n          ` : \"\"}\n          <text\n            x=\"${(x + barWidth / 2).toFixed(2)}\"\n            y=\"${height - 28}\"\n            fill=\"#aaa198\"\n            font-size=\"10\"\n            text-anchor=\"middle\"\n          >${escapeHTML(formatShortDate(key))}</text>\n        </g>\n      `;\n    }).join(\"\");\n\n    const averageY = top + chartHeight - (averageValue / axisMax) * chartHeight;\n\n    return `\n      ${gridLines.join(\"\")}\n      <line\n        x1=\"${left}\"\n        y1=\"${top + chartHeight}\"\n        x2=\"${width - right}\"\n        y2=\"${top + chartHeight}\"\n        stroke=\"rgba(255,255,255,.28)\"\n        stroke-width=\"1\"\n      />\n      ${bars}\n      <line\n        x1=\"${left}\"\n        y1=\"${averageY.toFixed(2)}\"\n        x2=\"${width - right}\"\n        y2=\"${averageY.toFixed(2)}\"\n        stroke=\"${AVERAGE_COLOR}\"\n        stroke-width=\"2\"\n        stroke-dasharray=\"7 5\"\n      />\n      <text\n        x=\"${width - right - 4}\"\n        y=\"${Math.max(top + 12, averageY - 6).toFixed(2)}\"\n        fill=\"${AVERAGE_COLOR}\"\n        font-size=\"11\"\n        font-weight=\"700\"\n        text-anchor=\"end\"\n      >Average: ${averageValue.toFixed(2)}</text>\n    `;\n  }\n\n  function updateUpcomingChart(section, data) {\n    const values = upcomingChartMode === \"boards\"\n      ? data.boardValues\n      : data.matchValues;\n    const unknownValues = upcomingChartMode === \"boards\"\n      ? data.unknownBoardValues\n      : [];\n    const averageValue = average(values);\n    const svg = section.querySelector(\".p2k-chart\");\n    const averageLabel = section.querySelector(\".p2k-chart-average\");\n    const swatch = section.querySelector(\".p2k-swatch\");\n\n    if (svg) {\n      svg.innerHTML = chartSVG(\n        data.dateKeys,\n        values,\n        averageValue,\n        upcomingChartMode,\n        unknownValues\n      );\n    }\n    if (averageLabel) {\n      averageLabel.textContent = upcomingChartMode === \"boards\"\n        ? `Average: ${averageValue.toFixed(2)} boards per day`\n        : `Average: ${averageValue.toFixed(2)} matches per day`;\n      averageLabel.style.color = upcomingChartMode === \"boards\"\n        ? BOARD_LABEL_COLOR\n        : AVERAGE_COLOR;\n    }\n    if (swatch) {\n      swatch.style.background = upcomingChartMode === \"boards\"\n        ? BOARD_BAR_COLOR\n        : MATCH_BAR_COLOR;\n    }\n\n    section.querySelectorAll(\"[data-upcoming-chart-mode]\").forEach(button => {\n      button.setAttribute(\n        \"aria-pressed\",\n        String(button.dataset.upcomingChartMode === upcomingChartMode)\n      );\n    });\n  }\n\n  function buildUpcomingChartSection(data) {\n    const section = document.createElement(\"div\");\n    section.id = \"p2kUpcomingRegistrationChart\";\n    section.className = \"p2k-section p2k-upcoming-chart-section\";\n    section.innerHTML = `\n      <div class=\"p2k-section-title-row\">\n        <div class=\"p2k-chart-heading\">\n          <div class=\"p2k-section-title\">\n            Registration matches \u2014 next ${UPCOMING_DAYS} days\n          </div>\n          <div class=\"p2k-chart-average\"></div>\n        </div>\n        <div class=\"p2k-chart-toggle\" role=\"group\" aria-label=\"Upcoming registration chart data\">\n          <button\n            class=\"p2k-chart-toggle-button\"\n            type=\"button\"\n            data-upcoming-chart-mode=\"matches\"\n            aria-pressed=\"true\"\n          >Matches</button>\n          <button\n            class=\"p2k-chart-toggle-button\"\n            type=\"button\"\n            data-upcoming-chart-mode=\"boards\"\n            aria-pressed=\"false\"\n          >Boards</button>\n        </div>\n      </div>\n      <div class=\"p2k-chart-wrap\">\n        <svg\n          class=\"p2k-chart\"\n          viewBox=\"0 0 960 340\"\n          role=\"img\"\n          aria-label=\"Registration matches starting during the upcoming 15 days\"\n        ></svg>\n      </div>\n      <div class=\"p2k-chart-legend\">\n        <span><i class=\"p2k-swatch\"></i><span class=\"p2k-upcoming-legend-label\">Daily total</span></span>\n        <span><i class=\"p2k-average-line\"></i>Daily average</span>\n      </div>\n      <div class=\"p2k-note\">\n        Includes registration matches whose scheduled start date falls between today and the next 14 days, in UTC.\n      </div>\n    `;\n\n    section.querySelectorAll(\"[data-upcoming-chart-mode]\").forEach(button => {\n      button.addEventListener(\"click\", () => {\n        upcomingChartMode = button.dataset.upcomingChartMode || \"matches\";\n        const legendLabel = section.querySelector(\".p2k-upcoming-legend-label\");\n        if (legendLabel) {\n          legendLabel.textContent = upcomingChartMode === \"boards\"\n            ? \"Boards\"\n            : \"Matches\";\n        }\n        updateUpcomingChart(section, data);\n      });\n    });\n\n    updateUpcomingChart(section, data);\n    return section;\n  }\n\n  function applyRegistrationCollapse(section) {\n    const rows = registrationRows(section);\n    if (!rows.length) return;\n\n    rows.forEach((row, index) => {\n      row.classList.toggle(\n        \"p2k-registration-extra-row\",\n        !registrationExpanded && index >= DEFAULT_REGISTRATION_ROWS\n      );\n    });\n\n    const existingRow = section.querySelector(\".p2k-registration-toggle-row\");\n    const originalTitle = section.querySelector(\":scope > .p2k-section-title\");\n    let titleRow = existingRow;\n\n    if (!titleRow && originalTitle) {\n      titleRow = document.createElement(\"div\");\n      titleRow.className = \"p2k-registration-toggle-row\";\n      originalTitle.before(titleRow);\n      titleRow.appendChild(originalTitle);\n    }\n\n    if (!titleRow) return;\n\n    let button = titleRow.querySelector(\"[data-registration-list-toggle]\");\n    if (rows.length <= DEFAULT_REGISTRATION_ROWS) {\n      button?.remove();\n      return;\n    }\n\n    if (!button) {\n      button = document.createElement(\"button\");\n      button.type = \"button\";\n      button.className = \"p2k-small-button\";\n      button.dataset.registrationListToggle = \"true\";\n      button.addEventListener(\"click\", () => {\n        registrationExpanded = !registrationExpanded;\n        applyRegistrationCollapse(section);\n      });\n      titleRow.appendChild(button);\n    }\n\n    button.textContent = registrationExpanded\n      ? \"Show fewer dates\"\n      : `Show all ${rows.length} dates`;\n    button.setAttribute(\"aria-expanded\", String(registrationExpanded));\n  }\n\n  function styleStartedChart(section) {\n    if (!section) return;\n\n    section.querySelectorAll(\"[data-chart-mode]\").forEach(button => {\n      if (button.dataset.v19BoardColorBound === \"true\") return;\n      button.dataset.v19BoardColorBound = \"true\";\n      button.addEventListener(\"click\", () => {\n        window.setTimeout(() => styleStartedChart(section), 0);\n      });\n    });\n\n    const boardButton = section.querySelector('[data-chart-mode=\"boards\"]');\n    const isBoardMode = boardButton?.getAttribute(\"aria-pressed\") === \"true\";\n    const barColor = isBoardMode ? BOARD_BAR_COLOR : MATCH_BAR_COLOR;\n    const labelColor = isBoardMode ? BOARD_LABEL_COLOR : MATCH_LABEL_COLOR;\n\n    section.querySelectorAll(\"svg rect\").forEach(rect => {\n      rect.setAttribute(\"fill\", barColor);\n    });\n    section.querySelectorAll('svg g > text[font-weight=\"700\"][text-anchor=\"middle\"]').forEach(text => {\n      text.setAttribute(\"fill\", labelColor);\n    });\n\n    const swatch = section.querySelector(\".p2k-swatch\");\n    if (swatch) {\n      swatch.style.background = barColor;\n      swatch.classList.toggle(\"p2k-board-chart-swatch\", isBoardMode);\n    }\n\n    const averageLabel = section.querySelector(\".p2k-chart-average\");\n    if (averageLabel && isBoardMode) {\n      averageLabel.style.color = BOARD_LABEL_COLOR;\n    } else if (averageLabel) {\n      averageLabel.style.color = AVERAGE_COLOR;\n    }\n  }\n\n  function applyPatch() {\n    if (applying) return;\n    applying = true;\n    observer?.disconnect();\n    try {\n      const registration = registrationSection();\n      const started = startedChartSection();\n\n      if (registration) {\n        const data = readUpcomingRegistrationData(registration);\n        let upcoming = document.getElementById(\"p2kUpcomingRegistrationChart\");\n        if (!upcoming && started) {\n          upcoming = buildUpcomingChartSection(data);\n          started.before(upcoming);\n        } else if (upcoming) {\n          updateUpcomingChart(upcoming, data);\n        }\n        applyRegistrationCollapse(registration);\n      }\n\n      styleStartedChart(started);\n    } finally {\n      observer?.observe(resultsBox, { childList: true, subtree: true });\n      applying = false;\n    }\n  }\n\n  function schedulePatch() {\n    if (scheduled) return;\n    scheduled = true;\n    window.requestAnimationFrame(() => {\n      scheduled = false;\n      applyPatch();\n    });\n  }\n\n  observer = new MutationObserver(schedulePatch);\n  observer.observe(resultsBox, { childList: true, subtree: true });\n  schedulePatch();\n})();\n";

  function p2kReplaceRequired(html, pattern, replacement, label) {
    const updated = html.replace(pattern, replacement);
    if (updated === html) {
      throw new Error(`Unable to install ${label}.`);
    }
    return updated;
  }

  function installTargetedStageAndScoringPatches(html) {
    html = p2kReplaceRequired(
      html,
      /securedDrawPoints \+= 2;/,
      "securedDrawPoints += 2 * record.boards;",
      "secured-draw board-scaled points"
    );

    html = p2kReplaceRequired(
      html,
      /async function loadDetailedRecords\(records, runId, resume = false\) \{\s*const plan = detailQueuePlan\(records\);\s*const orderedQueue = plan\.all;/,
      `async function loadDetailedRecords(records, runId, resume = false, targetStage = "all") {
        const plan = detailQueuePlan(records);
        const orderedQueue = targetStage === "registration"
          ? plan.registration
          : targetStage === "last30"
            ? [...plan.registration, ...plan.last30]
            : plan.all;`,
      "cumulative detailed-analysis target queue"
    );

    html = p2kReplaceRequired(
      html,
      /if \(!resume\) \{\s*records\.failed = 0;\s*records\.processed = 0;\s*records\.requested = orderedQueue\.length;\s*records\.registrationCheckpointRendered = false;\s*records\.recentCheckpointRendered = false;\s*\} else if \(!records\.requested\) \{\s*records\.requested = orderedQueue\.length;\s*\}/,
      `records.requested = orderedQueue.length;
        records.processed = orderedQueue.filter(
          record => record.detailState !== "pending"
        ).length;
        records.failed = orderedQueue.filter(
          record => record.detailState === "failed"
        ).length;
        if (!resume) {
          records.registrationCheckpointRendered = false;
          records.recentCheckpointRendered = false;
        }`,
      "target-stage progress counters"
    );

    html = p2kReplaceRequired(
      html,
      /async function runDetailedAnalysis\(\) \{/,
      `async function runDetailedAnalysis(targetStage = "all") {`,
      "target-stage detailed-analysis entry point"
    );

    html = p2kReplaceRequired(
      html,
      /const initialTotal = resume\s*\? currentRecords\.requested \|\| detailQueue\(currentRecords\)\.length\s*:\s*detailQueue\(currentRecords\)\.length;\s*const initialProcessed = resume \? currentRecords\.processed : 0;\s*\s*const initialPlan = detailQueuePlan\(currentRecords\);\s*const firstPendingRecord = initialPlan\.all\.find\(\s*record => record\.detailState === "pending"\s*\);\s*const initialStage = firstPendingRecord\s*\? progressStageForRecord\(firstPendingRecord, initialPlan\)\s*:\s*"remaining";/,
      `const initialPlan = detailQueuePlan(currentRecords);
        const initialQueue = targetStage === "registration"
          ? initialPlan.registration
          : targetStage === "last30"
            ? [...initialPlan.registration, ...initialPlan.last30]
            : initialPlan.all;
        const initialTotal = initialQueue.length;
        const initialProcessed = initialQueue.filter(
          record => record.detailState !== "pending"
        ).length;
        const firstPendingRecord = initialQueue.find(
          record => record.detailState === "pending"
        );
        const initialStage = firstPendingRecord
          ? progressStageForRecord(firstPendingRecord, initialPlan)
          : targetStage === "registration"
            ? "registration"
            : targetStage === "last30"
              ? "last30"
              : "remaining";`,
      "target-stage initial progress"
    );

    html = p2kReplaceRequired(
      html,
      /await loadDetailedRecords\(currentRecords, runId, resume\);\s*throwIfCancelled\(runId\);/,
      `await loadDetailedRecords(currentRecords, runId, resume, targetStage);
          throwIfCancelled(runId);

          if (targetStage !== "all") {
            const completedPlan = detailQueuePlan(currentRecords);
            isDetailedAnalysisPaused = completedPlan.all.some(
              record => record.detailState === "pending"
            );
            updateProgressStages(completedPlan, null, true);
            const completedPhase = targetStage === "registration"
              ? "registrationComplete"
              : "recentComplete";
            renderAnalysis(currentRecords, { phase: completedPhase });
            const completedLabel = targetStage === "registration"
              ? "Step 1 — Registration"
              : "Step 2 — Last 30 days";
            setStatus(
              completedLabel + " analysis complete. Loaded data is retained; choose another step or Resume analyzis to continue.",
              "success"
            );
            return;
          }`,
      "target-stage automatic stop"
    );

    html = p2kReplaceRequired(
      html,
      /analyzeButton\.addEventListener\("click", runDetailedAnalysis\);\s*cancelButton\.addEventListener\("click", cancelDetailedAnalysis\);/,
      `function requestedStageTarget(stageName) {
        if (stageName === "registration") return "registration";
        if (stageName === "last30") return "last30";
        return "all";
      }

      progressStageElements.forEach(stageElement => {
        stageElement.setAttribute("role", "button");
        stageElement.setAttribute("tabindex", "0");
        stageElement.setAttribute(
          "title",
          "Analyze cumulatively through this step and stop when it is complete"
        );
        const activate = () => {
          if (isDetailedAnalysisRunning) return;
          void runDetailedAnalysis(
            requestedStageTarget(stageElement.dataset.progressStage)
          );
        };
        stageElement.addEventListener("click", activate);
        stageElement.addEventListener("keydown", event => {
          if (event.key !== "Enter" && event.key !== " ") return;
          event.preventDefault();
          activate();
        });
      });

      analyzeButton.addEventListener("click", () => {
        void runDetailedAnalysis("all");
      });
      cancelButton.addEventListener("click", cancelDetailedAnalysis);`,
      "clickable cumulative analysis stages"
    );

    return html;
  }

  async function loadAnalyzer() {
    const response = await fetch(baseURL, { cache: "no-store" });
    if (!response.ok) {
      throw new Error(`Unable to load base analyzer: HTTP ${response.status}`);
    }

    let html = await response.text();
    html = html.replace(
      /<!--\s*Version 18:[\s\S]*?-->/i,
      "<!-- Version 22: version 21 plus cumulative clickable analysis stages and board-scaled secured-draw points -->"
    );

    const priorityHelper = `
      function p2kCreationPrioritySegment(values) {
        return window.p2kPrioritizeRecords
          ? window.p2kPrioritizeRecords(values)
          : values;
      }
    `;
    html = html.replace(
      /function detailQueuePlan\(records\)\s*\{/,
      `${priorityHelper}\n      function detailQueuePlan(records) {`
    );
    html = html.replace(
      /const registration = uniqueSegment\(records\.registered\);/,
      "const registration = p2kCreationPrioritySegment(uniqueSegment(records.registered));"
    );
    html = html.replace(
      /const last30 = uniqueSegment\(startedLast30Days\);/,
      "const last30 = p2kCreationPrioritySegment(uniqueSegment(startedLast30Days));"
    );
    html = html.replace(
      /const remaining = uniqueSegment\(olderOrUndatedInProgress\);/,
      "const remaining = p2kCreationPrioritySegment(uniqueSegment(olderOrUndatedInProgress));"
    );

    html = installTargetedStageAndScoringPatches(html);

    const injection =
      `<style id="p2kCreationV20Styles">${patchCSS}</style>` +
      `<scr` + `ipt id="p2kCreationV20Patch">${patchJS}<\/scr` + `ipt>`;

    if (/<\/body>/i.test(html)) {
      html = html.replace(/<\/body>/i, `${injection}</body>`);
    } else {
      html += injection;
    }

    document.open();
    document.write(html);
    document.close();
  }

  loadAnalyzer().catch(error => {
    console.error(error);
    const status = document.getElementById("p2kV20LoaderStatus");
    if (status) {
      status.textContent = `Unable to load the analyzer: ${error.message || error}`;
      status.style.color = "#ff8b79";
    }
  });
})();
