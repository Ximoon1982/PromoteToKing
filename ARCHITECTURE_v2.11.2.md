# Promote to King v2.11.2 architecture

## Dependency direction

Frontend ownership follows `shared ← feature ← page/bootstrap`. Shared code may not import page implementations. Feature business policy remains feature-owned. Page/bootstrap files compose existing features and retain compatibility globals.

Backend public services remain compatibility facades. Extracted collaborators own mechanics or one bounded business responsibility; they do not change endpoint contracts. Constructors stay explicit and small. No framework, service container or hidden global dependency system is introduced.

## Admin shell

The canonical shell owns six-tab selection, deep links, history, the detail registry, embedded/standalone mode, iframe lifecycle and sizing, parent/child messages, session checks, and loading/failure states. Recruitment, Team Depth, Chronology and Aliases use the standard detail-host route. Special navigation paths are forbidden.

## Compatibility policy

Existing globals, routes, public PHP methods, API envelopes, DOM identifiers, effective initialization order and persistence formats are frozen. New module scripts load immediately before their compatibility facade and do not introduce visible markup or styling. A compatibility facade may delegate internally but may not translate observable output. Shims remain until caller inventory proves they are unused.

## Admin jobs

`AdminJob` defines an internal normalized observation contract only. Existing Recruitment and maintenance processes keep their storage, scheduling, pause/resume and checkpoint policies. No new job or UI is introduced.

## Future placement

Transport belongs in `shared/api`; business refresh policy stays with its feature; Admin navigation belongs to the shell; endpoint formatting stays at the API boundary; SQL ownership stays in repositories; derived computations stay in aggregators; persisted formats may move only in a separately approved migration release.
