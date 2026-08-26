# Install v2.10.6.4

Upgrade from v2.10.6.3 with the supplied incremental ZIP and updater. The updater uses the established IONOS-safe PHP CLI selection pattern and validates payload hashes before and after installation.

No database migration or reseed is required.

After installation, start a fresh MCA **Source sync** so the paginated index is re-read and the exposed CSV URLs are captured. Existing failed queue entries from the previous cycle do not need to be preserved.
