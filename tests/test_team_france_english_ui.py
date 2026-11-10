from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]/'TeamFrance'
hub=(ROOT/'home.html').read_text()
assert 'lang="en"' in hub and 'Administration tools' in hub
assert 'Team France administrators only' in hub
for path in ('MatchAnalyzer/template.html','MatchRecruitment/template.html'):
    source=(ROOT/path).read_text()
    assert 'lang="en"' in source and 'localize.js' not in source
    assert '/TeamFrance/assets/theme.css' in source
    assert '/TeamFrance/assets/upcoming-selector.js' in source
assert 'p2kChartModalClose' in (ROOT/'MatchAnalyzer/template.html').read_text()
recruit=(ROOT/'MatchRecruitment/template.html').read_text()
assert 'p2kCancelButton' in recruit and 'tgpLogClear' in recruit
assert 'TEAM_FRANCE_RECRUITMENT_CSRF' in recruit
assert 'team-france-recruitment-csrf' in recruit
assert not (ROOT/'assets/localize.js').exists()
for path in ('Widget/index.php','Widget/admin/index.php'):
    src=(ROOT/path).read_text()
    assert 'lang="en"' in src and 'localize.js' not in src and 'widget-theme.css' in src
for path in ('assets/home.js','assets/upcoming-selector.js'):
    assert '/TeamFrance/api/overview.php' in (ROOT/path).read_text()
assert 'const CLUB="team-france"' in (ROOT/'MatchRecruitment/assets/js/recruitment.js').read_text()
print('PASS Team France English UI, auth-safe selectors, correct IDs and retained theme')
