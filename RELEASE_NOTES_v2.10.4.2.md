# Promote to King v2.10.4.2

## OAuth administrator persistence hotfix

v2.10.4.2 fixes the remaining reload/iframe administrator-authority regression introduced by the v2.10.4 security split.

### Root cause

v2.10.4 changed Team Points administrator-session creation so `session.php` reopened the HttpOnly `P2KOAUTH` PHP session in a second HTTP request. On production this second-session recovery was not reliable after a fresh page load, so a normal reload could leave Live Ranks, Storage & Capacity, Team Points Migration, and other administration surfaces without Team Points administrator authority even though the main OAuth adapter had already authenticated the real user.

v2.10.4.1 removed the browser-local username prerequisite, but it still depended on that same second `P2KOAUTH` reopen and therefore did not fix the production reload failure.

### v2.10.4.2 behavior

- `oauth.php?action=session` remains the authoritative real-OAuth session check used by every page.
- When authenticated, it returns a short-lived (60 second) HMAC-signed administrator-bootstrap assertion containing only the real OAuth username and audience/expiry claims.
- The assertion is signed with an existing protected server-only Team Points secret (`admin_token`, with `cron_token` fallback); the secret never leaves the server.
- `team-points-client.js` waits for the real OAuth adapter and sends only that signed assertion to `session.php`.
- `session.php` verifies the assertion without reopening `P2KOAUTH`, then independently verifies that the asserted username is a current Promote to King administrator before creating `P2KTPSESSID`.
- A stale assertion on a long-lived page triggers a real-OAuth refresh before Team Points reconnect.
- A compatibility fallback still permits direct `P2KOAUTH` recovery when it is available, but normal reloads no longer depend on it.
- `?name=` remains display-only and cannot alter the signed real identity or server authorization.
- Raw browser-posted usernames are never trusted for administrator authorization.
- Guarded administration pages now load `team-points-client.js` before `admin-page-guard.js` consistently.

### Scope

No database migration, Green state mutation, GICL/GQAC/GSCF change, Blue/Green cutover, or CRON change is included in this hotfix.
