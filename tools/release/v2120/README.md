# Promote to King v2.12.0 universal incremental installer

Network-independent cumulative upgrade for canonical P2K 2.11.x installations.
It validates known immutable source bytes, stages and backs up every replacement,
verifies the activated v2.12.0 payload and rolls back on failure. Configuration,
secrets/OAuth, `.env`, databases, data, storage, caches, uploads, logs and system
CRON are excluded and preserved. No database migration or CRON change is included.

`./install-promote-to-king-v2.12.0.sh /path/to/PromoteToKing`

`./install-promote-to-king-v2.12.0.sh /path/to/PromoteToKing verify`

The script requires the sibling payload and manifest/list files supplied in the ZIP.
