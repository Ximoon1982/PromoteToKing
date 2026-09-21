'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

async function main() {
  // Execute the production loaders with controllable transports and rendering.
  const dashboard = read('assets/js/admin/admin-session-controller.js');
  const loader = dashboard.slice(dashboard.indexOf('async function loadAdminRecentMatches('), dashboard.indexOf('async function loadAdminPriorityHealth('));
  let resolve;
  let renders = 0;
  const state = { admin: true, adminPriorityHealthLoading: true, adminRecentMatches: null };
  const ctx = vm.createContext({ state, Date, renderAdminPriorityCard: () => renders++, loadJSON: () => new Promise(r => { resolve = r; }) });
  vm.runInContext(loader, ctx);
  const pending = ctx.loadAdminRecentMatches({ force: true });
  assert.equal(state.adminRecentMatchesLoading, true);
  resolve({ matches: [{ id: 123 }] });
  await pending;
  assert.equal(state.adminRecentMatches.length, 1);
  assert.equal(state.adminPriorityHealthLoading, true, 'recent metric must finish while health remains pending');
  assert.equal(renders, 2, 'render on start and completion');
  for (const [payload, expected] of [[{}, null], [{ ok: false, matches: [] }, null], [{ matches: [] }, 0]]) {
    ctx.loadJSON = async () => payload;
    await ctx.loadAdminRecentMatches({ force: true });
    assert.equal(state.adminRecentMatches?.length ?? null, expected);
  }
  ctx.loadJSON = async () => { throw new Error('offline'); };
  await ctx.loadAdminRecentMatches({ force: true });
  assert.equal(state.adminRecentMatches, null);

  // Execute the whole OAuth adapter; keep the DOM uninitialized to isolate transport.
  const events = [];
  const document = { readyState: 'loading', addEventListener() {}, querySelector() { return null; }, getElementById() { return null; } };
  const bearerModes = [];
  const scheduledTimeouts = [];
  const window = {
    location: { search: '', href: 'https://p2k.test/index.html' },
    setTimeout: (fn, delay = 0) => { scheduledTimeouts.push(delay); if (delay <= 250) fn(); return scheduledTimeouts.length; },
    clearTimeout() {},
    dispatchEvent: event => events.push(event.detail),
    P2K_API_CLIENT: { setOAuthBearerMode: enabled => bearerModes.push(Boolean(enabled)) }
  };
  const oauth = vm.createContext({ window, document, URL, URLSearchParams, CustomEvent: class { constructor(name, options) { this.detail = options.detail; } }, console: { warn() {}, error() {} }, requestAnimationFrame() {}, fetch: async () => { throw new Error('offline'); } });
  vm.runInContext(read('assets/js/shared/real-oauth.js'), oauth);
  const api = window.P2K_AUTH;
  await api.refresh();
  assert.equal(api.getSession(), null, 'an initial outage must not invent identity');
  oauth.fetch = async () => ({ ok: true, json: async () => ({ authenticated: true, profile: { username: 'Alice' }, csrf: 'csrf', admin_bootstrap: 'bootstrap' }) });
  await api.refresh();
  assert.equal(api.getSession().username, 'Alice');
  assert.equal(bearerModes.at(-1), true, 'verified OAuth session enables Bearer transport');
  events.length = 0;
  for (const failure of [async () => { throw new Error('offline'); }, async () => ({ ok: false, status: 503, json: async () => ({}) }), async () => ({ ok: true, json: async () => ({}) })]) {
    oauth.fetch = failure;
    assert.equal((await api.refresh()).username, 'Alice');
    assert.equal(api.getSession().username, 'Alice');
    assert.equal(api.getCsrf(), 'csrf');
    assert.equal(api.getAdminBootstrap(), 'bootstrap');
    assert.equal(bearerModes.at(-1), true, 'status outage must retain Bearer transport for the last verified session');
  }
  assert.ok(events.every(event => event?.username === 'Alice'), 'outages must not emit a logout');
  assert.ok(scheduledTimeouts.some(delay => delay === 30_000), 'transient outage schedules a bounded recovery probe');
  oauth.fetch = async () => ({ ok: true, json: async () => ({ authenticated: false }) });
  await api.refresh();
  assert.equal(api.getSession(), null, 'authoritative logout must clear identity');
  assert.equal(api.getAdminBootstrap(), '');
  console.log('Validated independent recent-match completion, unknown states and transient OAuth identity retention.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
