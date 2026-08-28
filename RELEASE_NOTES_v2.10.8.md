# Promote to King v2.10.8

Baseline: v2.10.7

## Corrective scope

v2.10.8 is a focused corrective release. It changes no scoring rules, database schemas, Blue/Green routing, MCA acquisition logic, or user data.

### Release diagnostics and cache convergence

- Aligns `VERSION`, `MIGRATION_VERSION`, `site-manifest.json`, and `assets/js/site-config.js` on v2.10.8.
- Restores the site manifest schema marker to schema 8 and preserves the prior v2.10.6.24 manifest as `site-manifest_v2.10.6.24.json`.
- Advances every active root application page that loads `site-config.js` to the v2.10.8 cache key so browsers cannot remain on an older site-config copy.
- Cache-busts the runtime diagnostics script itself.
- Cache-busts the embedded Runtime Diagnostics iframe route itself so the browser also reloads the updated diagnostics HTML shell.
- Runtime Diagnostics no longer treats intentionally mixed cache generations for unrelated unchanged assets as a release-version error. It still warns when a loaded `site-config.js` cache generation disagrees with the current site-config/package version.

### Achievement refresh convergence

- Increases the optional achievement-maintenance slice from an impossible 7-second maximum to a 15-second maximum with an 11-second minimum start budget, matching the existing 10-second AnalyticsBuilder safety requirement.
- Advances the achievement logic watermark to `logic:achievement-v2108-canonical-counts`, forcing an achievement rebuild when v2.10.8 is first observed.
- During that rebuild, removes only the two proven transient v2.9.3 breadth identities: `groups-1` and `groups-20`. Unknown historical identities are otherwise preserved.

### Canonical achievement counts

- Hall of Fame achievement cards now count only keys present in the authoritative current `AchievementCatalog`.
- Members Insights uses the same catalogue-filtered count for display, milestone filtering, and achievement-count sorting.
- Player profile/catalogue behavior is unchanged: unknown/stale unlock identities remain excluded from the visible earned catalogue.
- This removes the common +1/+2 discrepancy such as a Hall card showing 177 achievements while the corresponding profile shows 175.

## Deployment behavior

The incremental installer upgrades v2.10.7 to v2.10.8 using complete replacement files. It validates its embedded payload, lints changed PHP, backs up every touched file, installs atomically, verifies installed hashes and version markers, and automatically restores the prior files on failure.

The installer does not reset or migrate databases, does not replace runtime data, local configuration, secrets, or CRON entries. The targeted `groups-1`/`groups-20` cleanup occurs inside the normal forced achievement rebuild after deployment.
