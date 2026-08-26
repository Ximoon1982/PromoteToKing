#!/usr/bin/env python3
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]

def check(value, message):
    if not value:
        raise AssertionError(message)

def main():
    tournament = (ROOT / 'TournamentManagement.html').read_text()
    service = (ROOT / 'server/tournaments/src/TournamentService.php').read_text()
    live_page = (ROOT / 'LiveRanks.html').read_text()
    live_page_js = (ROOT / 'assets/js/pages/live-ranks-page.js').read_text()
    rank_ladder = (ROOT / 'assets/js/shared/rank-ladder.js').read_text()
    dashboard = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text()
    ui = (ROOT / 'ui-v2.html').read_text()
    live_service = (ROOT / 'server/team-points/src/LiveRanksService.php').read_text()
    index = (ROOT / 'index.html').read_text()

    check("JOB_KEY='p2k-tournament-repair-job-v4'" in tournament, 'Compact v4 repair job key is missing')
    check("'p2k-tournament-repair-job-v3'" in tournament and 'migrateLegacyStorage()' in tournament,
          'Legacy quota-heavy repair state is not migrated')
    check('localStorage.setItem(CACHE_KEY' not in tournament,
          'Full Chess.com payloads are still persisted in localStorage')
    check('httpCache:new Map()' in tournament and 'while(state.httpCache.size>160)' in tournament,
          'Bounded in-memory tournament response cache is missing')
    check('id="repairPodiums"' in tournament and 'runPodiumRepairOnly' in tournament,
          'Podium-only repair action is missing')
    check("entry?.id" in tournament and "function playerName" in tournament,
          'Player tournament URL/row parsing is not robust')
    check('medalPlace++' in tournament and 'mergeFallback' in tournament,
          'Final-group tied medal fallback is missing')
    check('podiumUnresolvedSlugs' in tournament and 'Unresolved:' in tournament,
          'Unresolved podium diagnostics are missing')
    check('TournamentManagement.html?embedded=1&amp;v=2.8.1' in index,
          'UI v1 tournament administration iframe is not cache-busted')

    check(("$this->api->get($roundUrl, 0, true)" in service or "$this->api()->get($roundUrl, 0, true)" in service) and 'private function tournamentSlug' in service,
          'Server resolver does not force fresh round data or robust URL parsing')
    check('medalPlace++' in service and 'last round contains more than one group' in service,
          'Server tied-medal/fallback resolver is incomplete')

    check('P2K_RANK_LADDER.render' in dashboard and dashboard.count('P2K_RANK_LADDER.render') >= 2,
          'Daily and Live ranks do not share the same rank-ladder renderer')
    check('dashboard-hall-rank-card' in rank_ladder and 'dashboard-hall-members' in rank_ladder,
          'Shared rank-ladder component does not render the Daily-rank structure')
    check('assets/images/live-ranks' in dashboard and 'assets/images/ranks' in dashboard and '/thumbs/${size}/' in dashboard,
          'Live ranks do not use compact frameless/framed thumbnails')
    check('rank-ladder.js?v=2.8.1' in ui and 'rank-ladder.js?v=2.8.1' in live_page,
          'Shared rank-ladder component is not loaded by both pages')
    for field in ('liveRanksCurrentMembers','liveRanksRankedMembers','liveRanksLeader','liveRanksPersonalPosition'):
        check(f'id="{field}"' in ui and f'id="{field}"' in live_page, f'Live-rank summary field missing: {field}')
    check('P2K_RANK_LADDER.render' in live_page_js and 'ordered by Live arena points' in live_page_js,
          'Standalone Live ranks does not use the shared Daily-rank behavior')
    check('current_member' in dashboard and 'current_member' in live_page_js,
          'Public Live-rank ladder is not restricted to current P2K members')
    check("'icon' =>" in live_service and "'framed_image' =>" in live_service,
          'Live-rank API does not expose both frameless and framed assets')
    check('p2k_tp_username_key($username)' in live_service and
          'rawurlencode($usernameKey)' in live_service,
          'CSV/API username case alignment is missing')

    php = r'''
require 'server/tournaments/src/bootstrap.php';
$ref = new ReflectionClass(P2K\Tournaments\TournamentService::class);
$obj = $ref->newInstanceWithoutConstructor();
$fallback = $ref->getMethod('fallbackPlacements');
$fallback->setAccessible(true);
$result = $fallback->invoke($obj, [
 ['username'=>'Alpha','points'=>5,'tie_break'=>10],
 ['username'=>'ALPHA2','points'=>5,'tie_break'=>10],
 ['username'=>'Beta','points'=>4,'tie_break'=>8],
 ['username'=>'Gamma','points'=>3,'tie_break'=>7],
 ['username'=>'Delta','points'=>2,'tie_break'=>6],
]);
if ($result[1] !== ['Alpha','ALPHA2'] || $result[2] !== ['Beta'] || $result[3] !== ['Gamma'] || !$result['hasFourth']) exit(2);
$slug = $ref->getMethod('tournamentSlug');
$slug->setAccessible(true);
foreach ([
 'https://www.chess.com/tournament/promote-to-king-test' => 'promote-to-king-test',
 'https://api.chess.com/pub/tournament/promote-to-king-test' => 'promote-to-king-test',
 'https://api.chess.com/pub/tournament/promote-to-king-test/2/1' => 'promote-to-king-test',
] as $url => $expected) if ($slug->invoke($obj, $url) !== $expected) exit(3);
'''
    subprocess.run(['php', '-r', php], cwd=ROOT, check=True)
    print('v2.8.1 tournament and Live-rank tests passed.')

if __name__ == '__main__':
    main()
