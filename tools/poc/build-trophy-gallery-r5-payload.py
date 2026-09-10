#!/usr/bin/env python3
"""Rebuild the verified self-contained r5 payload appended to the .run file."""
from __future__ import annotations
import base64,gzip,hashlib,io,json,subprocess,sys,tarfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
INSTALLER=ROOT/"tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"
RUNTIME="e883881c083e1490335fff373bdeb8081ecc72cb"
BASE="6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
MARKER=b"__P2K_TROPHY_R5_PAYLOAD_BELOW__\n"
PAYLOAD=['assets/js/admin/admin-shell.js','assets/js/admin/trophy-gallery-poc.js','assets/js/pages/dashboard-v2.js','assets/trophy-gallery/engraving/P2KFullSans.ttf','assets/trophy-gallery/engraving/editor.html',*[f'assets/trophy-gallery/engraving/{kind}_{finish}.png' for kind in ('medal','cup','crystal') for finish in ('gold','silver','bronze')],'server/trophy-gallery/src/TrophyGalleryStore.php','server/trophy-gallery/public/api.php','server/trophy-gallery/public/media.php','server/trophy-gallery/resources/catalog.seed.json','trophies/index.html','tools/poc/engraving/README.md']
BASE_FILES=['assets/js/admin/tool-registry.js','ui-v2.html','assets/js/admin/admin-shell.js','assets/js/pages/dashboard-v2.js']

def git_bytes(ref,path):return subprocess.run(["git","show",f"{ref}:{path}"],cwd=ROOT,check=True,capture_output=True).stdout
def add(tar,name,data):
    info=tarfile.TarInfo(name);info.size=len(data);info.mode=0o644;info.mtime=0;tar.addfile(info,io.BytesIO(data))

raw=INSTALLER.read_bytes();head=raw[:raw.index(MARKER)+len(MARKER)]
files={path:git_bytes(RUNTIME,path) for path in PAYLOAD};manifest={"source_commit":RUNTIME,"base_commit":BASE,"payload_sha256":{path:hashlib.sha256(data).hexdigest() for path,data in files.items()}}
tar_buffer=io.BytesIO()
with tarfile.open(fileobj=tar_buffer,mode="w") as tar:
    add(tar,"manifest.json",json.dumps(manifest,sort_keys=True,separators=(",",":")).encode())
    for path,data in files.items():add(tar,"payload/"+path,data)
    for path in BASE_FILES:add(tar,"base/"+path,git_bytes(BASE,path))
buffer=io.BytesIO()
with gzip.GzipFile(fileobj=buffer,mode="wb",compresslevel=9,mtime=0) as compressed:compressed.write(tar_buffer.getvalue())
result=head+base64.b64encode(buffer.getvalue())+b"\n"
if "--check" in sys.argv:
    if INSTALLER.read_bytes()!=result:raise SystemExit("Self-contained Trophy installer payload is stale.")
else:
    INSTALLER.write_bytes(result);INSTALLER.chmod(0o755)
print(json.dumps({"installer":str(INSTALLER),"runtime":RUNTIME,"payload_bytes":len(buffer.getvalue()),"installer_bytes":len(result),"checked":"--check" in sys.argv},indent=2))
