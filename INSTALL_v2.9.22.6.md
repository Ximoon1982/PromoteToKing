# Install Promote to King v2.9.22.6

Preferred production path: use the verified v2.9.22.5 -> v2.9.22.6 incremental updater from the production PromoteToKing root.

```bash
cd ~/PromoteToKing
chmod +x update-v2.9.22.5-to-v2.9.22.6.sh
./update-v2.9.22.5-to-v2.9.22.6.sh
```

The updater preserves protected production configuration and the existing v2.9.22 CRON contract. PHP CLI is not required. Core 15 -> 16 is applied by the normal PHP-FCGI Repository bootstrap after deployment.

Do not replace the production tree with the standalone archive unless protected production files are explicitly preserved.
