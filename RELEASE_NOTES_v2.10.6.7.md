# Promote to King v2.10.6.7 — Green cutover observability and readiness correction

v2.10.6.7 is a narrow Green production-cutover corrective over v2.10.6.6. It does not change Club Points scoring, Green acquisition, database schema, CRON cadence, MCA timestamp-only behavior, or public-read default routing.

## Green observability corrections

- Green server timestamps in Scheduled Task Control are now interpreted as UTC and displayed in the browser's local timezone, including cycle start, GAB phases, GFFL oldest-due time, accelerator timestamps and Green worker invocation history.
- GAB reconciliation no longer presents cumulative projection attempts as `processed / unique match target`. The UI labels reconciliation as convergence work, shows cumulative projection attempts, pass number, and the last exact full-pass remaining count when available.
- GAB overall progress cannot show 100% while any lane remains incomplete.
- If the final GAB lane completes exactly at the end of a worker slice, GAB is finalized to `ready` immediately instead of waiting for another invocation. Status also self-heals a transient `running` state when all lanes are already complete, and refuses to advertise `ready` if a lane is incomplete.
- Public-read parity no longer uses the obsolete hard-coded denominator `13`. The exact number of checks is established from the current smoke audit; a pending audit is shown without a fake denominator.
- GQAC now explains when no cohort exists (for example while a quick cycle is still in `quick_matches` and is waiting for `quick_boards`).

## Cutover readiness

Administration → Scheduled Task Control → Green now shows explicit cutover readiness metrics:

- Green validation: READY / WAIT
- GAB / read adapter: READY / WAIT
- Read cutover: READY / BLOCKED
- blocking check count and exact blocker names

The normal migration gate remains fail-closed. v2.10.6.7 does not force Green public reads and does not weaken the existing validation/GAB/public-read adapter checks. When all gates pass, the recommended first production step remains **Green reads with both writers maintained**, leaving Blue live as the rollback source.

## Data / migration impact

- Database schema change: none.
- Database reset: none.
- Reseed: none.
- CRON change: none.
- Images: unchanged; reuse the v2.10.5.5 image archive.
- MCA source behavior remains v2.10.6.6 timestamp-only known-file date backfill.
