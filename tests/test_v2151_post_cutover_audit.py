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
