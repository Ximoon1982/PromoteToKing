# Team France Match Recruitment Assistant

Standalone administrator-only recruitment utility at `/TeamFrance/MatchRecruitment/`.

- Uses existing P2K Chess.com OAuth and checks Team France administrator privileges.
- Sends Chess.com requests through the server-side OAuth bearer gateway without exposing the token.
- Verifies current club membership and opponent-club exclusion before CSV export.
- Retains last successful member roster in the private `data/` folder across upgrades.
- Provides a searchable table, 50-row pagination, a folded request log, and a local CSV download.
- Shows the same registration-open Daily match radio selector as the Match Analyzer.
