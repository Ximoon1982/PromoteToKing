# Install Promote to King v2.10.5.4

Install over v2.10.5.3 using the supplied incremental package and updater from the PromoteToKing document root.

The update is source-only. It does not change database schema, reset/reseed Green, or change CRON definitions.

After installation:

1. Hard refresh `ui-v2.html` / Administration.
2. Open **Scheduled Tasks → Green Team Points** and confirm the version is 2.10.5.4.
3. Confirm Last cycle and Average · last 10 are populated once completed-cycle history exists.
4. During `quick_matches`, confirm an estimated progress card is shown instead of `0 / 1`.
5. Test Dashboard → Priority calls and Dashboard → Matches starting within 7 days; each should reveal the filtered assistant without leaving it hidden.
6. In Opponent Insights, verify the two Balance Analyzer charts use the zoned density rendering.
7. Match-monitoring history is retained; automatically tracked matches older than 24 hours after start will become unfollowed on the next Cron tracking listing/run.
