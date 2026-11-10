# Team France — admin hub and tools

The `/TeamFrance/` home page is restricted to verified Team France administrators through the existing Chess.com OAuth session. It shows club details, the current administrator list, and team matches currently open for registration.

The matching administration tools are:

- `/TeamFrance/Widget/admin/` — Match Showcase and public Widget configuration.
- `/TeamFrance/MatchAnalyzer/` — Team match lineup analysis and projections.
- `/TeamFrance/MatchRecruitment/` — Member eligibility checks, CSV exports and request diagnostics.

The Analyzer and Recruitment Assistant use a shared upcoming-match radio selector. All UI text is English, with Team France's blue-white-red theme retained. Match titles and user data come unchanged from Chess.com.

Run `bash TeamFrance/install-admin-hub.sh /kunden/homepages/43/d141198007/htdocs/PromoteToKing` from an extracted installer outside the live directory. The installer backs up modified source files without overwriting persistent Widget data, analyzer history or recruitment snapshots.
