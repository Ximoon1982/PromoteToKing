#!/usr/bin/env python3
from pathlib import Path
import hashlib, shutil, subprocess, sys
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1]).resolve()
name="P2K_TeamFrance_Leaderboard_v0.1.0_Full_Season_Recap_SSH_Installer"
package=out/name
if package.exists(): shutil.rmtree(package)
payload=package/"payload"
source=root/"TeamFrance/Leaderboard"
for p in sorted(source.rglob("*")):
    if not p.is_file() or p.name==".gitignore" or (p.relative_to(source).parts[0]=="data" and p.name!=".htaccess"): continue
    dest=payload/"TeamFrance/Leaderboard"/p.relative_to(source);dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
shutil.copy2(root/"TeamFrance/home.html",payload/"TeamFrance/home.html")
shutil.copy2(root/"tools/install-team-france-leaderboard-v010.sh",package/"install.sh")
# Only known immutable shipped application files may be replaced. Runtime data is never included.
baselines=["9a604f768818691fa16fdb63d4dc418d4930d2b4","a37e344c185bb17ea5c187687281798e15503760","ef236d06a3eaf22f61ccf355fcf5fef2f7cb82c6","9e5e7db900231600025d00cb3dd6ae4de1707004","2c3a508eba36d4199d9893c10399c834a0d11a18","b38247b0737c72ffe576c6ab68be523d19795f57","41e4890805a84168d9a304fbed819c9b48462c1d","b03147c0aa46c96d5f50565844b05e41fd968fbe","bd80b4d37ee835c4b194bfa2089d00dcd8a76b86","9e7a691b1f577ba83e5b1d4ac820a6df321ae6bd","f9fd011c4adabe6f36794aacf0e0890a6f78d62f","28776713f4377c012a15cba4e0de9815cfb349a3","0652f0cf9fcee802dbee793a85ac547cf915a368","8fd7eae439212d006069c29432472f85dc96d455","41bec6e9cf7a161f9b92a5c19031041e16143dd0"]
manifest=[]
for f in sorted(payload.rglob("*")):
    if not f.is_file(): continue
    rel=f.relative_to(payload).as_posix()
    allowed=set()
    for sha in baselines:
        p=subprocess.run(["git","show",sha+":"+rel],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
        allowed.add(hashlib.sha256(p.stdout).hexdigest() if p.returncode==0 else "MISSING")
    if rel=="TeamFrance/home.html": allowed.discard("MISSING")
    manifest.append(rel+"\t"+" ".join(sorted(allowed))+"\t"+hashlib.sha256(f.read_bytes()).hexdigest()+"\n")
(package/"FILE_CHECKSUMS.tsv").write_text("".join(manifest))
old=subprocess.check_output(["git","show","9a604f768818691fa16fdb63d4dc418d4930d2b4:TeamFrance/home.html"],cwd=root)
(package/"HUB_BASELINE_SHA256").write_text(hashlib.sha256(old).hexdigest()+"\n")
(package/"SHA256SUMS").write_text("".join(hashlib.sha256(p.read_bytes()).hexdigest()+"  "+p.relative_to(payload).as_posix()+"\n" for p in sorted(payload.rglob("*")) if p.is_file()))
(package/"README.txt").write_text("Team France Leaderboard 0.1.0 — full standalone installer\nComplete application and Team France hub card, for fresh install or upgrade from any shipped 0.1.0 baseline. No intermediate patches required. Not P2K Release Control.\nRun bash install.sh ROOT --check, then --apply, then --check.\nPreserves data/leaderboard.sqlite3, source archive, acquisition state, existing widgets and P2K core.\nPHP web SAPI must also provide PDO SQLite and cURL. Public iframe 260 x 590, transparent outer background and full-width cards. Leaderboard usernames and avatars open widget profiles; profile usernames open Chess.com. Official arena CSV recovery uses the CWL canonical download endpoint. Expanded leaderboards hide the other category and use the full iframe height. Official CSV parser safely repairs split display-name records. Admin Seasons tab downloads individual season and all-season category CSV rankings. Archived seasons are frozen and excluded from normal refresh; optional full archived refresh. Existing archive snapshots are captured in SQLite metadata on first initialization. Board-verified Fair Play summary correction included. Public season names omit archive labels; the admin season recap includes ongoing Daily match counts.\n")
print(package)
