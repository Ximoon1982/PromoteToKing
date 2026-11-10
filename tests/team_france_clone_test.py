from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
tf=root/'TeamFrance'
tgp=root/'TGP'
assert not (tf/'Widget/data/showcase.json').exists()
assert (tf/'Widget/data/showcase.example.json').exists()
assert (tf/'Widget/data/.htaccess').read_bytes()==(tgp/'Widget/data/.htaccess').read_bytes()
assert not any(p.name=='tgp-tracking.js' for p in tf.rglob('*'))
for p in tf.rglob('*'):
 if not p.is_file():continue
 if p.suffix not in ('.js','.php','.html','.txt','.json','.css','.md'):continue
 s=p.read_text()
 assert 'the-golden-phoenix' not in s or p.name in ['README.md'], p
 assert '/TGP/' not in s, p
assert 'team-france' in (tf/'Widget/config.php').read_text()
assert 'TFWIDGETSESSID' in (tf/'Widget/src/Auth.php').read_text()
assert 'HTTP_X_TF_CSRF' in (tf/'Widget/src/Auth.php').read_text()
assert 'TEAM_FRANCE_WIDGET_CONFIG' in (tf/'Widget/admin/index.php').read_text()
assert 'X-TF-CSRF' in (tf/'Widget/assets/admin.js').read_text()
assert 'team_france_authorize' in (tf/'MatchAnalyzer/auth.php').read_text()
assert 'https://api.chess.com/pub/club/team-france' in (tf/'MatchAnalyzer/auth.php').read_text()
js=(tf/'MatchAnalyzer/assets/analyze-match.js').read_text()
assert 'window.P2K_TEAM_FRANCE_ANALYZER' in js
assert 'teamKeyForClub(rawMatch, TEAM_FRANCE?.clubSlug' in js
assert re.search(r'club\\/team-france',js), 'Club-locking regex'
assert 'team-france-track-match' in (tf/'MatchAnalyzer/api/index.php').read_text()
assert 'TeamFranceMatchPolicy::containsClub' in (tf/'MatchAnalyzer/api/index.php').read_text()
assert 'team-france-oauth-csrf' in (tf/'MatchAnalyzer/index.php').read_text()
assert 'team-france-oauth-csrf' in (tf/'MatchAnalyzer/assets/team-france-tracking.js').read_text()
assert '/api/match-history/' in (tf/'MatchAnalyzer/assets/match-history-ui.js').read_text()
assert 'team-france-match-analyzer-diagnostics' in (tf/'MatchAnalyzer/api/index.php').read_text()
assert '/TeamFrance/MatchAnalyzer/assets/analyze-match.js' in (tf/'MatchAnalyzer/template.html').read_text()
assert '/TeamFrance/MatchAnalyzer/' in (tf/'MatchAnalyzer/index.php').read_text()
print('Team France static isolation and parity checks: PASS')
