# Promote to King v2.4.19.2 hotfix

This hotfix corrects UI v2 JSON request routing. Relative Team Points and tournament endpoints are fetched from the current site origin with same-origin credentials. Only external Chess.com PubAPI requests use `P2K_API_CLIENT`, whose origin allowlist intentionally contains `https://api.chess.com` only.

Affected views:

- Insights → Matches
- Insights → Opponents
- Hall of Fame → Live ranks
- Unified Hall of Fame search

No database or CRON migration is required.
