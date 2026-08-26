# Migration notes — v2.10.6.10

v2.10.6.10 does not alter the Blue/Green database schemas or routing model.

- Existing effective public source is preserved.
- Existing worker routing and browser ingest routing are preserved.
- Existing Green cycles, GAB/GABCRF and GFFL continue normally.
- No Green reseed is required.
- No Blue data is removed or frozen.

The tracking correction updates follow-registry state lazily when the explorer or tracking-reference path is read: any still-followed match already beyond start+24h is marked unfollowed while its snapshot archive remains untouched.
