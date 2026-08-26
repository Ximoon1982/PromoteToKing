# Install v2.9.22.3

Preferred production path: use the verified incremental updater from v2.9.22.2.

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.22.2-to-v2.9.22.3.sh
./update-v2.9.22.2-to-v2.9.22.3.sh
```

The updater does not require PHP CLI. It preserves protected runtime configuration and retains the existing v2.9.22 CRON jobs unchanged.
