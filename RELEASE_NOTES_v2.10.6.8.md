# Promote to King v2.10.6.8 — Green operational cutover

v2.10.6.8 converts the Green migration control from bootstrap-era hard readiness gates to an operational production switch suitable for the continuously cycling Green runtime.

## Green cutover behavior

- Adds one-click **Switch reads to Green** in Administration → Scheduled Task Control → Green.
- The switch sets public reads to Green while keeping **worker routing = Both** and **browser ingest = Both**, preserving Blue as the live rollback source.
- Adds separate **Make Green primary** and **Rollback reads to Blue** actions.
- Rollback restores Blue public reads with both maintenance paths active; it does not delete or reset Green.
- Green primary keeps Green public reads, routes Team Points maintenance to Green, and requests pause of the Blue Team Points workers. Blue data is not deleted.

## Readiness semantics

Continuous-cycle conditions no longer prevent cutover. In particular, ordinary non-zero values for unknown matches, pending boards, GFFL due/hot work, GABCRF convergence, or compatibility smoke findings are surfaced as **advisories** rather than hard blockers.

The only hard cutover prerequisites are technical ability to serve Green safely:

- Green Core connection/state available with schema 17 or newer.
- Green Analytics available with schema 9 or newer.

The public read router no longer requires `gab_status=ready`. Once Green is explicitly selected it still remains fail-closed for actual Green connection/schema failures; it never silently falls back to Blue after a Green cutover.

## Migration panel simplification

The main cutover card now focuses on useful production metrics:

- effective public read source;
- whether the Green switch is technically available;
- whether Blue rollback maintenance is active;
- advisory count;
- unknown matches / pending boards as live workload;
- current-match facts over the GFFL SLO;
- GFFL due/hot counts;
- GABCRF remaining drift/pass/attempts;
- compatibility smoke status;
- latest completed cycle duration.

The detailed phase/worker/ingest/mode selectors remain available under a collapsed **Advanced routing controls** section.

## Compatibility health checks

**Validate Green** now runs the compatibility smoke test on demand even while GAB is still converging. Its findings are displayed as advisory health information and do not prevent the operator from selecting Green public reads.

## Unchanged

- No database schema migration.
- No database reset or reseed.
- No CRON change.
- No source CSV or image change.
- GABCRF, GFFL, GQAC and normal Green quick/deep cycles continue after cutover.
- Blue remains available until the administrator deliberately makes Green primary or otherwise changes advanced routing.
