# Install Promote to King v2.11.3

This corrective installer accepts any existing Promote to King `2.11.x` installation. It applies only the runtime ownership consolidation files. It does not alter UI/display assets, behavior, configuration, runtime data, schemas, authentication, schedulers or CRON entries.

## Recommended command

```bash
chmod +x install-promote-to-king-2.11.3.sh PromoteToKing_v2.11.3_RUNTIME_CONSOLIDATION.run
./install-promote-to-king-2.11.3.sh /absolute/path/to/promote-to-king
```

Alternatively:

```bash
P2K_SITE_ROOT=/absolute/path/to/promote-to-king ./install-promote-to-king-2.11.3.sh
```

The installer checks its embedded SHA-256 manifest, validates the installed version, snapshots and verifies the existing crontab, performs a transactional file update, and restores all replaced files/removes all newly created files after any failure.

Each qualified build also carries a unique static-asset cache key derived from its exact source revision and build identity. This key is independent of the displayed v2.11.3 semantic version and prevents a later qualified build from reusing stale browser assets.
