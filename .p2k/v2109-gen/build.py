#!/usr/bin/env python3
from pathlib import Path
import base64, hashlib, json, lzma, os, shutil, subprocess, sys, tempfile

ROOT = Path.cwd()
BASE = '1f2eccab9dd83a019947bbafe9be41bb26b9465a'
DATA = ROOT / '.p2k' / 'v2109-gen'

def run(*args, input_bytes=None):
    return subprocess.run(args, input=input_bytes, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout

def git_blob(path: Path) -> str:
    return run('git','hash-object',str(path)).decode().strip()

def assert_blob(rel, expected):
    got=git_blob(ROOT/rel)
    if got != expected:
        raise SystemExit(f'Git blob mismatch {rel}: {got} != {expected}')
    print(f'OK blob {rel} {got}')

def sha256(path: Path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

# 1. Apply the two tiny source diffs and exact changelog prefix.
subprocess.run(['git','apply','--check',str(DATA/'core.patch')], check=True)
subprocess.run(['git','apply',str(DATA/'core.patch')], check=True)
prefix=(DATA/'changelog-prefix.txt').read_bytes()
cl=ROOT/'CHANGELOG.md'
cl.write_bytes(prefix + cl.read_bytes())

assert_blob('CHANGELOG.md','1fc92adf9b6e41abf14a94c1a68a8ab03f8fc9d3')
assert_blob('assets/js/pages/dashboard-v2.js','e0aa848d2a31e0e736427f70bbc02391493ec6e9')
assert_blob('server/team-points/src/Repository.php','f2ebef46f295b1e410e9e8e5b08c38a56e2fcc34')

# 2. Recreate the exact v2.10.8 source manifest from the immutable v2.10.8 base commit.
paths=lzma.decompress(base64.b64decode((DATA/'v2108-paths.xz.b64').read_bytes())).decode().splitlines()
rows=[]
for i, rel in enumerate(paths,1):
    data=run('git','show',f'{BASE}:{rel}')
    rows.append({'path':rel,'size':len(data),'sha256':hashlib.sha256(data).hexdigest()})
    if i % 250 == 0:
        print(f'manifest base scan {i}/{len(paths)}')
manifest={
    'schema':1,
    'version':'2.10.8',
    'baseline':'2.10.7',
    'generated_at_utc':'2026-08-28T10:04:36Z',
    'files':rows,
}
manifest_path=ROOT/'SOURCE_MANIFEST_v2.10.8.json'
manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
if sha256(manifest_path)!='8a8ff3a2ce09cb9456c645c73c75420cf25821eeb9c8ab412abed920fd0b1c4a':
    raise SystemExit('SOURCE_MANIFEST_v2.10.8.json SHA-256 mismatch')
assert_blob('SOURCE_MANIFEST_v2.10.8.json','dea2f2c1920533198f3ac720f5aafcc63ba55928')

# 3. Generate exact v2.10.9 production payload manifest from the now-complete source tree.
payload_paths=[
'AnalyzeMatch.html','AnalyzeMatchModal.html','AnalyzeMatches.htm','CHANGELOG.md','ChallengeListAssistant.html','ClubIntelligence.html','DataReconciliation.html','FindMatch.htm','InsightsHealth.html','LeagueSeasonCenter.html','LiveRanks.html','MIGRATION_VERSION','RELEASE_NOTES.md','RELEASE_NOTES_v2.10.9.md','RELEASE_v2.10.9.json','RecruitMatch.html','RecruitmentDemandPlanner.html','TaskControl.html','TaskLogs.html','TeamPointsAdmin.html','TeamPointsMigration.html','TournamentAchievementBadgesDemo.html','TournamentManagement.html','VERSION','assets/js/pages/dashboard-v2.js','assets/js/pages/team-points-features.js','assets/js/site-config.js','cron-mca-arena-v2.10.9.sh','index.html','MatchCreationAnalyzer.htm','reset-install-mca-cron-v2.10.9.sh','server/team-points/bin/mca-results-sync.php','server/team-points/bin/upgrade-schema-v2.10.9.php','server/team-points/sql/analytics-migration-v2.10.9.sql','server/team-points/sql/analytics-schema.sql','server/team-points/src/McaArenaParser.php','server/team-points/src/McaResultsCronService.php','server/team-points/src/Repository.php','site-manifest.json','ui-v2.html','INSTALL_v2.10.9.md']
payload_rows=[]
for rel in payload_paths:
    d=(ROOT/rel).read_bytes()
    payload_rows.append({'path':rel,'size':len(d),'sha256':hashlib.sha256(d).hexdigest()})
payload_manifest={'version':'2.10.9','predecessor':'2.10.8','files':payload_rows}
with tempfile.TemporaryDirectory() as td:
    td=Path(td)
    pm=td/'.p2k-v2109-payload-manifest.json'
    pm.write_text(json.dumps(payload_manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    if hashlib.sha256(pm.read_bytes()).hexdigest()!='0d734e566a6b2c91e9d7d4f13061e171dc5464341eef86ba0ce25591f459ed83':
        raise SystemExit('payload manifest mismatch')

    # 4. Recreate the exact GNU tar byte stream from the retained header/layout recipe.
    recipe=json.loads(lzma.decompress(base64.b64decode((DATA/'tar-recipe.xz.b64').read_bytes())))
    raw=bytearray()
    for ent in recipe['entries']:
        raw += base64.b64decode(ent['header'])
        if ent['type'] in ('0','\x00'):
            name=ent['name'][2:] if ent['name'].startswith('./') else ent['name']
            d=pm.read_bytes() if name=='.p2k-v2109-payload-manifest.json' else (ROOT/name).read_bytes()
            if len(d)!=ent['size']:
                raise SystemExit(f'tar payload size mismatch {name}: {len(d)} != {ent["size"]}')
            raw += d
            raw += b'\0' * ((-len(d)) % 512)
    if len(raw)>recipe['raw_len']:
        raise SystemExit('tar stream overflow')
    raw += b'\0' * (recipe['raw_len']-len(raw))
    if hashlib.sha256(raw).hexdigest()!='10124ca71a4190aefd6762602ec2966d243b8a9558593c498cba6d782ff0e875':
        raise SystemExit('raw tar mismatch')
    raw_tar=td/'payload.tar'; raw_tar.write_bytes(raw)
    gz=run('gzip','-n','-6','-c',str(raw_tar))
    if hashlib.sha256(gz).hexdigest()!='d073329bbf23741f30185673134945d6be36c7088c533c237f973bcfa6b9d651':
        raise SystemExit('gzip payload mismatch (runner gzip implementation differs)')
    header=base64.b64decode((DATA/'installer-header.b64').read_bytes())
    installer=ROOT/'PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run'
    installer.write_bytes(header+gz)
    os.chmod(installer,0o755)

if sha256(ROOT/'PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run')!='130178225a80a3baf72f3d3444a19b1ab50bb18d338f2fa9a661ec2b7f706d60':
    raise SystemExit('installer SHA-256 mismatch')
assert_blob('PromoteToKing_v2.10.9_INCREMENTAL_INSTALLER.run','0a1e583ffc2792c112471d9bd69253720ad80b8a')
print('ALL FIVE FINAL FILES EXACT')
