from __future__ import annotations
import hashlib, json, os
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
INSTALLER=ROOT/"tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"
BASE="6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
R4="4938f5cc8ed111702f867eee9e941e4c896e6163"
R5="e883881c083e1490335fff373bdeb8081ecc72cb"
KEY="poc-e883881c083e-20260910-r5"

def git_bytes(ref,path): return subprocess.run(["git","show",f"{ref}:{path}"],cwd=ROOT,check=True,capture_output=True).stdout
def tree(tmp):
 root=tmp/"PromoteToKing";(root/"assets/js/admin").mkdir(parents=True)
 for path in ("ui-v2.html","assets/js/admin/tool-registry.js","assets/js/admin/admin-shell.js","assets/js/pages/dashboard-v2.js"):
  target=root/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(git_bytes(BASE,path))
 (root/"cron.txt").write_bytes(b"17 2 * * * php cron.php\n")
 (root/"data/trophy-gallery/artwork").mkdir(parents=True);(root/"data/trophy-gallery/catalog.json").write_text('{"version":1,"records":[],"media":{}}\n')
 archive=tmp/"r5.tar.gz";subprocess.run(["git","archive","--format=tar.gz",f"--prefix=PromoteToKing-{R5}/","-o",str(archive),R5],cwd=ROOT,check=True)
 return root,archive
def snapshot(root): return {str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in root.rglob('*') if p.is_file() and '.p2k-poc-' not in str(p)}
def invoke(root,archive,action="install"):
 return subprocess.run(["bash",str(INSTALLER),str(root),action],text=True,capture_output=True,timeout=120)

def test_r5_install_reinstall_remove_and_persistent_data(tmp_path):
 root,archive=tree(tmp_path);before=snapshot(root);cron=(root/"cron.txt").read_bytes();catalog=(root/"data/trophy-gallery/catalog.json").read_bytes()
 first=invoke(root,archive);assert first.returncode==0,first.stdout+first.stderr
 assert KEY in (root/"ui-v2.html").read_text() and KEY in (root/"assets/js/admin/tool-registry.js").read_text()
 assert (root/"server/trophy-gallery/public/api.php").is_file() and (root/"trophies/index.html").is_file()
 state=json.loads((root/".p2k-poc-state/trophy-gallery-overlay.json").read_text());assert state["overlay_version"]==5 and state["source_commit"]==R5
 second=invoke(root,archive);assert second.returncode==0,second.stdout+second.stderr
 assert (root/"assets/js/admin/tool-registry.js").read_text().count("P2K_TROPHY_GALLERY_POC_OVERLAY_BEGIN")==1
 removed=invoke(root,archive,"remove");assert removed.returncode==0,removed.stdout+removed.stderr
 assert snapshot(root)==before and (root/"cron.txt").read_bytes()==cron and (root/"data/trophy-gallery/catalog.json").read_bytes()==catalog

def test_installer_is_self_contained_and_pins_exact_runtime():
 head=INSTALLER.read_bytes().split(b"__P2K_TROPHY_R5_PAYLOAD_BELOW__\n",1)[0].decode()
 assert R5 in head and KEY in head and BASE in head
 assert "urllib" not in head and "github.com" not in head and "P2K_TROPHY_ARCHIVE_FILE" not in head
 assert INSTALLER.stat().st_size>10_000_000

def test_r5_failed_archive_rolls_back_exact_tree_and_cron(tmp_path):
 root,archive=tree(tmp_path);before=snapshot(root)
 broken=tmp_path/"broken.run";data=INSTALLER.read_bytes();broken.write_bytes(data[:-100]+b"corrupt\n");broken.chmod(0o755)
 result=subprocess.run(["bash",str(broken),str(root),"install"],text=True,capture_output=True,timeout=120);assert result.returncode!=0
 assert snapshot(root)==before

def test_forced_post_install_failure_rolls_back_exact_tree_and_data(tmp_path):
 root,archive=tree(tmp_path);before=snapshot(root);env=os.environ.copy();env["P2K_TROPHY_TEST_FAIL_AFTER_INSTALL"]="1"
 result=subprocess.run(["bash",str(INSTALLER),str(root),"install"],env=env,text=True,capture_output=True,timeout=120)
 assert result.returncode!=0 and "Rollback completed" in result.stderr
 assert snapshot(root)==before

def test_r4_overlay_is_upgraded_without_losing_data(tmp_path):
 root,archive=tree(tmp_path);r4=tmp_path/"r4.run";r4.write_bytes(git_bytes(R4,"tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"));r4.chmod(0o755)
 dummy=tmp_path/"runtime.js";dummy.write_text('const STORAGE_KEY = "p2k-trophy-gallery-poc-v1"; function isAdminVisible(){return true} const tab={dataset:{}}; tab.dataset.hallSubtab="trophies"; const p2kTrophyAdminPanel={}; window.P2K_TROPHY_GALLERY_POC={mount(){}};')
 env=os.environ.copy();env["P2K_TROPHY_POC_SOURCE_FILE"]=str(dummy)
 old=subprocess.run(["bash",str(r4),str(root),"install"],env=env,text=True,capture_output=True);assert old.returncode==0,old.stdout+old.stderr
 marker=root/"data/trophy-gallery/catalog.json";original=marker.read_bytes()
 upgraded=invoke(root,archive);assert upgraded.returncode==0,upgraded.stdout+upgraded.stderr
 assert KEY in (root/"ui-v2.html").read_text() and marker.read_bytes()==original
 removed=invoke(root,archive,"remove");assert removed.returncode==0,removed.stdout+removed.stderr
 assert marker.read_bytes()==original
 for path in ("ui-v2.html","assets/js/admin/tool-registry.js","assets/js/admin/admin-shell.js","assets/js/pages/dashboard-v2.js"):
  assert (root/path).read_bytes()==git_bytes(BASE,path)
 assert not (root/"assets/js/admin/trophy-gallery-poc.js").exists()
