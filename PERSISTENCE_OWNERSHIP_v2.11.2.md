# Persistence ownership — v2.11.2

| State | Canonical owner | Structural rule |
|---|---|---|
| Core SQL | Team Points repositories/services | Authoritative; no schema or write-semantic change |
| Analytics SQL | Analytics builder/repository | Rebuildable projection; payload shape frozen |
| Blue/Green SQL | Migration and Green services | Existing routing/cutover policy frozen |
| Filesystem cache | `FilesystemCache` and owning feature | Paths, keys, TTL and retention frozen |
| Session/OAuth | `OAuthSession` | v2.11.1 seven-day sliding contract frozen |
| Checkpoints | Existing process-specific stores | `AdminJob` adapts; it does not migrate formats |
| Runtime temporary files | Owning runtime service | Location, protection and cleanup frozen |
| Protected local configuration | Existing `*.local.php`/server config | Never packaged, moved, overwritten or logged |
| CRON | Existing installed crontab | Installer snapshots and verifies byte-identical entries |
