# Promote to King v2.10.5 — Installation

## Split standalone package

The v2.10.5 standalone is delivered as two synchronized ZIP archives:

1. the main source archive, containing the complete release except `assets/images`;
2. the image-assets archive, containing `assets/images` at the same project-relative path.

Extract both into the same document root. The split is packaging-only; the preserved project-level source tree contains every file.

Do not overwrite protected local configuration with credentials from another environment. The release does not ship production `config.local.php`, `oauth.local.php`, `green.local.php`, database credentials, CRON tokens, runtime queues, logs or generated data.

## Cumulative incremental update

From the PromoteToKing document root, extract the incremental package and run its updater. It accepts any numbered v2.10.4.x migration baseline through v2.10.4.6 and is idempotent on v2.10.5.

The updater:

- backs up every existing source file it will replace;
- copies the complete sanitized non-image v2.10.5 source overlay;
- runs the idempotent Green schema migration when Green configuration is present;
- installs the accepted five-entry Green scheduler when `crontab` is available;
- preserves Blue and unrelated CRON entries;
- never reseeds or resets either database.

If the hosting shell does not expose `crontab`, the source update still succeeds and prints the scheduler command path for manual IONOS CRON management.

## After install

1. Hard-refresh the Dashboard and Administration pages.
2. Open Administration → Scheduled Task Control and confirm Green reports 50 s soft / 55 s hard and the five-entry one-per-minute scheduler contract.
3. Open Administration → Production Migration and verify the effective public source remains Blue.
4. Open Administration → Members and confirm the chronology panel loads.
5. Let a normal Quick cycle run and inspect GQAC retired/requeued/completion telemetry.

Public Green-read cutover remains safety-gated in v2.10.5; do not bypass that gate by manually editing state.
