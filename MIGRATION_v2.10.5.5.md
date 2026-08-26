# v2.10.5.5 Green migration corrective

This release does not advance the migration phase automatically. The cumulative updater accepts the currently deployed v2.10.5.3 as well as v2.10.5.4, so v2.10.5.4 does not need to be installed separately.

For the currently observed runtime state:

- keep public reads on **BLUE**;
- keep worker routing **Both**;
- keep browser ingest **Both**;
- keep mode **Auto**;
- keep GFFL enabled;
- install v2.10.5.5;
- allow one Green worker invocation to recover `quick_complete`;
- then use **Start / resume GAB** to retry `core_projection_matches`.

No completed GAB lane is restarted and no Green Core reconstruction is discarded.
