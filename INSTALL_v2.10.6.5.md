# Install v2.10.6.5

Install over the authoritative v2.10.6.4 source tree using the supplied incremental updater.

The updater verifies payload hashes, creates a rollback backup, selects a PHP 8.x CLI using the established IONOS-safe detection pattern, applies the overlay, verifies the installed payload, and PHP-lints delivered PHP files.

No database migration or reseed is required.

After installation, run **Source sync**. Do not use the prior failed queue as the normal discovery path: the fresh scan will rebuild the queue using the corrected new-arena-only logic.
