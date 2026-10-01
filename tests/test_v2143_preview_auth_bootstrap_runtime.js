'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const oauthSource = fs.readFileSync(path.join(root, 'assets/js/shared/real-oauth.js'), 'utf8');
const tpSource = fs.readFileSync(path.join(root, 'assets/js/shared/team-points-client.js'), 'utf8');
const bootstrap = Buffer.from(JSON.stringify({
  username: 'ximoon',
  csrf: 'oauth-csrf',
  admin_bootstrap: 'signed.preview.assertion',
  team_points: { username: 'ximoon', csrf: 'tp-csrf', expires_at: Math.floor(Date.now()/1000)+1800 },
  profile: { username: 'ximoon', realOAuth: true, oauthVerified: true, authMode: 'real-oauth' }
}), 'utf8').toString('base64');

let oauthFetches = 0;
let teamSessionPosts = 0;
const timers = [];
const events = [];
const meta = { content: bootstrap };
const document = {
  readyState: 'complete',
  body: {},
  querySelector(selector) {
    if (selector === 'meta[name="p2k-preview-auth-bootstrap"]') return meta;
    if (selector === '.site-header') return null;
    return null;
  },
  getElementById() { return null; },
  addEventListener() {},
  createElement() {
    return { setAttribute(){}, addEventListener(){}, appendChild(){}, replaceChildren(){},
      classList:{add(){},remove(){}}, style:{}, dataset:{} };
  }
};
const window = {
  location: { search: '', href: 'https://p2k.test/ui-v2.html', assign() {} },
  setTimeout(fn, delay = 0) { timers.push(delay); return timers.length; },
  clearTimeout() {},
  addEventListener() {},
  dispatchEvent(event) { events.push(event.detail); },
  P2K_API_CLIENT: { setOAuthBearerMode() {} },
  P2K_SITE_CONFIG: { serverStorage: {} }
};
const shared = {
  window, document, URL, URLSearchParams,
  CustomEvent: class { constructor(_name, options) { this.detail = options?.detail; } },
  console, requestAnimationFrame() {},
  MutationObserver: class { observe() {} disconnect() {} },
  atob: value => Buffer.from(value, 'base64').toString('binary'),
  TextDecoder, Uint8Array, JSON, Date, Intl, Buffer, AbortController, Response, FormData
};
const oauthContext = vm.createContext({
  ...shared,
  fetch: async () => { oauthFetches++; throw new Error('initial OAuth status probe must not run'); }
});
vm.runInContext(oauthSource, oauthContext);

(async () => {
  const ready = await window.P2K_REAL_OAUTH_READY;
  assert.equal(ready.username, 'ximoon');
  assert.equal(window.P2K_AUTH.getSession().username, 'ximoon');
  assert.equal(window.P2K_AUTH.getCsrf(), 'oauth-csrf');
  assert.equal(window.P2K_AUTH.getAdminBootstrap(), 'signed.preview.assertion');
  assert.equal(oauthFetches, 0);
  assert.ok(timers.includes(30_000));

  const tpContext = vm.createContext({
    ...shared,
    fetch: async (url, options={}) => {
      if (String(url).includes('/server/team-points/public/session.php')) teamSessionPosts++;
      return new Response(JSON.stringify({ok:true,username:'ximoon',csrf:'unexpected'}), {status:200,headers:{'content-type':'application/json'}});
    }
  });
  vm.runInContext(tpSource, tpContext);
  const connected = await window.P2K_TEAM_POINTS_CLIENT.connect();
  assert.equal(connected.username, 'ximoon');
  assert.equal(connected.csrf, 'tp-csrf');
  assert.equal(teamSessionPosts, 0, 'existing preview admin session should not be recreated on reload');
  console.log('Validated candidate reload restores OAuth/admin identity without initial public session probes.');
})().catch(error => { console.error(error); process.exitCode = 1; });
