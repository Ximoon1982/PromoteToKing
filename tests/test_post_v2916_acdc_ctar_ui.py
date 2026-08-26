from pathlib import Path
import hashlib, re

ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')
def sha(p): return hashlib.sha256((ROOT/p).read_bytes()).hexdigest()

def require(c,m):
    if not c: raise AssertionError(m)

# Approved 1WL masters: exact new approved bytes, including replacement of historical participation trio.
expected={
 '1wl-competitor':'f8e41e623779c538d4fadc0255a25c628e33fc2b9debcb29c56a9779581e1d31',
 '1wl-veteran':'266b770ff569e18364385768262dfa3e67d7c70bc4f5e71de98f1ac7cee4a428',
 '1wl-legend':'57804761ff7d1d64fb7ea85ef51f0843fa995f7ad3340fd6c546c4fc96125bd6',
}
for key,h in expected.items():
    require(sha(Path('assets/images/achievements')/(key+'.png'))==h, key+' does not use approved replacement master')
for key in ['1wl-competitor','1wl-veteran','1wl-legend','1wl-first-point','1wl-scorer','1wl-specialist','1wl-master']:
    require((ROOT/'assets/images/achievements/thumbs/128'/(key+'.webp')).is_file(), key+' thumb missing')
    require((ROOT/'assets/images/achievements/mini/64'/(key+'.webp')).is_file(), key+' mini missing')

repo=text(Path('server/team-points/src/Repository.php'))
team=text(Path('TeamInsights.html'))
tour=text(Path('Tournaments.html'))
maxjs=text(Path('assets/js/shared/chart-maximize.js'))
gateway=text(Path('server/shared/SharedChessGateway.php'))
api=text(Path('server/team-points/src/ChessApi.php'))
oauth=text(Path('server/team-points/src/OAuthSession.php'))
worker=text(Path('server/team-points/src/Worker.php'))
cron=text(Path('server/team-points/public/cron.php'))
coord=text(Path('server/team-points/src/CronMaintenanceCoordinator.php'))
analytics=text(Path('server/team-points/src/AnalyticsBuilder.php'))
control=text(Path('server/control/public/api.php'))
fallback=text(Path('server/team-points/src/PlayerMatchesFallbackState.php'))
observe=text(Path('server/team-points/src/ObservationIngestor.php'))
client=text(Path('assets/js/shared/client-continuous-refresh.js'))
apiclient=text(Path('assets/js/shared/api-client.js'))

# UI corrections.
require('Daily facts are materialized in the database' not in team,'durable daily-facts footer still visible')
require('Excluded accounts' not in tour,'Excluded accounts tournament metric still visible')
require("(string)$r['date']<=$today" in repo,'daily boards backend is not clamped to today')
require('associatedLegend' in maxjs and '100dvw' in maxjs and '100dvh' in maxjs,'maximize viewport/legend integration missing')
require('legendPlaceholder' in maxjs and 'body.append(legend)' in maxjs,'maximize does not move/restore live legend')
require('dispatchEvent(new Event("resize"))' in maxjs or "dispatchEvent(new Event('resize'))" in maxjs,'maximize does not request chart redraw')

# ACDC: absolute deadlines + dynamically bounded budgets + canonical stale safety.
require('deadline_at' in gateway and 'canonical_verification' in gateway,'gateway ACDC options missing')
require('$allowStale' in gateway and '!$canonicalVerification' in gateway,'stale-if-error can still advance canonical verification')
require('lockWaitSeconds' in gateway and 'deadlineAt' in gateway,'dynamic gateway lock deadline missing')
require('setDeadlineAt' in api and 'canonical_verification' in api,'ChessApi canonical deadline propagation missing')
require('$requestDeadlineAt' in cron and 'CronMaintenanceCoordinator' in cron and 'refreshIfDue' in coord,'request-wide CRON deadline/CMDI maintenance propagation missing')
require('absoluteDeadlineAt' in worker and 'maxItemsOverride' in worker,'Worker deadline/canonical-quota admission missing')
require('canonicalQuota' in control and "'acsr-browser', $maxSeconds, $absoluteDeadlineAt, $canonicalQuota" in control,'Fast Fetch canonical quota is not enforced')
require("'/fairness-'.$lane.'.json'" in worker and 'loadFairCursor' in worker and 'saveFairCursor' in worker,'persistent fairness cursors missing')
require("['sync_match']" in worker and "['sync_board']" in worker,'Club match/board fair schedule missing')
require('execution_attempted_items' in worker and 'terminal_committed_items' in worker,'attempted-vs-terminal telemetry missing')
require('client_refresh' in observe and 'observationSource: "client_refresh"' in client and 'client_refresh' in apiclient,'explicit client_refresh provenance missing')

# PMAF expansion: completion only after archive success + browser cooldown propagation.
require('successful_months' in fallback and 'failed_months' in fallback and 'verificationStatus' in fallback,'PMAF generation result ledger missing')
require('markMonthSucceeded' in worker and 'markMonthFailed' in worker,'PMAF archive completion/failure accounting missing')
require('PMAF verification pending' in worker,'PMAF can still mark freshness on scheduling only')
require('pmaf_matches_suppressed_by_cooldown' in control,'PMAF cooldown not propagated into Fast Fetch planner')

# CTAR transport/cache/amplification reductions.
require('ingestAttestedOAuth' in gateway and 'oauth-attested-v2917' in gateway,'server-attested OAuth cache ingest missing')
require('ingestAttestedOAuth' in oauth,'OAuth gateway results are not reused by canonical cache')
# Global lock must be released before request launch in main fetch path.
release=gateway.find('$this->releaseLock();'); request=gateway.find('$response = $this->request(', release)
require(release>=0 and request>release,'gateway lock still spans upstream HTTP request')
require('matchReadyForAuthoritativeFinalization' in repo and 'finalizationAttempted' in worker,'per-board match finalization amplification not coalesced')
require('observeClubMatchIndexBatch' in repo and 'enqueueBatch' in repo,'club-index N+1 / queue transaction batching missing')
require('analytics_generation_coalesce_seconds' in analytics and 'generation_coalescing' in analytics,'Analytics generation amplification control missing')
require("'empty_retry_ms'=>10000" in control,'idle planner backoff not increased')
require('archiveUsers' in worker,'syncMembers duplicate archive fan-out reduction missing')
require('$finishedCount=count($finished)' in worker and '$totalEntries=$finishedCount+$inProgressCount' in worker,'player continuation still requires flattened combined list')

print('post-v2.9.16 ACDC + CTAR + requested UI/artwork acceptance: PASS')
