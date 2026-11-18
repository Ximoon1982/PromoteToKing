"""Regression guard for 2.16.0 player achievement challenge recommendations."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text(encoding="utf-8")


def test_player_challenges_exclude_completed_thresholds_without_losing_catalogue_progress():
    php = read("server/team-points/src/ClubIntelligenceService.php")
    assert "$row['achievement_progress']=$this->achievementProgress($row,$league);" in php
    assert "array_filter($row['achievement_progress']" in php
    assert "(float)($p['remaining']??0)>0" in php
    assert "(float)($p['current']??0)<(float)$p['target']" in php
    assert "$row['challenges']=array_slice($pending,0,5)" in php
    assert "return array_slice(array_values(array_filter($this->achievementProgress(" in php


def test_public_profile_and_personal_dashboard_filter_cached_completed_challenges():
    for path in [
        "assets/js/pages/dashboard-v2.js",
        "assets/js/dashboard/personal-home.js",
    ]:
        js = read(path)
        assert "full.length?full:shortlist" in js
        assert "current<target" in js
        assert "Number(c?.remaining)>0" in js
        assert "Number(c?.progress_percent)<100" in js
        assert ".slice(0,5)" in js
    insights = read("assets/js/dashboard/insights-controller.js")
    assert 'challengeProgress(m).filter(c=>!achievementByKey.has(String(c?.key||"")))' in insights


def test_challenge_script_cache_busting_is_scoped():
    page = read("ui-v2.html")
    for path in [
        "assets/js/pages/dashboard-v2.js",
        "assets/js/dashboard/personal-home.js",
        "assets/js/dashboard/insights-controller.js",
    ]:
        assert path + "?v=p2k-2.16.0-pending-achievements-1" in page
