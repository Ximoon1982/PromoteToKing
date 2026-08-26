# Promote to King v2.10.4 installation

## Upgrade from v2.10.3

Use the cumulative v2.10.3 → v2.10.4 incremental package and its bash installer. The installer verifies payload hashes, creates a compact rollback archive, preserves protected configuration/data, applies release-controlled files/removals, validates the resulting release and installs the Green GSCF schedule when Green is configured.

Supported source state:
- `VERSION=2.10.3`
- `MIGRATION_VERSION=2.10.3`
- `BLUE_BASELINE_VERSION=2.9.22.10`

Post-v2.10.3 release-controlled Green hotfixes are tolerated; the updater intentionally does not require exact v2.10.3 hashes before applying the canonical target.

Protected state is never shipped/overwritten:
- `server/team-points/config/config.local.php`
- `server/team-points/config/oauth.local.php`
- `data/server-config.json`
- `server/team-points-green/config/green.local.php`
- runtime data/cache/log directories.

## Database

No reset/reseed is required. Blue Core remains schema 16. Analytics schema 7→8 is additive and is applied by the normal Team Points Repository upgrade path on the first database-backed request/worker after deployment. Green GQAC adds two small accounting tables (`p2k_g_quick_board_cycles` and `p2k_g_quick_board_cycle_items`) idempotently when the quick-board lane first needs them; existing Green facts are not rewritten merely to install the accounting layer.

## Green GSCF

When `server/team-points-green/config/green.local.php` exists, the updater runs `reset-install-green-cron-v2.10.4.sh`. It removes only prior P2K Green feeder entries and installs one managed bounded Green feeder at minutes `2` and `32` each hour while retaining Blue/unrelated CRON entries. If `crontab` is unavailable, the release installation succeeds with a clear manual GSCF notice; no Blue schedule is rewritten.

## Acceptance

After deployment verify:
1. Runtime Diagnostics reports package/migration 2.10.4 and Blue engine baseline 2.9.22.10.
2. `?name=<non-admin>` hides administrator UI even when the real OAuth account is an administrator, while normal public/player data is shown for the named user.
3. Without `?name=`, the real administrator account can still open secured admin tools.
4. Achievements shows **See mine** and progress bars only for unearned measurable achievements.
5. Stored MCA sources expose arena links and editable actual event dates; approximate dates are labelled.
6. Green leaderboards contain current members only.
7. GQAC reports one finite quick-board cohort with total/completed/pending and does not increase that cohort when newer hints arrive mid-cycle.
8. GSCF contains exactly one managed Green CRON line using `2,32 * * * *`, and existing Blue/unrelated CRON entries remain present.


## Matches / Team Points artwork

v2.10.4 intentionally retains the seven existing SVG placeholders for First Match, First Step, Rising Star, Clutch Player, Great Strategist, Match Veteran and Match Legend. The exact recovered Aug-6 source filenames and SHA-256 values remain recorded in `ARTWORK_PROVENANCE_v2.10.4.json` for a later artwork-only integration.
