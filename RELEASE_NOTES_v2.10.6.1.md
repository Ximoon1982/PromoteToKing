# Promote to King v2.10.6.1

## Dashboard immediate-match refresh CSRF hotfix

v2.10.6 introduced a network-first refresh when an administrator opens a match from **New matches in the last 24 hours**. The endpoint correctly required the secured Team Points session and CSRF validation, but the dashboard used a direct `fetch()` and did not attach the `X-P2K-CSRF` token.

v2.10.6.1 routes that request through the existing `P2K_TEAM_POINTS_CLIENT.endpointRequest()` path. This preserves the server-side security contract while adding the existing client behavior for CSRF headers, expired-session re-establishment, and one retry after a recoverable 401/403.

No database migration, data reset, reseed, MCA change, or Green reconstruction change is required.
