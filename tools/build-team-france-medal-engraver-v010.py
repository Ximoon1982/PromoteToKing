#!/usr/bin/env python3
import hashlib,json,shutil,sys,zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1]).resolve()
name="P2K_TeamFrance_Medal_Engraver_v0.1.0_SSH_Installer"
source=root/"TeamFrance/MedalEngraver"
m=json.loads((source/"artwork.json").read_text())
for tier,h in m["sha256"].items():
    p=source/"assets"/(tier+".png")
    if hashlib.sha256(p.read_bytes()).hexdigest()!=h: raise SystemExit("Incorrect artwork: "+tier)
pkg=out/name
if pkg.exists(): raise SystemExit("Package exists; choose an empty output directory")
payload=pkg/"payload"
shutil.copytree(source,payload/"TeamFrance/MedalEngraver",ignore=shutil.ignore_patterns(".gitignore"))
shutil.copy2(root/"TeamFrance/home.html",payload/"TeamFrance/home.html")
shutil.copy2(root/"tools/install-team-france-medal-engraver-v010.sh",pkg/"install.sh")
shutil.copy2(root/"tools/team-france-medal-engraver-hub-baseline.html",pkg/"hub-baseline.html")
with (pkg/"SHA256SUMS").open("w") as f:
    for p in sorted(payload.rglob("*")):
        if p.is_file(): f.write(hashlib.sha256(p.read_bytes()).hexdigest()+"  "+p.relative_to(payload).as_posix()+"\n")
with zipfile.ZipFile(out/(name+".zip"),"w",zipfile.ZIP_DEFLATED,compresslevel=6) as z:
    for p in sorted(pkg.rglob("*")):
        if p.is_file(): z.write(p,p.relative_to(out))
print(out/(name+".zip"))
