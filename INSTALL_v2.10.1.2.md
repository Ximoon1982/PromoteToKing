# Install Promote to King v2.10.1.2

Use the incremental package on the currently installed composite state: public Blue v2.9.22.10 plus migration layer v2.10.1.1.

```bash
cd ~/PromoteToKing
unzip -o PromoteToKing_v2.10.1.1_to_v2.10.1.2_INCREMENTAL.zip
chmod +x P2K_v2.10.1.1_to_v2.10.1.2_INCREMENTAL/update-v2.10.1.1-to-v2.10.1.2.sh
bash P2K_v2.10.1.1_to_v2.10.1.2_INCREMENTAL/update-v2.10.1.1-to-v2.10.1.2.sh "$PWD"
```

No database or CRON operation is performed.
