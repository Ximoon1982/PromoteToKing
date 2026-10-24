from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
p=(ROOT/"server/team-points/public/hall-search.php").read_text(encoding="utf-8")

assert "Hall of Fame search is a current-member lookup" in p
assert "if(!is_array($daily))" in p
assert "'found'=>false" in p
assert "'live'=>null" in p
assert "'podiums'=>0" in p
assert "$canonical=(string)($daily['username']??$username)" in p
assert "publicPlayerPayload($canonical)" in p
assert "publicPlayerProfile($club,$canonical,'search')" in p
print("PASS Hall of Fame unified search is current-member-only")
