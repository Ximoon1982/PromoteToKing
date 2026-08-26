# Install v2.10.4.1 from v2.10.4

```bash
cd ~/PromoteToKing
unzip -o PromoteToKing_v2.10.4_to_v2.10.4.1_INCREMENTAL.zip
chmod +x P2K_v2.10.4_to_v2.10.4.1_INCREMENTAL/update-v2.10.4-to-v2.10.4.1.sh
bash P2K_v2.10.4_to_v2.10.4.1_INCREMENTAL/update-v2.10.4-to-v2.10.4.1.sh "$PWD"
```

The updater changes application files only. It does not alter databases, Green state, Blue/Green routing, or CRON.
