# Promote to King v2.11.0 R3

Corrective hotfix for the native Administration → Members → Recruitment route.

Root cause: the v2.11.0 Recruitment module expected `dashboardAdminMainContent`, while the canonical toggle Administration shell uses `adminDashboardHost`. The core Admin router therefore displayed Members again and the native Recruitment module had no host to mount into.

R3 adds a small route bridge which creates the expected native mount host only for `view=admin&adminCategory=members&adminDetail=recruitment`. The existing Recruitment UI, server checkpointing and candidate scanner are unchanged.

No database, schema, Team Points calculation, Recruitment criteria or scan behavior is changed.
