# Promote to King v2.8.10 architecture

## Stable startup boundary

The production-confirmed v2.8.9 Dashboard/auth startup graph is frozen. Initialization, session application and configured/local admin recognition do not depend on lazy Hall/Insights modules, Chess.com second-wave data, ACAMR, Tournament state or Match Tracking state.

## Canonical outcome boundary

For a finished non-void match, authoritative stored team scores determine win/draw/loss. Analytics consumes the score-derived canonical summary view rather than trusting a duplicated historical result label. Authoritative finished 0–0 remains void and analytically excluded.

## Public Dashboard merge boundary

The canonical database owns current membership and all-history totals. Chess.com current `/matches` data may enrich registration/in-progress state but may not overwrite canonical all-history Finished KPIs. Secondary enrichment remains automatic post-paint.

## Mutable runtime boundary

Release archives contain code, static assets, schema/migrations and configuration examples—not live secrets/registries/archives. Tournament and Match Tracking runtime files are created/maintained on the host and have conservative backup recovery.

## Protected write/request boundary

Admin mutations use CSRF plus an explicit `X-Club-Tools-Request` action kind. The shared client centrally maps protected endpoints to their required action kind. CRON uses a separate protected `X-P2K-Cron-Token` header with legacy query-token compatibility.

## CRON observability boundary

The shell dispatcher records an invocation marker before HTTP. Application Task Registry records endpoint execution. Comparing the two layers distinguishes scheduler/daemon failures from authentication/HTTP/application failures.

## Intelligence boundary

Intelligence features report measured values only. Where counterfactual or historical data was not collected (e.g. historical recruitment conversion, old arena source URLs, pre-snapshot detailed time travel), the UI states the limitation rather than synthesizing data.


## Tournament maintenance boundary

Tournament status checking is primary maintenance and runs on every 10-minute Tournament invocation. Discovery and podium refresh are staged extras. Public browse signatures include the active recovery candidates, so serving a `.bak`/timestamped backup after an accidentally empty primary cannot leave the BrowseIndex/ETag pinned to the empty archive.

## Admin routing and lookup efficiency

Configured/local administrators are recognized before any remote Chess.com club-profile verification in both the Dashboard and outer site router. Personalized member intelligence uses a keyed single-member query; bulk member intelligence is cached within a request for aggregate views, avoiding repeated full-roster scans during authenticated-home/profile rendering.
