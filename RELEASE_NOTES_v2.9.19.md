# Promote to King v2.9.19

## Scope

v2.9.19 is built directly from the exact frozen internal v2.9.18 standalone SHA256 `c419aae7974ffd050e0641711792aa1673a616f2e7bedc09fb115e2ce519980a`, using internal source/package history only. GitHub is not used.

## MIAC alias / name-change experience

- Replaces the flat alias-edge display with the historical proof-of-concept interaction model: identity-chain explorer, successive transition timeline, searchable/filterable evidence table, evidence modal, and a separate manual confirmation queue.
- Shared physical Daily-board evidence is sufficient to automatically confirm a still-candidate rename edge. Automatic review is recorded as `auto:shared_board` and multiple promotions in one policy pass coalesce into one identity-map generation change.
- Explicit administrator rejection is sticky and is not silently reversed by later automatic evidence.
- Contradictory known non-null Chess.com `player_id` values remain a hard conflict and block merging.
- Manual Confirm/Reject uses the secured Team Points client, refreshes the live MIAC state, and is idempotent when the requested status already applies.
- The Intelligence alias payload returns the complete evidence set and seed chain topology so long chains cannot be truncated by the former 200-edge presentation limit.

## Identity Propagation & Derived-data Refresh Fix (IPDR)

- MIAC `identity_map_generation` is now an input to general Analytics and Achievement refresh watermarks, in addition to MIRA.
- Confirmed identity topology changes therefore invalidate and rebuild username-derived Analytics, achievements and MCA attribution instead of leaving old/new usernames split in derived data.
- Daily/monthly/player projections aggregate through the canonical identity map; Daily unique-player counts count canonical identities.
- Canonical player rating display can fall back to a confirmed alias rating when the chosen canonical username has no current rating snapshot.
- Player-profile lookup resolves historical aliases to the canonical player and recent match/opponent history spans all confirmed aliases.
- Achievement ownership and threshold derivation are canonicalized during rebuild; duplicate alias ownership is removed only from rebuildable Analytics output. Raw Core match/board/game/point evidence is not rewritten merely because identity attribution changes.
- **MCA historical substitution evidence:** re-uploading/replacing the same physical arena source can confirm exactly one disappeared username → exactly one new username only when stable arena participation fingerprints match and every unchanged participant remains stable. Comparison hashes/evidence are persisted before source replacement.
- **Daily historical substitution evidence:** a clean old username → new username ownership handover on the same physical `match_id + board_no` is definitive when neither identity owns another board in that match. Evidence is persisted before current board ownership is updated.
- Both definitive historical rules retain the explicit-rejection guard and contradictory-`player_id` veto.

## Achievement and player UX

- Adds **Most earned** beside **Achievement catalogue**, ranking catalogue entries from most to least current members who have earned them and displaying the current-member count.
- Player-specific unearned achievement details display progress when available.
- Achievement detail supports Previous / Next across the complete catalogue and Left / Right keyboard navigation in both dashboard-hosted and standalone/general catalogue flows.
- Player profile action is labelled `View all achievements of <member name>` and has spacing from the achievement card below.
- Personalized dashboard achievement labels use the same compact typography as Team Points and include `View →` to the general Achievements page.

## Insights and layout corrections

- Members Insights removes the summary metrics **All recorded members**, **Former members**, and **Team Points in period**; filtering capabilities are retained.
- Lineup evolution and win-probability history are not mounted when there is no stored historical snapshot; a single current/live point no longer creates a false history chart.
- Live Rook and other framed rank/achievement artwork use inset `contain` rendering on narrow/mobile layouts to prevent frame clipping.
- Highest-priority action rows and System-health rows share corresponding 72px desktop row geometry for vertical alignment; mobile rows remain content-sized.

## Compatibility

- Core schema: **14** (unchanged).
- Analytics schema: **7** (unchanged).
- Achievement catalogue: **162 definitions** (unchanged).
- MIAC seed/provenance: unchanged from v2.9.18; no reseed.
- Existing four operational CRON cadences and the weekly long-life backup cadence are unchanged.
- OAuth adaptive tuning namespace / P0 Interactive Survival / ACDC / CTAR / CMDI behavior remains intact.
- No destructive database reset or migration is required.
