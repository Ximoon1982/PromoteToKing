/* MCA -> MIAC reconciliation: a review queue, never an automatic merger. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number = v => Number(v || 0).toLocaleString('en-GB', {maximumFractionDigits: 2});
  const base = 'server/team-points/public/mca-renames.php';
  const perPage = 25;
  const state = {report: null, page: 1, busy: false, loading: false};

  function message(value, error = false) {
    const el = $('mcaRenameStatus');
    if (el) { el.textContent = value; el.className = 'p2k-tp-status' + (error ? ' error' : ''); }
  }
  async function call(action, options = {}) {
    const client = window.P2K_TEAM_POINTS_CLIENT;
    if (!client?.endpointRequest) throw new Error('Secured Team Points admin session is unavailable.');
    return client.endpointRequest(base, {action, ...options});
  }

  function render() {
    const d = state.report || {}, c = d.counts || {}, mira = d.mira || {};
    const rows = Array.isArray(d.unresolved) ? d.unresolved : [];
    if ($('mcaRenameMetrics')) $('mcaRenameMetrics').innerHTML = [
      ['Unresolved MCA usernames', c.unresolved || 0],
      ['MIAC candidates for review', c.ready_for_miac_review || 0],
      ['Already mapped; awaiting attribution', c.resolved_pending_attribution || 0],
      ['MIAC map generation', mira.generation || 0],
    ].map(pair => '<div class="p2k-tp-metric"><span>' + esc(pair[0]) + '</span><strong>' + number(pair[1]) + '</strong></div>').join('');
    const rebuild = $('mcaRenameRebuild');
    if (rebuild) rebuild.disabled = state.busy || !mira.stale || !mira.source_rows_available;
    if ($('mcaRenameAttributionStatus')) $('mcaRenameAttributionStatus').textContent = mira.stale
      ? 'MCA attribution is stale: MIAC identity generation ' + number(mira.derived_generation) + ' → ' + number(mira.generation) + '. Rebuild after reviewing changes.'
      : 'MCA attribution matches MIAC identity generation ' + number(mira.derived_generation || mira.generation) + '.';
    const totalPages = Math.max(1, Math.ceil(rows.length / perPage));
    state.page = Math.max(1, Math.min(state.page, totalPages));
    const subset = rows.slice((state.page - 1) * perPage, state.page * perPage);
    const tbody = $('mcaRenameRows');
    if (tbody) {
      tbody.innerHTML = subset.length ? subset.map((r, offset) => {
        const index = (state.page - 1) * perPage + offset;
        const suggestions = (r.suggestions || []).map(s => {
          const info = '<small>' + esc(s.status) + ' · ' + esc(s.confidence || 'Possible') + ' · ' + number(s.shared_boards) + ' shared board(s) · ' + esc(s.source || '') + '</small>';
          const actions = s.status === 'candidate'
            ? '<div class="p2k-tp-actions"><button type="button" class="p2k-tp-button" data-mca-review="' + index + '" data-mca-target="' + esc(s.new_username) + '" data-mca-decision="confirmed">Confirm</button>' +
              '<button type="button" class="p2k-tp-button" data-mca-review="' + index + '" data-mca-target="' + esc(s.new_username) + '" data-mca-decision="rejected">Reject</button></div>' : '';
          return '<div style="margin:.3rem 0"><strong>' + esc(s.new_username) + '</strong> ' + info + actions + '</div>';
        }).join('');
        const files = Array.isArray(r.source_files) ? r.source_files : [];
        const details = files.length
          ? '<details><summary>' + number(files.length) + ' source arena(s)</summary><div style="max-height:10rem;overflow:auto;overflow-wrap:anywhere">' +
            files.map(f => '<div><small>' + esc(f) + '</small></div>').join('') + '</div></details>' : '—';
        const profile = 'https://www.chess.com/member/' + encodeURIComponent(r.username_key);
        const aliases = 'ClubIntelligence.html?tab=aliases&amp;miac_search=' + encodeURIComponent(r.username_key);
        return '<tr><td><strong>' + esc(r.username) + '</strong><br><a href="' + profile + '" target="_blank" rel="noopener noreferrer">Chess.com profile</a></td>' +
          '<td>' + number(r.points) + '<br><small>' + number(r.arenas) + ' arena(s)</small></td><td>' + details + '</td>' +
          '<td><small>' + esc(r.verification_error || 'Chess.com username unavailable') + '</small>' +
          (r.identity_conflict ? '<div><strong>MIAC identity conflict</strong></div>' : '') + '</td>' +
          '<td>' + (suggestions || '<small>No MIAC candidate yet</small>') +
          '<div class="p2k-tp-actions" style="margin-top:.5rem;flex-wrap:wrap">' +
          '<input class="p2k-tp-input" data-mca-alias-input="' + index + '" maxlength="30" autocomplete="off" placeholder="Verified new username" style="flex:1;min-width:8rem;max-width:14rem" aria-label="New username for ' + esc(r.username) + '">' +
          '<button type="button" class="p2k-tp-button" data-mca-propose="' + index + '">Propose to MIAC</button></div>' +
          '<a href="' + aliases + '" target="_blank" rel="noopener noreferrer">Open alias management</a></td></tr>';
      }).join('') : '<tr><td colspan="5">No unresolved MCA possible renames. Already confirmed aliases are excluded.</td></tr>';
      tbody.querySelectorAll('[data-mca-propose]').forEach(button => button.addEventListener('click', () => propose(rows[Number(button.dataset.mcaPropose)], Number(button.dataset.mcaPropose))));
      tbody.querySelectorAll('[data-mca-review]').forEach(button => button.addEventListener('click', () => review(rows[Number(button.dataset.mcaReview)], button.dataset.mcaTarget, button.dataset.mcaDecision)));
    }
    const pager = $('mcaRenamePager');
    if (pager) {
      pager.innerHTML = rows.length > perPage
        ? '<button type="button" class="p2k-tp-button" id="mcaRenamePrev" ' + (state.page === 1 ? 'disabled' : '') + '>Previous</button>' +
          '<span>Page ' + number(state.page) + ' / ' + number(totalPages) + ' · ' + number(rows.length) + ' unresolved</span>' +
          '<button type="button" class="p2k-tp-button" id="mcaRenameNext" ' + (state.page === totalPages ? 'disabled' : '') + '>Next</button>' : '';
      $('mcaRenamePrev')?.addEventListener('click', () => {state.page--; render();});
      $('mcaRenameNext')?.addEventListener('click', () => {state.page++; render();});
    }
    if (d.ok) message(number(c.unresolved) + ' unresolved · ' + number(c.resolved_pending_attribution) + ' already mapped · ' + number(c.ready_for_miac_review) + ' MIAC candidates.');
    window.P2K_EMBEDDED_PAGE?.notifyHeight?.();
  }

  async function refresh() {
    if (state.loading || state.busy) return;
    state.loading = true;
    message('Loading MCA possible renames and MIAC identity evidence…');
    try { state.report = await call('report', {timeoutMs: 40_000}); render(); }
    catch (error) { message('MCA rename reconciliation unavailable: ' + (error.message || error), true); }
    finally { state.loading = false; }
  }

  async function propose(row, index) {
    if (!row || state.busy) return;
    const input = document.querySelector('[data-mca-alias-input="' + index + '"]');
    const target = String(input?.value || '').trim();
    if (!target) {message('Enter the proposed new Chess.com username.', true); input?.focus(); return;}
    if (!window.confirm('Propose ' + row.username + ' → ' + target + ' for MIAC review?\n\nA missing account does NOT prove a rename. This creates an unconfirmed candidate only; no points are merged.')) return;
    state.busy = true;
    try {
      await call('propose', {method: 'POST', body: {old_username: row.username, new_username: target}, timeoutMs: 45_000});
      message('MIAC candidate saved. Review its evidence before confirming or rejecting.');
    } catch (error) {message('MIAC proposal failed: ' + (error.message || error), true);}
    finally {state.busy = false; await refresh();}
  }

  async function review(row, target, decision) {
    if (!row || !target || state.busy) return;
    const warning = decision === 'confirmed'
      ? '\n\nThis changes canonical identity attribution after MCA rebuild. Verify the identity first; a missing account is not proof of a rename.'
      : '\n\nThis preserves the separate historical identities and records an explicit rejection.';
    if (!window.confirm((decision === 'confirmed' ? 'CONFIRM' : 'REJECT') + ' ' + row.username + ' → ' + target + '?' + warning)) return;
    state.busy = true;
    try {
      const result = await call('review', {method: 'POST', body: {old_username: row.username, new_username: target, decision}, timeoutMs: 45_000});
      state.report = result.report; state.busy = false; render();
      if (result.change?.status === 'conflict') message('MIAC detected contradictory verified player IDs. No accounts were merged.', true);
      else message('MIAC: ' + row.username + ' → ' + target + ': ' + (result.change?.status || decision) + '. Rebuild MCA attribution if the MIAC generation changed.');
    } catch (error) {message('MIAC review failed: ' + (error.message || error), true);}
    finally {state.busy = false;}
  }

  async function download() {
    try {
      const response = await call('export', {raw: true, timeoutMs: 40_000});
      const url = URL.createObjectURL(await response.blob());
      const anchor = document.createElement('a');
      anchor.href = url; anchor.download = 'p2k-mca-unresolved-renames.csv';
      document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
    } catch (error) {message('MCA export failed: ' + (error.message || error), true);}
  }

  function rebuild() {
    if (!state.report?.mira?.stale) return;
    if (!window.confirm('Rebuild MCA attribution from existing Results sources using the current MIAC map?\n\nExisting CSV files and score evidence are preserved.')) return;
    const button = $('liveRanksProcess');
    if (!button) {message('The MCA rebuild control is unavailable.', true); return;}
    button.click();
    message('MCA processing requested. Refresh this queue once processing completes.');
  }

  function setup() {
    if (!$('mcaRenameRows')) return;
    $('mcaRenameRefresh')?.addEventListener('click', refresh);
    $('mcaRenameExport')?.addEventListener('click', download);
    $('mcaRenameRebuild')?.addEventListener('click', rebuild);
    window.P2K_MCA_RENAME_REVIEW = {refresh, download};
    refresh();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, {once: true});
  else setup();
})();
