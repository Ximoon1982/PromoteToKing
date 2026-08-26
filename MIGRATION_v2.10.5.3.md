# v2.10.5.3 Green migration operational note

v2.10.5.3 does not change the formal production migration sequence:

`BLUE PRIMARY → SHADOW WRITING → GREEN VALIDATED → GREEN READS + BOTH MAINTAINED → GREEN PRIMARY`

It corrects scheduler fairness while Green validation/GAB convergence is underway.

## Expected post-install behavior

For an already-running finite Quick cycle:

- core Seed/Quick/Deep work receives a guaranteed first worker slice;
- GFFL/current-match sidecars continue afterward with bounded work;
- repeatedly transient GQAC items can be deferred to the next cycle after three current-cycle attempts;
- deferred boards remain dirty and will be retried; they are not counted as fresh data;
- the Accelerator reserves finite-cycle slots while continuing to put GAB first among background accelerator lanes;
- GFFL can stay enabled continuously.

The current Green Core and GAB state are preserved. No manual cycle reset or re-bootstrap is part of this migration.
