Team France Match Showcase v0.2.0

Standalone location:
  /TeamFrance/Widget/

Public card URL:
  https://www.promotetoking.org/TeamFrance/Widget/

Administration:
  https://www.promotetoking.org/TeamFrance/Widget/admin/

Scope
-----
- Public output is only the 2x2 Daily-match card grid.
- The first four enabled configured matches are displayed.
- No arena support, no League/Friendly selector, no heading and no trailing button.
- Persistent application state is data/showcase.json. No SQL/P2K data store is used.
- P2K is used only for the existing Chess.com OAuth identity assertion.
- Admin authorization is checked against the configured Chess.com club slug.

v0.2.0 admin additions
----------------------
- Registration chooser loaded from one Chess.com club match-index request:
    https://api.chess.com/pub/club/team-france/matches
  Only the registered Daily-match bucket is listed; the chooser does not fetch each match.
- Each registration-row has an Add button.
- Save validation first uses the same club match index and only falls back to match detail for an id missing from that index.
- Copy-ready public card URL and iframe HTML are shown at the top of the admin page.
- Default iframe size is 260 x 260 px, matching the 2x2 card surface.

Install
-------
From the PromoteToKing root, extract the overlay so that TeamFrance/Widget/ is updated, then ensure data/ is writable by PHP. The incremental overlay intentionally does not contain data/showcase.json, so an existing configured match list is preserved. On a fresh installation the application creates the state file automatically.

Configuration
-------------
config.php defaults to:
  team_slug = team-france
  team_name = Team France

The data/cache directory is disposable. data/showcase.json is the persistent configuration.

Install with TeamFrance/install-standalone.sh. Configuration state is never overwritten.
