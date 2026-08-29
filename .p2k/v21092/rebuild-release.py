#!/usr/bin/env python3
from pathlib import Path
import argparse,base64,gzip,hashlib,io,json,lzma,shutil,stat,subprocess,tarfile,tempfile,importlib.util
HERE=Path(__file__).resolve().parent
EXPECTED_SHA256="79fa9e7df8e9c6711c8bdfe28ebe1c037682159aac2dffe854e0c02f97273991"
TAR_MTIME=1787961600

def apply_previous_recovery(root):
    prev=HERE.parent/'v21091'/'rebuild-release.py'
    if prev.exists():
        spec=importlib.util.spec_from_file_location('p2k_v21091_recovery',prev); mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); mod.apply_patch(root)

def apply_patch(root):
    enc=(HERE/'remaining-source.patch.xz.b85.part1').read_text()+(HERE/'remaining-source.patch.xz.b85.part2').read_text()
    patch=lzma.decompress(base64.b85decode(enc.strip()))
    p=subprocess.run(['patch','-p1','--forward','--batch'],cwd=root,input=patch,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
    if p.returncode not in (0,1): raise SystemExit(p.stdout.decode(errors='replace'))
    if p.returncode==1:
        txt=p.stdout.decode(errors='replace')
        if 'Reversed (or previously applied) patch detected' not in txt and 'Skipping patch' not in txt: raise SystemExit(txt)

def build(source,output):
    manifest_bytes=(HERE/'payload-manifest.json').read_bytes(); manifest=json.loads(manifest_bytes)
    with tempfile.TemporaryDirectory(prefix='p2k-v21092-build-') as td:
        work=Path(td)/'tree'; shutil.copytree(source,work,symlinks=True); apply_previous_recovery(work); apply_patch(work)
        for row in manifest['files']:
            p=work/row['path']; got=hashlib.sha256(p.read_bytes()).hexdigest()
            if got!=row['sha256'] or p.stat().st_size!=row['size']: raise SystemExit(f"source mismatch: {row['path']}")
        names=['.','./.p2k-v21092-payload-manifest.json','./CHANGELOG.md','./INSTALL_v2.10.9.2.md','./MIGRATION_VERSION','./RELEASE_NOTES.md','./RELEASE_NOTES_v2.10.9.2.md','./RELEASE_v2.10.9.2.json','./TeamPointsAdmin.html','./VERSION','./assets','./assets/js','./assets/js/pages','./assets/js/pages/team-points-features.js','./assets/js/site-config.js','./server','./server/team-points','./server/team-points/bin','./server/team-points/bin/mca-results-sync.php','./server/team-points/src','./server/team-points/src/LiveRanksService.php','./server/team-points/src/McaIndexParser.php','./server/team-points/src/McaResultsCronService.php','./server/team-points/src/McaSourceCatalogue.php','./site-manifest.json']
        bio=io.BytesIO()
        with tarfile.open(fileobj=bio,mode='w',format=tarfile.GNU_FORMAT) as t:
            for name in names:
                ti=tarfile.TarInfo(name); ti.uid=ti.gid=0; ti.uname=ti.gname=''; ti.mtime=TAR_MTIME
                rel=name[2:] if name.startswith('./') else name
                if name=='.' or rel in {'assets','assets/js','assets/js/pages','server','server/team-points','server/team-points/bin','server/team-points/src'}:
                    ti.type=tarfile.DIRTYPE; ti.mode=0o2755; ti.size=0; t.addfile(ti); continue
                data=manifest_bytes if rel=='.p2k-v21092-payload-manifest.json' else (work/rel).read_bytes(); ti.type=tarfile.REGTYPE; ti.mode=0o644; ti.size=len(data); t.addfile(ti,io.BytesIO(data))
        tar_path=Path(td)/'payload.tar'; tar_path.write_bytes(bio.getvalue())
        z=subprocess.run(['gzip','-n','-9','-c',str(tar_path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if z.returncode!=0: raise SystemExit(z.stderr.decode(errors='replace'))
        data=(HERE/'installer-header.sh').read_bytes()+z.stdout; got=hashlib.sha256(data).hexdigest()
        if got!=EXPECTED_SHA256: raise SystemExit(f"rebuild SHA mismatch: {got}")
        output.write_bytes(data); output.chmod(output.stat().st_mode|stat.S_IXUSR|stat.S_IXGRP|stat.S_IXOTH); print(f"rebuilt {output} SHA256={got}")
if __name__=='__main__':
    ap=argparse.ArgumentParser(); ap.add_argument('source',nargs='?',default='.'); ap.add_argument('output',nargs='?',default='PromoteToKing_v2.10.9.2_INCREMENTAL_INSTALLER.run'); a=ap.parse_args(); build(Path(a.source).resolve(),Path(a.output).resolve())
