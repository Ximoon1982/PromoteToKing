# Promote to King v2.10.4.1

## Admin authority propagation hotfix

v2.10.4 correctly moved administrator authorization to the real server-side Chess.com OAuth identity, but several administration surfaces still required a browser-local username before they attempted the secured Team Points session bootstrap. Embedded or early-loading frames could therefore show a false “Log in with a verified club administrator account” message even while the HttpOnly `P2KOAUTH` session was valid.

v2.10.4.1 fixes that propagation regression across the shared Team Points client, Dashboard/site router, standalone admin guard, Live Ranks administration, Storage & Capacity, and Team Points Migration.

### Security model retained
- `session.php` remains authoritative: it derives the real username only from the HttpOnly `P2KOAUTH` server session and verifies current Promote to King administrator membership.
- The browser posts no username when opening a Team Points admin session.
- `?name=<username>` remains display-only. A viewed non-admin does not inherit the real account's admin UI.
- `?oauth=1` simulated mode does not use the real-OAuth admin shortcut.
- OAuth logout/auth changes clear cached Team Points CSRF state in the current page.

### Deployment impact
- Source baseline: v2.10.4.
- No database migration or reset.
- No Blue/Green routing change.
- No Green state mutation.
- No CRON change.
