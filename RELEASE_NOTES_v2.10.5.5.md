# Promote to King v2.10.5.5

## Scope

Corrective release whose final state is based on v2.10.5.4 and whose cumulative installer accepts either v2.10.5.3 or v2.10.5.4. It preserves all v2.10.5.4 UI, Zoned Density Heatmap,
automatic tracking retirement, Green scheduling, GAB, GFFL and cutover behavior.


### Zoned Density Heatmap visual parity

The v2.10.5.4 chart already had the agreed half-size bins and one light 3x3 Gaussian smoothing pass,
but it still painted each smoothed bin as a rectangular SVG cell. That final block renderer was the
remaining source of the visibly pixelated look compared with the approved proof of concept.

v2.10.5.5 keeps the smoothing parameters, all six density-zone thresholds, and the approved
deep-blue → cyan → green → yellow → orange → red palette unchanged. It bilinearly interpolates the
already-smoothed density field between bin centres and renders the zoned result at modest supersampled
resolution before display. This produces the smoother contour-like zone boundaries from the proof while
retaining the same raw bins, tooltips, filters, log-space match-size axis, reference lines and zoom/pan.

### GAB compatibility board projection

A Green match may retain more than one historical username row for the same board and side because
`p2k_g_match_players` is keyed by `(match_id, username_key)`. The v2.10.5.4 compatibility projector
joined those rows directly to the authoritative board table, allowing one board to be projected twice
and causing MariaDB error 1062 on `uq_tp_board_match_number`.

v2.10.5.5 projects exactly one deterministic lineup identity per authoritative board/side. Selection
prefers point-event evidence, then the authoritative board payload, then canonical/trusted identity
evidence. Compatibility games are gathered through `p2k_g_identity_map`, so old/new username aliases
belonging to the same canonical player remain attached to one compatibility board/member.

The production failure observed at match 1779702, board 11 is covered by this generic duplicate-lineup
regression contract. No Green Core board/history rows are deleted by this corrective.

### Quick-cycle transition recovery

`quick_complete` is a transition marker, not a runnable phase. Previously, an exception between cycle
completion and the later `quick_index_roster` stage write could leave the state at `quick_complete`.
A subsequent worker could then start a new cycle in that unhandled stage indefinitely.

v2.10.5.5 makes the safe next stage durable together with cycle completion, before analytics rebuilding.
Existing persisted `quick_complete` states self-heal on the next Green worker invocation. If analytics
rebuilding itself fails, the worker reports the error but the cycle state has already advanced safely.

## Safety

- No database schema change.
- No database reset or Green reseed.
- No CRON definition/cadence change.
- Green worker remains 50 s soft / 55 s hard.
- GFFL remains enabled-capable and finite-cycle fairness is unchanged.
- Public reads remain Blue by default until the existing validation/GAB cutover gates pass.
- Blue is not retired.
- assets/images are unchanged.
