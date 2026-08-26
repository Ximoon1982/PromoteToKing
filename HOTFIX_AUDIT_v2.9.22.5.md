# v2.9.22.5 Task Control / Diagnostics Hotfix Audit

Observed production symptoms:

- only the browser-native Fast Fetcher / Fresh Reconstruction cards remained visible;
- Runtime Diagnostics reported 8-second timeouts for `insights-health.php` and traffic intelligence;
- loaded cache markers mixed `2.9.22.4.4`, older release markers, while site-config reported `2.9.22.4`.

Root causes confirmed in the exact v2.9.22.4 package:

1. `server/control/public/api.php?action=status` synchronously generated full Club and Player work details for every Task Control refresh. Large durable queues made card bootstrap depend on expensive queue/coverage scans.
2. Task Control also synchronously waited for Fresh Reconstruction staging sync before rendering the server task cards.
3. `intelligence.php` eagerly opened Core and Analytics before dispatching `scope=traffic`, despite traffic analytics being filesystem-backed.
4. Runtime Diagnostics imposed the same 8-second deadline on DB-backed health and lightweight diagnostics.
5. Twenty HTML/HTM entry points were stamped with the invalid cache generation `2.9.22.4.4`; additional entry points retained older cache generations.

Corrective contract:

- status bootstrap returns task registry state + CRON shell markers only;
- expensive work reports are lazy per selected task;
- all four server cards have a visible fallback state if bootstrap is unavailable;
- traffic diagnostics bypass databases;
- Insights Health gets a separate 20-second diagnostic budget;
- all HTML/HTM `?v=` asset markers are exactly `2.9.22.5`.
