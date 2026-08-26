/* P2K v2.10.4.6 — quick_matches observability adapter
 * Read-only UI instrumentation. It uses values already rendered by Team Points Migration.
 * The percentage is deliberately labelled ESTIMATED because quick_matches has no frozen
 * server-side cohort in v2.10.4.5.
 */
(() => {
  'use strict';
  const ID = 'p2k-quick-matches-progress-v21046';

  const norm = s => String(s ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
  const num = s => {
    const t = norm(s).replace(/[^\d,.\-]/g, '').replace(/\.(?=\d{3}(?:\D|$))/g, '').replace(',', '.');
    const n = Number(t);
    return Number.isFinite(n) ? n : 0;
  };

  function leafWithText(re) {
    return [...document.querySelectorAll('div,span,p,small,strong,label,td,th,h2,h3')]
      .find(el => el.children.length === 0 && re.test(norm(el.textContent)));
  }

  function metric(label) {
    const exact = new RegExp('^' + label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', 'i');
    const lab = leafWithText(exact);
    if (!lab) return 0;

    let parent = lab.parentElement;
    for (let depth = 0; parent && depth < 4; depth++, parent = parent.parentElement) {
      const strong = [...parent.querySelectorAll('strong')].find(x => x !== lab && /\d/.test(norm(x.textContent)));
      if (strong) return num(strong.textContent);
      const candidates = [...parent.children].filter(x => x !== lab && /\d/.test(norm(x.textContent)));
      if (candidates.length) return num(candidates[0].textContent);
    }
    return 0;
  }

  function stageIsQuickMatches() {
    return [...document.querySelectorAll('body *')].some(el =>
      el.children.length === 0 && /stage\s+quick_matches/i.test(norm(el.textContent))
    );
  }

  function requestMetrics() {
    let maintenance = 0, detail = 0, retry = 0;
    for (const table of document.querySelectorAll('table')) {
      const head = norm(table.querySelector('thead')?.textContent || '');
      if (!/Request type/i.test(head) || !/Source/i.test(head) || !/Outcome/i.test(head) || !/Count/i.test(head)) continue;
      for (const tr of table.querySelectorAll('tbody tr')) {
        const td = [...tr.querySelectorAll('td')].map(x => norm(x.textContent));
        if (td.length < 4) continue;
        const [type, , outcome, countText] = td;
        const count = num(countText);
        const ok = outcome === '200' || /accepted|success/i.test(outcome);
        if (type === 'current_match_maintenance') {
          if (ok) maintenance += count; else retry += count;
        } else if (type === 'match_detail') {
          if (ok) detail += count; else retry += count;
        }
      }
    }
    return { maintenance, detail, retry };
  }

  function fmt(n) {
    return Math.max(0, Math.round(n)).toLocaleString(undefined);
  }

  function ensurePanel(anchor) {
    let box = document.getElementById(ID);
    if (box) return box;
    box = document.createElement('div');
    box.id = ID;
    box.style.cssText = [
      'margin:14px 0 4px',
      'padding:14px 16px',
      'border:1px solid rgba(127,127,127,.28)',
      'border-radius:10px',
      'background:rgba(127,127,127,.055)'
    ].join(';');
    anchor.insertAdjacentElement('afterend', box);
    return box;
  }

  function findAnchor() {
    const h = leafWithText(/^Green current cycle$/i);
    if (!h) return null;
    let p = h;
    for (let i=0; i<3 && p.parentElement; i++) p = p.parentElement;
    return p || h;
  }

  function updateSummary(pct) {
    const candidates = [...document.querySelectorAll('div,p,small,span')]
      .filter(el => el.children.length === 0 && /Mode\s+quick\s+·\s+stage\s+quick_matches\s+·\s+cycle/i.test(norm(el.textContent)));
    for (const el of candidates) {
      const t = norm(el.textContent);
      const next = t.replace(/·\s*(?:~?\d+(?:[.,]\d+)?%\s*(?:est(?:imated)?)?|0\.0%)\s*·/i, `· ~${pct.toFixed(1)}% est ·`);
      if (next !== t) el.textContent = next;
    }
  }

  function render() {
    const existing = document.getElementById(ID);
    if (!stageIsQuickMatches()) {
      if (existing) existing.remove();
      return;
    }

    const inProgress = metric('In progress');
    const due = metric('Current fact refresh due');
    const changed = metric('Changed objects');
    const {maintenance, detail, retry} = requestMetrics();

    // Active sweep denominator: current active population, floored by already processed
    // maintenance requests so percentage cannot exceed 100% if statuses change mid-cycle.
    const activeTotal = Math.max(inProgress, maintenance);
    // Historical denominator: processed detail requests plus the live remaining due backlog.
    const historicalTotal = detail + due;
    const processed = maintenance + detail;
    const total = activeTotal + historicalTotal;
    const remainingEst = Math.max(0, total - processed);
    const pct = total > 0 ? Math.min(100, Math.max(0, processed / total * 100)) : 0;

    const anchor = findAnchor();
    if (!anchor) return;
    const box = ensurePanel(anchor);
    box.innerHTML = `
      <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
        <strong style="font-size:15px">Quick matches progress</strong>
        <span style="font-size:12px;opacity:.72">estimated from live stage workload</span>
        <strong style="margin-left:auto">${pct.toFixed(1)}%</strong>
      </div>
      <div style="height:8px;border-radius:999px;background:rgba(127,127,127,.20);overflow:hidden;margin:9px 0 12px">
        <div style="height:100%;width:${pct.toFixed(2)}%;background:currentColor;opacity:.72;transition:width .35s ease"></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(125px,1fr));gap:8px 14px;font-size:12px">
        <div><span style="opacity:.7">Processed</span><br><strong>${fmt(processed)} / ${fmt(total)}</strong></div>
        <div><span style="opacity:.7">Remaining est.</span><br><strong>${fmt(remainingEst)}</strong></div>
        <div><span style="opacity:.7">Active sweep</span><br><strong>${fmt(maintenance)} / ${fmt(activeTotal)}</strong></div>
        <div><span style="opacity:.7">Historical refresh</span><br><strong>${fmt(detail)} / ${fmt(historicalTotal)}</strong></div>
        <div><span style="opacity:.7">Live fact due</span><br><strong>${fmt(due)}</strong></div>
        <div><span style="opacity:.7">Changed</span><br><strong>${fmt(changed)}</strong></div>
        <div><span style="opacity:.7">Retry / failed</span><br><strong>${fmt(retry)}</strong></div>
      </div>
      <div style="font-size:11px;opacity:.62;margin-top:9px">
        Estimate = successful current-match maintenance + match-detail work divided by
        the current active sweep plus processed + still-due historical refresh work.
        It is intentionally not presented as an exact frozen cohort.
      </div>`;
    updateSummary(pct);
  }

  let timer = 0;
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(render, 120);
  };
  new MutationObserver(schedule).observe(document.documentElement, {subtree:true,childList:true,characterData:true});
  window.addEventListener('load', schedule);
  schedule();
})();
