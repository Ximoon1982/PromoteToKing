(() => {
  'use strict';

  const state = {
    connected: false,
    status: null,
    preview: [],
    previewShape: 'totals',
    sortBy: 'points',
    sortDir: 'desc',
    statusTimer: null,
    manualRunning: false,
    manualStopRequested: false,
    manualProcessed: 0,
    querySerial: 0,
    storage: null,
    storageLoaded: false,
    memberEventsLoaded: false
  };

  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

  function setMessage(id, message, type = '') {
    const box = $(id);
    box.textContent = message;
    box.className = `p2k-tp-status${type ? ` ${type}` : ''}`;
  }

  async function request(action, { method = 'GET', body = null, params = {}, raw = false } = {}) {
    if (!window.P2K_TEAM_POINTS_CLIENT) throw new Error('The secured Team Points client is not loaded.');
    const payload = await window.P2K_TEAM_POINTS_CLIENT.request(action, { method, body, params, raw });
    state.connected = true;
    return payload;
  }

  async function greenRequest(action, { method='GET', body=null, params={} } = {}) {
    if (!window.P2K_TEAM_POINTS_CLIENT?.endpointRequest) throw new Error('The secured Team Points client is not loaded.');
    return window.P2K_TEAM_POINTS_CLIENT.endpointRequest('server/team-points-green/public/api.php', { action, method, body, params, serverTrafficClass:'foreground' });
  }

  function number(value) {
    return Number(value || 0).toLocaleString('en-GB', { maximumFractionDigits: 1 });
  }

  function formatUtc(value) {
    if (!value) return '—';
    return String(value).replace('T', ' ').replace(/\+00:00$|Z$/, ' UTC');
  }

  function bytes(value) {
    const n = Number(value || 0);
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B','KB','MB','GB','TB'];
    const power = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return `${(n / (1024 ** power)).toLocaleString('en-GB', { maximumFractionDigits: power >= 3 ? 2 : 1 })} ${units[power]}`;
  }

  function capacityCard(title, metric, detail = '') {
    const available = metric?.available !== false;
    const status = metric?.status || (metric?.quota_bytes ? 'green' : 'unbounded');
    const quota = Number(metric?.quota_bytes || 0);
    const used = Number(metric?.bytes ?? metric?.total_bytes ?? 0);
    const pct = metric?.percent == null ? null : Number(metric.percent);
    const width = Math.max(0, Math.min(100, pct ?? (quota ? 100 * used / quota : 0)));
    return `<div class="p2k-storage-card ${esc(status)}">
      <span class="p2k-storage-light ${esc(status)}" aria-label="${esc(status)}"></span>
      <h3>${esc(title)}</h3>
      <div class="p2k-storage-value">${available ? esc(bytes(used)) : 'Unavailable'}</div>
      <div class="p2k-storage-meta">${quota ? `${esc(pct?.toFixed(1) ?? '0.0')}% of ${esc(bytes(quota))}` : 'No total filesystem quota configured'}${detail ? `<br>${esc(detail)}` : ''}</div>
      ${quota ? `<div class="p2k-storage-bar"><span style="width:${width}%"></span></div>` : ''}
    </div>`;
  }

  function objectCountCard(title, metric = {}) {
    const files = Number(metric.files || 0), dirs = Number(metric.directories || 0), maxFiles = Number(metric.max_files || 0);
    const pct = metric.file_percent == null ? null : Number(metric.file_percent);
    const status = metric.status || (maxFiles ? 'green' : 'unbounded');
    const width = Math.max(0, Math.min(100, pct ?? 0));
    return `<div class="p2k-storage-card ${esc(status)}">
      <span class="p2k-storage-light ${esc(status)}" aria-label="${esc(status)}"></span>
      <h3>${esc(title)}</h3>
      <div class="p2k-storage-value">${esc(number(files))} files</div>
      <div class="p2k-storage-meta">${maxFiles ? `${esc((pct ?? 0).toFixed(1))}% of ${esc(number(maxFiles))} file contract` : 'No hosting file-count limit configured'}<br>${esc(number(dirs))} directories · ${esc(number(metric.objects || files + dirs))} filesystem objects</div>
      ${maxFiles ? `<div class="p2k-storage-bar"><span style="width:${width}%"></span></div>` : ''}
    </div>`;
  }

  function renderStorageChart(history = [], storage = {}) {
    const host = $('storageHistoryChart');
    if (!history.length) {
      host.innerHTML = '<span class="p2k-storage-empty">Storage history will appear after measurements accumulate.</span>';
      return;
    }
    const width = 960, height = 300, left = 58, right = 20, top = 18, bottom = 38;
    const coreQuota = Number(storage?.databases?.core?.quota_bytes || 0);
    const analyticsQuota = Number(storage?.databases?.analytics?.quota_bytes || 0);
    const fsQuota = Number(storage?.filesystem?.quota_bytes || 0);
    const maxValue = Math.max(1, ...history.flatMap(r => [Number(r.core_bytes||0), Number(r.analytics_bytes||0), Number(r.filesystem_bytes||0)]), coreQuota, analyticsQuota, fsQuota);
    const x = i => left + (history.length === 1 ? 0 : i * (width-left-right)/(history.length-1));
    const y = v => top + (height-top-bottom) * (1 - Number(v||0)/maxValue);
    const path = key => history.map((r,i) => `${i?'L':'M'}${x(i).toFixed(1)},${y(r[key]).toFixed(1)}`).join(' ');
    const ticks = [0,.25,.5,.75,1].map(f => `<g><line class="grid" x1="${left}" x2="${width-right}" y1="${y(maxValue*f)}" y2="${y(maxValue*f)}"/><text class="axis" x="${left-8}" y="${y(maxValue*f)+4}" text-anchor="end">${esc(bytes(maxValue*f))}</text></g>`).join('');
    const labels = history.map((r,i) => (i===0 || i===history.length-1 || i%Math.max(1,Math.ceil(history.length/6))===0) ? `<text class="axis" x="${x(i)}" y="${height-12}" text-anchor="middle">${esc(r.week || r.month || r.date)}</text>` : '').join('');
    const threshold = quota => quota ? `<line class="threshold80" x1="${left}" x2="${width-right}" y1="${y(quota*.8)}" y2="${y(quota*.8)}"/><line class="threshold100" x1="${left}" x2="${width-right}" y1="${y(quota)}" y2="${y(quota)}"/>` : '';
    const dots = history.map((r,i) => `<circle data-storage-index="${i}" cx="${x(i)}" cy="${y(r.core_bytes)}" r="6" fill="transparent"/><circle data-storage-index="${i}" cx="${x(i)}" cy="${y(r.analytics_bytes)}" r="6" fill="transparent"/>`).join('');
    host.innerHTML = `<svg viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-hidden="true">${ticks}${threshold(Math.max(coreQuota,analyticsQuota))}<path class="series-core" d="${path('core_bytes')}"/><path class="series-analytics" d="${path('analytics_bytes')}"/><path class="series-filesystem" d="${path('filesystem_bytes')}"/>${dots}${labels}</svg><div class="p2k-storage-tooltip" hidden></div>`;
    const tip = host.querySelector('.p2k-storage-tooltip');
    let pinnedIndex = null;
    const showTip = dot => {
      const index=Number(dot.dataset.storageIndex),r=history[index];
      tip.innerHTML = `<strong>${esc(r.week || r.month || r.date)}</strong><br>Core: ${esc(bytes(r.core_bytes))}<br>Analytics: ${esc(bytes(r.analytics_bytes))}<br>Filesystem: ${esc(bytes(r.filesystem_bytes))}`;
      tip.hidden = false; tip.style.left = `${dot.cx.baseVal.value/width*100}%`; tip.style.top = `${dot.cy.baseVal.value/height*100}%`;
    };
    host.querySelectorAll('[data-storage-index]').forEach(dot => {
      dot.addEventListener('pointerenter', event => { if(event.pointerType !== 'touch') showTip(event.currentTarget); });
      dot.addEventListener('pointerleave', () => { if(pinnedIndex === null) tip.hidden = true; });
      dot.addEventListener('click', event => {
        const dot=event.currentTarget,index=Number(dot.dataset.storageIndex);
        if(pinnedIndex===index){pinnedIndex=null;tip.hidden=true;}else{pinnedIndex=index;showTip(dot);}
      });
    });
    host.addEventListener('click', event => { if(event.target===host || event.target.tagName==='svg'){pinnedIndex=null;tip.hidden=true;} });
  }

  function renderProjection(name, projection) {
    if (!projection?.available) {
      const reason = projection?.reason === 'collecting_baseline' ? `Collecting baseline (${projection.samples || 0} sample${projection.samples===1?'':'s'})` : 'No positive growth trend measured';
      return `<div class="p2k-storage-projection"><span>${esc(name)}</span><strong>${esc(reason)}</strong><small>80%: ${esc(projection?.reaches_80_at || '—')} · 100%: ${esc(projection?.reaches_100_at || '—')}</small></div>`;
    }
    const basis = projection?.basis === 'daily_bootstrap' ? 'bootstrap from daily samples' : 'weekly measured trend';
    return `<div class="p2k-storage-projection"><span>${esc(name)}</span><strong>${esc(bytes(projection.growth_bytes_per_week))} / week</strong><small>${esc(basis)}<br>80%: ${esc(projection.reaches_80_at || 'not projected')}<br>100%: ${esc(projection.reaches_100_at || 'not projected')}</small></div>`;
  }

  function renderStorage(payload) {
    state.storage = payload;
    const core = payload?.databases?.core || {};
    const analytics = payload?.databases?.analytics || {};
    const fs = payload?.filesystem || {};
    const cache = fs.cache || {};
    $('storageCards').innerHTML = [
      capacityCard('Core DB', core, core.available ? `${number(core.tables)} tables` : core.error || 'Connection unavailable'),
      capacityCard('Analytics DB', analytics, analytics.available ? `${number(analytics.tables)} tables` : analytics.error || 'Connection unavailable'),
      capacityCard('Filesystem total', { ...fs, bytes:fs.total_bytes, available:true }, `Cache ${bytes(cache.bytes)} · logs ${bytes(fs.logs?.bytes)} · archive ${bytes(fs.archive?.bytes)}`),
      capacityCard('Filesystem cache', { ...cache, available:true }, `${number(cache.files)} compressed cache files · ${number(cache.max_entries || 0)} entry ceiling`),
      objectCountCard('Hosting file count', fs.objects || {})
    ].join('');
    renderStorageChart(payload?.weekly_history || [], payload);
    $('storageProjections').innerHTML = [renderProjection('Core DB', payload?.projection?.core), renderProjection('Analytics DB', payload?.projection?.analytics), renderProjection('Filesystem', payload?.projection?.filesystem)].join('');
    const red = [core, analytics, cache, fs.objects].filter(x => x?.status === 'red').length;
    setMessage('storageStatus', red ? `${red} storage area${red===1?' is':'s are'} at or above the 80% warning threshold.` : `Measured ${formatUtc(payload?.measured_at)}. Database indicators are green below 80%.`, red ? 'error' : 'success');
  }

  async function loadStorageMetrics(force = false) {
    if (state.storageLoaded && !force) return;
    setMessage('storageStatus', 'Measuring Core DB, Analytics DB and protected filesystem…');
    try {
      const payload = await request('storage');
      renderStorage(payload.storage || {});
      state.storageLoaded = true;
    } catch (error) {
      setMessage('storageStatus', error.message, 'error');
    }
  }

  function metricsHtml(totals = {}, job = null, freshness = {}) {
    const done = Number(job?.queue?.done || 0);
    const total = Number(job?.total_items || 0);
    const items = [
      ['Current members', number(totals.current_members), 'Roster seen in the latest member sync'],
      ['Known members', number(totals.known_members), 'Retained historical identities'],
      ['Participations', number(totals.participations), 'Player/team-match board records'],
      ['Normalized games', number(totals.games), `${number(totals.points)} total points`],
      ['UTC months', number(totals.months), totals.first_month && totals.last_month ? `${String(totals.first_month).slice(0, 7)} to ${String(totals.last_month).slice(0, 7)}` : 'No month stored yet'],
      ['HTTP cache', number(totals.http_cache_entries), 'Buffered Chess.com responses'],
      ['Queue progress', total ? `${number(done)} / ${number(total)}` : 'No queue', 'Committed task items'],
      ['Worker calls', number(totals.worker_runs), 'Manual and CRON invocations'],
      ['Club index verified', formatUtc(freshness.club_index_last_verified_at), 'Authoritative CRON/server fetch; target ≤5 minutes, hard floor ≤60 minutes'],
      ['Club index observed', formatUtc(freshness.club_index_last_observed_at), 'Latest authoritative or opportunistic interface observation'],
      ['Roster verified', formatUtc(freshness.members_last_verified_at), 'Authoritative CRON/server fetch; target ≤30 minutes, hard floor ≤60 minutes'],
      ['Roster observed', formatUtc(freshness.members_last_observed_at), 'Latest authoritative or opportunistic interface observation'],
      ['Core generation', number(freshness.core_generation), 'Cheap freshness generation used by Analytics']
    ];
    return items.map(([label, value, detail]) => `<div class="p2k-tp-metric"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(detail)}</small></div>`).join('');
  }

  function taskLabel(type) {
    return ({
      sync_roster: 'Club member roster refresh',
      sync_members: 'Member reconciliation',
      sync_player: 'Player match history',
      sync_player_profile: 'Player profile/avatar verification',
      sync_player_stats: 'Player rating verification',
      sync_player_archive: 'Player monthly archive verification',
      sync_match: 'Match verification',
      sync_board: 'Board/game normalization',
      worker_started: 'Worker start',
      worker_finished: 'Worker finish',
      task_started: 'Task start',
      job_created: 'Job creation',
      job_completed: 'Job completion',
      job_paused: 'Safe pause',
      worker_waiting: 'Retry wait',
      pause_requested: 'Pause request',
      job_resumed: 'Job resume',
      worker_failed: 'Worker failure'
    })[type] || String(type || 'Task').replaceAll('_', ' ');
  }

  function compactContext(value) {
    if (!value) return '';
    try {
      const data = typeof value === 'string' ? JSON.parse(value) : value;
      return Object.entries(data || {})
        .filter(([, entry]) => entry !== null && entry !== '' && typeof entry !== 'object')
        .slice(0, 8)
        .map(([key, entry]) => `${key.replaceAll('_', ' ')}: ${entry}`)
        .join(' · ');
    } catch (_) {
      return String(value).slice(0, 240);
    }
  }

  function renderProcess(job) {
    if (!job) {
      $('tpProcessOverview').innerHTML = '<div class="p2k-tp-process-item"><span>Job</span><strong>No collection job</strong></div>';
      $('tpTaskBreakdown').innerHTML = '<tr><td colspan="7">No task data loaded.</td></tr>';
      return;
    }
    const current = job.current_item;
    const overview = [
      ['Job ID', job.id || '—'],
      ['Created', formatUtc(job.created_at)],
      ['Started', formatUtc(job.started_at)],
      ['Last activity', formatUtc(job.updated_at)],
      ['Finished', formatUtc(job.finished_at)],
      ['Current task', current ? `${taskLabel(current.item_type)} · ${current.item_key}` : 'No task currently claimed'],
      ['Current attempt', current ? current.attempts : '—'],
      ['Next retry', formatUtc(job.next_retry_at)]
    ];
    $('tpProcessOverview').innerHTML = overview.map(([label, value]) => `<div class="p2k-tp-process-item"><span>${esc(label)}</span><strong title="${esc(value)}">${esc(value)}</strong></div>`).join('');
    const rows = job.task_breakdown || [];
    $('tpTaskBreakdown').innerHTML = rows.length ? rows.map(row => `<tr>
      <td>${esc(taskLabel(row.item_type))}</td>
      <td>${esc(row.total || 0)}</td><td>${esc(row.pending || 0)}</td><td>${esc(row.running || 0)}</td>
      <td>${esc(row.retry || 0)}</td><td>${esc(row.done || 0)}</td><td>${esc(row.failed || 0)}</td>
    </tr>`).join('') : '<tr><td colspan="7">No queued task data.</td></tr>';
  }

  function renderLogs(logs = []) {
    $('tpProcessLogs').innerHTML = logs.length ? logs.map(log => {
      const level = String(log.level || 'info').toLowerCase();
      const context = compactContext(log.context_json);
      return `<tr>
        <td>${esc(formatUtc(log.created_at))}</td>
        <td><span class="p2k-tp-log-level ${esc(level)}">${esc(level)}</span></td>
        <td>${esc(taskLabel(log.task_type))}</td>
        <td title="${esc(log.item_key || '')}">${esc((log.item_key || '').slice(0, 70))}</td>
        <td title="${esc(log.message || '')}">${esc((log.message || '').slice(0, 260))}</td>
        <td title="${esc(context)}">${esc(context.slice(0, 220))}</td>
      </tr>`;
    }).join('') : '<tr><td colspan="6">No process log loaded.</td></tr>';
  }

  function renderRuns(runs = []) {
    $('tpRuns').innerHTML = runs.length ? runs.map(run => `<tr>
      <td>${esc(run.trigger_type || '')}</td><td>${esc(formatUtc(run.started_at))}</td><td>${esc(formatUtc(run.finished_at))}</td>
      <td>${esc(run.processed_items || 0)}</td><td>${esc(run.result_status || '')}</td>
      <td title="${esc(run.message || '')}">${esc((run.message || '').slice(0, 220))}</td>
    </tr>`).join('') : '<tr><td colspan="6">No worker invocation recorded.</td></tr>';
  }

  function renderStatus(payload) {
    state.status = payload;
    const job = payload.job;
    $('tpMetrics').innerHTML = metricsHtml(payload.totals, job, payload.freshness || {});
    renderProcess(job);
    renderLogs(payload.process_logs || []);
    renderRuns(payload.worker_runs || []);

    if (!job) {
      $('tpJobStatus').textContent = 'No job';
      $('tpProgressBar').style.width = '0%';
      if (!state.manualRunning) setMessage('tpJobMessage', 'No collection job exists yet.');
      $('tpIssues').innerHTML = '<tr><td colspan="5">No job issues.</td></tr>';
      updateControls();
      setDefaultMonths(payload.totals);
      return;
    }

    $('tpJobStatus').textContent = job.status;
    const total = Math.max(0, Number(job.total_items || 0));
    const processed = Math.max(0, Number(job.processed_items || 0));
    const pct = total > 0 ? Math.min(100, Math.round(processed / total * 100)) : 0;
    $('tpProgressBar').style.width = `${pct}%`;
    const q = job.queue || {};
    if (!state.manualRunning) {
      const currentText = job.current_item ? ` · current ${taskLabel(job.current_item.item_type)}` : '';
      setMessage('tpJobMessage', `${job.status}: ${processed}/${total} complete · pending ${q.pending || 0} · retry ${q.retry || 0} · failed ${q.failed || 0}${currentText}.`, job.status === 'completed' ? 'success' : job.status === 'failed' ? 'error' : '');
    }
    const issues = job.issues || [];
    $('tpIssues').innerHTML = issues.length ? issues.map(item => `<tr>
      <td>${esc(taskLabel(item.item_type))}<br><small>${esc(item.item_key)}</small></td><td>${esc(item.status)}</td>
      <td>${esc(item.attempts)}</td><td>${esc(formatUtc(item.available_at || item.updated_at))}</td>
      <td title="${esc(item.last_error || '')}">${esc((item.last_error || '').slice(0, 220))}</td>
    </tr>`).join('') : '<tr><td colspan="5">No retry or permanently failed item.</td></tr>';

    if (['new', 'running'].includes(job.status) || state.manualRunning) startPolling(); else stopPolling();
    setDefaultMonths(payload.totals);
    updateControls();
  }

  function setDefaultMonths(totals = {}) {
    const current = new Date().toISOString().slice(0, 7);
    if (!$('tpStartMonth').value) $('tpStartMonth').value = totals.first_month ? String(totals.first_month).slice(0, 7) : `${current.slice(0, 4)}-01`;
    if (!$('tpEndMonth').value) $('tpEndMonth').value = totals.last_month ? String(totals.last_month).slice(0, 7) : current;
  }

  async function refreshStatus(announce = false) {
    try {
      const payload = await request('status');
      renderStatus(payload);
      const environment = payload.environment === 'local-simulation' ? ' · local SQLite simulation' : '';
      setMessage('tpConnectionStatus', `Connected. Server UTC: ${payload.server_utc}${environment}`, 'success');
      if (announce && !state.manualRunning) setMessage('tpJobMessage', 'Status refreshed.', 'success');
      return payload;
    } catch (error) {
      stopPolling();
      setMessage('tpConnectionStatus', error.message, 'error');
      throw error;
    }
  }

  function startPolling() {
    if (state.statusTimer) return;
    state.statusTimer = window.setInterval(() => refreshStatus(false).catch(() => {}), state.manualRunning ? 2500 : 5000);
  }

  function stopPolling() {
    window.clearInterval(state.statusTimer);
    state.statusTimer = null;
  }

  function updateControls() {
    const job = state.status?.job;
    const connected = Boolean(state.status && state.connected);
    $('tpStart').disabled = !connected || state.manualRunning;
    $('tpRun').disabled = !connected || state.manualRunning || !job || !['new', 'running'].includes(job.status);
    $('tpPrioritizeDiscovery').disabled = !connected;
    $('tpStop').disabled = !connected || !job || !['new', 'running'].includes(job.status);
    $('tpResume').disabled = !connected || state.manualRunning || !job || !['paused', 'failed'].includes(job.status);
    $('tpRefresh').disabled = !state.connected;
    $('tpRun').textContent = state.manualRunning ? 'Running one server segment…' : 'Run one segment now';
  }

  async function runSimpleAction(action, body = null) {
    const buttonMap = { start: 'tpStart', resume: 'tpResume', prioritize_discovery: 'tpPrioritizeDiscovery' };
    const button = $(buttonMap[action] || 'tpRefresh');
    if (button) button.disabled = true;
    try {
      const payload = await request(action, { method: 'POST', body: body || {} });
      await refreshStatus(false);
      setMessage('tpJobMessage', payload.message || `${action} request completed.`, 'success');
      return payload;
    } catch (error) {
      setMessage('tpJobMessage', error.message, 'error');
      throw error;
    } finally {
      updateControls();
    }
  }

  async function runManualContinuously() {
    if (state.manualRunning) return;
    let job = state.status?.job;
    if (!job) {
      await runSimpleAction('start');
      job = state.status?.job;
    }
    if (job && ['paused', 'failed'].includes(job.status)) {
      await runSimpleAction('resume', { job_id: job.id });
      job = state.status?.job;
    }
    if (!job || !['new', 'running'].includes(job.status)) {
      setMessage('tpJobMessage', 'No runnable job is available. Start or resume the server-controlled task first.', 'error');
      return;
    }
    state.manualRunning = true;
    state.manualProcessed = 0;
    updateControls();
    setMessage('tpJobMessage', 'Running one bounded compatibility segment. Normal processing remains owned by the external five-minute CRON.');
    try {
      const payload = await request('run', { method: 'POST', body: { job_id: job.id } });
      state.manualProcessed = Number(payload.processed_items || 0);
      await refreshStatus(false);
      setMessage('tpJobMessage', `${payload.message || 'Server segment completed.'} Processed ${number(state.manualProcessed)} item(s).`, payload.ok === false ? 'error' : 'success');
    } catch (error) {
      setMessage('tpJobMessage', `Immediate segment failed: ${error.message}`, 'error');
    } finally {
      state.manualRunning = false;
      updateControls();
    }
  }

  async function requestSafePause() {
    const jobId = state.status?.job?.id;
    if (!jobId) return;
    state.manualStopRequested = true;
    setMessage('tpJobMessage', 'Safe pause requested. The active server item is allowed to finish and commit.');
    try {
      const payload = await request('stop', { method: 'POST', body: { job_id: jobId } });
      setMessage('tpJobMessage', payload.message || 'Safe pause requested.');
      if (!state.manualRunning) await refreshStatus(false);
    } catch (error) {
      state.manualStopRequested = false;
      setMessage('tpJobMessage', error.message, 'error');
    }
  }

  function defaultSort(shape) {
    if (shape === 'monthly') return { column: 'month', direction: 'asc' };
    if (shape === 'events') return { column: 'game_end_utc', direction: 'asc' };
    return { column: 'points', direction: 'desc' };
  }

  function queryParams(shape = $('tpShape').value, includeLimit = true) {
    const start = $('tpStartMonth').value;
    const end = $('tpEndMonth').value;
    if (!/^\d{4}-\d{2}$/.test(start) || !/^\d{4}-\d{2}$/.test(end)) throw new Error('Select a start and end month.');
    if (start > end) throw new Error('The start month must not follow the end month.');
    return {
      start_month: start,
      end_month: end,
      current_only: $('tpCurrentOnly').checked ? 1 : 0,
      shape,
      member: $('tpMemberSearch').value.trim(),
      sort_by: state.sortBy,
      sort_dir: state.sortDir,
      ...(includeLimit ? { limit: 500 } : {})
    };
  }

  function columnsFor(shape) {
    return shape === 'events'
      ? ['username', 'match_id', 'game_end_utc', 'month', 'result_code', 'points', 'game_url']
      : shape === 'monthly'
        ? ['month', 'username', 'points', 'matches', 'games', 'wins', 'draws', 'losses']
        : ['username', 'points', 'matches', 'games', 'wins', 'draws', 'losses'];
  }

  function renderPreview(shape, rows, truncated = false) {
    const columns = columnsFor(shape);
    $('tpPreviewHead').innerHTML = `<tr>${columns.map(column => {
      const active = state.sortBy === column;
      const arrow = active ? (state.sortDir === 'asc' ? '▲' : '▼') : '↕';
      return `<th aria-sort="${active ? (state.sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}"><button class="p2k-tp-sort" type="button" data-sort="${esc(column)}">${esc(column.replaceAll('_', ' '))}<span aria-hidden="true">${arrow}</span></button></th>`;
    }).join('')}</tr>`;
    $('tpPreviewBody').innerHTML = rows.length ? rows.map(row => `<tr>${columns.map(column => `<td>${esc(row[column])}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${columns.length}">No stored data matches this period and member search.</td></tr>`;
    const search = $('tpMemberSearch').value.trim();
    const qualifier = search ? ` matching “${search}”` : '';
    $('tpPreviewNote').textContent = truncated
      ? `Showing the first ${rows.length} sorted row(s)${qualifier}. CSV export includes all matching rows.`
      : `${rows.length} sorted row(s)${qualifier}. Select any column heading to reverse or change the ordering.`;
  }

  async function queryDatabase() {
    const serial = ++state.querySerial;
    try {
      const shape = $('tpShape').value;
      setMessage('tpExportStatus', 'Querying the server database…');
      const payload = await request('results', { params: queryParams(shape, true) });
      if (serial !== state.querySerial) return;
      state.previewShape = shape;
      state.preview = Array.isArray(payload.rows) ? payload.rows : [];
      if (payload.sort) {
        state.sortBy = payload.sort.column;
        state.sortDir = payload.sort.direction;
      }
      renderPreview(shape, state.preview, Boolean(payload.truncated));
      setMessage('tpExportStatus', `Database query completed: ${state.preview.length}${payload.truncated ? '+' : ''} row(s) in the preview.`, 'success');
    } catch (error) {
      if (serial !== state.querySerial) return;
      setMessage('tpExportStatus', error.message, 'error');
    }
  }

  async function downloadCsv() {
    try {
      const shape = $('tpShape').value;
      setMessage('tpExportStatus', 'Preparing the complete sorted CSV export…');
      const response = await request('export', { params: queryParams(shape, false), raw: true });
      const blob = await response.blob();
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="([^"]+)"/i);
      const filename = match?.[1] || `p2k-team-points-${shape}.csv`;
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      setMessage('tpExportStatus', `Downloaded ${filename}.`, 'success');
    } catch (error) {
      setMessage('tpExportStatus', error.message, 'error');
    }
  }

  function renderMemberChronology(events=[]) {
    const rows=$('memberChronologyRows');if(!rows)return;
    const label={discovered:'Discovered',joined:'Joined',left:'Left',name_changed:'Name changed',rejoined:'Rejoined'};
    rows.innerHTML=events.map(e=>{const transition=e.event_type==='name_changed'?`${esc(e.previous_username||e.username||'—')} → ${esc(e.new_username||'—')}`:'—';const profile=e.event_type==='left'?`${esc(e.profile_status||'pending')}${e.profile_checked_at?` · ${esc(formatUtc(e.profile_checked_at))}`:''}`:'—';return `<tr><td>${esc(formatUtc(e.detected_at))}</td><td>${esc(label[e.event_type]||e.event_type||'')}</td><td>${esc(e.username||e.new_username||'—')}</td><td>${transition}</td><td>${profile}</td><td>${esc(e.source||'—')}</td><td>${e.cycle_no?esc(`#${e.cycle_no}`):'—'}</td></tr>`}).join('')||'<tr><td colspan="7">No member lifecycle event has been recorded yet.</td></tr>';
  }
  async function loadMemberChronology(force=false){if(state.memberEventsLoaded&&!force)return;setMessage('memberChronologyStatus','Loading Green member chronology…');try{const d=await greenRequest('member-events',{params:{limit:500}});const events=Array.isArray(d.events)?d.events:[];renderMemberChronology(events);state.memberEventsLoaded=true;const pending=events.filter(e=>e.event_type==='left'&&e.profile_status==='pending').length;setMessage('memberChronologyStatus',`${number(events.length)} event${events.length===1?'':'s'} loaded${pending?` · ${number(pending)} departure profile check${pending===1?'':'s'} pending`:''}.`,pending?'':'success');}catch(error){setMessage('memberChronologyStatus',error.message||String(error),'error');}}

  function setupTabs() {
    const tabs = [...document.querySelectorAll('[data-tab]')];
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => selectTab(tab.dataset.tab));
      tab.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        const next = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next].focus();
        selectTab(tabs[next].dataset.tab);
      });
    });
  }

  function selectTab(key, { updateHistory = true } = {}) {
    const allowed = [...document.querySelectorAll('[data-tab]')].map(tab => tab.dataset.tab);
    if (!allowed.includes(key)) key = allowed[0] || 'control';
    document.querySelectorAll('[data-tab]').forEach(tab => {
      const active = tab.dataset.tab === key;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    });
    document.querySelectorAll('[data-panel]').forEach(panel => { panel.hidden = panel.dataset.panel !== key; });
    if (key === 'storage') loadStorageMetrics(false);
    if (key === 'members') loadMemberChronology(false);
    if (updateHistory) {
      const url = new URL(location.href);
      if (key === 'control') url.searchParams.delete('tab'); else url.searchParams.set('tab', key);
      if (window.parent !== window) { history.replaceState({ tab: key }, '', url); window.parent.postMessage({ type: 'p2k-embedded-tab-change', tool: 'team-points-admin', tab: key }, window.location.origin); } else history.pushState({ tab: key }, '', url);
    }
  }

  function setupSorting() {
    $('tpPreviewHead').addEventListener('click', event => {
      const button = event.target.closest('[data-sort]');
      if (!button) return;
      const column = button.dataset.sort;
      if (state.sortBy === column) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
      else {
        state.sortBy = column;
        state.sortDir = ['username', 'result_code'].includes(column) ? 'asc' : 'desc';
      }
      queryDatabase();
    });
  }

  function setup() {
    $('tpMetrics').innerHTML = metricsHtml({}, null, {});
    setupTabs();
    setupSorting();
    selectTab(new URLSearchParams(location.search).get('tab') || 'control', { updateHistory: false });
    window.addEventListener('popstate', () => selectTab(new URLSearchParams(location.search).get('tab') || 'control', { updateHistory: false }));

    $('tpConnect').addEventListener('click', async () => {
      state.connected = false;
      setMessage('tpConnectionStatus', 'Re-establishing the secured administrator session…');
      try {
        await window.P2K_TEAM_POINTS_CLIENT.connect();
        await refreshStatus(true);
      } catch (error) {
        setMessage('tpConnectionStatus', error.message, 'error');
      }
    });
    $('tpStart').addEventListener('click', () => runSimpleAction('start').catch(() => {}));
    $('tpStop').addEventListener('click', requestSafePause);
    $('tpResume').addEventListener('click', () => runSimpleAction('resume', { job_id: state.status?.job?.id || '' }).catch(() => {}));
    $('tpRun').addEventListener('click', runManualContinuously);
    $('tpRefresh').addEventListener('click', () => refreshStatus(true).catch(() => {}));
    $('storageRefresh')?.addEventListener('click', () => loadStorageMetrics(true));
    $('memberChronologyRefresh')?.addEventListener('click', () => loadMemberChronology(true));
    $('tpQuery').addEventListener('click', queryDatabase);
    $('tpDownload').addEventListener('click', downloadCsv);
    $('tpClearSearch')?.addEventListener('click', () => {
      $('tpMemberSearch').value = '';
      queryDatabase();
    });
    $('tpMemberSearch').addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        queryDatabase();
      }
    });
    $('tpShape').addEventListener('change', () => {
      const next = defaultSort($('tpShape').value);
      state.sortBy = next.column;
      state.sortDir = next.direction;
      state.preview = [];
      renderPreview($('tpShape').value, []);
    });

    $('tpCronUrl').textContent = 'Configured server-side; see protected server/team-points/config/config.local.php';
    updateControls();
    Promise.resolve(window.P2K_ADMIN_ACCESS_READY).then(async allowed => {
      if (!allowed) return;
      try {
        await window.P2K_TEAM_POINTS_CLIENT.connect();
        state.connected = true;
        await refreshStatus(false);
      } catch (error) {
        setMessage('tpConnectionStatus', error.message, 'error');
        updateControls();
      }
    });
  }

  setup();
})();
