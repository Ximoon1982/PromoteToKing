# Install Promote to King v2.10.6.15

Preferred deployment is the incremental **v2.10.6.14 → v2.10.6.15** installer package.

The installer:

- requires the installed `VERSION` to be `2.10.6.14` (or exits harmlessly if already `2.10.6.15`);
- verifies the SHA-256 of every payload file before changing production;
- creates a timestamped rollback backup;
- installs only the cumulative v2.10.6.15 delta;
- verifies release identity after installation.

No database or CRON operation is performed.

After installation, hard-refresh `ui-v2.html` once.
