# Promote to King v2.10.6.15

## Green Scheduled Task Control reachability corrective

v2.10.6.15 is a cumulative corrective release from the canonical v2.10.6.14 source tree.

### Corrected

- Restores `Scheduled tasks / Green Team Points` as the native navigation owned by `TaskControl.html`, including when Task Control is embedded.
- Replaces the v2.10.6.14 Admin-detail duplication with a parent-level `Task Control / Production migration` split. Green remains a native Task Control surface rather than a separate parent iframe tab.
- Scheduled Task Control card shortcuts can still open either Scheduled tasks or Green Team Points directly.
- Persists the native Task Control subtab in `adminToolTab` for the new Admin shell.
- Restores Green reachability through legacy `page=administration&adminTool=tasks`, including Lost & found links and existing bookmarks.
- Legacy Administration now propagates Task Control tab changes into the parent URL and restores the selected tab on Refresh / Back / Forward.
- Direct `TaskControl.html?tab=green` behavior remains supported.

### Green operational functionality preserved

The corrective explicitly retains the authoritative Green operations surface, including:

- Green scheduler/runtime and cutover metrics;
- migration phase, worker/browser routing and force-mode controls;
- GAB start/resume, slice execution, metrics and per-phase progress;
- GFFL enable/disable/freshness-target controls and metrics;
- Green Accelerator Start / Run once / Stop, metrics and log;
- current cycle/phase progress and recent Green worker invocations.

Production Migration remains a separate advanced/emergency diagnostic surface.

### Regression locks

- Public Dashboard DOM/layout unchanged from v2.10.6.14.
- Public Hall of Fame DOM/layout unchanged from v2.10.6.14.
- Public Insights DOM/layout unchanged from v2.10.6.14.
- UTC timestamp behavior from v2.10.6.13/v2.10.6.14 retained.
- No database schema/reset/reseed change.
- No CRON change.
- No Blue/Green routing or scoring change.
- No artwork change.
