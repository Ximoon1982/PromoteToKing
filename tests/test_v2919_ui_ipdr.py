from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')

def test_members_insight_summary_trimmed():
    ui=text('ui-v2.html')
    start=ui.index('aria-label="Member summary"') if 'aria-label="Member summary"' in ui else ui.index('membersStatCurrent');summary=ui[start:ui.index('</section>',start)]
    for label in ('All recorded members','Former members','Team Points in period'): assert label not in summary

def test_most_earned_current_members():
    h=text('TournamentAchievementBadgesDemo.html')
    assert 'id="popular"' in h and 'Most earned' in h
    assert 'earned_current_member_count' in h and 'current player' in h

def test_profile_dynamic_achievement_label_and_spacing():
    js=text('assets/js/pages/dashboard-v2.js'); css=text('assets/css/dashboard-v2.css')
    assert 'View all achievements of ${escapeHTML(player.username||name)}' in js
    assert 'data-profile-achievement-actions' in js and 'margin-bottom:14px' in css

def test_dashboard_achievement_heading_and_view_link():
    js=text('assets/js/pages/dashboard-v2.js'); css=text('assets/css/dashboard-v2.css')
    assert 'dashboard-achievement-heading' in js and '>Achievements</strong>' in js and 'View →' in js
    assert 'font-size:10px;font-weight:900;text-transform:uppercase' in css

def test_history_requires_archived_snapshot():
    js=text('assets/js/shared/match-history-ui.js')
    assert 'historicalPointCount' in js and 'if (!historicalPointCount) return' in js

def test_live_rook_and_framed_art_contained():
    css=text('assets/css/dashboard-v2.css'); hall=text('TournamentAchievementBadgesDemo.html')
    assert 'object-fit:contain!important' in css and '.p2k-profile-rank-pair img' in css
    assert 'object-fit:contain' in hall

def test_achievement_detail_navigation_and_progress():
    js=text('assets/js/pages/dashboard-v2.js'); hall=text('TournamentAchievementBadgesDemo.html')
    for token in ('achievementDetailNav','data-achievement-prev','data-achievement-next','ArrowLeft','ArrowRight'): assert token in js
    assert 'options.progress' in js and '<progress' in js
    for token in ('standaloneAchievementNav','achPrev','achNext','ArrowLeft','ArrowRight'): assert token in hall

def test_admin_rows_align_on_desktop():
    css=text('assets/css/dashboard-v2.css')
    assert '@media(min-width:701px)' in css and 'height:72px;min-height:72px' in css

def test_ipdr_identity_generation_invalidates_analytics():
    php=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'identity_map_generation' in php and "'identity:'" in php
    assert 'p2k_miac_canonical_map' in php

def test_ipdr_canonical_player_and_daily_projections():
    php=text('server/team-points/src/AnalyticsBuilder.php')
    assert 'COALESCE(im.canonical_username_key,u.username_key)' in php
    assert 'COUNT(DISTINCT COALESCE(im.canonical_username_key,u.username_key)) unique_players' in php
    assert 'CASE WHEN u.current_member=1 THEN u.daily_rating' in php

def test_ipdr_profiles_span_alias_chain():
    php=text('server/team-points/src/Repository.php')
    assert 'aliasesFor($usernameKey)' in php and 'identity_aliases' in php
    assert 'participation.username_key IN (' in php

def test_ipdr_definitive_history_rules_and_vetoes():
    live=text('server/team-points/src/LiveRanksService.php'); repo=text('server/team-points/src/Repository.php'); miac=text('server/team-points/src/MiacService.php')
    assert 'recordHistoricalArenaSubstitution' in live and 'unchanged_participants' in live and 'stable_fingerprint' in live
    assert "'daily_board_substitution'" in repo and 'one_to_one_match_ownership' in repo
    assert "existingStatus==='rejected'?'rejected'" in miac and 'edgePlayerIdConflict' in miac
