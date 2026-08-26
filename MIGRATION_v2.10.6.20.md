# Migration to v2.10.6.20

No database migration or reset is required. The corrective uses the existing
`p2k_g_gffl_match_debt` and `p2k_g_matches` schema.

After deployment, the next GFFL plan/snapshot automatically retires already
stranded pending debts for terminal/ineligible matches. HTTP 404/410 remains
terminal for the current freshness obligation unless the exact match later
reappears in the authoritative Chess.com club index.
