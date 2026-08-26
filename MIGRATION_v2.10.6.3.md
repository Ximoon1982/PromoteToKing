# v2.10.6.2 -> v2.10.6.3

This is a source-only MCA pagination/date corrective.

- Database schema: unchanged (Core 17 / Analytics 9).
- Database reset: none.
- Reseed: none.
- Existing MCA files/data: retained.
- Existing failed MCA queue rows: retained. Use **Retry failed events** after installation; each failed arena restarts at its page stage and uses the new paginated traversal.
- Runtime scratch pagination files are created under the protected Live Ranks data area and are not public web assets.

The installer creates a rollback backup before replacing any existing file and uses the established IONOS modern-PHP CLI selection logic.
