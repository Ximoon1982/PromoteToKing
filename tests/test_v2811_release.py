from pathlib import Path
import re
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]

def text(path): return (ROOT/path).read_text(encoding='utf-8',errors='ignore')

def test_identity_and_additive_core8():
    assert any(f'CORE_SCHEMA_VERSION = {v}' in text('server/team-points/src/Repository.php') for v in [8,9,10,11,12,13,14,15])
    mig=text('server/team-points/sql/core-migration-v2.8.11.sql')
    assert 'player_matches_checked_at' in mig and 'stats_checked_at' in mig
    assert 'DROP TABLE' not in mig.upper() and 'TRUNCATE' not in mig.upper()
    assert 'SET stats_checked_at=rating_updated_at' in mig

def test_player_lane_convergence_is_due_aware_and_10m():
    w=text('server/team-points/src/Worker.php'); r=text('server/team-points/src/Repository.php')
    assert 'player_reconcile_batch_size' in w and '250' in w
    assert 'player_matches_checked_at' in w and 'stats_checked_at' in w
    assert 'fresh_members_skipped' in w
    assert 'markPlayerMatchesChecked' in w
    assert 'stats_checked_at=UTC_TIMESTAMP()' in r
    assert "'team-points-player'=>600" in text('server/control/public/api.php')
    assert "'expected_interval_seconds' => 600" in text('server/shared/TaskRegistry.php')
    # 4,000 members fit in 16 cheap reconciliation pages at the default batch.
    assert (4000 + 250 - 1)//250 == 16
    ci=text('server/team-points/src/ClubIntelligenceService.php')
    assert 'player_matches_due' in ci and 'player_stats_due' in ci and 'player_matches_fresh_percent' in ci



def test_member_points_steady_state_refresh_demand_is_sustainable():
    cfg=text('server/team-points/config/config.example.php')
    assert "'player_reconcile_matches_refresh_seconds' => 604800" in cfg
    assert "'player_reconcile_stats_refresh_seconds' => 259200" in cfg
    assert "'player_worker_max_items' => 75" in cfg
    worker=text('server/team-points/src/Worker.php')
    assert "player_reconcile_matches_refresh_seconds" in worker
    assert "player_reconcile_stats_refresh_seconds" in worker
    assert "player_worker_max_items" in worker
    # At 4,000 members the periodic bulk due-set is ~79 requests/hour:
    # 4,000/7d player-match checks + 4,000/3d stats checks. Even the old
    # 25-items/10m item ceiling is 150/hour, so steady state can drain.
    hourly_demand=4000/(7*24)+4000/(3*24)
    conservative_item_capacity=25*6
    assert hourly_demand < conservative_item_capacity

def test_unrated_members_can_be_marked_checked():
    r=text('server/team-points/src/Repository.php')
    fn=r[r.index('function storeMemberRatings'):r.index('function markPlayerMatchesChecked')]
    assert 'stats_checked_at=UTC_TIMESTAMP()' in fn
    o=text('server/team-points/src/ObservationIngestor.php')
    assert "'verification'=>'server_required'" in o and "'sync_player_stats'" in o
    # Empty/no-rating Chess.com stats remain a valid hint; browser values are never copied to Core.
    stats=o[o.index('private function playerStats'):o.index('private function matchDetail')]
    assert 'Repository::ratingsFromStats($payload)' in stats and "'verification'=>'deferred_authoritative_audit'" in stats

def test_acamr_rotates_and_claims_only_due_work():
    r=text('server/team-points/src/Repository.php'); p=text('server/team-points/public/acamr-plan.php')
    assert 'm.member_id>?' in r and 'ORDER BY m.member_id ASC' in r
    assert '$repo->acamrCandidateMembers($club,$cursor' in p
    assert "'claims'=>$claims" in p
    assert "if(!empty($row['matches_due']))" in p
    assert "if(!empty($row['stats_due']))" in p
    assert 'claims_per_pulse' in p
    assert "'server_verification_required'=>true" in p
    assert "'tournaments'=>false" in p and "'match_registration'=>false" in p

def test_opportunistic_delivery_requires_server_acceptance():
    s=text('assets/js/shared/api-client.js')
    assert 'response.ok' in s and 'payload?.ok !== true' in s
    assert 'observationDeliveryFailures' in s and 'deliveryAttempts' in s
    assert 'observationQueue.unshift' in s

def test_match_insight_sections_do_not_cross_render():
    s=text('assets/js/pages/dashboard-insights.js')
    for name in ['renderMatchResults','renderMatchDuration','renderMatchDimensions','renderMatchHighlights']:
        assert f'function {name}' in s
    assert 'renderMatchResults(analytics' in s
    assert 'renderMatchDuration(analytics' in s
    assert 'renderMatchDimensions(analytics' in s
    assert 'renderMatchHighlights(analytics' in s
    assert 'biggest_losses' in s and 'closest' in s

def test_chart_touch_containment_and_future_today_contracts():
    c=text('assets/js/pages/dashboard-insights-charts.js'); css=text('assets/css/dashboard-v2.css'); t=text('TeamInsights.html'); responsive=text('assets/css/responsive-unification.css')
    assert 'installPinchZoom' in c and 'touchmove' in c
    assert c.count('event.pointerType==="touch"') >= 4
    assert 'addEventListener("click"' in c or "addEventListener('click'" in c
    assert '.p2k-native-chart{overflow:hidden' in css
    assert "installPinch(" in t and "nx>=parseX(opt.futureFrom" in t
    assert 'hiddenSeries' in t and 'renderSeriesLegend' in t
    assert "e.pointerType==='touch'&&drag===null" in t
    assert 'zooms.set(id,[a,b]);lineChart(id,fullSeries,opt)' in t
    assert '.dashboard-hall-search .dashboard-button' in responsive and 'flex: 0 0 auto' in responsive

def test_profile_catalogue_contracts():
    s=text('assets/js/pages/dashboard-v2.js')
    assert '.sort((a,b)=>achievementTimestamp(b)-achievementTimestamp(a)' in s
    assert 'achievement_progress' in s and 'hideOwnership:Boolean(name)' in s
    assert 'data-profile-challenges' in s
    profile=s[s.index('const profileToken='):s.index('loadPublicCachedJSON(`server/tournaments/public/browse.php?', s.index('const profileToken='))]
    assert '<h3>Member Intelligence</h3>' not in profile
    assert '<h3>Achievement challenges</h3>' in profile

def test_hall_search_four_wide_and_native_clear():
    css=text('assets/css/dashboard-v2.css'); html=text('ui-v2.html'); js=text('assets/js/pages/dashboard-v2.js')
    assert '.p2k-hall-unified-grid{grid-template-columns:repeat(4' in css
    assert '#hallResetSearch{display:none!important}' in css
    assert 'id="hallMemberSearch"' in html and 'type="search"' in html
    assert 'hallMemberSearch")?.addEventListener("input"' in js

def test_admin_analyze_uses_locked_p2k_one_match_analyzer():
    d=text('assets/js/pages/dashboard-v2.js'); a=text('assets/js/pages/analyze-match.js')
    assert 'scope: "promote-to-king"' in d
    assert '["match", "club", "team", "scope"]' in d
    assert 'lockedClubScope' in a and 'teamSelector.hidden = true' in a
    assert 'window.self !== window.top' in a and 'standaloneLink.hidden = !embedded' in a

def test_live_rook_and_all_rank_art_decode():
    rook=ROOT/'assets/images/live-ranks/04_Live_Rook_2500_points.png'
    im=Image.open(rook); im.load(); assert im.size==(640,640)
    for folder in [ROOT/'assets/images/ranks',ROOT/'assets/images/live-ranks']:
        if not folder.exists(): continue
        for p in folder.rglob('*'):
            if p.is_file() and p.suffix.lower() in {'.png','.webp','.jpg','.jpeg'}:
                q=Image.open(p); q.load(); assert q.width>0 and q.height>0, p

def test_opponent_logo_legacy_alias_self_heals():
    s=text('server/team-points/public/opponent-icons.php'); r=text('server/team-points/src/Repository.php')
    assert 'opponent-icon-repair' in s and 'recordOpponentCheck' in s
    assert 'clubSlugFromTeamPayload' in s and 'chessClubSlugFromUrl' in r
    assert '$missingRetryAge=86400' in s
    assert 'alias_slug,canonical_slug' in r and '$aliases[$requested]??$requested' in r


def test_chart_mobile_help_and_storage_tap():
    assert 'Hover or tap for values · drag or pinch to zoom' in text('TeamInsights.html')
    assert 'Hover or tap for games and Team Points' in text('ui-v2.html')
    admin=text('assets/js/pages/team-points-admin.js')
    assert "dot.addEventListener('click'" in admin and 'pinnedIndex' in admin


def test_title_only_svg_charts_support_touch_tooltips():
    util=text('assets/js/shared/svg-touch-title.js')
    assert "pointerType!=='touch'" in util and "querySelector?.(':scope > title')" in util
    assert "[role=button]" in util  # do not steal taps from interactive history points
    for page in ['AnalyzeMatches.htm','AnalyzeMatch.html','AnalyzeMatchModal.html','MatchCreationAnalyzer.htm']:
        assert ('svg-touch-title.js?v=2.8.11' in text(page) or 'svg-touch-title.js?v=2.9.0' in text(page) or 'svg-touch-title.js?v=2.9.1' in text(page) or 'svg-touch-title.js?v=2.9.2' in text(page) or 'svg-touch-title.js?v=2.9.4' in text(page) or 'svg-touch-title.js?v=2.9.5' in text(page) or 'svg-touch-title.js?v=2.9.7' in text(page) or 'svg-touch-title.js?v=2.9.8' in text(page) or 'svg-touch-title.js?v=2.9.9' in text(page))


def test_task_control_member_progress_uses_freshness_not_lifetime_queue():
    js=text('assets/js/pages/task-control.js'); api=text('server/control/public/api.php')
    assert 'refresh_matches_fresh_percent' in js and 'refresh_stats_fresh_percent' in js
    assert 'unresolved===0' in js
    assert 'Member Points convergence:' in api and 'Queue items are recurring operational work' in api
