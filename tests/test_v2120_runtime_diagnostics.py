from pathlib import Path
import importlib.util
import subprocess

R = Path(__file__).resolve().parents[1]
SOURCE = "f193ec4847968ac25eaf881cdbff86cbb852c309"
BUILD_ID = "runtime-diagnostics-cache-marker-correction-1"
KEY = "p2k-2.12.0-f193ec484796-365aac4e1e4ef57f"
OLD_RUNTIME = "583dce403ee5709c4c6d4e2915685a012780704b424146fdff867a141bbde4ec"
NEW_RUNTIME = "60ea06d510249b89924f963cdc735d632bc7c88fceac7567e16386f718e98d1a"
OLD_PAGE = "429b510daec253ab7cdab76d9191454873ab7a3eb7c8b345ba8ab37fbcbd823e"
NEW_PAGE = "907642514a87dbeba117a5f4e0bfa5b35e2c419e3732ce111d16f0a5445ab4f8"


def read(path):
    return (R / path).read_text(encoding="utf-8")


def sha(path):
    return subprocess.check_output(["sha256sum", str(path)], text=True).split()[0]


def test_runtime_diagnostics_cache_marker_semantics_and_provenance():
    assert subprocess.run(["git", "cat-file", "-e", SOURCE + "^{commit}"], cwd=R).returncode == 0
    q = R / "tools/release/static_asset_cache_key.py"
    spec = importlib.util.spec_from_file_location("cache_key", q)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    assert module.make_key("2.12.0", SOURCE, BUILD_ID) == KEY

    runtime = read("assets/js/pages/runtime-diagnostics.js")
    assert "function siteConfigCacheMarkerMatchesVersion(marker,version)" in runtime
    assert "parts.length===2&&/^[0-9a-f]{12}$/.test(parts[0])&&/^[0-9a-f]{16}$/.test(parts[1])" in runtime
    assert "siteConfigAssetVersions.some(v=>!siteConfigCacheMarkerMatchesVersion(v,cfg.version))" in runtime
    assert "siteConfigAssetVersions.some(v=>v!==cfg.version)" not in runtime
    assert f"assets/js/pages/runtime-diagnostics.js?v={KEY}" in read("InsightsHealth.html")


def test_runtime_diagnostics_correction_installer(tmp_path):
    target = tmp_path / "site"
    (target / "assets/js/pages").mkdir(parents=True)
    (target / "VERSION").write_text("2.12.0\n", encoding="utf-8")
    (target / "assets/js/site-config.js").write_text('window.P2K_SITE_CONFIG = { version: "2.12.0" };\n', encoding="utf-8")

    runtime_old = subprocess.check_output(["git", "show", f"c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303:assets/js/pages/runtime-diagnostics.js"], cwd=R, text=True)
    page_old = subprocess.check_output(["git", "show", f"c52fd68dbcd8fdb7cca8a1ab63ee6dd6da1e0303:InsightsHealth.html"], cwd=R, text=True)
    runtime = target / "assets/js/pages/runtime-diagnostics.js"
    page = target / "InsightsHealth.html"
    runtime.write_text(runtime_old, encoding="utf-8")
    page.write_text(page_old, encoding="utf-8")
    runtime.chmod(0o640)
    page.chmod(0o644)
    assert sha(runtime) == OLD_RUNTIME and sha(page) == OLD_PAGE

    script = R / "tools/release/v2120/install-runtime-diagnostics-correction-v2.12.0.sh"
    first = subprocess.run(["bash", str(script), str(target)], cwd=R, capture_output=True, text=True)
    assert first.returncode == 0, first.stderr
    assert sha(runtime) == NEW_RUNTIME and sha(page) == NEW_PAGE
    assert runtime.read_text(encoding="utf-8") == read("assets/js/pages/runtime-diagnostics.js")
    assert page.read_text(encoding="utf-8") == read("InsightsHealth.html")
    assert runtime.stat().st_mode & 0o777 == 0o640
    assert page.stat().st_mode & 0o777 == 0o644

    second = subprocess.run(["bash", str(script), str(target)], cwd=R, capture_output=True, text=True)
    assert second.returncode == 0 and "already installed" in second.stdout

    runtime.write_text("unknown\n", encoding="utf-8")
    rejected = subprocess.run(["bash", str(script), str(target)], cwd=R, capture_output=True, text=True)
    assert rejected.returncode == 2
    assert "not the exact qualified pre-correction file" in rejected.stderr
    assert runtime.read_text(encoding="utf-8") == "unknown\n"


def test_runtime_diagnostics_correction_is_retained_in_package_builder():
    builder = read("tools/release/v2120/build-package.sh")
    assert "install-runtime-diagnostics-correction-v2.12.0.sh" in builder
    assert f"runtime_diagnostics_key={KEY}" in builder
    assert f"runtime_diagnostics_source={SOURCE}" in builder
    assert f"runtime_diagnostics_build_id={BUILD_ID}" in builder
