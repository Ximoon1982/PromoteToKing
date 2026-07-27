# Promote to King standardized website

This folder is ready to publish at the root of the GitHub Pages repository.
No bundler or build command is required.

## Public entry points

- `FindMatch.htm` — Match Assistant
- `AnalyzeMatches.htm` — Upcoming Matches Analyzer
- `MatchCreationAnalyzer.htm` — Match Creation Analyzer
- `AnalyzeMatch.html` — standalone single-match analyzer

Internal helper:

- `AnalyzeMatchModal.html` — loaded lazily by the Match Assistant only when a detailed-analysis button is clicked

All deployable page names are unversioned. Historical versioned aliases are intentionally excluded from this package.

## Shared structure

- `assets/js/shared/api-cache.js` contains the shared IndexedDB cache, request de-duplication, conditional revalidation, and priority helpers.
- `assets/js/site-config.js` centralizes routes and compatibility source URLs.
- `assets/js/pages/` contains one page controller per tool.
- `assets/css/upcoming-matches-analyzer.css` is shared by the Upcoming Analyzer and modal detail view.
- `assets/css/analyze-match-modal.css` contains only modal-specific overrides.
- `assets/css/legacy-loader.css` is shared by the Match Assistant and Match Creation compatibility bootstraps.

## Deployment

1. Copy the complete contents of this folder to the root of `P2KMatchFinder`.
2. Keep the directory names and relative paths unchanged.
3. Confirm GitHub Pages serves the repository over HTTPS.
4. Test the four public entry-point URLs.

## Compatibility note

The current Match Assistant and Match Creation Analyzer remain compatibility loaders around pinned historical source files. Their local custom logic and styling are externalized, and the pinned source URLs are centralized in `assets/js/site-config.js`. The Upcoming Analyzer, standalone match analyzer, and modal helper are fully local standalone pages.

A future cleanup can vendor those two historical base files without changing any public URL: place local copies in the site and update only the two `legacySources` values in `assets/js/site-config.js`.
