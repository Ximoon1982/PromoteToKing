# Promote to King v2.9.21 migration

Upgrade from the exact frozen v2.9.20 release using the supplied incremental updater.

There is no database schema migration, reset or reseed. Core remains revision 14 and Analytics remains revision 7. Existing MCA source CSV files and computed state are retained. After deployment, the existing stored MCA CSV dataset can be processed again directly from Team Points Administration; re-upload is not required.
