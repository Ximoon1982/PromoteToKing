# Migration notes v2.10.6.5

- Database schema: unchanged (Core 17 / Analytics 9).
- Database reset: none.
- Blue/Green reseed: none.
- MCA source files: preserved.
- Existing event dates: preserved except impossible future dates, which continue to be cleared and re-backfilled.
- Existing failed queue rows are transient; a fresh Source sync recreates the queue using v2.10.6.5 discovery rules.
