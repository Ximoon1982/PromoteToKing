# Install Promote to King v2.10.6.16

Preferred deployment is the incremental **v2.10.6.15 → v2.10.6.16** installer package.

The installer:

- requires the installed `VERSION` to be `2.10.6.15` (or exits harmlessly if already `2.10.6.16`);
- verifies every payload SHA-256 before modifying the installation;
- creates a timestamped rollback backup of every replaced file;
- installs only the cumulative v2.10.6.16 delta;
- performs release identity, PHP/JS syntax and package-validation gates after installation.

No database or CRON installation action is required for this release.
