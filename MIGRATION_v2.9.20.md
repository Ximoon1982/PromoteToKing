# Promote to King v2.9.20 migration

v2.9.20 is a code/UI/transport corrective release from v2.9.19.

- Core schema remains 14.
- Analytics schema remains 7.
- Achievement catalogue remains 162.
- No database reset or reseed is required.
- Existing MIAC seed/runtime review state is preserved.
- Existing MCA uploaded/staged source data is preserved; MORCF permits retrying processing without re-upload when the prior run failed at OAuth rate capture.
- CRON cadence remains four operational jobs plus the weekly long-life backup.

Use the verified v2.9.19 → v2.9.20 incremental updater for an existing v2.9.19 production installation.
