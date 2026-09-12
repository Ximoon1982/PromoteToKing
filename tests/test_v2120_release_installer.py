from pathlib import Path
import importlib.util,subprocess
R=Path(__file__).resolve().parents[1];S="d025d8c4610390e54058bca069785e9916af5483";RK="p2k-2.12.0-d025d8c46103-8e4b12768d33959c";SK="p2k-2.12.0-d025d8c46103-ae73ae796f09667d"
def read(p):return (R/p).read_text()
def test_version_surfaces_and_loaders():
 assert read("VERSION").strip()=="2.12.0" and 'version: "2.12.0"' in read("assets/js/site-config.js")
 pages=[*R.glob("*.html"),*R.glob("*.htm")];loaders=[p for p in pages if "assets/js/site-config.js?v=" in p.read_text()]
 assert loaders and all(f"assets/js/site-config.js?v={SK}" in p.read_text() for p in loaders)
def test_real_cache_provenance():
 assert subprocess.run(["git","cat-file","-e",S+"^{commit}"],cwd=R).returncode==0
 q=R/"tools/release/static_asset_cache_key.py";sp=importlib.util.spec_from_file_location("q",q);m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
 assert m.make_key("2.12.0",S,"match-recruitment-release-identity-2")==RK
 assert m.make_key("2.12.0",S,"v2.12.0-release-identity-1")==SK
def test_installer_contract():
 i=read("tools/release/v2120/install-promote-to-king-v2.12.0.sh");q=read("tools/release/v2120/qualify-installer.sh")
 for x in ("set -Eeuo pipefail","SUPPORTED_BASELINES.sha256","verify_package","verify_installed","rollback","crontab.before","P2K_FORCE_INSTALL_FAILURE_AFTER"):assert x in i
 for x in ("2ca1fc191aeef444b4886b53e25a54a83820c25c","b8bf26c7c41ca1914323717766bca995139291aa","4ececcc230ca07099b346cb47396ad00bedd5c21","6706e619d310e2c74fe2734cfcef8dd2f83d70d1","c534b2dbb0346eac0fa6de869621d6b7d785ead8"):assert x in q
def test_inherited_files_unchanged():
 for p in ("tests/test_v2112_structural_consolidation.py","tests/test_v288_release.py","tests/v2.11.3-structural-metrics.json"):
  assert subprocess.run(["git","diff","--exit-code","c534b2dbb0346eac0fa6de869621d6b7d785ead8","HEAD","--",p],cwd=R).returncode==0
