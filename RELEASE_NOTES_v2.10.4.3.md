# Promote to King v2.10.4.3

Maintenance hotfix for administrator propagation on the Blue/Green migration panel and Achievement Condition Integrity.

## Team Points Migration administrator authority

- `TeamPointsMigration.html` now uses the same secured Team Points administrator session as Live Ranks, Storage & Capacity and the other administrator surfaces.
- Green browser API endpoints accept the secured `P2KTPSESSID` + `X-P2K-CSRF` authority through `P2K\TeamPoints\Auth`.
- The historical `X-P2K-Admin-Token` / `app.admin_token` path remains available only as an explicit compatibility fallback for manual/server tooling.
- A saved legacy token is no longer automatically activated after a reload.
- The migration panel refreshes immediately using the real OAuth administrator session.
- The stale visible migration build label is corrected to v2.10.4.3.
- Auth/client/guard cache-busters are refreshed across guarded administrator pages so deployment cannot appear unchanged because of cached v2.10.4.1/2 scripts.

## ACIF2 — Achievement Condition Integrity

v2.10.4 introduced progress metrics for the new achievement families, but the persisted unlock projection could remain stale. This created impossible catalogue states such as `130 / 100` while the 100-opponent achievement still appeared unearned.

v2.10.4.3 enforces the invariant that an authoritative threshold metric at or above its target is earned:

- Consecutive match-start days
- Match Impact — decisive winners and match savers
- Photo finish / close calls
- Winning Side
- Opponent Variety
- Rematch / Old Foes

Protection exists at two levels:

1. The historical achievement rebuild has a final threshold reconciliation pass. The achievement logic watermark is bumped to `logic:achievement-v21043-acif2`, forcing a new persistence rebuild.
2. Dashboard and standalone Achievement catalogue apply an immediate read-side reconciliation from the same authoritative progress rows. A completed threshold therefore cannot be displayed as unearned while a persistence rebuild is pending. Earned achievements do not show progress bars.

Normal chronological reconstruction still provides the exact historical completion date. The emergency reconciliation fallback does not invent an achievement date when an exact crossing timestamp is unavailable.

## Preserved behavior

- Public Team Points remains Blue-hardwired.
- Blue engine baseline remains v2.9.22.10.
- No Core or Analytics schema migration.
- No Green reset/reseed/state reset.
- No CRON change; GSCF remains as installed by v2.10.4.
- GICL and GQAC are unchanged.
- v2.10.4.2 signed OAuth → Team Points administrator handoff is retained.
- `?name=` remains display-only and cannot grant real server authority.
