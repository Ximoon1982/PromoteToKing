# Migration v2.9.9 → v2.9.10

Core schema changes from 11 to 12; Analytics remains 6. The migration is additive except that `p2k_tp_match_metadata.last_verified_at` becomes nullable so browser-discovered, not-yet-authoritatively-verified matches can be represented honestly.

New fields separate passive/observed freshness from authoritative verification for club roster, club match index, member profiles/stats/player-match observations and match observations. Browser observations never reset canonical membership and never promote observed match bucket/status into canonical match status.

New browser-discovered match IDs can be inserted as canonical status `unknown` with an immediate verification due time. The normal `sync_match` worker remains responsible for authoritative match data.

The CRON scheduler now uses authoritative verified timestamps for its one-hour roster/index deadlines. Opportunistic browser observations update observed clocks only.
