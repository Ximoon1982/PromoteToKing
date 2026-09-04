# Install Promote to King v2.11.4

The incremental installer accepts any existing Promote to King `2.11.x` installation, including v2.11.0 initial/R4/R6, v2.11.1, v2.11.2, and v2.11.3. It preserves configuration, data/storage, authentication, and the existing crontab exactly.

## Recommended command

```bash
chmod +x install-promote-to-king-2.11.4.sh PromoteToKing_v2.11.4_OPERATIONAL_ROBUSTNESS.run
./install-promote-to-king-2.11.4.sh /absolute/path/to/promote-to-king
```

Alternatively:

```bash
P2K_SITE_ROOT=/absolute/path/to/promote-to-king ./install-promote-to-king-2.11.4.sh
```

The installer verifies its embedded SHA-256 payload and manifest, performs a transactional managed-file update, checks CRON equality, supports idempotent reinstall, and restores the exact pre-install managed tree on failure. Every qualified build carries a unique immutable static-asset cache key derived from its exact source revision and build identity, independent of the displayed semantic version.
