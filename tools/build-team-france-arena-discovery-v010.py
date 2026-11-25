#!/usr/bin/env python3
from pathlib import Path
import hashlib, shutil, subprocess, sys
root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1]).resolve()
name="P2K_TeamFrance_Leaderboard_v0.1.0_Player_Info_SSH_Installer"
package=out/name
if package.exists():shutil.rmtree(package)
payload=package/"payload"
base=["a37e344c185bb17ea5c187687281798e15503760","ef236d06a3eaf22f61ccf355fcf5fef2f7cb82c6","9e5e7db900231600025d00cb3dd6ae4de1707004","2c3a508eba36d4199d9893c10399c834a0d11a18"]
files=["src/ArenaIndexParser.php","src/ArenaDiscovery.php","src/Core.php","src/Refresh.php","api.php","assets/admin.js","admin/index.php","index.php","assets/public.js","assets/style.css"]
manifest=[]
for file in files:
    rel="TeamFrance/Leaderboard/"+file
    dest=payload/rel
    dest.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(root/rel,dest)
    hashes=set()
    for sha in base:
        p=subprocess.run(["git","show",sha+":"+rel],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
        hashes.add(hashlib.sha256(p.stdout).hexdigest() if p.returncode==0 else "MISSING")
    manifest.append(rel+"\t"+" ".join(sorted(hashes))+"\t"+hashlib.sha256(dest.read_bytes()).hexdigest()+"\n")
(package/"FILE_CHECKSUMS.tsv").write_text("".join(manifest))
shutil.copy2(root/"tools/install-team-france-arena-discovery-v010.sh",package/"install.sh")
(package/"README.txt").write_text("Team France Leaderboard 0.1.0 cumulative Arena Discovery and Player Information patch\nFor an existing v0.1.0 installation, with or without the previous Daily URL and Daily Results fixes. Run bash install.sh ROOT --check, then --apply, then --check. No database migration or data overwrite. Avatars are cached during normal account checks; Refresh all populates them immediately. Counts reflect unique verified participated events in the selected season. Select an arena season with both dates configured. Default organizer team-france; additional organizers can be searched. Select all filtered includes all result pages. Already assigned events are disabled. No CRON.\n")
print(package)
