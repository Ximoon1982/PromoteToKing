# Promote to King v2.15.3 — Team Widgets collection

The administration Team area now offers a Widgets card with seven tabs: the relocated Match Showcase editor and six independent data-driven widgets (Club Points / Pulse, Recruitment Radar, Trophy Spotlight, Rivalry Spotlight, Club Progress, P2K Around the World).

The existing Events Showcase file state (`data/events-showcase.json`) and its endpoints remain unchanged. The old Competitions card is no longer mounted; its editor is mounted under Team > Widgets > Match Showcase. The other six widgets each have a settings form, on-demand preview and iframe HTML.

New public endpoints: `server/widgets/public/embed.php?widget=ID`, `server/widgets/public/api.php?action=config&widget=ID`, and `server/widgets/public/api.php?action=countries`. Only published data sources are used. No placeholder figures are shipped. Widgets are 260 px wide, with iframe height reporting; the iframe HTML includes a useful static fallback height.

Configuration is independent, under shared `data/widgets/config.json`, with atomic writes, advisory file locking, backups and optimistic revision checks. The directory is shared across public release slots via existing P2K release runtime linking. All public code and state stay behind the existing P2K PublicRouter and administrator write gates.

Data limitations: Recruitment Radar counts verified upcoming registrations, but does not report player shortfalls without measured roster data. Around the World only plots opponent country codes already stored by P2K and reports unclassified opponents; the map is schematic. Club Progress excludes forecasts. Trophy Spotlight displays published trophy records only.

No database migration, new cron, or change to Match Showcase state is required. The release candidate installer must read and accept the **exact** current public version and full source HEAD; never bypass that check. Preview and promotion remain operations in P2K Release Control.
