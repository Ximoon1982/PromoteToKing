# Promote to King v2.11.0 R2

Corrective Administration stability release for v2.11.0. No database schema or Team Points calculation change.

## Administration SPA stability

- Administration styling now follows the live Public/Admin state instead of the URL state captured only at initial page load.
- The red Administration accent remains synchronized while switching categories, opening/closing details, using browser history and refreshing.
- Recruitment is mounted reliably in **Admin → Members** after in-page navigation as well as direct page loads.
- The canonical Recruitment detail remains `view=admin&adminCategory=members&adminDetail=recruitment` and keeps the existing resumable server-side candidate/run state.

## Embedded Administration tools

- Adds a shared embedded-frame height sender driven by ResizeObserver plus DOM mutation observation.
- The active Admin detail iframe is kept visible with a safe minimum height while its embedded tool changes content.
- This addresses tools whose result surface could appear to disappear/collapse after an analysis or dynamic rerender, including Match Creation.

## Team Points maintenance cleanup

- Visible **Green Team Points** wording becomes simply **Team Points** now that Green is the sole production architecture.
- Removes the retired cutover/rollback controls from Task Control.
- Removes the retired GAB migration/bootstrap card from the normal maintenance surface.
- Removes the retired historical heatmap backfill card.
- Keeps production operations: worker/cycle status, GFFL, GQAC/runtime metrics, Team Points accelerator, invocation history, CRON/task controls and match monitoring.

## Deployment

- R2 leaves `VERSION` at `2.11.0` because this is a corrective revision of the same release.
- The R2 installer updates the central site configuration and stability module, then bumps `site-config.js` cache keys on the canonical Admin/embedded tool pages.
- No database state is changed by the R2 installer.
