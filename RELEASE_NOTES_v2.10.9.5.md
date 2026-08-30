# Promote to King v2.10.9.5

Members Insights period/ranking release built on canonical v2.10.9.4.

## Members Insights

- Adds independent From / To period filtering for Members Insights statistics and Daily Points.
- Adds Team position ranked by Daily Points, with net wins (wins minus losses) as the first tie breaker.
- Adds position evolution against 1 week, 1 month, 3 months or 1 year earlier, with up/down/unchanged/new indicators.
- Corrects member win-rate calculation to use result-covered wins / (wins + draws + losses); unavailable coverage displays as an em dash instead of a false 0%.
- Adds full filtered CSV export using the same canonical table projection without page-size truncation.
- Calculates ranking before display filtering and pagination, preserving a member's true team position.

## Cache generation

- Promotes the public generation to v2.10.9.5.
- Cache-busts every real HTML/HTM `site-config.js` bootstrap and the Members enhancement JS/CSS chain.

## Preservation

- No database schema change.
- No runtime-data reset.
- No CRON definition change.
- Existing MCA and Team Points data are preserved.
