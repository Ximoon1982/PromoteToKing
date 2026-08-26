# Install v2.10.6.17

The cumulative incremental installer accepts an installed source identity of **2.10.6.15 or 2.10.6.16** and upgrades it to v2.10.6.17.

After installation:

1. Hard-refresh `ui-v2.html`.
2. Open Administration → Scheduled Task Control → Green Team Points.
3. Click **Start / resume GAB** once to retry the errored GABCRF lane.
4. Observe GABCRF. The previous `uq_tp_game_board_sequence` alias-duplication error should not recur.

No database reset/reseed is required.
