from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
p=(ROOT/"server/team-points-green/src/GreenCompatibility.php").read_text(encoding="utf-8")

assert "partial_green_projection" in p
assert "authoritativeEventCount" in p
assert "projectableEventCount" in p
assert "if($projectableEventCount<$authoritativeEventCount)" in p
assert p.index("if($projectableEventCount<$authoritativeEventCount)") < p.index("DELETE b FROM p2k_tp_boards")
print("PASS partial Green projection cannot delete existing compatibility points")
