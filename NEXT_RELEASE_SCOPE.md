# Promote to King v2.10.6.24 — planned scope

This branch starts from the immutable canonical v2.10.6.23 tree.

## Insights → Arenas

Implement a new Arenas section using the same Insights visual system, chart components, card/panel structures, responsive constraints, hover/zoom behavior, UTC handling, loading/empty states and redraw rules as the existing Insights panels.

First-release scope:

1. Arena KPI row: arenas played, P2K participations, unique P2K players, victories, podiums, Top-10 finishes, best finish and average P2K players per arena where space permits.
2. P2K participation per arena, with raw participants / share-of-field toggle.
3. Best P2K placement over time, with #1 at the top and detailed hover.
4. Normalized finishing percentile over time.
5. MCA points per arena and cumulative progression.
6. W/D/L and score-% evolution with counts/percentage views as appropriate.
7. Arena leaders sortable table using canonical player identities and links to the existing player profile.
8. Arena records panel for reliable Results-derived records and streaks.
9. All Arenas searchable/sortable explorer with per-arena detail view and original Chess.com source link.

Use existing stored MCA Results/event metadata and current MIRA/MCA derivations only. Games-CSV-dependent analytics remain out of scope until that source is integrated.

## Achievements artwork

Integrate the seven approved 640×640 Matches masters staged under `artwork/approved-next-release/v2.10.6.24/matches/` for milestones 1, 10, 50, 100, 250, 500 and 1000. Generate/wire the normal miniature and thumbnail derivatives used by the achievement catalogue. Do not alter unrelated artwork.
