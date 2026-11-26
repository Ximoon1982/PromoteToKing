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
paths=['AnalyzeMatchModal.html','AnalyzeMatches.htm','FindMatch.htm','assets/js/shared/upcoming-analysis-core.js','assets/js/pages/find-match.js','assets/js/pages/analyze-match-modal.js','server/team-points/public/detailed-match-analysis.php','assets/js/pages/v2-11-0.js','assets/js/site-config.js','server/team-points/public/recruitment-admin.php','server/team-points/src/RecruitmentAnalysisStore.php','OAuthPerformanceLab.html','assets/js/pages/oauth-performance-lab.js','VERSION','ui-v2.html']
for path in paths[:-2]:
    p=payload/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/path).read_bytes())
(payload/'VERSION').write_text(VERSION+'\n')
key='p2k-'+VERSION+'-'+HEAD[:12]+'-'+sha((HEAD+'|oauth-performance-lab').encode())[:16]
# Preserve the previously promoted UI exactly except its authoritative cache identity.
previous_key=metadata['cache_key']
assert previous_key in previous_ui
ui=previous_ui.replace(previous_key,key)
assert 'assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1' in ui
ui=ui.replace('assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1','assets/js/site-config.js?v=p2k-2.16.2-recruitment-2')
assert key in ui
(payload/'ui-v2.html').write_text(ui)
manifest=''.join(sha((payload/path).read_bytes())+'  ./'+path+'\n' for path in sorted(paths))
(directory/'CANDIDATE_PAYLOAD.sha256').write_text(manifest)
meta={'schema_version':1,'version':VERSION,'source_head':HEAD,'cache_key':key,'build_id':'p2k-v2162-oauth-performance-lab-'+HEAD[:12],'qualification_workflow':'p2k-v2162-oauth-performance-lab.yml','payload_manifest_sha256':sha(manifest.encode()),'accepted_public_builds':[{'version':'2.16.0','source_head':BASE},{'version':'2.16.2','source_head':'279ad6f17718365bdda31c6a9c0b52d45a5760e6'},{'version':'2.16.2','source_head':'5b66f1aa3ac86e6e57c6cc350bd676ece8bca37b'},{'version':'2.16.2','source_head':'378a65361975d3aeefd0fae92a145bb8e79583e0'},{'version':'2.16.2','source_head':'00593141e69ea75f4403dd763e0bc22f9b0cd6e9'},{'version':'2.16.2','source_head':'16b5fbd6caf100ce21a3a85539d852f271df3928'}]}
(directory/'CANDIDATE_RELEASE.json').write_text(json.dumps(meta,indent=2)+'\n')
(directory/'README.txt').write_text('P2K 2.16.2 - OAuth Performance Laboratory\nOpen /OAuthPerformanceLab.html after promotion. Standalone client-only benchmark; existing PHP gateway and rate controller unchanged. Pause other acquisition, paste representative usernames, and keep the tab visible. Up to five minutes, JSON/CSV export. Preview blocks real OAuth POSTs.\nUpload in Release Control, preview, then explicitly promote. Accepted builds: 2.16.0-02e4247723bf, 2.16.2-279ad6f17718, 2.16.2-5b66f1aa3ac8, 2.16.2-378a65361975, 2.16.2-00593141e69e, 2.16.2-16b5fbd6caf1.\nRevision 2: individual POST deadlines replace the premature drain cutoff; the five-minute budget is preserved by adaptive admission windows. JSON/CSV include drain, gateway timing and pending POST diagnostics. The stable root routing correction (ee6682e988f6) must be present; this package does not overwrite .htaccess.\nIncludes previous administrator-only detailed analysis repair. Recruitment uses dedicated protected PDO SQLite storage; imports legacy JSON once on first access, without modifying the source files. One active browser scan, automatic recovery of expired ownership while the page is visible, pause flush, explicit error retry, pagination and analysis-specific CSV. Preview is isolated and read-only; production migration occurs after promotion and first authorized access. Rollback retains SQLite but old code sees old JSON only. Do not run old and new scans concurrently during promotion. No Green writes or CRON changes. Required recovery files match the promoted base.\n')
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
(out/'SHA256SUMS').write_text(sha(archive.read_bytes())+'  '+archive.name+'\n')
print(archive, archive.stat().st_size,sha(archive.read_bytes()))
