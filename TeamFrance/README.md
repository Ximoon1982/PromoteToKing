# Team France standalone chess applications

P2K release/v2.15.3 addition, cloned from the independently working TGP Widget v0.2.0 and Match Analyzer.

- Public widget: `/TeamFrance/Widget/`
- Widget administration: `/TeamFrance/Widget/admin/`
- Match Analyzer: `/TeamFrance/MatchAnalyzer/`
- Club identity: `https://www.chess.com/club/team-france`

The widget is publicly visible. Administration in the widget and all analyzer functions require a verified P2K Chess.com OAuth identity **and Team France club-admin membership**. Each installation has independent widget data, cache, PHP session, CSRF header, and diagnostics. Analyzer monitoring uses the existing shared P2K match registry (same behavior as TGP).

**Deploy** by copying this whole `TeamFrance/` folder to a staging location on IONOS and running:

```bash
bash TeamFrance/install-standalone.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing
```

The installer validates input, PHP syntax, and dependencies; backs up previously installed source; and copies only files under `/TeamFrance/`. It intentionally never overwrites `Widget/data/showcase.json` or cached data. It does not change root `.htaccess`, OAuth, Release Control, public slots, or TGP applications. P2K PHP 8.5 CLI is required on the server. When running the local installer simulation, set `PHP_CLI` to an installed compatible CLI.

The analyzer is available at its standalone URL rather than through P2K's preview-slot router. Shared history endpoints continue to resolve from the P2K root.
