# Promote to King v2.10.6.4

## MCA index CSV acquisition corrective

v2.10.6.4 corrects the remaining MCA Results auto-sync failure seen after v2.10.6.3.

### Root cause
The paginated Promote to King past-events index exposed Results CSV download links, but `discoverArenaLinks()` retained only arena identities and initialized every `csv_url` to an empty string. P2K therefore discarded the authoritative CSV URL and unnecessarily tried to rediscover/export Results from each arena page.

### Corrective behavior
- Every paginated MCA index page is parsed as arena + exposed Results CSV metadata.
- CSV URLs are accepted from normal anchors, data export attributes, and hydrated JSON URLs.
- URLs are associated by arena id where possible and by nearest arena card as a guarded fallback.
- Index CSV URLs survive duplicate/rename merging and retry queue resets.
- If an event needs CSV but its date is already known, the worker starts directly at the CSV stage.
- If its date is missing, the worker fetches the arena page once for the date, then uses the already captured index CSV URL.
- Arena-page CSV discovery and complete Player Results reconstruction remain fallback paths only.
- Existing >=1 request/second serial source pacing is unchanged.

### Data / migration
No schema migration, database reset, Green reseed, or MCA data deletion is required.
