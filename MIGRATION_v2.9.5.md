# Promote to King v2.9.4 → v2.9.5 migration

v2.9.5 upgrades Core schema 9 to Core schema 10 using `server/team-points/sql/core-migration-v2.9.5.sql`. Analytics remains schema 6.

The migration is additive:
- `p2k_tp_opponents.country_code` stores the normalized Chess.com opponent club country/region code when available;
- `p2k_tp_boards.p2k_rating` and `opponent_rating` store paired board ratings;
- `rating_source` records whether the evidence came from a match lineup or board game;
- `rating_captured_at` records when the stored pair was captured.

No table is dropped, no canonical match/game rows are deleted, and no database reset/reseed is required.

Match-lineup rating evidence has priority over board-game fallback evidence. Existing historical rows are left unknown until authoritative revalidation supplies the missing facts.
