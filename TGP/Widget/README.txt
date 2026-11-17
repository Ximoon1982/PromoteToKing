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

Version 0.3.4 (TGP + Team France):
- Admin-only upcoming MCA preselector shared with P2K's validated Chess.com upcoming-arena index parser.
- Each club keeps a separate organizer watchlist and resumable seven-day scan (up to 20 pages).
- Arena suggestions prefill the existing Add arena modal (name, Chess.com link, Luxembourg start date/time); duration/time control require admin verification. The suggestion is NOT saved or enabled automatically.
- Scans are admin-triggered and share the P2K Chess.com index rate limiter (minimum one second between request starts). Public widget reads do not scan arenas.
- Configured Daily matches explicitly in progress or finished according to the club match index are removed automatically on public widget reads (also reconciled on admin refresh). Missing or unavailable upstream data never triggers deletion; current arena configuration and layout remain unchanged.
- All existing club administrator permissions, CSRF protection, revision conflicts and live-card layout controls remain active.

Standalone SSH delivery of Widget 0.3.4
------------------------------------
Do not use P2K Release Control: /TGP/Widget/ is a physically served sibling application, not part of P2K public release-slot routing.
The combined offline ZIP P2K_TGP_TeamFrance_Widget_v0.3.4_SSH_Installer includes an install.sh script and only the 20 updated code/documentation files for both widgets.
Upload the ZIP via SSH/SFTP to the IONOS account home directory (outside PromoteToKing), extract it and run:
  bash P2K_TGP_TeamFrance_Widget_v0.3.4_SSH_Installer/install.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing --check
  bash P2K_TGP_TeamFrance_Widget_v0.3.4_SSH_Installer/install.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
The installer validates SHA-256 checksums and PHP syntax, checks the live widget baseline, saves source backups under storage/standalone-widget-backups, and copies code atomically.
It never overwrites either widget's data/showcase.json, locks or API cache; does not touch P2K Release Control, public routing, OAuth or CRON.
