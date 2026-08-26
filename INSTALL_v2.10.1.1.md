# Install Promote to King v2.10.1.1

Use the incremental package on the currently installed v2.10.1 migration layer (with or without Migration Panel Resilience Hotfix 1).

```bash
cd ~/PromoteToKing
unzip -o PromoteToKing_v2.10.1_to_v2.10.1.1_INCREMENTAL.zip -d P2K_v2.10.1_to_v2.10.1.1_INCREMENTAL
chmod +x P2K_v2.10.1_to_v2.10.1.1_INCREMENTAL/update-v2.10.1-to-v2.10.1.1.sh
bash P2K_v2.10.1_to_v2.10.1.1_INCREMENTAL/update-v2.10.1-to-v2.10.1.1.sh "$PWD"
```

The installer performs no database operation and does not alter Green/Blue routing or the current migration cycle/stage. Close old migration tabs and reopen `TeamPointsMigration.html?oauth=2` after installation.
