# v2.10.6.17 Green migration note

This release repairs compatibility projection only. Existing Green Core, Green Analytics, GAB, GFFL, GQAC, cycle state and public Green routing remain in place.

The GABCRF lane that failed with duplicate `uq_tp_game_board_sequence` can be resumed in place. **Start / resume GAB** resets only errored GAB lanes and preserves completed lanes.

Do not reset or reseed Green for this corrective.
