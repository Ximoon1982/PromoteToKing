# Architecture

## Public pages

| Entry point | Controller | Stylesheet |
|---|---|---|
| `FindMatch_v1.htm` | `assets/js/pages/find-match.js` | `assets/css/legacy-loader.css` |
| `AnalyzeMatches_v1.htm` | `assets/js/pages/upcoming-matches-analyzer.js` | `assets/css/upcoming-matches-analyzer.css` |
| `MatchCreationAnalyzer_v1.htm` | `assets/js/pages/match-creation-analyzer.js` | `assets/css/legacy-loader.css` |
| `AnalyzeMatch.html` | `assets/js/pages/analyze-match.js` | `assets/css/analyze-match.css` |

## Internal page

`AnalyzeMatchModal.html` is instantiated lazily by the Match Assistant. It shares the Upcoming Analyzer stylesheet and adds only `assets/css/analyze-match-modal.css` as an override layer.

## Shared runtime

`assets/js/shared/api-cache.js` is loaded before each page controller and provides the common Chess.com API cache, in-flight de-duplication, stale revalidation, and request ordering helpers.

`assets/js/site-config.js` holds routes and the two pinned compatibility-source URLs. It is the only file that needs editing when routes or deployment locations change.

## Dependency direction

```text
HTML entry point
  ├── page stylesheet(s)
  ├── site-config.js             (compatibility pages)
  ├── shared/api-cache.js
  └── pages/<page-controller>.js
```

Page controllers do not import one another. The Match Assistant opens `AnalyzeMatchModal.html` through the configured route.
