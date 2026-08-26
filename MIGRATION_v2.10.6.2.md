# v2.10.6 / v2.10.6.1 → v2.10.6.2

This is a non-destructive cumulative hotfix. No database migration, reset, or reseed is required.

The installer accepts either `2.10.6` or `2.10.6.1` as the installed VERSION. It backs up every replaced file, verifies payload hashes, applies the cumulative overlay, checks the final VERSION and performs PHP/JavaScript syntax checks where the required CLI is available.

After installation, open Administration Tools → MCA source data. The previous durable error rows are displayed. Use **Retry failed events** to reprocess only those failures with the corrected acquisition logic. Use **Sync MCA Blue → Green** when you want Green's MCA domain to become an exact validated snapshot of Blue's MCA domain.
