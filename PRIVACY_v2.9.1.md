# Promote to King v2.9.1 — privacy notes

## Traffic / Visitors

Traffic analytics remain first-party and cookieless. Do Not Track and Global Privacy Control continue to suppress Traffic collection. Raw IP addresses, full external referrer URLs and browser fingerprints are not persisted by the Traffic analyzer.

v2.9.1 adds diagnostics that make DNT/GPC suppression and collector/storage health visible to administrators; it does not override those privacy signals.

## ACAMR operational telemetry

ACAMR is separate from the Traffic analyzer. When ACAMR is active for an authenticated/simulated-authenticated browser, it uses:

- a random client ID stored in `localStorage` to distinguish the browser profile until site storage is cleared;
- a random browsing-session ID stored in `sessionStorage` to distinguish the current browsing session;
- the authenticated actor username for operational accounting.

These raw values are sent only to the same-origin ACAMR planning endpoint. Protected ACAMR telemetry stores hashes rather than the raw client/session/actor identifiers. These IDs are operational diagnostics and do not make browser observations authoritative; canonical facts still require server verification.
