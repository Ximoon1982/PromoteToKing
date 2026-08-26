# Install v2.9.22.2

Preferred upgrade from v2.9.22.1:

```bash
cd /path/to/PromoteToKing
chmod +x update-v2.9.22.1-to-v2.9.22.2.sh
./update-v2.9.22.1-to-v2.9.22.2.sh
```

Pause a running Fresh Points Reconstruction before deploying. After deployment, hard-refresh Scheduled Task Control and resume the same persisted reconstruction run.

The hotfix does not require PHP CLI and does not perform a database migration.
