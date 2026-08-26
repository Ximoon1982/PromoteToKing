# Browser support

The package targets current desktop and mobile versions of Chromium-based browsers, Firefox, and Safari.

Core requirements:

- ES2020 JavaScript features
- `fetch`, `AbortController`, `URL`, `Promise`, and `CustomEvent`
- same-origin iframes
- modern CSS grid/flexbox

Progressive enhancements:

- IndexedDB: persistent API cache; an in-memory LRU cache is used when unavailable
- BroadcastChannel: cross-tab request and analysis coordination; each page still works independently when unavailable
- Storage Estimate API: quota-aware emergency cleanup; static fallback ceilings are used otherwise
- Clipboard API: direct copying; existing manual-copy fallbacks remain
- ResizeObserver: automatic retained-frame height; normal iframe scrolling remains possible without it
- Visual Viewport API: more precise mobile popover placement around the on-screen keyboard and zoomed viewport; window dimensions are used when unavailable

The tools should be served through HTTP or HTTPS. `file://` execution is not supported.


Information popovers require standard pointer, focus, DOM geometry, and MutationObserver support available in the targeted current browsers. Same-origin retained frames additionally observe parent scroll/resize events; standalone and cross-origin embedding fall back to the local document viewport.


## Local server-default support

No browser extension or additional Python package is required. Start the included `serve_local.py` with Python 3.9 or newer. The static website remains usable through another HTTP server, but server-default Challenge Assistant list saving, usage logs, and match-history tracking require the packaged PHP backend or another implementation of the same API. Apache/PHP deployments use the included `api/` endpoint directories automatically.

## Versioned assets

Production deployments should include the packaged `.htaccess` where Apache-compatible hosting is used. HTML is revalidated on each visit; JavaScript and CSS are loaded with a release query token so a browser cannot silently combine different repository versions.
