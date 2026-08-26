# Promote to King v2.7.3

## Scope

Authentication/access decoupling only. No Team Points schema, Insights, CRON, tournament, scoring, recommendation, or importer behavior is changed.

## Changes

- Administrator page access no longer opens MariaDB or calls the Chess.com public API.
- `?admin=1` remains an offline-safe administrator access path.
- Embedded administrator pages inherit the already-established administrator state from their parent page without database access.
- Future OAuth sessions can grant administrator access through an administrator claim/role.
- An optional local `adminUsernames` allow-list is available in `config/site-branding.js` for OAuth-authenticated identities when a local authorization list is preferred.
- Existing short-lived local administrator markers remain accepted as a transition path.
- DB-backed controls establish their Team Points server session only when those controls actually need the database. A database failure therefore affects the relevant data/control panel rather than hiding the administrator page.
- `AnalyzeMatch.html` is public again; the accidental administrator page guard was removed.

## Immediate access behavior

With MariaDB unavailable, pages opened with `?admin=1` continue to render. Non-database tools remain usable. Components whose data source is MariaDB can display their normal connection/error state without revoking administrator page access.

## Future OAuth integration

The client access layer recognizes OAuth session claims such as `isAdmin`, `admin`, `superAdmin`, and roles/permissions including `admin`, `administrator`, or `super_admin`. This keeps login identity independent from application data services.
