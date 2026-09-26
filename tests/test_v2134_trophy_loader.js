const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync('assets/js/admin/tool-registry.js', 'utf8');
const appended = [];
const nodes = new Map();
const listeners = new Map();
function makeNode(tag) { return { tagName: String(tag).toUpperCase(), id: '', src: '', defer: false, onload: null, onerror: null }; }
const document = {
  head: { appendChild(node) { appended.push(node); if (node.id) nodes.set(node.id, node); return node; } },
  createElement: makeNode,
  getElementById(id) { return nodes.get(id) || null; },
  querySelector() { return null; }
};
const window = {
  P2K_DASHBOARD_MODULES: {},
  addEventListener(type, fn) { if (!listeners.has(type)) listeners.set(type, []); listeners.get(type).push(fn); },
  dispatchEvent(event) { for (const fn of listeners.get(event.type) || []) fn(event); }
};
const context = vm.createContext({ window, document, console, URL, queueMicrotask, Promise, setTimeout, clearTimeout });
vm.runInContext(source, context, { filename: 'tool-registry.js' });
window.P2K_DASHBOARD_MODULES.adminTools.create({
  byId: () => null,
  integratedAdminHref: () => '#',
  preservedURL: path => new URL(path, 'https://www.promotetoking.org/ui-v2.html'),
  config: { routes: {} }
});
const tick = () => new Promise(resolve => setImmediate(resolve));
const byId = id => nodes.get(id);
(async () => {
  await tick();
  assert(byId('p2kTrophyGalleryPocScript'));
  assert(!byId('p2kTrophyAdminViewV2121'));
  assert(!byId('p2kTrophyMatchesV2121'));
  assert(!byId('p2kTrophyEngraverV2121'));
  assert(!byId('p2kTrophyAdminV2121'));
  window.P2K_TROPHY_GALLERY_POC = { mount(){ window.__pocMounted = (window.__pocMounted || 0) + 1; } };
  byId('p2kTrophyGalleryPocScript').onload();
  await tick();
  assert.strictEqual(window.__pocMounted, 1);
  assert(byId('p2kTrophyPublicV2121'));
  assert(byId('p2kTrophyCardPresentationV2122'));
  window.dispatchEvent({ type: 'p2k-admin-shell-route', detail: { nativeKey: 'trophy-gallery' } });
  await tick();
  assert(byId('p2kTrophyAdminViewV2121'));
  assert(byId('p2kTrophyMatchesV2121'));
  assert(byId('p2kTrophyEngraverV2121'));
  assert(!byId('p2kTrophyAdminV2121'));
  window.P2K_TROPHY_ADMIN_VIEW_V2121 = {};
  byId('p2kTrophyAdminViewV2121').onload();
  await tick();
  assert(!byId('p2kTrophyAdminV2121'));
  window.P2K_TROPHY_MATCHES_V2121 = {};
  byId('p2kTrophyMatchesV2121').onload();
  await tick();
  assert(!byId('p2kTrophyAdminV2121'));
  window.P2K_TROPHY_ENGRAVER_V2121 = {};
  byId('p2kTrophyEngraverV2121').onload();
  await tick();
  assert(byId('p2kTrophyAdminV2121'));
  window.P2K_TROPHY_ADMIN_V2121 = { mount(){ window.__adminMounted = (window.__adminMounted || 0) + 1; } };
  byId('p2kTrophyAdminV2121').onload();
  await tick();
  assert.strictEqual(window.__adminMounted, 1);
  console.log('Trophy admin v2.13.4 lazy/parallel loader gate passed.');
})().catch(error => { console.error(error); process.exit(1); });
