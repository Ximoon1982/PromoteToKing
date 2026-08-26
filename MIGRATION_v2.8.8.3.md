# Promote to King v2.8.8.3 migration

From v2.8.8, v2.8.8.1 or v2.8.8.2:

- **No database migration is required.**
- Core schema remains **6**.
- Analytics schema remains **5**.
- No reset or reseed is required.
- CRON cadence is unchanged.

The migration is behavioral: Dashboard loading is rolled back to the v2.8.6 data path and ACAMR is disabled. Existing ACAMR runtime telemetry may remain as historical operational data; no new ACAMR work is issued after deployment.
