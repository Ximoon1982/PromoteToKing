#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ui = (ROOT / 'ui-v2.html').read_text(encoding='utf-8')
dashboard = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text(encoding='utf-8')
finder = (ROOT / 'assets/js/pages/find-match.js').read_text(encoding='utf-8')
table = (ROOT / 'assets/js/shared/data-table.js').read_text(encoding='utf-8')
repository = (ROOT / 'server/team-points/src/Repository.php').read_text(encoding='utf-8')
live = (ROOT / 'server/team-points/src/LiveRanksService.php').read_text(encoding='utf-8')
public = (ROOT / 'server/team-points/public/public.php').read_text(encoding='utf-8')
schema = (ROOT / 'server/team-points/sql/core-schema.sql').read_text(encoding='utf-8') + (ROOT / 'server/team-points/sql/analytics-schema.sql').read_text(encoding='utf-8')
css = (ROOT / 'assets/css/dashboard-v2.css').read_text(encoding='utf-8')

assert 'dashboardLoadProgress' not in ui and 'dashboardLoadBar' not in ui
assert 'loadingTarget' not in dashboard and 'animateLoading' not in dashboard
assert dashboard.count('document.createElement("iframe")') == 1
assert 'p2k-dashboard-full-assistant-ready' in dashboard and 'p2k-dashboard-full-assistant-ready' in finder
assert 'p2k-dashboard-assistant-hydrating' in finder
assert 'remoteLoader' in table and 'setRemoteData' in table
assert 'publicMatchInsights(string $clubSlug, array $options = [])' in repository
assert 'LIMIT {$pageSize} OFFSET {$offset}' in repository
assert "'pagination' => [" in repository
assert "'page_size' => (int)($_GET['page_size'] ?? 25)" in public
assert "'include_summary' => (string)($_GET['include_summary'] ?? '1') !== '0'" in public
assert "'players' => $players" not in live[live.index('public function publicPayload'):live.index('public function publicPlayerPayload')]
public_rows = live[live.index('private function publicPlayerRows'):live.index('private function compactPlayerRow')]
assert 'source_files_json' not in public_rows
assert "'groups'=>array_values($groups)" in live and "'unranked_count'" in live and "$payload['members']=$slice" in live
assert 'assets/images/ranks/thumbs/320/' in dashboard and 'assets/images/ranks/thumbs/${size}/' in dashboard
assert 'id="personalModeToggle"' in ui and 'renderLivePersonalCard' in dashboard
for metric in ('personalLiveBestScore','personalLiveTotalWins','personalLiveMaxWins','personalLiveBestStreak'):
    assert f'id="{metric}"' in ui
for column in ('total_wins','best_streak','max_wins_single_arena','best_rank','top3_count','top10_count','best_score'):
    assert column in schema and column in live
assert 'p2k_tp_username_key($username)' in live
assert 'id="dashboardAdminPriorityCard"' not in ui
assert 'function ensureAdminPriorityCard()' in dashboard and 'if (!state.admin) return null' in dashboard
assert 'dashboardAdminQueue(processedCache?.entries || matches)' in finder
assert '.dashboard-admin-priority-card' in css
print('v2.8.1 Phase 1, player-card and administrator-card tests passed.')
