from pathlib import Path

AUDIT = Path("server/team-points/bin/post-cutover-audit.php")

def test_post_cutover_audit_uses_closure_variables_for_row_helpers():
    text = AUDIT.read_text(encoding="utf-8")
    assert "$row=static function" in text
    forbidden = [
        "$greenPoint=row(",
        "$analyticsPoint=row(",
        "$insight=row(",
        "$opponents=row(",
        "$greenState=row(",
    ]
    for token in forbidden:
        assert token not in text, f"bare helper call remains: {token}"
    required = [
        "$greenPoint=$row(",
        "$analyticsPoint=$row(",
        "$insight=$row(",
        "$opponents=$row(",
        "$greenState=$row(",
    ]
    for token in required:
        assert token in text, f"closure helper call missing: {token}"


def test_player_points_allows_recent_analytics_lag_but_reports_exactness():
    text = AUDIT.read_text(encoding="utf-8")
    assert "'key'=>'player_points'" in text
    assert "'exact'=>abs((float)($greenPoint['points']??0)-(float)($analyticsPoint['points']??0))<0.001" in text
    assert "$allFresh&&trim((string)($all['last_error']??''))===''" in text
    assert "'freshness_limit_seconds'=>900" in text
    assert "'delta'=>['points'=>" in text
