from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8", errors="ignore")

def test_no_synthetic_tournament_award_date_fallback():
    builder=text("server/team-points/src/AnalyticsBuilder.php")
    assert "tournament-pending" in builder
    assert "logic:tournament-achievement-date-v3" in builder
    assert "$at=$finishTs!==false?gmdate('Y-m-d H:i:s',$finishTs):null" in builder
    assert "$period.'-01 00:00:00'" not in builder
    assert "$precision=$finishTs!==false?'tournament-finish':'tournament-period'" not in builder

def test_pending_tournament_date_is_not_replaced_by_first_recorded():
    repo=text("server/team-points/src/Repository.php")
    assert "!in_array($precision,['tournament-pending','threshold-reconciled'],true)" in repo
    dash=text("assets/js/pages/dashboard-v2.js")
    assert 'Date pending tournament refresh' in dash
    assert 'date pending tournament refresh' in dash

def test_standalone_tournament_history_does_not_use_period_or_start_as_award_date():
    page=text("TournamentAchievementBadgesDemo.html")
    assert "date:t.finishAt||t.end_time||''" in page
    assert "t.end_time||t.period||t.startDate" not in page
    assert "Date pending tournament refresh" in page

def test_real_finish_date_upgrades_pending_tournament_unlock():
    builder=text("server/team-points/src/AnalyticsBuilder.php")
    assert "WHEN VALUES(earned_at_precision)='tournament-finish' THEN VALUES(earned_at)" in builder
    assert "WHEN VALUES(earned_at_precision)='tournament-pending' AND earned_at_precision<>'tournament-finish' THEN NULL" in builder
    service=text("server/tournaments/src/TournamentService.php")
    assert "empty($row['finishAt'])" in service
    assert "$root['finish_time']" in service


def test_logic_change_bypasses_fresh_filesystem_throttle_and_clears_legacy_rows():
    builder=text("server/team-points/src/AnalyticsBuilder.php")
    assert "storedWatermark === $currentWatermark" in builder
    assert "domain_key='achievements'" in builder
    assert "earned_at_precision='tournament-period'" in builder
    assert "SET earned_at=NULL, earned_at_precision='tournament-pending'" in builder
    # The old unconditional early return before watermark comparison must be gone.
    prefix=builder.split("public function refreshAchievementsIfDue",1)[1].split("public function refreshIfNeeded",1)[0]
    first_return=prefix.find("time() - $last < $minimumSeconds")
    watermark_check=prefix.find("storedWatermark === $currentWatermark")
    assert watermark_check != -1 and watermark_check < first_return

def test_dashboard_tournament_event_dates_do_not_fall_back_to_start_date():
    dash=text("assets/js/pages/dashboard-v2.js")
    section=dash.split("function tournamentAchievements",1)[1].split("function profileMetric",1)[0]
    assert "startDate" not in section
    assert "start_time" not in section
    assert 'date: tournament?.finishAt || tournament?.end_time || ""' in section
