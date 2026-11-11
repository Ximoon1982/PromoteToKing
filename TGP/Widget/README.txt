The Golden Phoenix Match Showcase v0.3.2

Standalone location:
  /TGP/Widget/

Public card URL:
  https://www.promotetoking.org/TGP/Widget/

Administration:
  https://www.promotetoking.org/TGP/Widget/admin/

Scope
-----
- Public output retains the 2x2 Daily-match card grid, with an optional P2K-style two-column arena-card grid above it.
- The first four enabled configured matches are displayed.
- Up to two active upcoming/registration/on-going arenas are displayed above the cards with Bullet, Blitz and Rapid speed icons, phase badges, and live timers; completed arenas expire automatically. No League/Friendly selector or trailing button.
- Arenas are configured by club administrators (name, Chess.com link, Luxembourg-local start date/time, duration, time control, enabled flag).
- WCGC fixtures are recognized as league matches.
- Persistent application state is data/showcase.json. No SQL/P2K data store is used.
- P2K is used only for the existing Chess.com OAuth identity assertion.
- Admin authorization is checked against the configured Chess.com club slug.

v0.3.0 admin additions
----------------------
- Registration chooser loaded from one Chess.com club match-index request:
    https://api.chess.com/pub/club/the-golden-phoenix/matches
  Only the registered Daily-match bucket is listed; the chooser does not fetch each match.
- Each registration-row has an Add button.
- Save validation first uses the same club match index and only falls back to match detail for an id missing from that index.
- Copy-ready public card URL and iframe HTML are shown at the top of the admin page.
- Suggested iframe size is 260 x 490 px with arenas (260 x 300 px without), including both section headings and P2K-style arena cards.

Install
-------
From the PromoteToKing root, extract the overlay so that TGP/Widget/ is updated, then ensure data/ is writable by PHP. The incremental overlay intentionally does not contain data/showcase.json, so an existing configured match list is preserved. On a fresh installation the application creates the state file automatically.

Configuration
-------------
config.php defaults to:
  team_slug = the-golden-phoenix
  team_name = The Golden Phoenix

The data/cache directory is disposable. data/showcase.json is the persistent configuration.

Update v0.3.2: MCA compact list with add/edit modal, independent immediate arena saves; unsaved Daily-match edits stay local.

Widget rows (v0.3.3): choose 1–2 MCA rows (up to 4 arenas) and 1–3 Daily rows (up to 6 matches). Defaults remain 1 and 2 rows. Arena priority badges are configured independently in the admin arena list. Updated iframe HTML reflects displayed rows; re-copy after changing settings on an external host.
