from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
js = (ROOT / "assets/js/pages/recruit-match.js").read_text(encoding="utf-8")
html = (ROOT / "RecruitMatch.html").read_text(encoding="utf-8")
api = (ROOT / "server/team-points/public/recruitment-history.php").read_text(encoding="utf-8")
store = (ROOT / "server/team-points/src/RecruitmentHistoryStore.php").read_text(encoding="utf-8")

assert 'state.resultCsv=csvText' in js
assert 'new Blob([csvText]' in js
assert 'archiveRecruitment(match,state.resultCsv)' in js
assert 'action=list&page=' in js
assert 'action=download&id=' in js
assert 'action=delete' in js
assert 'p2kRecruitmentHistoryBody' in html
assert 'Finished recruitments' in html
assert 'DEFAULT_PAGE_SIZE = 10' in store
assert "RecruitmentHistoryStore::fromConfig" in api
assert "$store->archive(" in api
assert "$store->delete(" in api
print("PASS v2.15.2 match recruitment history integration")
