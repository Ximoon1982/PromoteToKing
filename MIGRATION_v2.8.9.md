# Promote to King v2.8.9 migration

## From v2.8.8.x

No SQL migration, database reset or re-seed is required.

- Core remains schema **6**.
- Analytics remains schema **5**.
- Existing production configuration/secrets must be preserved.
- Existing CRON entries remain valid.

v2.8.9 re-enables ACAMR at the application level; it does not add an ACAMR database table or CRON job.

A hard browser refresh is recommended once after deployment because the Dashboard/API-client startup code and ACAMR client use the new v2.8.9 cache token.
