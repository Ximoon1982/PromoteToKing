# v2.10.6.18 Green migration note

This release repairs mutable registration-lineup projection and adds an optional accelerated historical Opponent Insights heatmap backfill.

Existing Green Core, Green Analytics, GAB, GFFL, GQAC, cycle state and public Green routing remain in place. No reset/reseed is required.

After installation, **Start / resume GAB** once if GABCRF is currently errored. The same GABCRF lane can resume in place using the canonical member-per-match projection.

Heatmap backfill is independent of GAB. Prepare it from Task Control and then start the Green Accelerator; no Deep mode is required.
