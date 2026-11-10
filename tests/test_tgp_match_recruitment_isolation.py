from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]/"TGP"/"MatchRecruitment"
source=(ROOT/"assets/js/recruitment.js").read_text()
api=(ROOT/"api/index.php").read_text()
index=(ROOT/"index.php").read_text()
auth=(ROOT/"auth.php").read_text()
assert "function tgp_authorize(): array" in auth
assert "require_once __DIR__.'/auth.php'" in index
assert "tgp_authorize();" in api
assert "the-golden-phoenix" in source and "the-golden-phoenix" in api
assert "memberClubs.has(CLUB)" in source
assert "memberClubs.has(clubSlug(state.opp))" in source
assert "base+\"/clubs\"" in source
assert "C.csv(eligible,result.match)" in source
assert "recruitment-history" not in source
assert "recruitment-pool.php" not in source
assert "PublicReadDatabase" not in (index+api+source)
assert "configure the club membership list as Public" in api
assert "'source'=>'fallback'" in api and "'snapshotAt'=>$cached['fetchedAt']" in api
assert "recruitment_valid_snapshot" in api and "recruitment_save_snapshot" in api
assert (ROOT/"data/.htaccess").read_text().strip()=="Require all denied"
print("TGP standalone recruitment isolation checks passed")
