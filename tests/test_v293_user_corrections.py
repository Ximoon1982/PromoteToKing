from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8',errors='ignore')

def achievement_keys():
    cat=text('server/team-points/src/AchievementCatalog.php')
    return re.findall(r"self::item\('([^']+)'",cat)

def test_heatmaps_live_on_insights_opponents_not_club_intelligence_balance_tab():
    ui=text('ui-v2.html')
    insights=text('assets/js/pages/dashboard-insights.js')
    club=text('ClubIntelligence.html')
    assert 'id="opponentsBalanceAnalyzer"' in ui
    assert ('opponent-balance-analyzer.js?v=2.9.5' in ui or 'opponent-balance-analyzer.js?v=2.9.7' in ui or 'opponent-balance-analyzer.js?v=2.9.8' in ui or 'opponent-balance-analyzer.js?v=2.9.9' in ui or 'opponent-balance-analyzer.js?v=2.9.10' in ui or 'opponent-balance-analyzer.js?v=2.9.11' in ui or 'opponent-balance-analyzer.js?v=2.9.12' in ui or 'opponent-balance-analyzer.js?v=2.9.13' in ui or 'opponent-balance-analyzer.js?v=2.9.14' in ui or 'opponent-balance-analyzer.js?v=2.9.15' in ui or 'opponent-balance-analyzer.js?v=2.9.16' in ui or 'opponent-balance-analyzer.js?v=2.9.17' in ui or 'opponent-balance-analyzer.js?v=2.9.18' in ui or 'opponent-balance-analyzer.js?v=2.9.19' in ui or 'opponent-balance-analyzer.js?v=2.9.20' in ui or 'opponent-balance-analyzer.js?v=2.9.21' in ui or 'opponent-balance-analyzer.js?v=2.9.22' in ui)
    assert 'section:"balance"' in insights
    assert 'window.P2K_OPPONENT_BALANCE.render(host,payload)' in insights
    assert 'data-tab="balance"' not in club
    assert 'Balance Analyzer' not in club

def test_public_heatmap_payload_is_all_non_void_matches_with_paired_rating_coverage():
    repo=text('server/team-points/src/Repository.php')
    assert 'public function publicOpponentBalance' in repo
    block=repo[repo.index('public function publicOpponentBalance'):repo.index('/** v2.8.8: summary/top chart', repo.index('public function publicOpponentBalance'))]
    assert "WHERE club_slug=? AND is_void=0 AND board_count>0" in block
    assert "status='finished'" not in block
    assert "'all_matches'=>$total" in block
    assert "'rating_source'=>'paired_board_positions'" in block
    renderer=text('assets/js/pages/opponent-balance-analyzer.js')
    assert 'coverage.all_matches ?? coverage.finished_matches' in renderer

def test_breadth_achievements_are_additive_not_replacements():
    keys=achievement_keys()
    legacy=(ROOT/'tests/fixtures/v291_achievement_keys.txt').read_text(encoding='utf-8').splitlines()
    added={f'breadth-groups-{n}' for n in (1,5,10,15,20)}
    assert len(legacy)==128
    assert len(keys)==162
    assert set(legacy).issubset(keys)
    assert added.issubset(keys)
    for key in ['groups-5','groups-10','groups-15','groups-all']:
        assert key in keys

def test_player_achievement_display_rules_from_august_10_are_preserved():
    dash=text('assets/js/pages/dashboard-v2.js')
    intel=text('server/team-points/src/ClubIntelligenceService.php')
    assert 'progress:progressByKey.get(key)||null' in dash
    assert 'hideOwnership:Boolean(name)' in dash
    assert 'const progress = !earned && options.progress && Number(options.progress.current) > 0' in dash
    assert '.sort((a,b)=>achievementTimestamp(b)-achievementTimestamp(a)' in dash
    assert 'data-challenge-achievement' in dash and 'openAchievementCatalog(player.username||name,String(button.dataset.challengeAchievement' in dash
    assert "preg_match('/^breadth-groups-(1|5|10|15|20)$/" in intel

def test_player_profile_unions_persisted_and_profile_derived_achievements():
    repo=text('server/team-points/src/Repository.php')
    assert 'persisted unlocks are additive evidence' in repo
    assert '$earnedByKey=[];' in repo
    assert 'foreach($fallbackAchievements as $item)' in repo
    assert 'if($key===\'\'||isset($earnedByKey[$key]))continue;' in repo
    assert "$profile['achievements']=array_values($earnedByKey);" in repo
    assert "$profile['achievements']=$earned!==[]?$earned:$fallbackAchievements" not in repo


def test_public_catalog_and_player_catalog_use_complete_catalog_without_breadth_filtering():
    endpoint=text('server/team-points/public/achievements.php')
    repo=text('server/team-points/src/Repository.php')
    dash=text('assets/js/pages/dashboard-v2.js')
    standalone=text('TournamentAchievementBadgesDemo.html')
    assert "'catalog'=>AchievementCatalog::all()" in endpoint
    assert 'foreach(AchievementCatalog::all() as $item)' in repo
    assert 'catalog.forEach(item=>' in dash
    assert 'catalog.forEach(item=>' in standalone
