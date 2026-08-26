/* Opponent maintenance and Live ranks MCA source processing. current integrated release */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
  const client = () => window.P2K_TEAM_POINTS_CLIENT;
  const opponentEndpoint = 'server/team-points/public/opponents-admin.php';
  const liveEndpoint = 'server/team-points/public/live-ranks-admin.php';
  const state = { opponentInventory: [], opponentResults: [], selectedFiles: [], liveRunning: false, mcaSyncRunning: false, mcaSync: {} };
  const MCA_REBUILD_TIMEOUT_MS = 110_000;
  const MCA_PROFILE_STEP_TIMEOUT_MS = 75_000;
  const MCA_PROFILE_LAUNCH_BUDGET_SECONDS = 12;
  const MCA_PROFILE_BATCH_CAP = 32;

  function message(id, text, type = '') {
    const node = $(id); if (!node) return;
    node.textContent = text;
    node.className = `p2k-tp-status${type ? ` ${type}` : ''}`;
  }
  function fmt(value, max = 1) { return Number(value || 0).toLocaleString('en-GB', { maximumFractionDigits: max }); }
  function oauthTransportPlan() {
    const diagnostics = window.P2K_API_CLIENT?.diagnostics?.() || {};
    const enabled = diagnostics.oauthBearerMode === true;
    const target = Math.max(1, Math.min(256, Number(diagnostics.oauthGatewayTarget || 8) || 8));
    const rate = Math.max(0.5, Math.min(120, Number(diagnostics.oauthGatewayRateTarget || 8) || 8));
    return { enabled, target, rate, reservoir: enabled ? Math.max(32, Math.min(256, Math.ceil(rate * 4))) : 8 };
  }
  function observeOAuthTransport(payload) {
    if (payload?.oauth_transport) window.P2K_API_CLIENT?.observeOAuthBatch?.(payload.oauth_transport);
  }
  function mcaProfileBatchLimit(oauth) {
    if (!oauth?.enabled) return 8;
    const rate = Math.max(0.5, Number(oauth.rate || 0.5));
    const paced = Math.max(1, Math.floor(rate * MCA_PROFILE_LAUNCH_BUDGET_SECONDS));
    return Math.max(1, Math.min(MCA_PROFILE_BATCH_CAP, Number(oauth.reservoir || MCA_PROFILE_BATCH_CAP), paced));
  }
  function bytes(value) {
    let n = Number(value || 0); const units = ['B','KB','MB','GB']; let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toLocaleString('en-GB', { maximumFractionDigits: i ? 1 : 0 })} ${units[i]}`;
  }
  async function endpoint(base, options = {}) {
    if (!client()?.endpointRequest) throw new Error('The secured Team Points client is not ready.');
    return client().endpointRequest(base, options);
  }
  async function download(response, fallback) {
    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const name = disposition.match(/filename="([^"]+)"/i)?.[1] || fallback;
    const url = URL.createObjectURL(blob); const a = document.createElement('a');
    a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  }

  function opponentStatusClass(status) {
    if (status === 'unchanged') return 'good';
    if (status === 'disabled') return 'bad';
    return 'warn';
  }
  function renderOpponentRows(rows, scanned = false) {
    const host = $('opponentAdminRows'); if (!host) return;
    if (!rows.length) { host.innerHTML = '<tr><td colspan="6">No opponent records are stored yet. Run Team Points discovery first.</td></tr>'; return; }
    host.innerHTML = rows.map(row => {
      const oldSlug = row.old_slug || row.opponent_slug || '';
      const oldName = row.old_name || row.display_name || oldSlug;
      const status = scanned ? (row.status || 'unchanged') : (row.disabled ? 'disabled' : 'not_checked');
      const action = status === 'renamed' || status === 'rename_or_name_update' ? 'Update name/alias' : status === 'disabled' ? 'Mark disabled' : 'No change';
      return `<tr>
        <td><strong>${esc(oldName)}</strong><br><small>${esc(oldSlug)}</small></td>
        <td>${fmt(row.matches,0)}</td>
        <td><span class="p2k-tp-state ${opponentStatusClass(status)}">${esc(status.replaceAll('_',' '))}</span><br><small>${esc(row.message || row.last_error || '')}</small></td>
        <td>${esc(row.new_name || oldName)}</td><td>${esc(row.new_slug || oldSlug)}</td><td>${esc(action)}</td>
      </tr>`;
    }).join('');
  }
  async function loadOpponents() {
    message('opponentAdminStatus', 'Loading opponent inventory…');
    try {
      const payload = await endpoint(opponentEndpoint, { action: 'list' });
      state.opponentInventory = payload.rows || []; state.opponentResults = [];
      renderOpponentRows(state.opponentInventory, false);
      $('opponentApply').disabled = true; $('opponentExport').disabled = true;
      message('opponentAdminStatus', `${state.opponentInventory.length} opponent club(s) loaded.`, 'success');
    } catch (error) { message('opponentAdminStatus', error.message, 'error'); }
  }
  async function scanOpponents() {
    message('opponentAdminStatus', oauthTransportPlan().enabled ? 'Checking opponent club endpoints through the adaptive OAuth Bearer gateway…' : 'Checking opponent club endpoints serially…');
    $('opponentScan').disabled = true;
    try {
      if (!state.opponentInventory.length) {
        const inventory = await endpoint(opponentEndpoint, { action: 'list' });
        state.opponentInventory = inventory.rows || [];
      }
      const slugs = state.opponentInventory.map(row => row.opponent_slug).filter(Boolean);
      state.opponentResults = [];
      for (let offset = 0; offset < slugs.length; offset += 25) {
        const oauth = oauthTransportPlan();
        const payload = await endpoint(opponentEndpoint, { action: 'scan', method: 'POST', body: { slugs: slugs.slice(offset, offset + 25), oauth_concurrency: oauth.target, oauth_rate_cps: oauth.rate } });
        observeOAuthTransport(payload);
        state.opponentResults.push(...(payload.rows || []));
        renderOpponentRows(state.opponentResults, true);
        message('opponentAdminStatus', `${Math.min(offset + 25, slugs.length)} / ${slugs.length} opponent endpoints checked…`);
      }
      const changed = state.opponentResults.filter(row => ['renamed','rename_or_name_update','disabled'].includes(row.status));
      $('opponentApply').disabled = changed.length === 0; $('opponentExport').disabled = state.opponentResults.length === 0;
      message('opponentAdminStatus', `${state.opponentResults.length} checked; ${changed.length} update(s) or disabled club(s) detected.`, 'success');
    } catch (error) { message('opponentAdminStatus', error.message, 'error'); }
    finally { $('opponentScan').disabled = false; }
  }
  async function applyOpponents() {
    const rows = state.opponentResults.filter(row => ['renamed','rename_or_name_update','disabled'].includes(row.status));
    if (!rows.length) return;
    message('opponentAdminStatus', 'Applying detected opponent name and status changes…');
    try {
      const payload = await endpoint(opponentEndpoint, { action: 'apply', method: 'POST', body: { rows } });
      message('opponentAdminStatus', `${payload.updated || 0} opponent record(s) updated.`, 'success');
      await loadOpponents();
    } catch (error) { message('opponentAdminStatus', error.message, 'error'); }
  }
  function exportOpponents() {
    if (!state.opponentResults.length) return;
    const quote = value => `"${String(value ?? '').replaceAll('"','""')}"`;
    const headers = ['old_name','old_slug','status','new_name','new_slug','disabled','message'];
    const text = '\uFEFF' + [headers, ...state.opponentResults.map(row => headers.map(key => row[key] ?? ''))]
      .map(row => row.map(quote).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a'); a.href = url; a.download = 'p2k-opponent-name-check.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  }

  function stateBadge(value) {
    const cls = value === 'current_member' || value === 'former_member' ? 'good' : value === 'possible_renamed' || value === 'pending_profile' ? 'warn' : 'bad';
    return `<span class="p2k-tp-state ${cls}">${esc(String(value || 'unknown').replaceAll('_',' '))}</span>`;
  }
  function renderMcaSync(sync = {}) {
    state.mcaSync = sync || {};
    const progress = Math.max(0, Math.min(100, Number(sync.progress_percent || 0)));
    const progressNode = $('liveRanksSyncProgress'); if (progressNode) progressNode.value = progress;
    if ($('liveRanksSyncPercent')) $('liveRanksSyncPercent').textContent = `${progress.toFixed(1)}%`;
    const queue = sync.queue || {};
    const metrics = [
      ['Known files needing dates', sync.total_events || 0], ['Checked', sync.checked_events || 0],
      ['Dates added', sync.dates_added || 0], ['Errors', sync.error_count || 0],
      ['Requests', sync.request_count || 0], ['Next scan', sync.next_scan_at || '—']
    ];
    if ($('liveRanksSyncMetrics')) $('liveRanksSyncMetrics').innerHTML = metrics.map(([label,value]) => `<div class="p2k-tp-metric"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');
    const errors = Array.isArray(sync.errors) ? sync.errors : [];
    const errorWrap = $('liveRanksSyncErrorsWrap'), errorRows = $('liveRanksSyncErrorRows');
    if (errorWrap) errorWrap.hidden = errors.length === 0;
    if (errorRows) errorRows.innerHTML = errors.map(row => `<tr>
      <td><a href="${esc(row.arena_url || '#')}" target="_blank" rel="noopener noreferrer"><strong>#${fmt(row.arena_id,0)}</strong><br><small>${esc(row.arena_slug || '')}</small></a></td>
      <td>${esc(row.stage || 'page')}</td><td>${fmt(row.attempts || 0,0)}</td><td><small>${esc(row.error || 'Unable to recover event date')}</small></td><td>${esc(row.updated_at || '—')}</td>
    </tr>`).join('');
    const active = sync.status === 'running';
    const current = sync.current_arena_id ? ` · arena #${fmt(sync.current_arena_id,0)}` : '';
    const remaining = Number(queue.pending || 0) + Number(queue.running || 0);
    const text = active
      ? `Date backfill running${current}. ${fmt(remaining,0)} stored arena(s) remain; requests are serial and server-paced at ≥1 second apart.`
      : sync.status === 'failed'
      ? `Date backfill failed: ${sync.last_error || 'unknown error'}`
      : sync.status === 'completed'
      ? `Date backfill completed. ${fmt(sync.checked_events || 0,0)} stored arena(s) checked${Number(sync.dates_added||0) ? `, ${fmt(sync.dates_added,0)} date(s) added` : ''}${Number(sync.error_count||0) ? `, ${fmt(sync.error_count,0)} error(s)` : ''}.`
      : 'Ready to timestamp stored MCA source files whose actual event date is still unknown.';
    message('liveRanksSyncStatus', text, sync.status === 'failed' ? 'error' : sync.status === 'completed' && !Number(sync.error_count||0) ? 'success' : '');
    if ($('liveRanksSyncStart')) $('liveRanksSyncStart').disabled = state.mcaSyncRunning;
    if ($('liveRanksSyncResume')) $('liveRanksSyncResume').disabled = state.mcaSyncRunning || !active;
    if ($('liveRanksSyncRetry')) $('liveRanksSyncRetry').disabled = state.mcaSyncRunning || errors.length === 0;
  }

  function renderLive(payload) {
    const files = payload.files || [], players = payload.players || [], summary = payload.summary || {}, processing = payload.processing || {};
    renderMcaSync(payload.sync || {});
    $('liveRanksFileRows').innerHTML = files.length ? files.map(file => {
      const approx = file.event_date_approximate ? ' <small>(approximative date)</small>' : '';
      const editLabel = file.actual_event_date ? 'Edit actual date' : 'Set actual date';
      return `<tr>
      <td><a href="${esc(file.event_url || '#')}" target="_blank" rel="noopener noreferrer"><strong>${esc(file.name)}</strong></a>${file.arena_id ? `<br><small>Arena #${fmt(file.arena_id,0)}</small>` : ''}${file.replaced_at ? '<br><small>Corrected replacement</small>' : ''}</td>
      <td><strong>${esc(file.event_date || '—')}</strong>${approx}<br><button type="button" class="p2k-tp-small" data-mca-event-date="${Number(file.id)||0}" data-current-date="${esc(file.actual_event_date || '')}">${editLabel}</button></td>
      <td>${bytes(file.size)}</td><td><code>${esc(String(file.sha256 || '').slice(0,12))}…</code></td><td>${esc(file.uploaded_at || '—')}</td>
      <td>${stateBadge(file.status === 'processed' ? 'current_member' : file.status === 'error' ? 'closed_account' : 'pending_profile').replace(/current member|closed account|pending profile/, esc(file.status || 'uploaded'))}${file.error ? `<br><small>${esc(file.error)}</small>` : ''}</td>
      <td>${fmt(file.rows,0)}</td><td>${fmt(file.p2k_rows,0)}</td></tr>`;
    }).join('') : '<tr><td colspan="8">No stored MCA source CSV files.</td></tr>';
    $('liveRanksFileRows').querySelectorAll('[data-mca-event-date]').forEach(button => button.addEventListener('click', async () => {
      const fileId=Number(button.dataset.mcaEventDate||0),current=String(button.dataset.currentDate||'');
      const value=window.prompt('Actual MCA event date (YYYY-MM-DD). Leave blank to clear the known date and return to interpolation/upload fallback.',current);
      if(value===null)return;
      const date=value.trim();if(date&&!/^\d{4}-\d{2}-\d{2}$/.test(date)){message('liveRanksStatus','Use YYYY-MM-DD for the actual MCA event date.','error');return;}
      button.disabled=true;
      try{const updated=await endpoint(liveEndpoint,{action:'event_date',method:'POST',body:{file_id:fileId,event_date:date}});const refreshed={...payload,files:updated.files||files};renderLive(refreshed);message('liveRanksStatus',date?'Actual MCA event date saved; neighboring approximate dates were recalculated.':'Known date cleared; approximate dates were recalculated.','success');}
      catch(error){message('liveRanksStatus',error.message,'error');button.disabled=false;}
    }));
    $('liveRanksPlayerRows').innerHTML = players.length ? players.map(player => `<tr>
      <td><strong>${esc(player.username)}</strong></td><td>${fmt(player.points,2)}</td><td>${fmt(player.arenas,0)}</td>
      <td>${stateBadge(player.account_state)}</td><td><small>${esc((player.source_files || []).join(' · '))}</small></td>
      <td>${esc(player.profile_checked_at || 'Pending')}${player.error ? `<br><small>${esc(player.error)}</small>` : ''}</td></tr>`).join('') : '<tr><td colspan="6">No computed players.</td></tr>';
    const metrics = [
      ['Stored MCA source files', files.length], ['P2K players', summary.players || 0], ['Arena points', fmt(summary.total_points,2)],
      ['Ranked 50+', summary.ranked_players || 0], ['Current members', summary.current_members || 0], ['Possible renames', summary.possible_renames || 0],
      ['Closed accounts', summary.closed_accounts || 0], ['Pending checks', summary.pending_checks || 0]
    ];
    $('liveRanksMetrics').innerHTML = metrics.map(([label,value]) => `<div class="p2k-tp-metric"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('');
    $('liveRanksExportCorrections').disabled = Number(summary.possible_renames || 0) === 0;
    if (processing.status === 'running') message('liveRanksStatus', `Processing ${processing.phase || ''}: ${processing.checked_players || 0} profile check(s) completed.`, '');
  }
  async function loadLive() {
    try { const payload = await endpoint(liveEndpoint, { action: 'status' }); renderLive(payload); }
    catch (error) { message('liveRanksStatus', error.message, 'error'); }
  }
  function setSelectedFiles(files) {
    state.selectedFiles = [...files].filter(file => /\.csv$/i.test(file.name));
    message('liveRanksStatus', state.selectedFiles.length ? `${state.selectedFiles.length} MCA source CSV file(s) selected for upload.` : 'No MCA source CSV files selected.');
  }
  const LIVE_UPLOAD_BATCH_FILES = 10;
  const LIVE_UPLOAD_BATCH_BYTES = 6 * 1024 * 1024;

  function liveUploadBatches(files) {
    const batches = [];
    let batch = [];
    let bytes = 0;
    for (const file of files) {
      const size = Number(file.size || 0);
      if (batch.length && (batch.length >= LIVE_UPLOAD_BATCH_FILES || bytes + size > LIVE_UPLOAD_BATCH_BYTES)) {
        batches.push(batch);
        batch = [];
        bytes = 0;
      }
      batch.push(file);
      bytes += size;
      // A single large file must still be attempted by itself. PHP will return
      // an explicit size/configuration error when the hosting limit is lower.
      if (batch.length >= LIVE_UPLOAD_BATCH_FILES || bytes >= LIVE_UPLOAD_BATCH_BYTES) {
        batches.push(batch);
        batch = [];
        bytes = 0;
      }
    }
    if (batch.length) batches.push(batch);
    return batches;
  }

  async function uploadLiveRequest(files, attempt = 1) {
    const form = new FormData();
    files.forEach(file => form.append('files[]', file, file.name));
    try {
      return await endpoint(liveEndpoint, { action: 'upload', method: 'POST', body: form });
    } catch (error) {
      if (attempt >= 3) throw error;
      await new Promise(resolve => setTimeout(resolve, 700 * attempt));
      return uploadLiveRequest(files, attempt + 1);
    }
  }

  async function uploadLive() {
    if (!state.selectedFiles.length) { message('liveRanksStatus', 'Select or drop at least one MCA source CSV file.', 'error'); return; }
    const selected = [...state.selectedFiles];
    const batches = liveUploadBatches(selected);
    const totals = { uploaded: 0, replaced: 0, errors: [], unresolved: [] };
    let lastPayload = null;
    $('liveRanksUpload').disabled = true;
    $('liveRanksProcess').disabled = true;
    try {
      for (let index = 0; index < batches.length; index++) {
        const batch = batches[index];
        message('liveRanksStatus', `Uploading batch ${index + 1} of ${batches.length} · ${totals.uploaded + totals.errors.length} / ${selected.length} file(s) handled…`);
        const payload = await uploadLiveRequest(batch);
        lastPayload = payload;
        const uploaded = payload.uploaded || [];
        const errors = payload.errors || [];
        totals.uploaded += uploaded.length;
        totals.replaced += (payload.replaced || []).length;
        totals.errors.push(...errors);

        // PHP commonly defaults to max_file_uploads=20. The normal batches are
        // smaller, but detect any still-lower host limit and retry omitted files
        // one at a time rather than silently losing them.
        const handled = new Set([
          ...uploaded.map(row => String(row.name || '').toLowerCase()),
          ...errors.map(row => String(row.name || '').toLowerCase()),
        ]);
        const omitted = batch.filter(file => !handled.has(String(file.name || '').toLowerCase()));
        for (const file of omitted) {
          message('liveRanksStatus', `Host omitted ${file.name} from a multipart batch; retrying it separately…`);
          const single = await uploadLiveRequest([file]);
          lastPayload = single;
          const singleUploaded = single.uploaded || [];
          const singleErrors = single.errors || [];
          totals.uploaded += singleUploaded.length;
          totals.replaced += (single.replaced || []).length;
          totals.errors.push(...singleErrors);
          if (singleUploaded.length + singleErrors.length === 0) totals.unresolved.push(file.name);
        }
        if (lastPayload) renderLive(lastPayload);
      }

      state.selectedFiles = [];
      $('liveRanksFiles').value = '';
      const created = Math.max(0, totals.uploaded - totals.replaced);
      const failed = totals.errors.length + totals.unresolved.length;
      const details = [
        `${totals.uploaded} / ${selected.length} file(s) uploaded`,
        `${created} new`,
        `${totals.replaced} filename replacement(s)`,
        `${failed} failure(s)`,
        'previous computation invalidated',
      ];
      if (totals.unresolved.length) details.push(`host omitted: ${totals.unresolved.join(', ')}`);
      message('liveRanksStatus', details.join(' · '), failed ? 'error' : 'success');
      await loadLive();
    } catch (error) {
      message('liveRanksStatus', `Upload stopped after ${totals.uploaded + totals.errors.length} / ${selected.length} file(s): ${error.message}`, 'error');
    } finally {
      $('liveRanksUpload').disabled = false;
      $('liveRanksProcess').disabled = false;
    }
  }
  async function processLive() {
    if (state.liveRunning) return false;
    let success = false;
    state.liveRunning = true; $('liveRanksProcess').disabled = true;
    try {
      message('liveRanksStatus', 'Parsing all stored MCA source CSV files and reading the current P2K member list…');
      let payload = await endpoint(liveEndpoint, { action: 'process_start', method: 'POST', body: {}, timeoutMs: MCA_REBUILD_TIMEOUT_MS });
      renderLive(payload);
      while (Number(payload.summary?.pending_checks || 0) > 0) {
        const oauth = oauthTransportPlan();
        const batchLimit = mcaProfileBatchLimit(oauth);
        message('liveRanksStatus', `${payload.summary.pending_checks} former-player profile check(s) remaining. Points are already computed and visible.${oauth.enabled ? ` OAuth Bearer target ${oauth.target} @ ${fmt(oauth.rate,1)} CPS; probing a bounded batch of ${batchLimit}.` : ''}`);
        payload = await endpoint(liveEndpoint, { action: 'process_step', method: 'POST', body: { limit: batchLimit, oauth_concurrency: oauth.target, oauth_rate_cps: oauth.rate }, timeoutMs: MCA_PROFILE_STEP_TIMEOUT_MS });
        observeOAuthTransport(payload);
        renderLive(payload);
      }
      const renamed = Number(payload.summary?.possible_renames || 0);
      message('liveRanksStatus', renamed
        ? `Computation completed. ${renamed} possible renamed account(s) remain included under their MCA source usernames; review/export them and re-upload corrected MCA source CSV files when available.`
        : 'Live rank points computation completed successfully.', renamed ? 'error' : 'success');
      success = true;
    } catch (error) { message('liveRanksStatus', error.message, 'error'); }
    finally { state.liveRunning = false; $('liveRanksProcess').disabled = false; }
    return success;
  }
  async function runMcaSourceSync(force = false) {
    if (state.mcaSyncRunning) return;
    state.mcaSyncRunning = true; renderMcaSync(state.mcaSync || {});
    let payload = null;
    try {
      if (force || state.mcaSync?.status !== 'running') {
        message('liveRanksSyncStatus', 'Building the timestamp queue from stored MCA CSV files with missing dates…');
        payload = await endpoint(liveEndpoint, { action: 'sync_start', method: 'POST', body: { force }, timeoutMs: 45_000 });
        renderLive(payload);
      }
      while ((payload?.sync || state.mcaSync || {}).status === 'running') {
        payload = await endpoint(liveEndpoint, { action: 'sync_step', method: 'POST', body: {}, timeoutMs: 45_000 });
        renderLive(payload);
      }
      const finalSync = payload?.sync || state.mcaSync || {};
      if (finalSync.status === 'completed') {
        message('liveRanksSyncStatus', `MCA date backfill completed: ${fmt(finalSync.dates_added || 0,0)} date(s) added, ${fmt(finalSync.error_count || 0,0)} error(s). No arena discovery or CSV download was performed.`, Number(finalSync.error_count||0) ? 'error' : 'success');
      }
    } catch (error) {
      message('liveRanksSyncStatus', error.message, 'error');
      try { await loadLive(); } catch (_) {}
    } finally {
      state.mcaSyncRunning = false; renderMcaSync(state.mcaSync || {});
    }
  }

  async function retryMcaSourceErrors() {
    if (state.mcaSyncRunning) return;
    try {
      message('liveRanksSyncStatus', 'Re-queueing failed timestamp lookups for stored MCA arenas…');
      const payload = await endpoint(liveEndpoint, { action: 'sync_retry_errors', method: 'POST', body: {}, timeoutMs: 45_000 });
      renderLive(payload);
      if (payload.sync?.status === 'running') await runMcaSourceSync(false);
    } catch (error) { message('liveRanksSyncStatus', error.message, 'error'); }
  }

  async function syncMcaBlueToGreen() {
    const button = $('liveRanksBlueGreen'); if (!button) return;
    if (!window.confirm('Replace the Green MCA data domain with the current Blue MCA snapshot? Other Green analytics are not touched.')) return;
    button.disabled = true; message('liveRanksBlueGreenStatus', 'Validating Blue MCA source files and copying the MCA domain to Green…');
    try {
      const payload = await endpoint(liveEndpoint, { action: 'sync_blue_to_green', method: 'POST', body: {}, timeoutMs: 120_000 });
      const result = payload.blue_to_green || {}; renderLive(payload);
      message('liveRanksBlueGreenStatus', `Blue → Green MCA sync complete: ${fmt(result.rows_copied || 0,0)} row(s) across ${Object.keys(result.tables || {}).length} table(s); ${fmt(result.source_files_verified || 0,0)} source file(s) verified.`, 'success');
    } catch (error) { message('liveRanksBlueGreenStatus', error.message, 'error'); }
    finally { button.disabled = false; }
  }

  async function exportCorrections() {
    try { await download(await endpoint(liveEndpoint, { action: 'export_corrections', raw: true }), 'p2k-live-ranks-possible-renames.csv'); }
    catch (error) { message('liveRanksStatus', error.message, 'error'); }
  }

  function setup() {
    if (!$('liveRanksDrop')) return;
    $('opponentRefreshList').addEventListener('click', loadOpponents);
    $('opponentScan').addEventListener('click', scanOpponents);
    $('opponentApply').addEventListener('click', applyOpponents);
    $('opponentExport').addEventListener('click', exportOpponents);
    const drop = $('liveRanksDrop'), input = $('liveRanksFiles');
    input.addEventListener('change', () => setSelectedFiles(input.files || []));
    ['dragenter','dragover'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.add('is-dragging'); }));
    ['dragleave','drop'].forEach(type => drop.addEventListener(type, event => { event.preventDefault(); drop.classList.remove('is-dragging'); }));
    drop.addEventListener('drop', event => setSelectedFiles(event.dataTransfer?.files || []));
    drop.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); } });
    $('liveRanksUpload').addEventListener('click', uploadLive);
    $('liveRanksRefresh').addEventListener('click', loadLive);
    $('liveRanksProcess').addEventListener('click', processLive);
    $('liveRanksSyncStart')?.addEventListener('click', () => runMcaSourceSync(true));
    $('liveRanksSyncResume')?.addEventListener('click', () => runMcaSourceSync(false));
    $('liveRanksSyncRetry')?.addEventListener('click', retryMcaSourceErrors);
    $('liveRanksBlueGreen')?.addEventListener('click', syncMcaBlueToGreen);
    $('liveRanksExportCorrections').addEventListener('click', exportCorrections);
    loadOpponents(); loadLive();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, { once: true }); else setup();
})();
