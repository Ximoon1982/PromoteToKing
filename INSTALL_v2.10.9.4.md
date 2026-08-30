# Install Promote to King v2.10.9.4

From the deployed P2K root:

```bash
chmod +x PromoteToKing_v2.10.9.4_INCREMENTAL_INSTALLER.run install-promote-to-king-2.10.9.4.sh
./install-promote-to-king-2.10.9.4.sh .
```

The incremental installer accepts deployed `VERSION` 2.10.9.3 or 2.10.9.4 replay. It preserves runtime data and does not modify the database schema or crontab.
