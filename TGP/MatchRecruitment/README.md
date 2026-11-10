# TGP Match Recruitment — standalone v2.15.3

URL: https://www.promotetoking.org/TGP/MatchRecruitment/

Installer: `bash TGP/MatchRecruitment/install-standalone.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing`

## Security and scope
- P2K OAuth identity and live TGP admin verification, using exactly the established `TGP/MatchAnalyzer/auth.php` implementation.
- Only matches containing `the-golden-phoenix`; recruitment is always from TGP's perspective.
- No P2K Core/Analytics databases, recruitment-pool endpoint, recruitment archive, or saved CSV.
- The recruitment core and visual style are copied from P2K. Player data comes directly from Chess.com in the browser.
- The same player-clubs response verifies both continued TGP membership and nonmembership in the opponent club.
- Unverified players are excluded from eligible CSV.
- A four-worker concurrency limit bounds browser-side Chess.com calls.

## Member roster and fallback
Private snapshot: `data/members-latest.json`, containing UTC retrieval timestamp and normalized usernames.
Successful full roster retrieval atomically replaces the saved snapshot. An invalid/partial/failed call never overwrites it.
If a saved snapshot exists, the visible warning includes its timestamp and explains that the member list must be configured Public on Chess.com while the analysis is in progress.
Without a valid snapshot, analysis fails and displays the same visibility guidance.
Both fresh and fallback-roster candidates are individually checked via Chess.com `/pub/player/{username}/clubs` before they can qualify.
The browser generates a downloadable CSV only when requested. No CSV is stored.

The installer copies only this application's source, preserves runtime `data/`, and never alters `.htaccess`, TGP Widget, TGP Analyzer, P2K databases, or Release Control.
