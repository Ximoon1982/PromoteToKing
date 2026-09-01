# Promote to King v2.11.0 R5

R5 is a same-version corrective release for the canonical v2.11.0 Green-primary Administration line. `VERSION` remains `2.11.0`.

## Recruitment

- Recruitment is a native **Administration → Members → Recruitment** detail. The canonical Admin header and category navigation remain visible; the obsolete detail iframe is not mounted for this route.
- First-paint route suppression prevents the Members overview/iframe from flashing behind Recruitment.
- Candidate capacity is raised from 10,000 to **100,000** normalized unique Chess.com usernames.
- The pre-scan membership phase no longer performs the previous per-candidate Members Insights lookup. It resolves current/former/none membership directly from canonical Green identity/player data in batches, with visible preparation progress.
- Membership is revalidated server-side at every result checkpoint before the authoritative Recruitment decision is stored.
- Non-GET Recruitment requests use the secured Team Points session/CSRF client.
- Recruitment state remains checkpointed and resumable. Only the most recent 500 checkpointed rows are rendered in the browser for large scans; the stored run and CSV remain complete.

## Maintenance and Green production

- **Data Reconciliation** and legacy production-migration routes/controls are retired from the active Administration UI. The old reconciliation mutation endpoint returns HTTP 410 `RECONCILIATION_RETIRED`; source remains in the repository for recovery/history.
- Freshness is rebuilt from canonical Green Core/Analytics state rather than Blue-era compatibility freshness tables.
- The Freshness surface now reports Green roster age, player/profile/stats coverage, rating/avatar coverage, current-match debt, historical match audit debt, board debt, GFFL, work claims/retries, GQAC quick-cycle state, cycle/worker state, Analytics source-cycle lag and tournament maintenance.
- Missing timestamps are reported as **Unknown**, never as Current.
- The Maintenance overview GFFL headline is backed by the actual Green GFFL due count.
- Legacy ACAMR per-player work-class warnings are no longer inferred from unrelated Green current-match debt after Green primary.
- Remaining visible migration/Blue/"Green Team Points" production terminology is removed or normalized to Team Points/Green-native operational terms as appropriate.

## Deployment and compatibility

- No database schema change.
- No runtime-data reset.
- No configuration reset.
- The installer is transactional: all replaced files are backed up under `data/release-backups/` and restored on failure.
- `ui-v2.html` and Club Intelligence cache keys are refreshed so R5 JavaScript is fetched immediately after deployment.
