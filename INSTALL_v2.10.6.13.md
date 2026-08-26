# Install Promote to King v2.10.6.13

## Incremental installation from v2.10.6.12

1. Upload `P2K_v2.10.6.12_to_v2.10.6.13_INCREMENTAL.zip` to the Promote to King web root.
2. From that web root, extract the installer to a temporary directory and run its shell script.
3. Hard-refresh `ui-v2.html` after the installer reports success.

The installer creates a timestamped `_p2k_backups/v2.10.6.12_to_v2.10.6.13_*` rollback copy of every replaced file.

No database, CRON, Green/Blue routing, scoring, or artwork operation is performed.
