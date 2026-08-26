# Install Promote to King v2.10.6.6

v2.10.6.6 is an incremental source update over v2.10.6.5.

## What changes

MCA automatic source discovery/download is removed. The MCA scheduled/manual worker now only timestamps already-stored CSV files whose actual event date is missing or has been invalidated by the future-date sanity check.

## Database

No schema migration, reset or reseed is required.

## After installation

Open **Administration tools → MCA source data**.

- Continue uploading new MCA Results CSV files manually.
- Use **Backfill missing dates** to timestamp any stored CSV without a trusted date.
- **Retry failed dates** retries only failed arena-page timestamp lookups.
- **Process complete MCA dataset** remains the explicit control for recomputing MCA statistics after uploads.

The twice-daily MCA CRON remains valid; it performs only the same timestamp-only maintenance.
