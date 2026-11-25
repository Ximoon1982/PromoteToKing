#!/usr/bin/env python3
from pathlib import Path
import hashlib, shutil, subprocess, sys
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1]).resolve()
name="P2K_TeamFrance_Leaderboard_v0.1.0_SSH_Installer"
package=out/name
if package.exists(): shutil.rmtree(package)
payload=package/"payload"
source=root/"TeamFrance/Leaderboard"
for p in sorted(source.rglob("*")):
    if not p.is_file() or p.name==".gitignore" or (p.relative_to(source).parts[0]=="data" and p.name!=".htaccess"): continue
    dest=payload/"TeamFrance/Leaderboard"/p.relative_to(source);dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
shutil.copy2(root/"TeamFrance/home.html",payload/"TeamFrance/home.html")
shutil.copy2(root/"tools/install-team-france-leaderboard-v010.sh",package/"install.sh")
old=subprocess.check_output(["git","show","9a604f768818691fa16fdb63d4dc418d4930d2b4:TeamFrance/home.html"],cwd=root)
(package/"HUB_BASELINE_SHA256").write_text(hashlib.sha256(old).hexdigest()+"\n")
(package/"SHA256SUMS").write_text("".join(hashlib.sha256(p.read_bytes()).hexdigest()+"  "+p.relative_to(payload).as_posix()+"\n" for p in sorted(payload.rglob("*")) if p.is_file()))
(package/"README.txt").write_text("Team France Leaderboard 0.1.0\nStandalone SSH installation; not P2K Release Control.\nRun bash install.sh ROOT --check, then --apply, then --check.\nPreserves data/leaderboard.sqlite3, source archive, acquisition state, existing widgets and P2K core.\nPHP web SAPI must also provide PDO SQLite and cURL. Public iframe 260 x 590.\n")
print(package)
