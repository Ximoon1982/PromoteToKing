# Install Promote to King v2.10.5.5

Install directly over **v2.10.5.3 or v2.10.5.4** using the supplied cumulative incremental ZIP and installer. The updater detects the installed source version and preserves rollback to that exact version.

```bash
chmod +x install-v2.10.5.5.sh
bash install-v2.10.5.5.sh "$PWD"
```

After installation:

1. Open **Scheduled Tasks → Green Team Points**.
2. Confirm runtime version 2.10.5.5.
3. Open **Insights → Opponents** and hard-refresh once; verify the Zoned Density heatmaps have smooth/interpolated zone boundaries while retaining the same colors.
4. Do not reset/reseed Green.
5. The next Green worker invocation will self-heal a persisted `quick_complete` state if present.
6. Click **Start / resume GAB** once. The errored `core_projection_matches` lane returns to pending and
   resumes from its existing cursor; completed GAB lanes are preserved.
