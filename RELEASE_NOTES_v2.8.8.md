# Promote to King v2.8.8 release notes

## Hall, achievements and profiles

- Achievement trigger links are normalized to human-facing `https://www.chess.com/...` URLs. Legacy stored API URLs are corrected at read time; new achievement records are normalized before persistence.
- Hall of Fame now opens on **Achievements** by default. Explicit Daily/Live rank navigation still opens the requested rank view.
- Both authenticated Daily and Live Dashboard views expose the unified player profile.
- Daily/Live Hall rank cards have dedicated narrow-mobile layout rules so text/counts no longer spill across adjacent rank artwork.
- Nested dashboard modals now preserve DOM nodes, focus and scroll state. Closing an Achievement opened from a Profile restores the original live Profile DOM/event handlers, allowing the next Achievement to open correctly.
- The unified player profile is the cross-feature player surface for Hall, Achievements, Insights, embedded Recruitment, embedded Tournaments and Club Intelligence.
- Unified profiles and the personalized authenticated home add member activity, availability/load, contribution and nearest achievement challenges.

## Club Intelligence Center

A new Administration → **Club Intelligence** tool center consolidates the new operational/member intelligence features. Corresponding tools are also present in the Administration tool collection.

- Team depth visualization by 100-rating-point Daily/Classical and Chess960 bands.
- Data freshness / coverage dashboard for roster, ratings, avatars, boards and worker queues.
- Automatic anomaly detector and linked admin action queue.
- Member activity monitoring and activity classes.
- Member availability / overload score from activity, data freshness and current board load.
- Member contribution profiles: Club Points share, points/game and win rate.
- Opponent intelligence profiles from the canonical opponent projection.
- Compact daily historical snapshots / time travel.
- Explainable Low / Medium / High Club Points forecast with visible drivers.
- Performance telemetry by Team Points public endpoint (calls/errors/p50/p95/max/peak memory).
- ACAMR effectiveness dashboard.
- Achievement challenges for nearest unearned Daily, Live and MCA milestones.

## Recruitment and ACAMR

- Recruitment Assistant now displays an explainable confidence score using stored-rating freshness, opponent-lineup coverage, candidate availability and projected board coverage.
- Candidate rows expose activity, availability and current load while retaining strongest-first recruitment semantics.
- ACAMR work allocation is adaptive: stale member data, recent activity and incomplete/in-progress point work receive higher priority. Existing cross-session claims continue to suppress duplicate work.
- ACAMR scope is unchanged: Club/Member Points support is included; tournaments and match-registration monitoring are excluded.
- Real OAuth authentication activates ACAMR regardless of the simulated-OAuth flag; simulated authentication activates it only when that flag is enabled.

## Opponents Insights

- Top Opponents graph can display the opponent Chess.com club logo.
- Opponent club icons are persisted in Core with long-lived cache metadata and can be populated by normal opponent maintenance or bounded on-demand icon refresh.

## Operations

- Core schema **6** (from 5): adds opponent icon/profile cache columns only.
- Analytics schema remains **5**.
- No database reset/re-seed.
- CRON cadence unchanged; no new CRON entry.
- Daily Intelligence snapshot and internally hourly-gated anomaly maintenance reuse the existing Club worker when time budget remains.
