#!/usr/bin/env python3
from pathlib import Path
import json
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def check(value, message):
    if not value:
        raise AssertionError(message)

def main():
    check((ROOT / 'VERSION').read_text().strip() == '2.8.1', 'VERSION is not 2.8.1')
    ui = (ROOT / 'ui-v2.html').read_text()
    index = (ROOT / 'index.html').read_text()
    dashboard = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text()
    worker = (ROOT / 'server/team-points/src/Worker.php').read_text()
    repo = (ROOT / 'server/team-points/src/Repository.php').read_text()
    api = (ROOT / 'server/team-points/src/ChessApi.php').read_text()
    schema = (ROOT / 'server/team-points/sql/core-schema.sql').read_text() + '\n' + (ROOT / 'server/team-points/sql/analytics-schema.sql').read_text()
    live = (ROOT / 'server/team-points/src/LiveRanksService.php').read_text()
    admin = (ROOT / 'TeamPointsAdmin.html').read_text()
    admin_js = (ROOT / 'assets/js/pages/team-points-features.js').read_text()
    opponents = (ROOT / 'Opponents.html').read_text()
    opponents_admin = (ROOT / 'server/team-points/public/opponents-admin.php').read_text()
    tournaments = (ROOT / 'TournamentManagement.html').read_text()

    check('data-key="teamInsights"' not in index and 'data-key="tournaments"' not in index,
          'UI v1 exposes UI-v2-only pages')
    for key in ('team', 'members', 'matches', 'opponents'):
        check(f'data-insights-subtab="{key}"' in ui, f'Insights subtab missing: {key}')
    member_section = ui[ui.index('data-insights-subtab="members"'):][:260]
    check('disabled' not in member_section and 'id="membersDataTable"' in ui,
          'Members must be active and database-backed')
    for key, panel_id in (('matches', 'matchesDataTable'), ('opponents', 'opponentsDataTable')):
        section = ui[ui.index(f'data-insights-subtab="{key}"'):][:260]
        check('disabled' not in section and f'id="{panel_id}"' in ui,
              f'{key} must be an active native database-backed Insights subtab')
    check('id="opponentsFrame"' not in ui and 'id="matchesFrame"' not in ui,
          'Native Insights panels must not be iframe-backed')
    live_section = ui[ui.index('data-hall-subtab="live"'):][:220]
    check('disabled' not in live_section and 'id="liveRanksNativeGrid"' in ui and 'id="liveRanksFrame"' not in ui,
          'Native Live ranks must be active in Hall of Fame')
    check('url.searchParams.set("insights", state.insightsSubtab)' in dashboard and
          'url.searchParams.set("hall", state.hallSubtab)' in dashboard and 'popstate' in dashboard,
          'Refresh/back state persistence is incomplete')

    for label in ('Different opponents', 'Currently playing', 'In registration', 'Finished matches'):
        check(label in ui, f'Native Opponents summary missing: {label}')
    for field in ('Total', 'Ongoing', 'Registered', 'Finished', 'Wins', 'Draws', 'Losses', 'Our score', 'Their score', 'Balance', 'Win rate'):
        check(field in ui, f'Native Opponents table field missing: {field}')
    for label in ('Stored matches', 'Average boards', 'Average duration', 'Median duration'):
        check(label in ui, f'Native Matches summary missing: {label}')
    check('window.P2KDataTable' in (ROOT / 'assets/js/shared/data-table.js').read_text() and
          'new window.P2KDataTable' in dashboard, 'Shared native table component is not reused')
    check("const opponentEndpoint = 'server/team-points/public/opponents-admin.php'" in admin_js and "action: 'scan'" in admin_js and "action: 'apply'" in admin_js,
          'Opponent verification/apply workflow is not wired')
    check('exportOpponents' in admin_js and 'Old endpoint failed' in opponents_admin,
          'Opponent rename/disabled CSV workflow is incomplete')
    check('upsertMatchMetadata' in worker and 'publicOpponentStats' in repo,
          'Worker does not populate the opponent data model')

    thresholds = [('Live Pawn',50),('Live Knight',150),('Live Bishop',500),('Live Rook',2500),('Live Queen',7500),('Live King',15000)]
    for name, points in thresholds:
        check(name in live and f"'minimum' => {points}" in live, f'Live rank threshold missing: {name}')
    check("['username', 'club', 'score']" in live and "'promote to king'" in live,
          'Arena CSV parser does not use the required columns/team')
    check("account_state='pending_profile'" in live and "possible_renamed" in live and 'points computation' in live,
          'Possible username changes must remain a non-blocking corrective action')
    check('original_name=?' in live and 'replaced_at=UTC_TIMESTAMP()' in live,
          'Same-filename upload replacement is missing')
    check('uq_lr_file_name' in schema and 'UNIQUE KEY uq_lr_file_hash' not in schema and 'KEY idx_lr_file_hash' in schema,
          'CSV filename/hash identity migration is incorrect')
    check('invalidateProcessingAfterUpload' in live and 'DELETE FROM p2k_lr_players' in live,
          'CSV replacement does not invalidate stale Live-rank results')
    check('same filename' in admin.lower() and 'replaces' in admin.lower(),
          'Administration does not explain filename replacement')
    check('previous computation invalidated' in admin_js,
          'Upload result does not explain invalidation')
    for asset in [
        '01_Live_Pawn_50_points.png','02_Live_Knight_150_points.png','03_Live_Bishop_500_points.png',
        '04_Live_Rook_2500_points.png','05_Live_Queen_7500_points.png','06_Live_King_15000_points.png'
    ]:
        check((ROOT / 'assets/images/live-ranks' / asset).is_file(), f'Missing Live-rank image: {asset}')
        check((ROOT / 'assets/images/live-ranks/thumbs/192' / asset.replace('.png','.webp')).is_file(), f'Missing 192 thumbnail: {asset}')
        check((ROOT / 'assets/images/live-ranks/thumbs/640' / asset.replace('.png','.webp')).is_file(), f'Missing 640 thumbnail: {asset}')

    for task in ('sync_club_matches','sync_match','discover_match_ids'):
        check(f"'{task}'" in worker, f'Historical discovery task missing: {task}')
    check("/club/{$this->clubSlug}/matches" in worker and 'queueRawHistoryRepair' in repo and 'discover_match_ids' in worker,
          'Club-index routine discovery and explicit raw-ID repair are not both present')
    gateway=(ROOT / 'server/shared/SharedChessGateway.php').read_text()
    check('jsonIfExists' in api and '[404, 410]' in gateway and 'probeHealthInsideLock' in gateway,
          'Raw numeric discovery does not distinguish absent IDs safely')
    check('saveDiscoveryState' in worker and 'cursor_match_id' in repo,
          'Raw discovery is not resumable')
    check("$status !== 'finished'" in repo and 'finalizeMatchSummaryAuthoritatively' in worker,
          'Authoritative finished status is not mandatory')

    check('fast-former-member-audit.php' in admin and 'consistency-repair.php' in admin,
          'DB audit/correction tools were lost')
    check("mode:'reinitialize'" in tournaments and "$('podiums').checked=true" in tournaments,
          'Full tournament/podium reinitialization was lost')

    subprocess.run(['php','-r',
        "require 'server/team-points/src/bootstrap.php'; $e=new \\P2K\\TeamPoints\\RetryableException('x',5); exit($e->retryAfterSeconds===5?0:1);"],
        cwd=ROOT, check=True)
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    check(manifest.get('version') == '2.8.1', 'Manifest version is wrong')
    print('v2.8.1 integration tests passed.')

if __name__ == '__main__':
    main()
