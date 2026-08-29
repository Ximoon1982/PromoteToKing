#!/usr/bin/env python3
from pathlib import Path
import base64, hashlib, json, lzma, os, subprocess, tempfile
ROOT=Path.cwd(); BASE='1f2eccab9dd83a019947bbafe9be41bb26b9465a'; DATA=ROOT/'.p2k/v2109-gen'
def run(*a): return subprocess.run(a,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True).stdout
def blob(p): return run('git','hash-object',str(ROOT/p)).decode().strip()
def check(p,h):
 g=blob(p); print('OK blob',p,g)
 if g!=h: raise SystemExit(f'blob mismatch {p}: {g} != {h}')
def sha(p): return hashlib.sha256((ROOT/p).read_bytes()).hexdigest()
# restore immutable v2.10.8 inputs so reruns are deterministic
for p in ['CHANGELOG.md','assets/js/pages/dashboard-v2.js','server/team-points/src/Repository.php']:
 (ROOT/p).write_bytes(run('git','show',f'{BASE}:{p}'))
# exact three text-source changes
subprocess.run(['git','apply','--check',str(DATA/'core.patch')],check=True)
subprocess.run(['git','apply',str(DATA/'core.patch')],check=True)
(ROOT/'CHANGELOG.md').write_bytes((DATA/'changelog-prefix.txt').read_bytes()+(ROOT/'CHANGELOG.md').read_bytes())
check('CHANGELOG.md','1fc92adf9b6e41abf14a94c1a68a8ab03f8fc9d3')
check('assets/js/pages/dashboard-v2.js','e0aa848d2a31e0e736427f70bbc02391493ec6e9')
check('server/team-points/src/Repository.php','f2ebef46f295b1e410e9e8e5b08c38a56e2fcc34')
# exact v2.10.8 source manifest; installer was a local release artifact, not committed on main
pb=b''.join((DATA/f'v2108-paths.part-{i:02d}').read_bytes() for i in range(5))
paths=lzma.decompress(base64.b64decode(pb)).decode().splitlines()
overrides={'PromoteToKing_v2.10.8_INCREMENTAL_INSTALLER.run':(230631,'e65667bbdc1db9b6020e2c5779bae098ce1c4af10556eab4e4683883803a786f')}
rows=[]
for i,p in enumerate(paths,1):
 if p in overrides: size,digest=overrides[p]
 else:
  try: d=run('git','show',f'{BASE}:{p}')
  except subprocess.CalledProcessError: raise SystemExit(f'missing base path without override: {p}')
  size=len(d); digest=hashlib.sha256(d).hexdigest()
 rows.append({'path':p,'size':size,'sha256':digest})
 if i%250==0: print('manifest base scan',i,'/',len(paths))
m={'schema':1,'version':'2.10.8','baseline':'2.10.7','generated_at_utc':'2026-08-28T10:04:36Z','files':rows}
mp=ROOT/'SOURCE_MANIFEST_v2.10.8.json'; mp.write_text(json.dumps(m,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
if hashlib.sha256(mp.read_bytes()).hexdigest()!='8a8ff3a2ce09cb9456c645c73c75420cf25821eeb9c8ab412abed920fd0b1c4a': raise SystemExit('source manifest sha mismatch')
check('SOURCE_MANIFEST_v2.10.8.json','dea2f2c1920533198f3ac720f5aafcc63ba55928')
# exact v2.10.9 installer payload
payload=['AnalyzeMatch.html','AnalyzeMatchModal.html','AnalyzeMatches.htm','CHANGELOG.md','ChallengeListAssistant.html','ClubIntelligence.html','DataReconciliation.html','FindMatch.htm','InsightsHealth.html','LeagueSeasonCenter.html','LiveRanks.html','MIGRATION_VERSION','RELEASE_NOTES.md','RELEASE_NOTES_v2.10.9.md','RELEASE_v2.10.9.json','RecruitMatch.html','RecruitmentDemandPlanner.html','TaskControl.html','TaskLogs.html','TeamPointsAdmin.html','TeamPointsMigration.html','TournamentAchievementBadgesDemo.html','TournamentManagement.html','VERSION','assets/js/pages/dashboard-v2.js','assets/js/pages/team-points-features.js','assets/js/site-config.js','cron-mca-arena-v2.10.9.sh','index.html','MatchCreationAnalyzer.htm','reset-install-mca-cron-v2.10.9.sh','server/team-points/bin/mca-results-sync.php','server/team-points/bin/upgrade-schema-v2.10.9.php','server/team-points/sql/analytics-migration-v2.10.9.sql','server/team-points/sql/analytics-schema.sql','server/team-points/src/McaArenaParser.php','server/team-points/src/McaResultsCronService.php','server/team-points/src/Repository.php','site-manifest.json','ui-v2.html','INSTALL_v2.10.9.md']
pr=[]
for p in payload:
 d=(ROOT/p).read_bytes(); pr.append({'path':p,'size':len(d),'sha256':hashlib.sha256(d).hexdigest()})
pmobj={'version':'2.10.9','predecessor':'2.10.8','files':pr}
with tempfile.TemporaryDirectory() as t:
 t=Path(t); pm=t/'.p2k-v2109-payload-manifest.json'; pm.write_text(json.dumps(pmobj,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
 if hashlib.sha256(pm.read_bytes()).hexdigest()!='0d734e566a6b2c91e9d7d4f13061e171dc5464341eef86ba0ce25591f459ed83': raise SystemExit('payload manifest mismatch')
 rec=json.loads(lzma.decompress(base64.b64decode((DATA/'tar-recipe.xz.b64').read_bytes())))
 raw=bytearray()
 for e in rec['entries']:
  raw+=base64.b64decode(e['header'])
  if e['type'] in ('0','\x00'):
   n=e['name'][2:] if e['name'].startswith('./') else e['name']; d=pm.read_bytes() if n=='.p2k-v2109-payload-manifest.json' else (ROOT/n).read_bytes()
   if len(d)!=e['size']: raise SystemExit(f'tar size mismatch {n}')
   raw+=d; raw+=b'\0'*((-len(d))%512)
 raw+=b'\0'*(rec['raw_len']-len(raw))
 if hashlib.sha256(raw).hexdigest()!='10124ca71a4190aefd6762602ec2966d243b8a9558593c498cba6d782ff0e875': raise SystemExit('raw tar mismatch')
 rt=t/'payload.tar'; rt.write_bytes(raw); gz=run('gzip','-n','-6','-c',str(rt))
 if hashlib.sha256(gz).hexdigest()!='d073329bbf23741f30185673134945d6be36c7088c533c237f973bcfa6b9d651': raise SystemExit('gzip mismatch')
 hb=b''.join((DATA/f'installer-header.part-{i:02d}').read_bytes() for i in range(5)); inst=ROOT/'PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run'; inst.write_bytes(base64.b64decode(hb)+gz); os.chmod(inst,0o755)
if sha('PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run')!='130178225a80a3baf72f3d3444a19b1ab50bb18d338f2fa9a661ec2b7f706d60': raise SystemExit('installer sha mismatch')
check('PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run','0a1e583ffc2792c112471d9bd69253720ad80b8a')
print('ALL FIVE FINAL FILES EXACT')
