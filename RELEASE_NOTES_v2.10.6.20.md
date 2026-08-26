# Promote to King v2.10.6.20

## GFFL terminal-debt corrective

This release fixes repeated Green Factorized Freshness Lane requests for matches
whose current Chess.com detail resource is terminal, including a Daily team match
legitimately cancelled while still in registration and subsequently returning 404.

It also fixes browser-accelerated HTTP 200 GFFL observations so a successful
accelerated refresh closes the corresponding freshness debt just like the CRON
worker path. Existing stranded terminal/ineligible debt self-heals when GFFL is
planned or observed. Trusted historical Green facts are preserved.

No schema migration, database reset/reseed, CRON edit, routing change, scoring
change, heatmap formula change, or artwork change is required.
