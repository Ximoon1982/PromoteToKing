# Promote to King v2.9.21

## MCA Processing Request Budget Fix (MPRBF)

v2.9.21 is a focused corrective release built from the exact frozen v2.9.20 release.

The v2.9.20 MORCF correction allowed authenticated MCA profile verification to reach the shared OAuth transport, but the MCA administration page still inherited the generic Team Points 30-second request timeout. A legitimate full MCA rebuild or a profile batch running after adaptive OAuth backoff could therefore be aborted by the browser with:

> The Team Points administration request timed out before the server-side operation completed.

v2.9.21 corrects both sides of that request boundary:

- `process_start` receives an explicit 110-second administration-client deadline instead of the generic 30-second default.
- MCA OAuth `process_step` receives a 75-second client deadline.
- Profile-check batches are dynamically sized from the currently learned OAuth CPS using a 12-second launch budget.
- Each OAuth MCA profile batch is additionally capped at 32 profiles.
- `live-ranks-admin.php` independently enforces the same rate-derived limit, so a stale v2.9.20 browser cannot submit an oversized batch to the v2.9.21 backend.
- The MCA progress message now shows the learned OAuth rate and the bounded batch size.
- Existing stored MCA CSV source files remain reusable; no re-upload is required.

This release does not change MCA scoring, MIAC/MIRA attribution semantics, OAuth credentials, ACDM, DMBHF, database schemas, or CRON cadence.

## Compatibility

- Core schema: 14 (unchanged)
- Analytics schema: 7 (unchanged)
- Achievement catalogue: 162 (unchanged)
- No database reset, reseed or schema migration.
- Existing 4 operational CRON cadences and the weekly backup remain unchanged.
