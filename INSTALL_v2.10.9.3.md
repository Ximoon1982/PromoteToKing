# Install Promote to King v2.10.9.3

From the deployed Promote to King root (or pass it as the launcher argument):

```bash
chmod +x PromoteToKing_v2.10.9.3_INCREMENTAL_INSTALLER.run install-promote-to-king-2.10.9.3.sh
./install-promote-to-king-2.10.9.3.sh .
```

The incremental installer accepts deployed `VERSION` 2.10.9.2 or 2.10.9.3 replay. It preserves runtime data and does not modify the database schema or crontab.

After installation, the existing MCA worker automatically performs the one-time full occurrence-ordered index reconciliation because the legacy numeric high-water marker is still present. When that full reconciliation completes successfully, later twice-daily discovery cycles switch to newest-to-first-known scanning.
