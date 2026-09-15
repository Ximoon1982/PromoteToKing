from pathlib import Path
import importlib.util,json,subprocess
R=Path(__file__).resolve().parents[1];S="d025d8c4610390e54058bca069785e9916af5483";V2120="c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303";RK="p2k-2.12.0-d025d8c46103-8e4b12768d33959c";SK="p2k-2.12.0-d025d8c46103-ae73ae796f09667d"
def read(p):return (R/p).read_text()
def git_read(p,ref=V2120):return subprocess.check_output(["git","show",f"{ref}:{p}"],cwd=R,text=True)
def git_root_pages(ref=V2120):
 names=subprocess.check_output(["git","ls-tree","--name-only",ref],cwd=R,text=True).splitlines()
 return [p for p in names if Path(p).suffix in (".html",".htm")]
def test_version_surfaces_and_loaders():
 assert git_read("VERSION").strip()=="2.12.0" and 'version: "2.12.0"' in git_read("assets/js/site-config.js")
 manifest=json.loads(git_read("site-manifest.json"));assert manifest["schemaVersion"]==8 and manifest["version"]=="2.12.0"
 assert manifest["release"]["sourceBaseline"]=="v2.11.5" and manifest["releaseNotes"][0]=="RELEASE_NOTES_v2.12.0.md"
 pages=git_root_pages();loaders=[p for p in pages if "assets/js/site-config.js?v=" in git_read(p)]
 assert loaders and all(f"assets/js/site-config.js?v={SK}" in git_read(p) for p in loaders)
def test_real_cache_provenance():
 assert subprocess.run(["git","cat-file","-e",S+"^{commit}"],cwd=R).returncode==0
 q=R/"tools/release/static_asset_cache_key.py";sp=importlib.util.spec_from_file_location("q",q);m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
 assert m.make_key("2.12.0",S,"match-recruitment-release-identity-2")==RK
 assert m.make_key("2.12.0",S,"v2.12.0-release-identity-1")==SK
def test_installer_contract():
 i=read("tools/release/v2120/install-promote-to-king-v2.12.0.sh");q=read("tools/release/v2120/qualify-installer.sh")
 for x in ("set -Eeuo pipefail","SUPPORTED_BASELINES.sha256","SUPPORTED_TREES.sha256","PACKAGE-MANIFEST.sha256","REMOVALS.list","verify_package","verify_supported_baseline","verify_installed","remove_obsolete_files","rollback","attempt_automatic_rollback","PRESERVE_BACKUP","ROLLBACK_SUCCEEDED","check_disk_space","largest_file_kb","cron.before","P2K_FORCE_INSTALL_FAILURE_AFTER","P2K_FORCE_INSTALL_FAILURE_PHASE","P2K_FORCE_ROLLBACK_FAILURE"):assert x in i
 for x in ("2ca1fc191aeef444b4886b53e25a54a83820c25c","b8bf26c7c41ca1914323717766bca995139291aa","4ececcc230ca07099b346cb47396ad00bedd5c21","6706e619d310e2c74fe2734cfcef8dd2f83d70d1","c534b2dbb0346eac0fa6de869621d6b7d785ead8"):assert x in q
 for x in ("package control-metadata and payload tamper rejection passed","portable relocated SHA256SUMS verification passed","sha256sum -c SHA256SUMS.txt","cmp -s \"$PACKAGE/FILES.list\" \"$WORK/managed.files\"","removal rollback restored removed path","rollback-failure diagnostic and recovery-backup preservation passed"):assert x in q

def test_production_path_policy_is_shared_and_preserves_mutable_state():
 p=read("tools/release/v2120/production_paths.py")
 assert "--root" in p and "git_paths" in p and "installed_paths" in p
 for name in ("data","storage","cache","uploads","logs","runtime","backups","sessions"):
  assert f'\"{name}\"' in p

def test_lived_production_upgrade_contract():
 b=read("tools/release/v2120/build-package.sh")
 meta=read("tools/release/v2120/lived-production-baseline.meta").strip().split("\t")
 assert meta==["538ee589d58360c411d6769fbe752f64867c053d167f50a3669f355e6cf2f57d","2.11.5-lived-production-20260913","production-capture-20260913-plus-site-manifest"]
 removals=[x for x in read("tools/release/v2120/lived-production-removals.txt").splitlines() if x]
 assert len(removals)==18 and len(removals)==len(set(removals))
 assert "lived-production-baseline.meta" in b and "lived-production-removals.txt" in b
 assert 'cat "$LIVED_BASELINE" >>"$PACKAGE/SUPPORTED_TREES.sha256"' in b and 'cat "$work/canonical-removals" "$LIVED_REMOVALS" | sort -u >"$PACKAGE/REMOVALS.list"' in b
 q=R/"tools/release/v2120/production_paths.py";sp=importlib.util.spec_from_file_location("v2120_paths",q);m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
 preserved=("assets/trophy-gallery/legacy-r4/club-wars-galactic-conflict.png","assets/trophy-gallery/legacy-r4/owl-2024-classic-u1700.png","assets/trophy-gallery/legacy-r4/owl-2024-grand-prix-candidates.png","assets/trophy-gallery/legacy-r4/owl-2024-swiss4all.jpg","assets/trophy-gallery/legacy-r4/owl-2024-vote-g1.png","assets/trophy-gallery/legacy-r4/pcl-super-bingo-2025.png","assets/trophy-gallery/legacy-r4/tcmac-centurion-s4.png","resources/miac/seed.zip","server/team-points/public/config.local.php")
 assert all(not m.included(path) for path in preserved)
 assert m.included("assets/js/site-config.js") and m.included("ui-v2.html") and m.included("site-manifest.json")

def test_site_manifest_correction_installer(tmp_path):
 target=tmp_path/"site";target.mkdir();(target/"VERSION").write_text("2.12.0\n")
 (target/"assets/js").mkdir(parents=True);(target/"assets/js/site-config.js").write_text('window.P2K_SITE_CONFIG = { version: "2.12.0" };\n')
 old=subprocess.check_output(["git","show","6de1192cf455c6ef137f54b8262bcb16dc837ec5:site-manifest.json"],cwd=R,text=True)
 manifest=target/"site-manifest.json";manifest.write_text(old);manifest.chmod(0o640)
 script=R/"tools/release/v2120/install-site-manifest-correction-v2.12.0.sh"
 first=subprocess.run(["bash",str(script),str(target)],cwd=R,capture_output=True,text=True);assert first.returncode==0
 assert manifest.read_text()==git_read("site-manifest.json") and (manifest.stat().st_mode&0o777)==0o640
 second=subprocess.run(["bash",str(script),str(target)],cwd=R,capture_output=True,text=True);assert second.returncode==0 and "already installed" in second.stdout
 manifest.write_text("unknown\n")
 rejected=subprocess.run(["bash",str(script),str(target)],cwd=R,capture_output=True,text=True);assert rejected.returncode==2
 assert "not the exact qualified pre-correction file" in rejected.stderr and manifest.read_text()=="unknown\n"

def test_complete_package_integrity_contract():
 b=read("tools/release/v2120/build-package.sh")
 for name in ("FILES.list","REMOVALS.list","MODES.list","SUPPORTED_BASELINES.sha256","SUPPORTED_TREES.sha256","RELEASE-IDENTITY.txt","PACKAGE-MANIFEST.sha256"):
  assert name in b
 assert "! -name PACKAGE-MANIFEST.sha256" in b
 assert 'cd "$OUTPUT"' in b
 assert '"$OUTPUT/PromoteToKing_v2.12.0_INCREMENTAL.zip"' not in b
 assert "install-site-manifest-correction-v2.12.0.sh" in b
def test_inherited_files_unchanged():
 # This is the v2.12.0 release's preservation contract, not a freeze on later test corrections.
 for p in ("tests/test_v2112_structural_consolidation.py","tests/test_v288_release.py","tests/v2.11.3-structural-metrics.json"):
  assert subprocess.run(["git","diff","--exit-code","c534b2dbb0346eac0fa6de869621d6b7d785ead8",V2120,"--",p],cwd=R).returncode==0
