# Install v2.10.4.3

Use the cumulative incremental updater supplied with the release. It accepts v2.10.4, v2.10.4.1 or v2.10.4.2 and converges to v2.10.4.3.

```bash
cd ~/PromoteToKing
unzip -o PromoteToKing_v2.10.4_to_v2.10.4.3_CUMULATIVE_INCREMENTAL.zip
chmod +x P2K_v2.10.4_to_v2.10.4.3_CUMULATIVE_INCREMENTAL/update-v2.10.4-to-v2.10.4.3.sh
bash P2K_v2.10.4_to_v2.10.4.3_CUMULATIVE_INCREMENTAL/update-v2.10.4-to-v2.10.4.3.sh "$PWD"
```

No database migration, Green reset/reseed, Blue/Green cutover or CRON rewrite is performed.
