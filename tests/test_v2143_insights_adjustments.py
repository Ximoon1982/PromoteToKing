from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

def test_daily_rank_distribution_is_current_members_only():
    repo = read("server/team-points/src/Repository.php")
    assert "SELECT points FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1" in repo
    assert "foreach($all as $rr){if(empty($rr['current_member']))continue;" in repo
    assert "hallRankForPoints((float)($rr['points']??0))" in repo

def test_average_boards_uses_independent_start_and_finish_months():
    repo = read("server/team-points/src/Repository.php")
    js = read("assets/js/pages/dashboard-insights.js")
    ui = read("ui-v2.html")
    assert "DATE_FORMAT(start_time,'%Y-%m') month,ROUND(AVG(board_count),1) average_boards_started" in repo
    assert "DATE_FORMAT(end_time,'%Y-%m') month,ROUND(AVG(board_count),1) average_boards" in repo
    assert "status='finished' AND is_void=0 AND end_time IS NOT NULL AND board_count>0" in repo
    assert 'label:"Ongoing month →"' in js
    assert 'futureBoundary:elapsedBoundary' in js
    assert 'String(row.month)===currentMonth?"Ongoing month · values are incomplete":""' in js
    assert "Started in month" in ui and "Finished in month" in ui
    assert "values to the right belong to the ongoing month" in ui

def test_arena_leaders_recompute_with_member_and_period_filters():
    endpoint = read("server/team-points/public/arenas-insights.php")
    service = read("server/team-points/src/LiveRanksService.php")
    js = read("assets/js/pages/dashboard-insights.js")
    ui = read("ui-v2.html")
    export = read("server/team-points/public/arenas-insights-export.php")

    for key in ("filter", "activity_status", "start", "end"):
        assert f"'{key}' =>" in endpoint

    assert "arenaInsightsLeaders($arenas, $resultsByFile, $leaderOptions)" in service
    assert "publicMemberInsights($this->clubSlug,$memberOptions)" in service
    assert "'filter'=>(string)($options['filter']??'current')" in service
    assert "'activity_status'=>(string)($options['activity_status']??'')" in service
    assert "if($start!==''&&$date<$start)return false;" in service
    assert "if($end!==''&&$date>$end)return false;" in service
    assert "$row['arenas']++" in service
    assert "$row['points']=round((float)$row['points']+(float)($player['points']??0),2)" in service

    assert 'id="arenasLeadersFilter"' in ui
    assert 'id="arenasLeadersActivityStatusFilter"' in ui
    assert 'id="arenasLeadersPeriodStart"' in ui
    assert 'id="arenasLeadersPeriodEnd"' in ui
    assert 'id="arenasLeadersExportCsv"' in ui
    assert "arenaLeadersRequestURL" in js
    assert "reloadArenaLeaders" in js
    assert "arenas-insights-export.php" in js
    assert "P2K_Arena_Leaders_" in export
    assert "Activity status" in export
