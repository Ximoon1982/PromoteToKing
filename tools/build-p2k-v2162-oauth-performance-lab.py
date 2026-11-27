#!/usr/bin/env python3
"""Release Control overlay against the last confirmed promoted core 2.16.0."""
from pathlib import Path
from hashlib import sha256
import json,re,subprocess,zipfile
ROOT=Path(__file__).resolve().parents[1]
HEAD=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
BASE='02e4247723bf5ab73464854aaffb08b6d8369d68'
VERSION='2.16.2'
assert subprocess.check_output(['git','merge-base',BASE,HEAD],cwd=ROOT,text=True).strip()==BASE
previous=ROOT/'release-packages/v2.16.0/P2K_2.16.0_GAB_Verified_Alias_Games_RC.zip'
out=ROOT/'dist'
out.mkdir(exist_ok=True)
directory=out/'P2K_2.16.2_OAuth_Performance_Lab_RC'
payload=directory/'payload'
payload.mkdir(parents=True,exist_ok=True)
sha=lambda data:sha256(data).hexdigest()
# Reuse precisely the required recovery files from the promoted package, not newer recovery code.
with zipfile.ZipFile(previous) as z:
    assert z.testzip() is None
    metadata_path=next(n for n in z.namelist() if n.endswith('CANDIDATE_RELEASE.json'))
    prefix=metadata_path[:-len('CANDIDATE_RELEASE.json')]
    metadata=json.loads(z.read(metadata_path))
    assert metadata['version']=='2.16.0' and metadata['source_head']==BASE,metadata
    recovery=z.read(prefix+'RECOVERY_PLANE.sha256')
    for line in recovery.decode().splitlines():
        if not line.strip():continue
        digest,path=line.split(None,1)
        path=path.strip().removeprefix('./')
        content=z.read(prefix+'payload/'+path)
        assert sha(content)==digest,path
        assert content==(ROOT/path).read_bytes(), 'Recovery-plane source differs from promoted package: '+path
        p=payload/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
    (directory/'RECOVERY_PLANE.sha256').write_bytes(recovery)
    previous_ui=z.read(prefix+'payload/ui-v2.html').decode()
# Only the three targeted application files plus required identity files.
paths=['AnalyzeMatchModal.html','AnalyzeMatches.htm','FindMatch.htm','assets/js/shared/upcoming-analysis-core.js','assets/js/pages/find-match.js','assets/js/pages/analyze-match-modal.js','server/team-points/public/detailed-match-analysis.php','assets/js/pages/v2-11-0.js','assets/js/site-config.js','server/team-points/public/recruitment-admin.php','server/team-points/src/RecruitmentAnalysisStore.php','OAuthPerformanceLab.html','assets/js/pages/oauth-performance-lab.js','server/team-points/src/OAuthSession.php','server/team-points/src/OAuthRateCoordinator.php','server/shared/FilesystemCache.php','server/team-points/src/Housekeeping.php','assets/js/shared/api-oauth-context.js','server/team-points/public/oauth.php','assets/js/shared/green-accelerator.js','assets/js/pages/recruit-match.js','assets/js/pages/recruit-match-v2121-bootstrap.js','VERSION','ui-v2.html']
# Immutable browser assets must be refreshed together. These extra documents
# were unchanged from the promoted source; only their API module URL is changed.
extra_docs=['AnalyzeMatch.html','ChallengeListAssistant.html','DataReconciliation.html','MatchCreationAnalyzer.htm','MaxRatingBackfill.php','RecruitMatch.html','RecruitmentAdmin.html','RecruitmentDemandPlanner.html','TaskControl.html','TeamPointsAdmin.html','TeamPointsMigration.html','TournamentManagement.html','index.html','TGP/MatchAnalyzer/template.html']
shared_modules=['api-request-semantics','api-oauth-context','api-transport','api-request-coordinator','api-client']
paths[-2:-2]=['assets/js/shared/'+m+'.js' for m in shared_modules if 'assets/js/shared/'+m+'.js' not in paths]+extra_docs
asset_pattern=re.compile(rb'(assets/js/shared/api-(?:request-semantics|oauth-context|transport|request-coordinator|client)\.js)(?:\?v=[^\"\'\s<>]*)?')
asset_suffix=b'?v=2.16.2-oauth-inc3-'+HEAD[:12].encode()
other_asset_pattern=re.compile(rb'(assets/js/(?:site-config|shared/green-accelerator|pages/recruit-match-v2121-bootstrap)\.js)(?:\?v=[^\"\'\s<>]*)?')
def stamp_api_assets(content):
    found={m.group(1).decode().split('/')[-1][:-3] for m in asset_pattern.finditer(content)}
    assert found==set(shared_modules),found
    content=asset_pattern.sub(lambda m:m.group(1)+asset_suffix,content)
    return other_asset_pattern.sub(lambda m:m.group(1)+asset_suffix,content)
for path in extra_docs:
    assert (ROOT/path).read_bytes()==subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT), 'Unexpected unrelated document change: '+path
for path in paths[:-2]:
    content=(ROOT/path).read_bytes()
    if path in extra_docs or path in ['AnalyzeMatches.htm','FindMatch.htm','server/team-points/public/detailed-match-analysis.php']:
        content=stamp_api_assets(content)
    p=payload/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
(payload/'VERSION').write_text(VERSION+'\n')
key='p2k-'+VERSION+'-'+HEAD[:12]+'-'+sha((HEAD+'|oauth-performance-lab').encode())[:16]
# Preserve the previously promoted UI exactly except its authoritative cache identity.
previous_key=metadata['cache_key']
assert previous_key in previous_ui
ui=previous_ui.replace(previous_key,key)
assert 'assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1' in ui
ui=ui.replace('assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1','assets/js/site-config.js?v=p2k-2.16.2-recruitment-2')
assert key in ui
(payload/'ui-v2.html').write_bytes(stamp_api_assets(ui.encode()))
manifest=''.join(sha((payload/path).read_bytes())+'  ./'+path+'\n' for path in sorted(paths))
(directory/'CANDIDATE_PAYLOAD.sha256').write_text(manifest)
meta={'schema_version':1,'version':VERSION,'source_head':HEAD,'cache_key':key,'build_id':'p2k-v2162-oauth-performance-lab-'+HEAD[:12],'qualification_workflow':'p2k-v2162-oauth-performance-lab.yml','payload_manifest_sha256':sha(manifest.encode()),'accepted_public_builds':[{'version':'2.16.0','source_head':BASE},{'version':'2.16.2','source_head':'279ad6f17718365bdda31c6a9c0b52d45a5760e6'},{'version':'2.16.2','source_head':'5b66f1aa3ac86e6e57c6cc350bd676ece8bca37b'},{'version':'2.16.2','source_head':'378a65361975d3aeefd0fae92a145bb8e79583e0'},{'version':'2.16.2','source_head':'00593141e69ea75f4403dd763e0bc22f9b0cd6e9'},{'version':'2.16.2','source_head':'16b5fbd6caf100ce21a3a85539d852f271df3928'},{'version':'2.16.2','source_head':'a06cb3a93eca7e7515b6b50f88c0e875ca6ddae4'},{'version':'2.16.2','source_head':'e130743a6a0dddc153451afbc015b6bc98d514a2'},{'version':'2.16.2','source_head':'48cd56498ecf6fd0d750d489f2ad9b6c3914d08b'},{'version':'2.16.2','source_head':'8a15397222e330bdf36fb5b2f963890b46f09ea5'},{'version':'2.16.2','source_head':'ae0b80f47bbebcd4224fe74fcb70b60f344d10d8'},{'version':'2.16.2','source_head':'24518f7f261d17236d82ffa8a74d8f26a8a1ad9a'},{'version':'2.16.2','source_head':'a26f29437909856bb0758dc273ef5ff75a2bd248'},{'version':'2.16.2','source_head':'0887600e5bd298873f10849210bcc3ffc75de33b'},{'version':'2.16.2','source_head':'72ef75c5fceaf5b4c2a7c65cbc6b6c0e313cffaf'},{'version':'2.16.2','source_head':'40a6aa7494f1bc9fc8d9b8bce7826b4c957f894f'}]}
(directory/'CANDIDATE_RELEASE.json').write_text(json.dumps(meta,indent=2)+'\n')
(directory/'README.txt').write_text('P2K 2.16.2 - OAuth Performance Laboratory\nOpen /OAuthPerformanceLab.html after promotion. Revision 9 / modernization increment 1: authoritative per-token initial rate 24/s, maximum 30/s, 10% margin below active unsafe evidence; persisted state and deadlines retained. Both benchmark modes remain available under the production cap. Shared browser telemetry aligned. Modernization increment 2: typed deferred rows, deadline-aware retries and queue admission, status-zero classification, pinned authenticated route and versioned shared browser assets. Modernization increment 3: protected detail iframe initializes verified OAuth, deferred recruitment work remains pending, Green reports pending separately, and the standalone recruitment apps use the selected public OAuth runtime. After promoting this exact candidate, apply the companion guarded standalone ZIP for Team France and TGP physical-root files. Feeder redesign remains increment 4. Shared rate limit and cooldown protections retained. Pause other acquisition, paste representative usernames, and keep the tab visible. Up to five minutes, JSON/CSV export. Preview blocks real OAuth POSTs.\nUpload in Release Control, preview, then explicitly promote. Accepted builds: 2.16.0-02e4247723bf, 2.16.2-279ad6f17718, 2.16.2-5b66f1aa3ac8, 2.16.2-378a65361975, 2.16.2-00593141e69e, 2.16.2-16b5fbd6caf1, 2.16.2-a06cb3a93eca, 2.16.2-e130743a6a0d, 2.16.2-48cd56498ecf, 2.16.2-8a15397222e3, 2.16.2-ae0b80f47bbe, 2.16.2-24518f7f261d, 2.16.2-a26f29437909, 2.16.2-0887600e5bd2, 2.16.2-72ef75c5fcea, 2.16.2-40a6aa7494f1. Drain existing acquisition before promotion; already-running old PHP processes cannot be retroactively constrained.\nCache maintenance update: no synchronous random purge on writes; existing scheduled housekeeping owns cleanup. Bounded workload, fair endpoint rotation, progressive POST comparison and a 45-second batch timeout. Five-minute budget preserved. JSON/CSV include server acquisition, cache ingestion, reservation and pending POST diagnostics. The stable root routing correction (ee6682e988f6) must be present; this package does not overwrite .htaccess.\nIncludes previous administrator-only detailed analysis repair. Recruitment uses dedicated protected PDO SQLite storage; imports legacy JSON once on first access, without modifying the source files. One active browser scan, automatic recovery of expired ownership while the page is visible, pause flush, explicit error retry, pagination and analysis-specific CSV. Preview is isolated and read-only; production migration occurs after promotion and first authorized access. Rollback retains SQLite but old code sees old JSON only. Do not run old and new scans concurrently during promotion. No Green writes or CRON changes. Required recovery files match the promoted base.\n')
archive=out/('P2K_2.16.2_OAuth_Performance_Lab_RC_'+HEAD[:12]+'.zip')
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for f in sorted(directory.rglob('*')):
        if f.is_file():z.write(f,f.relative_to(directory))
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert len(z.namelist())==len(set(z.namelist()))
    for manifest_name in ['CANDIDATE_PAYLOAD.sha256','RECOVERY_PLANE.sha256']:
        for line in z.read(manifest_name).decode().splitlines():
            if not line.strip(): continue
            digest,path=line.split(None,1)
            assert sha(z.read('payload/'+path.strip().removeprefix('./')))==digest
# These standalone surfaces are physically served and are not activated by a
# core slot promotion. Keep the companion archive separate from RC payload.
standalone_paths=[club+'/MatchRecruitment/'+name for club in ['TeamFrance','TGP'] for name in ['auth.php','api/index.php','assets/js/recruitment.js','template.html','compat/ActiveOAuthRuntime.php']]+['TeamFrance/MatchAnalyzer/'+name for name in ['auth.php','template.html','compat/ActiveOAuthRuntime.php']]
assert len(standalone_paths)==13
standalone=out/('P2K_2.16.2_OAuth_Standalone_Inc3_'+HEAD[:12]+'.zip')
parent='40a6aa7494f1bc9fc8d9b8bce7826b4c957f894f'
assert subprocess.check_output(['git','merge-base',parent,HEAD],cwd=ROOT,text=True).strip()==parent
with zipfile.ZipFile(standalone,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    checks=[];baseline=[]
    for path in sorted(standalone_paths):
        data=(ROOT/path).read_bytes()
        if path=='TeamFrance/MatchAnalyzer/template.html':data=stamp_api_assets(data)
        z.writestr('payload/'+path,data)
        checks.append(sha(data)+'  payload/'+path+'\n')
        old=subprocess.run(['git','show',parent+':'+path],cwd=ROOT,capture_output=True)
        if old.returncode:assert path.endswith('/compat/ActiveOAuthRuntime.php'),path
        baseline.append((sha(old.stdout) if not old.returncode else '-')+'  '+path+'\n')
    z.writestr('STANDALONE_PAYLOAD.sha256',''.join(checks))
    z.writestr('STANDALONE_BASELINE.sha256',''.join(baseline))
    z.writestr('STANDALONE_RELEASE.txt',HEAD+'\n')
    z.write(ROOT/'tools/install-p2k-v2162-oauth-standalone-inc3.sh','install-standalone-increment3.sh')
    z.writestr('README.txt','First promote the matching core Release Control ZIP. Stop both club recruitment applications, extract this ZIP on the host and run bash install-standalone-increment3.sh. The script validates the active release, all payload checksums and every local baseline before changing any files. Existing local data and configuration are preserved. Exit Release Control preview before using standalone recruitment.\n')
with zipfile.ZipFile(standalone) as z:
    assert z.testzip() is None
    assert len(z.namelist())==len(set(z.namelist()))
    for line in z.read('STANDALONE_PAYLOAD.sha256').decode().splitlines():
        digest,path=line.split(None,1);assert sha(z.read(path))==digest
(out/'SHA256SUMS').write_text(''.join(sha(f.read_bytes())+'  '+f.name+'\n' for f in [archive,standalone]))
print(archive, archive.stat().st_size,sha(archive.read_bytes()))
