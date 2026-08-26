(() => {
  "use strict";

  const NS = "http://www.w3.org/2000/svg";
  const state = {
    match: "both",
    chess: "both",
    top: "all",
    coverage: "0",
    color: "log",
    views: new Map()
  };

  const esc = value => String(value ?? "").replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);
  const fmt = value => Number(value || 0).toLocaleString("en-GB");
  const svg = (tag, attributes = {}) => {
    const node = document.createElementNS(NS, tag);
    Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };

  const normalizedCache = new WeakMap();

  function normalizeRows(data) {
    if (!data || typeof data !== "object") return [];
    const cached = normalizedCache.get(data);
    if (cached) return cached;
    const source = Array.isArray(data.rows) ? data.rows : [];
    const opponents = Array.isArray(data.opponents) ? data.opponents : [];
    const compact = Number(data.format) === 2;
    const rows = source.map(row => {
      if (compact && Array.isArray(row)) {
        const boards = Math.max(1, Number(row[0]) || 1);
        const ratedBoards = Math.max(0, Number(row[1]) || 0);
        const opponent = opponents[Math.max(0, Number(row[6]) || 0)] || {};
        const p2k = Number(row[4]) || 0, other = Number(row[5]) || 0;
        return {
          boards,
          rated_boards: ratedBoards,
          rated_coverage_percent: 100 * ratedBoards / boards,
          match_type: Number(row[2]) === 1 ? "league" : "friendly",
          chess_type: Number(row[3]) === 2 ? "chess960" : (Number(row[3]) === 1 ? "classical" : "unknown"),
          p2k_avg_rating: p2k,
          opponent_avg_rating: other,
          avg_rating_delta: p2k - other,
          opponent_slug: String(opponent.slug || ""),
          opponent_name: String(opponent.name || opponent.slug || "Unknown opponent"),
          _boardLog: Math.log10(boards)
        };
      }
      const boards = Math.max(1, Number(row?.boards) || 1);
      return { ...row, _boardLog: Math.log10(boards) };
    });
    normalizedCache.set(data, rows);
    return rows;
  }

  function baseFiltered(rows) {
    const minimumCoverage = Number(state.coverage || 0);
    return rows.filter(row =>
      (state.match === "both" || row.match_type === state.match) &&
      (state.chess === "both" || row.chess_type === state.chess) &&
      Number(row.rated_coverage_percent || 0) >= minimumCoverage
    );
  }

  function opponentKey(row) {
    return String(row.opponent_slug || row.opponent_name || "unknown-opponent").trim().toLowerCase();
  }

  function applyTopOpponents(rows) {
    if (state.top === "all") return rows;
    const limit = Math.max(1, Number(state.top) || 0);
    const counts = new Map();
    for (const row of rows) {
      const key = opponentKey(row);
      const entry = counts.get(key) || { key, name: row.opponent_name || row.opponent_slug || "Unknown opponent", count: 0 };
      entry.count += 1;
      counts.set(key, entry);
    }
    const allowed = new Set([...counts.values()]
      .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
      .slice(0, limit)
      .map(entry => entry.key));
    return rows.filter(row => allowed.has(opponentKey(row)));
  }

  function filtered(rows) {
    return applyTopOpponents(baseFiltered(rows));
  }

  function includedOpponents(rows) {
    const counts = new Map();
    for (const row of rows) {
      const key = opponentKey(row);
      const entry = counts.get(key) || { name: row.opponent_name || row.opponent_slug || "Unknown opponent", count: 0 };
      entry.count += 1;
      counts.set(key, entry);
    }
    return [...counts.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
  }

  function metrics(rows, opponentCount = null) {
    const deltas = rows.map(row => Number(row.avg_rating_delta));
    const boards = rows.map(row => Number(row.boards)).sort((a, b) => a - b);
    const abs = deltas.map(Math.abs).sort((a, b) => a - b);
    const coverage = rows.map(row => Number(row.rated_coverage_percent || 0));
    const median = values => values.length ? values[Math.floor(values.length / 2)] : 0;
    const mean = values => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
    return {
      n: rows.length,
      opponents: opponentCount === null ? includedOpponents(rows).length : opponentCount,
      boards: median(boards),
      mean: mean(deltas),
      mad: median(abs),
      coverage: mean(coverage),
      p50: rows.length ? 100 * deltas.filter(value => Math.abs(value) <= 50).length / rows.length : 0,
      p100: rows.length ? 100 * deltas.filter(value => Math.abs(value) <= 100).length / rows.length : 0
    };
  }

  function fullDomain(rows, kind) {
    if (!rows.length) return kind === 1 ? [Math.log10(1), Math.log10(1000), -400, 400] : [800, 2400, 800, 2400];
    if (kind === 1) {
      const xs = rows.map(row => (Number.isFinite(row._boardLog) ? row._boardLog : Math.log10(Math.max(1, Number(row.boards)))));
      const ys = rows.map(row => Number(row.avg_rating_delta));
      return [Math.min(...xs), Math.max(...xs), Math.min(-100, ...ys), Math.max(100, ...ys)];
    }
    const xs = rows.map(row => Number(row.p2k_avg_rating));
    const ys = rows.map(row => Number(row.opponent_avg_rating));
    const low = Math.min(...xs, ...ys);
    const high = Math.max(...xs, ...ys);
    return [low, high, low, high];
  }

  function heat(host, rows, kind) {
    host.innerHTML = "";
    const width = Math.max(620, host.clientWidth || 900);
    const height = 520;
    const margin = { left: 68, right: 116, top: 28, bottom: 60 };
    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;
    const key = host.id;
    let domain = state.views.get(key) || fullDomain(rows, kind);
    if (domain[1] <= domain[0]) domain[1] = domain[0] + 1;
    if (domain[3] <= domain[2]) domain[3] = domain[2] + 1;

    const root = svg("svg", { viewBox: `0 0 ${width} ${height}`, class: "ci-balance-svg ci-zoned-density-heatmap", role: "img" });
    const plot = svg("g");
    root.append(plot);
    host.append(root);

    const X = value => margin.left + (value - domain[0]) / (domain[1] - domain[0]) * innerWidth;
    const Y = value => margin.top + innerHeight - (value - domain[2]) / (domain[3] - domain[2]) * innerHeight;
    const visible = rows.filter(row => {
      const x = kind === 1 ? (Number.isFinite(row._boardLog) ? row._boardLog : Math.log10(Math.max(1, Number(row.boards)))) : Number(row.p2k_avg_rating);
      const y = kind === 1 ? Number(row.avg_rating_delta) : Number(row.opponent_avg_rating);
      return x >= domain[0] && x <= domain[1] && y >= domain[2] && y <= domain[3];
    });

    // v2.10.6 Zoned Density Heatmap: half the former visual bin size,
    // one light Gaussian pass, bilinear interpolation, then discrete density zones. For match size,
    // x is already logarithmic, so both binning and smoothing occur in log space.
    const nx = Math.max(36, Math.min(110, Math.round(innerWidth / 9.5)));
    const ny = Math.max(32, Math.min(90, Math.round(innerHeight / 8)));
    const raw = Array.from({ length: ny }, () => Array.from({ length: nx }, () => ({ n: 0, deltaSum: 0, coverageSum: 0 })));
    for (const row of visible) {
      const x = kind === 1 ? (Number.isFinite(row._boardLog) ? row._boardLog : Math.log10(Math.max(1, Number(row.boards)))) : Number(row.p2k_avg_rating);
      const y = kind === 1 ? Number(row.avg_rating_delta) : Number(row.opponent_avg_rating);
      const ix = Math.min(nx - 1, Math.max(0, Math.floor((x - domain[0]) / (domain[1] - domain[0]) * nx)));
      const iy = Math.min(ny - 1, Math.max(0, Math.floor((y - domain[2]) / (domain[3] - domain[2]) * ny)));
      const bin = raw[iy][ix];
      bin.n += 1;
      bin.deltaSum += Number(row.avg_rating_delta) || 0;
      bin.coverageSum += Number(row.rated_coverage_percent || 0);
    }

    const kernel = [[1,2,1],[2,4,2],[1,2,1]];
    const smooth = Array.from({ length: ny }, () => Array(nx).fill(0));
    let maximum = 0;
    for (let iy = 0; iy < ny; iy += 1) {
      for (let ix = 0; ix < nx; ix += 1) {
        let weighted = 0, weight = 0;
        for (let ky = -1; ky <= 1; ky += 1) {
          for (let kx = -1; kx <= 1; kx += 1) {
            const sy = iy + ky, sx = ix + kx;
            if (sy < 0 || sy >= ny || sx < 0 || sx >= nx) continue;
            const w = kernel[ky + 1][kx + 1];
            weighted += raw[sy][sx].n * w;
            weight += w;
          }
        }
        const value = weight ? weighted / weight : 0;
        smooth[iy][ix] = value;
        maximum = Math.max(maximum, value);
      }
    }

    const zonePalette = [
      { label: "Very low", color: "#163c9a" },
      { label: "Low", color: "#22b7e6" },
      { label: "Medium", color: "#5bcf6a" },
      { label: "High", color: "#f3df46" },
      { label: "Very high", color: "#f08a28" },
      { label: "Peak", color: "#d92525" }
    ];
    // Intensity is still selectable as linear/log, but colors are deliberately
    // quantized to zones rather than interpolated, avoiding the old pixel/noise look.
    const normalized = value => {
      if (maximum <= 0 || value <= 0) return 0;
      if (maximum <= 1) return Math.min(1, value / maximum);
      return state.color === "linear" ? value / maximum : Math.log1p(value) / Math.log1p(maximum);
    };
    const zoneFor = value => {
      const t = normalized(value);
      if (t < .035) return -1;
      if (t < .10) return 0;
      if (t < .22) return 1;
      if (t < .38) return 2;
      if (t < .58) return 3;
      if (t < .78) return 4;
      return 5;
    };

    // Preserve the proof-of-concept smoothing parameters exactly: half-size bins
    // plus ONE light 3x3 Gaussian pass. The earlier v2.10.5.4 implementation
    // then painted each smoothed bin as a rectangle, which reintroduced a blocky
    // look. Interpolate the already-smoothed field continuously between bin
    // centres and quantize only the interpolated value into the SAME six zones.
    // A modest supersampled raster gives contour-like boundaries without adding
    // another blur/smoothing pass or changing the palette/thresholds.
    const bilinearDensity = (gx, gy) => {
      const x = Math.min(nx - 1, Math.max(0, gx));
      const y = Math.min(ny - 1, Math.max(0, gy));
      const x0 = Math.floor(x), y0 = Math.floor(y);
      const x1 = Math.min(nx - 1, x0 + 1), y1 = Math.min(ny - 1, y0 + 1);
      const tx = x - x0, ty = y - y0;
      const a = smooth[y0][x0] * (1 - tx) + smooth[y0][x1] * tx;
      const b = smooth[y1][x0] * (1 - tx) + smooth[y1][x1] * tx;
      return a * (1 - ty) + b * ty;
    };
    const rasterScale = 1.6;
    const rasterWidth = Math.max(640, Math.min(1200, Math.round(innerWidth * rasterScale)));
    const rasterHeight = Math.max(360, Math.min(760, Math.round(innerHeight * rasterScale)));
    const densityCanvas = document.createElement("canvas");
    densityCanvas.width = rasterWidth; densityCanvas.height = rasterHeight;
    const densityContext = densityCanvas.getContext("2d", { alpha: true });
    const densityImage = densityContext.createImageData(rasterWidth, rasterHeight);
    const rgba = densityImage.data;
    const zoneRgb = zonePalette.map(entry => {
      const hex = entry.color.slice(1);
      return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
    });
    for (let py = 0; py < rasterHeight; py += 1) {
      // smooth[] is indexed bottom-to-top in data space; canvas is top-to-bottom.
      const gy = (1 - (py + .5) / rasterHeight) * ny - .5;
      for (let px = 0; px < rasterWidth; px += 1) {
        const gx = ((px + .5) / rasterWidth) * nx - .5;
        const zone = zoneFor(bilinearDensity(gx, gy));
        if (zone < 0) continue;
        const offset = (py * rasterWidth + px) * 4;
        const rgb = zoneRgb[zone];
        rgba[offset] = rgb[0]; rgba[offset + 1] = rgb[1]; rgba[offset + 2] = rgb[2]; rgba[offset + 3] = 255;
      }
    }
    densityContext.putImageData(densityImage, 0, 0);
    const raster = svg("image", {
      x: margin.left, y: margin.top, width: innerWidth, height: innerHeight,
      preserveAspectRatio: "none", class: "ci-density-raster"
    });
    const densityHref = densityCanvas.toDataURL("image/png");
    raster.setAttributeNS("http://www.w3.org/1999/xlink", "href", densityHref);
    raster.setAttribute("href", densityHref);
    plot.append(raster);

    if (kind === 1 && domain[2] <= 0 && domain[3] >= 0) {
      plot.append(svg("line", { x1: margin.left, x2: margin.left + innerWidth, y1: Y(0), y2: Y(0), class: "ci-balance-ref" }));
    }
    if (kind === 2) {
      const low = Math.max(domain[0], domain[2]), high = Math.min(domain[1], domain[3]);
      if (low < high) plot.append(svg("line", { x1: X(low), x2: X(high), y1: Y(low), y2: Y(high), class: "ci-balance-ref" }));
    }

    const ticks = 5;
    for (let index = 0; index <= ticks; index += 1) {
      const xv = domain[0] + (domain[1] - domain[0]) * index / ticks;
      const yv = domain[2] + (domain[3] - domain[2]) * index / ticks;
      const tx = svg("text", { x: X(xv), y: height - 31, "text-anchor": "middle", class: "ci-balance-axis" });
      const ty = svg("text", { x: 58, y: Y(yv) + 4, "text-anchor": "end", class: "ci-balance-axis" });
      tx.textContent = kind === 1 ? String(Math.max(1, Math.round(10 ** xv))) : String(Math.round(xv));
      ty.textContent = String(Math.round(yv));
      root.append(tx, ty);
    }
    const xLabel = svg("text", { x: margin.left + innerWidth / 2, y: height - 8, "text-anchor": "middle", class: "ci-balance-label" });
    const yLabel = svg("text", { x: 15, y: margin.top + innerHeight / 2, transform: `rotate(-90 15 ${margin.top + innerHeight / 2})`, "text-anchor": "middle", class: "ci-balance-label" });
    xLabel.textContent = kind === 1 ? "Boards per match · logarithmic scale" : "Average P2K strength";
    yLabel.textContent = kind === 1 ? "Average rating difference · P2K − opponent" : "Average opponent strength";
    root.append(xLabel, yLabel);

    const legendX = margin.left + innerWidth + 18;
    const legendTitle = svg("text", { x: legendX, y: margin.top + 4, class: "ci-balance-legend-title" });
    legendTitle.textContent = "Match count";
    root.append(legendTitle);
    [...zonePalette].reverse().forEach((entry, index) => {
      const y = margin.top + 22 + index * 31;
      root.append(svg("rect", { x: legendX, y, width: 18, height: 18, rx: 3, fill: entry.color, class: "ci-balance-zone-swatch" }));
      const label = svg("text", { x: legendX + 25, y: y + 13, class: "ci-balance-legend-label" });
      label.textContent = entry.label;
      root.append(label);
    });
    root.append(svg("rect", { x: legendX, y: margin.top + 22 + zonePalette.length * 31, width: 18, height: 18, rx: 3, fill: "#17191e", class: "ci-balance-zone-swatch" }));
    const noneLabel = svg("text", { x: legendX + 25, y: margin.top + 35 + zonePalette.length * 31, class: "ci-balance-legend-label" });
    noneLabel.textContent = "None";
    root.append(noneLabel);

    const tip = document.createElement("div");
    tip.className = "ci-balance-tip";
    tip.hidden = true;
    host.append(tip);

    const overlay = svg("rect", { x: margin.left, y: margin.top, width: innerWidth, height: innerHeight, fill: "transparent", class: "ci-balance-overlay" });
    const selection = svg("rect", { class: "ci-balance-selection", visibility: "hidden" });
    root.append(selection, overlay);
    let drag = null, pinch = null;
    const position = event => {
      const bounds = root.getBoundingClientRect();
      return [(event.clientX - bounds.left) / bounds.width * width, (event.clientY - bounds.top) / bounds.height * height];
    };
    const toData = (px, py) => [
      domain[0] + (px - margin.left) / innerWidth * (domain[1] - domain[0]),
      domain[3] - (py - margin.top) / innerHeight * (domain[3] - domain[2])
    ];
    const redraw = () => { state.views.set(key, domain); heat(host, rows, kind); };
    const showTip = event => {
      if (drag || event.buttons) { tip.hidden = true; return; }
      const [px, py] = position(event);
      if (px < margin.left || px > margin.left + innerWidth || py < margin.top || py > margin.top + innerHeight) { tip.hidden = true; return; }
      const ix = Math.min(nx - 1, Math.max(0, Math.floor((px - margin.left) / innerWidth * nx)));
      const iyTop = Math.min(ny - 1, Math.max(0, Math.floor((py - margin.top) / innerHeight * ny)));
      const iy = ny - 1 - iyTop;
      const bin = raw[iy][ix];
      const zone = zoneFor(smooth[iy][ix]);
      if (zone < 0 && !bin.n) { tip.hidden = true; return; }
      const x0 = domain[0] + ix * (domain[1] - domain[0]) / nx;
      const x1 = domain[0] + (ix + 1) * (domain[1] - domain[0]) / nx;
      const y0 = domain[2] + iy * (domain[3] - domain[2]) / ny;
      const y1 = domain[2] + (iy + 1) * (domain[3] - domain[2]) / ny;
      const meanDelta = bin.n ? bin.deltaSum / bin.n : 0;
      const meanCoverage = bin.n ? bin.coverageSum / bin.n : 0;
      const zoneLabel = zone >= 0 ? zonePalette[zone].label : "None";
      tip.innerHTML = kind === 1
        ? `<b>${bin.n} raw matches · ${zoneLabel} zone</b><br>Boards ${Math.round(10 ** x0)}–${Math.round(10 ** x1)}<br>Rating Δ ${Math.round(y0)} to ${Math.round(y1)}<br>Mean Δ ${meanDelta.toFixed(1)}<br>Rated-board coverage ${meanCoverage.toFixed(1)}%`
        : `<b>${bin.n} raw matches · ${zoneLabel} zone</b><br>P2K ${Math.round(x0)}–${Math.round(x1)}<br>Opponent ${Math.round(y0)}–${Math.round(y1)}<br>Mean advantage ${meanDelta.toFixed(1)}<br>Rated-board coverage ${meanCoverage.toFixed(1)}%`;
      const bounds = host.getBoundingClientRect();
      tip.style.left = `${Math.min(Math.max(8, event.clientX - bounds.left + 12), Math.max(8, bounds.width - 240))}px`;
      tip.style.top = `${Math.max(8, event.clientY - bounds.top + 12)}px`;
      tip.hidden = false;
    };
    overlay.addEventListener("pointermove", showTip);
    overlay.addEventListener("pointerleave", () => { tip.hidden = true; });

    overlay.addEventListener("wheel", event => {
      event.preventDefault(); tip.hidden = true;
      const [px, py] = position(event), [cx, cy] = toData(px, py), factor = event.deltaY > 0 ? 1.22 : .82;
      domain = [cx + (domain[0] - cx) * factor, cx + (domain[1] - cx) * factor, cy + (domain[2] - cy) * factor, cy + (domain[3] - cy) * factor];
      redraw();
    }, { passive: false });
    overlay.addEventListener("pointerdown", event => {
      if (event.pointerType === "touch") return;
      tip.hidden = true;
      const [px, py] = position(event);
      drag = { x: px, y: py, start: domain.slice(), box: event.shiftKey };
      overlay.setPointerCapture(event.pointerId);
    });
    overlay.addEventListener("pointermove", event => {
      if (!drag) return;
      const [px, py] = position(event);
      if (drag.box) {
        selection.setAttribute("visibility", "visible");
        selection.setAttribute("x", Math.min(drag.x, px)); selection.setAttribute("y", Math.min(drag.y, py));
        selection.setAttribute("width", Math.abs(px - drag.x)); selection.setAttribute("height", Math.abs(py - drag.y));
      } else {
        const dx = (px - drag.x) / innerWidth * (drag.start[1] - drag.start[0]);
        const dy = (py - drag.y) / innerHeight * (drag.start[3] - drag.start[2]);
        domain = [drag.start[0] - dx, drag.start[1] - dx, drag.start[2] + dy, drag.start[3] + dy];
      }
    });
    overlay.addEventListener("pointerup", event => {
      if (!drag) return;
      const [px, py] = position(event), gesture = drag;
      drag = null; selection.setAttribute("visibility", "hidden");
      if (gesture.box && Math.abs(px - gesture.x) > 15 && Math.abs(py - gesture.y) > 15) {
        const a = toData(Math.min(gesture.x, px), Math.max(gesture.y, py));
        const b = toData(Math.max(gesture.x, px), Math.min(gesture.y, py));
        domain = [a[0], b[0], a[1], b[1]];
      }
      redraw();
    });

    const touchDistance = touches => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);
    overlay.style.touchAction = "none";
    overlay.addEventListener("touchstart", event => {
      if (event.touches.length !== 2) return;
      event.preventDefault();
      const bounds = root.getBoundingClientRect();
      const cx = (event.touches[0].clientX + event.touches[1].clientX) / 2;
      const cy = (event.touches[0].clientY + event.touches[1].clientY) / 2;
      pinch = { distance: Math.max(1, touchDistance(event.touches)), cx: (cx - bounds.left) / bounds.width * width, cy: (cy - bounds.top) / bounds.height * height, start: domain.slice() };
    }, { passive: false });
    overlay.addEventListener("touchmove", event => {
      if (!pinch || event.touches.length !== 2) return;
      event.preventDefault();
      pinch.current = Math.max(1, touchDistance(event.touches));
    }, { passive: false });
    overlay.addEventListener("touchend", event => {
      if (!pinch || event.touches.length >= 2) return;
      const gesture = pinch; pinch = null;
      const [cx, cy] = toData(gesture.cx, gesture.cy);
      const factor = Math.max(.25, Math.min(4, gesture.distance / Math.max(1, gesture.current || gesture.distance)));
      domain = [cx + (gesture.start[0] - cx) * factor, cx + (gesture.start[1] - cx) * factor, cy + (gesture.start[2] - cy) * factor, cy + (gesture.start[3] - cy) * factor];
      redraw();
    }, { passive: true });

    host._p2kResetZoom = () => { state.views.delete(key); heat(host, rows, kind); };
  }

  function render(root, data) {
    const allRows = normalizeRows(data);
    const rows = filtered(allRows);
    const opponents = includedOpponents(rows);
    const summary = metrics(rows, opponents.length);
    const coverage = data?.coverage || {};

    root.innerHTML = `
      <article class="ci-card">
        <h3>Opponent Balance Analyzer · all matches</h3>
        <p>One aggregate heatmap set for the complete selected match population. Both team averages use the same valid rated board positions. Matches without paired-board provenance stay omitted until authoritative <code>sync_match</code> revalidation. Historical coverage grows through the Administration → Green Team Points → Historical heatmap backfill lane; the Green Accelerator can process that known-match backfill in parallel. Current paired-match coverage is <strong>${Number(coverage.paired_match_percent || 0).toFixed(1)}%</strong> (${fmt(coverage.paired_rating_matches)} / ${fmt(coverage.all_matches ?? coverage.finished_matches)} non-void matches).</p>
        <div class="ci-balance-controls">
          <label>Match type <select id="ciBalanceMatch"><option value="both">Both</option><option value="friendly">Friendly</option><option value="league">League</option></select></label>
          <label>Chess type <select id="ciBalanceChess"><option value="both">Both</option><option value="classical">Classical</option><option value="chess960">Chess960</option></select></label>
          <label>Opponents <select id="ciBalanceTop"><option value="all">All opponents</option><option value="10">Top 10</option><option value="25">Top 25</option><option value="50">Top 50</option><option value="100">Top 100</option></select></label>
          <label>Minimum rated-board coverage <select id="ciBalanceCoverage"><option value="0">Any paired coverage</option><option value="25">25%</option><option value="50">50%</option><option value="75">75%</option><option value="100">100%</option></select></label>
          <label>Color scale <select id="ciBalanceColor"><option value="log">Logarithmic</option><option value="linear">Linear</option></select></label>
        </div>
      </article>
      <div class="ci-grid ci-balance-kpis">
        <article class="ci-card ci-metric"><span>Matches shown</span><strong>${fmt(summary.n)}</strong></article>
        <article class="ci-card ci-metric"><span>Opponents shown</span><strong>${fmt(summary.opponents)}</strong></article>
        <article class="ci-card ci-metric"><span>Median boards</span><strong>${fmt(summary.boards)}</strong></article>
        <article class="ci-card ci-metric"><span>Mean rating Δ</span><strong>${summary.mean.toFixed(1)}</strong></article>
        <article class="ci-card ci-metric"><span>Mean rated coverage</span><strong>${summary.coverage.toFixed(1)}%</strong></article>
        <article class="ci-card ci-metric"><span>Within ±50</span><strong>${summary.p50.toFixed(1)}%</strong></article>
        <article class="ci-card ci-metric"><span>Within ±100</span><strong>${summary.p100.toFixed(1)}%</strong></article>
        <article class="ci-card ci-metric"><span>Median |Δ|</span><strong>${summary.mad.toFixed(1)}</strong></article>
      </div>
      <article class="ci-card">
        <div class="ci-balance-head"><div><h3>Match size vs rating balance · Zoned Density</h3><p>Zoned density · half-size bins · one light Gaussian pass + interpolated contours in log space · discrete density zones. Zero line means equal average rating.</p></div><button data-reset-balance="ciBalanceSize">Reset zoom</button></div>
        <div class="ci-balance-chart" id="ciBalanceSize"></div>
      </article>
      <article class="ci-card">
        <div class="ci-balance-head"><div><h3>P2K strength vs opponent strength · Zoned Density</h3><p>Zoned density · half-size bins · light smoothing · discrete density zones. The equality diagonal means balanced average strength.</p></div><button data-reset-balance="ciBalanceStrength">Reset zoom</button></div>
        <div class="ci-balance-chart" id="ciBalanceStrength"></div>
      </article>
      <article class="ci-card ci-balance-opponent-strip">
        <h3>Included opponents</h3>
        <p>${opponents.length ? opponents.slice(0, 100).map(entry => `<span class="ci-balance-opponent-name">${esc(entry.name)} <b>${fmt(entry.count)}</b></span>`).join(" ") + (opponents.length > 100 ? ` <span class="ci-balance-opponent-name">+${fmt(opponents.length - 100)} more</span>` : "") : "No opponent has a match satisfying the current filters."}</p>
      </article>
      <p class="ci-status">All valid matches are visible by default · wheel/pinch to zoom · drag to pan · Shift+drag for box zoom.</p>`;

    const controls = {
      match: root.querySelector("#ciBalanceMatch"), chess: root.querySelector("#ciBalanceChess"), top: root.querySelector("#ciBalanceTop"),
      coverage: root.querySelector("#ciBalanceCoverage"), color: root.querySelector("#ciBalanceColor")
    };
    Object.entries(controls).forEach(([key, node]) => { node.value = state[key]; });
    const rerender = () => {
      Object.entries(controls).forEach(([key, node]) => { state[key] = node.value; });
      state.views.clear();
      render(root, data);
    };
    Object.values(controls).forEach(node => node.addEventListener("change", rerender));

    heat(root.querySelector("#ciBalanceSize"), rows, 1);
    heat(root.querySelector("#ciBalanceStrength"), rows, 2);
    root.querySelectorAll("[data-reset-balance]").forEach(button => button.addEventListener("click", () => {
      root.querySelector(`#${button.dataset.resetBalance}`)?._p2kResetZoom?.();
    }));
  }

  window.P2K_OPPONENT_BALANCE = Object.freeze({ render });
})();
