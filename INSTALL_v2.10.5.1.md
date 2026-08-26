# Install v2.10.5.1

This is a narrow corrective for an installed v2.10.5 release.

From the PromoteToKing document root, place the corrective ZIP beside the installer/updater, then run the supplied installer or the package updater.

The updater:

- accepts v2.10.5 and idempotent v2.10.5.1;
- verifies the payload hashes before copying;
- backs up every overwritten file;
- removes newly introduced files if rollback is needed;
- performs no database, reseed, CRON, or scheduler changes;
- validates runtime/version/cache consistency after installation.

After installation, hard-refresh the browser once. Runtime Diagnostics should show Package, Site config, and Manifest all on 2.10.5.1 with a single loaded asset cache generation.
