# Analytical model versions

The Administration Diagnostics tab reports model identifiers separately from the package release. These identifiers describe the calculation, eligibility, projection, or classification logic currently in use; they are not browser-cache versions and they do not replace the repository version in `VERSION`.

| Identifier | Model | Current source revision |
|---|---|---|
| `FM-32` | Match Assistant eligibility and recommendation | FindMatch v32 |
| `UA-45` | Upcoming lineup comparison, win probability, recruitment recommendation, and historical snapshot summarization | Upcoming Matches Analyzer v45 |
| `MC-23` | Match Creation scoring, secured results, and pending-points calculations | Match Creation Analyzer v23 |
| `AM-3` | In-progress match score projection from start-time ratings and completed games | Analyze Match v3 |
| `RM-1` | Recruitment candidate eligibility | Recruit Match v1 |
| `CL-4` | Challenge validation, error classification, ordered recommendation, and permissive safe slug parsing | Challenge validation and recommendation model v4 |
| `MH-1` | Match registration history and probability evolution | Match history model v1 |

## Versioning rule

A model identifier changes only when its calculation rules, eligibility rules, probability/scoring formula, or result classification changes. Refactoring, caching, diagnostics, documentation, styling, or transport changes do not require a model-version change when outputs remain equivalent.

The registry is defined once in `assets/js/site-config.js`, exposed in copied diagnostics, and displayed in the Administration Diagnostics tab.
