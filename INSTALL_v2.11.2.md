# Install Promote to King v2.11.2

This corrective installer accepts any existing Promote to King `2.11.x` installation. It applies only the structural PHP consolidation files. It does not alter UI/display assets, behavior, configuration, runtime data, schemas, authentication, schedulers or CRON entries.

## Recommended command

```bash
chmod +x install-promote-to-king-2.11.2.sh PromoteToKing_v2.11.2_STRUCTURAL_CONSOLIDATION.run
./install-promote-to-king-2.11.2.sh /absolute/path/to/promote-to-king
```

Alternatively:

```bash
P2K_SITE_ROOT=/absolute/path/to/promote-to-king ./install-promote-to-king-2.11.2.sh
```

The installer checks its embedded SHA-256 manifest, validates the installed version, snapshots and verifies the existing crontab, performs a transactional file update, and restores all replaced files/removes all newly created files after any failure.
