# Promote to King v2.8.6 architecture delta

v2.8.6 keeps the v2.8.5 Core 5 / Analytics 5 storage and two-worker synchronization architecture. The change is primarily the public read/render pipeline.

## Public loading priorities

- **P0:** static shell and safe session snapshot.
- **P1:** information visible on the first screen: materialized Dashboard KPIs, first Achievement page, Insights summaries/first graph, default Podium page.
- **P2:** automatic second-wave/current data and sections approaching the viewport.
- **P3:** interaction-specific detail such as expanded rank pages, full achievement catalogue and player tournament history.

P2/P3 work must not delay P1 rendering.

## Progressive server reads

Existing public endpoints accept lightweight `section`/mode requests while retaining full compatibility responses. Materialized generation-keyed caches remain the source for public Analytics. Tables paginate in MariaDB or before JSON output and return 25 rows by default.

## Browser scheduling

`assets/js/shared/progressive-loader.js` owns viewport preloading, low-priority concurrency, first-paint/idle scheduling, short safe session snapshots and connection-aware prefetch suppression. Public GETs allow HTTP/ETag revalidation; operational/admin writes remain uncached.

## JavaScript loading

The native Insights SVG engine is isolated in `assets/js/pages/dashboard-insights-charts.js` and dynamically loaded on first chart use, reducing initial Dashboard parse work without changing chart behavior.
