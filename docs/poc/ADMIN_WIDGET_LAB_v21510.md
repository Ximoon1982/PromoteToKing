# P2K Admin Widget Lab — design-only reference (2026-11-15)

**Status:** Ten **APPROVED-DEFERRED** proposals, P2K-BL-014 through P2K-BL-023; [GitHub issue #6](https://github.com/Ximoon1982/PromoteToKing/issues/6). No implementation authorized.

## Original proof of concept

Delivered in the P2K ChatGPT conversation on 2026-11-15 as `P2K_Admin_Widgets_PoC_v21510.html` and `P2K_Admin_Widgets_PoC_v21510.zip`. The original standalone HTML artifact is available in the original conversation, **not committed as a production page**. Do not represent the illustrative scenarios as live analytics.

The demo used the established public widget **260 × 430 px** proportions, P2K compact charcoal/gold theme and light-mode variant. It contained ten independent interactive visual prototypes, with **Steady / Busy / Incident** sample scenarios, tabs where relevant, and responsive desktop/mobile layout. No P2K API, scraping or database queries were performed.

## Candidate set

| Backlog ID | Concept | Read-only administrative value |
| --- | --- | --- |
| P2K-BL-014 | Operations Pulse | Overview of Green maintenance, MCA sync, analytics and Insights freshness, last successful CRON execution, bounded worker health and age of stale queues. |
| P2K-BL-015 | Attention Required | Prioritized, de-duplicated exceptions requiring administrator attention: missing parsed Results, failed archives, alias/identity reviews, failed maintenance, metadata gaps and reconciliation mismatches. |
| P2K-BL-016 | Match Readiness | Upcoming matches at risk of an inadequate lineup, with registration shortfall, time to start, existing recruitment Assistant targets, rating constraints and trajectory only when adequately sourced. |
| P2K-BL-017 | Data Integrity Monitor | Compare authoritative Green Club Points to analytics, freshness/watermarks, active match counts, player snapshots and time-series refresh lag. |
| P2K-BL-018 | MCA Acquisition Watch | Show newly discovered arenas and Results completion, failed/awaiting sources, acquisition stage, retry age, parse coverage, and MIAC alias review exceptions. |
| P2K-BL-019 | Club Momentum | Short-window trends in member joins/departures, engagement, inactivity, matches, new boards and recruitment effectiveness using canonical stored history. |
| P2K-BL-020 | Visitor & Widget Analytics | Use existing Traffic/Visitors and valid-name-search audit sources for visitors, widget activity, verified search events and trend comparisons. |
| P2K-BL-021 | Competition Calendar & Discovery | Upcoming events, expected monthly/quarterly entries, discovery gaps, registration/start deadlines, Results qualification and review actions. |
| P2K-BL-022 | Release & Maintenance Watch | Show current public release, pending candidate, last promotion, preview status, CRON target-versus-public runtime identity, recent maintenance errors and version mismatch warnings. |
| P2K-BL-023 | Admin Control Tower | Compact Health / Actions / Growth selector composing summaries of the preceding nine widgets, preserving each independent configuration and reusing the same cached read models rather than nesting many iframes or multiplying API requests. |

## Architecture and acceptance gates

1. Design for independently embeddable **260 px**-wide admin widgets at **430 px** nominal height; preserve dark/gold and light themes, mobile ergonomics and accessibility.
2. **Admin-only at the API boundary**; never trust hidden UI alone. Use existing authentication, CSRF for any separate administrative writes, and strict preview isolation.
3. Read canonical Green, MCA, Insights, visitor and Release Control data only through bounded/cacheable existing services. No new Chess.com requests, maintenance, source acquisition or uncontrolled DB scans from widget reads.
4. Reuse authoritative definitions; display source and freshness, unknown/unavailable explicitly, avoid inventing live health or activity.
5. Keep user actions in the existing admin tools via safe links. Any future mutation requires a separately reviewed workflow, not the read-only widget.
6. The **Control Tower** should compose shared read models and avoid nested iframe fanout or duplicated data fetches.
7. Before implementation, audit overlap with existing administration features. No version change, release installer, deploy or promotion is implied by backlog registration.

**Source retrieval key:** 2026-11-15 ChatGPT conversation "Those were widgets for public users. Give ideas of widgets for admin usage" → "Mke a page with proof of concepts..." → "Put those on the backlog."
