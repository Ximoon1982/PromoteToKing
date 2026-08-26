# v2.10.6 migration

Source baseline: **v2.10.5.5**.

The migration is in-place and additive:

- Core schema 16 → 17: add `p2k_tp_boards.opponent_username` and its lookup index.
- Analytics schema 8 → 9: add MCA event/source provenance plus `p2k_lr_sync_state` and `p2k_lr_sync_queue`.
- Existing Team Points, Green, MCA source files, MCA derived data, identities, configuration and runtime data are preserved.
- No database reset or Green reseed is required.
- The v2.10.6 updater backs up every replaced existing file before copy and performs no source-file deletion.
- GABCRF is self-reconciling and can run against the existing Green database after schema migration.
- MCA automatic source synchronization can coexist with the existing manual upload path.
