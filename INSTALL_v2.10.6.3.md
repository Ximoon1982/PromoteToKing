# Install Promote to King v2.10.6.3

## Incremental update from v2.10.6.2

Place `P2K_v2.10.6.2_to_v2.10.6.3_INCREMENTAL.zip` in the PromoteToKing directory and use the supplied same-package updater. The updater:

1. accepts v2.10.6.2 as the source runtime;
2. selects PHP CLI 8.0+ using the established IONOS order (preferring `/usr/bin/php8.5-cli`);
3. verifies every payload SHA-256;
4. backs up every replaced file and records new files;
5. applies the overlay;
6. verifies installed payload hashes;
7. runs PHP/shell syntax checks with the selected modern PHP CLI;
8. keeps the rollback backup after success.

No database migration, reset or reseed is performed.

After installation, open **Administration Tools -> MCA source data** and use **Retry failed events**. Previously failed 25-of-N arenas will be retried with paginated Player Results traversal.
