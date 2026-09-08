from __future__ import annotations

import json
import hashlib
import os
from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"
V2114_BASE = "6706e619d310e2c74fe2734cfcef8dd2f83d70d1"
R3_HEAD = "1c1c256037b9742b6e23d2975a62beaa7c188f8e"
RUNTIME_COMMIT = "1512a6fa0cd9316df6c24a11b74fd2ba21defeb7"
FIXTURE_BUILD_ID = "trophy-r4-fixture"
FIXTURE_IDENTITY = hashlib.sha256(f"{V2114_BASE}\0{FIXTURE_BUILD_ID}".encode()).hexdigest()[:16]
QUALIFIED_REGISTRY_SRC = f"assets/js/admin/tool-registry.js?v=p2k-2.11.4-{V2114_BASE[:12]}-{FIXTURE_IDENTITY}"
POC_CACHE_KEY = "poc-1512a6fa0cd9-20260908-r4"
POC_TOKEN = f"p2k_trophy_poc={POC_CACHE_KEY}"
BEGIN = "/* P2K_TROPHY_GALLERY_POC_OVERLAY_BEGIN */"
END = "/* P2K_TROPHY_GALLERY_POC_OVERLAY_END */"
SENTINEL = "return Object.freeze({ tools, renderTools, routeFallback });"


DUMMY_PAYLOAD = '''(() => {
"use strict";
const STORAGE_KEY = "p2k-trophy-gallery-poc-v1";
function isAdminVisible(){ return true; }
const tab = {dataset:{}};
tab.dataset.hallSubtab="trophies";
const p2kTrophyAdminPanel = {};
window.P2K_TROPHY_GALLERY_POC = Object.freeze({mount(){}});
})();
'''


def git_bytes(ref: str, path: str) -> bytes:
    return subprocess.run(["git", "show", f"{ref}:{path}"], cwd=ROOT, check=True, capture_output=True).stdout


def prepare_tree(tmp_path: Path) -> tuple[Path, bytes, bytes, Path]:
    root = tmp_path / "PromoteToKing"
    admin = root / "assets/js/admin"
    admin.mkdir(parents=True)
    (root / "ui-v2.html").write_bytes(git_bytes(V2114_BASE, "ui-v2.html"))
    (admin / "tool-registry.js").write_bytes(git_bytes(V2114_BASE, "assets/js/admin/tool-registry.js"))
    subprocess.run(
        ["python3", str(ROOT / "tools/release/static_asset_cache_key.py"), "stamp", "--root", str(root), "--version", "2.11.4", "--source-head", V2114_BASE, "--build-id", FIXTURE_BUILD_ID],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    assert current_registry_src(root) == QUALIFIED_REGISTRY_SRC
    payload = tmp_path / "dummy-trophy-gallery-poc.js"
    payload.write_text(DUMMY_PAYLOAD, encoding="utf-8")
    return (
        root,
        (root / "ui-v2.html").read_bytes(),
        (admin / "tool-registry.js").read_bytes(),
        payload,
    )


def invoke(root: Path, payload: Path, action: str = "install", installer: Path = INSTALLER) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env["P2K_TROPHY_POC_SOURCE_FILE"] = str(payload)
    return subprocess.run(
        ["bash", str(installer), str(root), action],
        env=env,
        text=True,
        capture_output=True,
        timeout=30,
    )


def current_registry_src(root: Path) -> str:
    ui = (root / "ui-v2.html").read_text(encoding="utf-8")
    marker = 'src="assets/js/admin/tool-registry.js'
    start = ui.index(marker) + len('src="')
    return ui[start : ui.index('"', start)]


def test_overlay_install_reinstall_remove_preserves_qualified_fingerprint(tmp_path: Path):
    root, ui_before, registry_before, payload = prepare_tree(tmp_path)

    first = invoke(root, payload)
    assert first.returncode == 0, first.stdout + first.stderr
    src = current_registry_src(root)
    assert src.startswith(QUALIFIED_REGISTRY_SRC)
    assert src == f"{QUALIFIED_REGISTRY_SRC}&{POC_TOKEN}"
    registry = (root / "assets/js/admin/tool-registry.js").read_text(encoding="utf-8")
    assert registry.count(BEGIN) == 1
    assert registry.count(END) == 1
    assert (root / "assets/js/admin/trophy-gallery-poc.js").is_file()

    state = json.loads((root / ".p2k-poc-state/trophy-gallery-overlay.json").read_text(encoding="utf-8"))
    assert state["overlay_version"] == 4
    assert state["source_commit"] == RUNTIME_COMMIT
    assert state["original_registry_src"] == QUALIFIED_REGISTRY_SRC

    second = invoke(root, payload)
    assert second.returncode == 0, second.stdout + second.stderr
    assert current_registry_src(root) == f"{QUALIFIED_REGISTRY_SRC}&{POC_TOKEN}"
    registry = (root / "assets/js/admin/tool-registry.js").read_text(encoding="utf-8")
    assert registry.count(BEGIN) == 1
    assert registry.count(END) == 1

    removed = invoke(root, payload, "--remove")
    assert removed.returncode == 0, removed.stdout + removed.stderr
    assert (root / "ui-v2.html").read_bytes() == ui_before
    assert (root / "assets/js/admin/tool-registry.js").read_bytes() == registry_before
    assert not (root / "assets/js/admin/trophy-gallery-poc.js").exists()
    assert not (root / ".p2k-poc-state/trophy-gallery-overlay.json").exists()


def test_overlay_upgrades_legacy_v1_without_losing_qualified_registry_url(tmp_path: Path):
    root, ui_before, registry_before, payload = prepare_tree(tmp_path)
    legacy_registry = registry_before.decode().replace(
        SENTINEL,
        f'''{BEGIN}\nfunction oldTrophyLoader(){{}}\n{END}\n{SENTINEL}''',
        1,
    )
    (root / "assets/js/admin/tool-registry.js").write_text(legacy_registry, encoding="utf-8")
    (root / "assets/js/admin/trophy-gallery-poc.js").write_text(DUMMY_PAYLOAD, encoding="utf-8")

    upgraded = invoke(root, payload)
    assert upgraded.returncode == 0, upgraded.stdout + upgraded.stderr
    assert current_registry_src(root) == f"{QUALIFIED_REGISTRY_SRC}&{POC_TOKEN}"
    registry = (root / "assets/js/admin/tool-registry.js").read_text(encoding="utf-8")
    assert registry.count(BEGIN) == 1
    assert "oldTrophyLoader" not in registry

    removed = invoke(root, payload, "--remove")
    assert removed.returncode == 0, removed.stdout + removed.stderr
    assert (root / "ui-v2.html").read_bytes() == ui_before
    assert (root / "assets/js/admin/tool-registry.js").read_bytes() == registry_before
    assert not (root / "assets/js/admin/trophy-gallery-poc.js").exists()


def test_overlay_upgrades_r2_replaced_v_key_back_to_qualified_fingerprint(tmp_path: Path):
    r2_src = "assets/js/admin/tool-registry.js?v=poc-aed6ad0fcebd-20260905-r2"
    root, ui_before_r2, registry_before, payload = prepare_tree(tmp_path)
    ui_path = root / "ui-v2.html"
    ui_path.write_text(ui_path.read_text(encoding="utf-8").replace(QUALIFIED_REGISTRY_SRC, r2_src), encoding="utf-8")
    r2_registry = registry_before.decode().replace(
        SENTINEL,
        f'''{BEGIN}\nfunction r2TrophyLoader(){{}}\n{END}\n{SENTINEL}''',
        1,
    )
    registry_path = root / "assets/js/admin/tool-registry.js"
    registry_path.write_text(r2_registry, encoding="utf-8")
    (root / "assets/js/admin/trophy-gallery-poc.js").write_text(DUMMY_PAYLOAD, encoding="utf-8")
    state_dir = root / ".p2k-poc-state"
    state_dir.mkdir(parents=True)
    (state_dir / "trophy-gallery-overlay.json").write_text(
        json.dumps(
            {
                "overlay_version": 2,
                "source_commit": "aed6ad0fcebd95e0dd176f3eeb33e9d30b714505",
                "cache_key": "poc-aed6ad0fcebd-20260905-r2",
                "original_registry_src": QUALIFIED_REGISTRY_SRC,
                "original_poc_existed": False,
            }
        ),
        encoding="utf-8",
    )

    upgraded = invoke(root, payload)
    assert upgraded.returncode == 0, upgraded.stdout + upgraded.stderr
    assert current_registry_src(root) == f"{QUALIFIED_REGISTRY_SRC}&{POC_TOKEN}"

    removed = invoke(root, payload, "--remove")
    assert removed.returncode == 0, removed.stdout + removed.stderr
    assert current_registry_src(root) == QUALIFIED_REGISTRY_SRC
    assert not (root / "assets/js/admin/trophy-gallery-poc.js").exists()
    assert ui_before_r2 == (root / "ui-v2.html").read_bytes()


def test_overlay_upgrades_current_r3_to_r4_then_restores_exact_v2114(tmp_path: Path):
    root, ui_before, registry_before, payload = prepare_tree(tmp_path)
    r3_installer = tmp_path / "trophy-r3.run"
    r3_installer.write_bytes(git_bytes(R3_HEAD, "tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"))
    r3_installer.chmod(0o755)

    installed_r3 = invoke(root, payload, installer=r3_installer)
    assert installed_r3.returncode == 0, installed_r3.stdout + installed_r3.stderr
    assert "p2k_trophy_poc=poc-aed6ad0fcebd-20260905-r3" in current_registry_src(root)

    upgraded = invoke(root, payload)
    assert upgraded.returncode == 0, upgraded.stdout + upgraded.stderr
    assert current_registry_src(root) == f"{QUALIFIED_REGISTRY_SRC}&{POC_TOKEN}"
    state = json.loads((root / ".p2k-poc-state/trophy-gallery-overlay.json").read_text(encoding="utf-8"))
    assert state["overlay_version"] == 4 and state["source_commit"] == RUNTIME_COMMIT

    removed = invoke(root, payload, "--remove")
    assert removed.returncode == 0, removed.stdout + removed.stderr
    assert (root / "ui-v2.html").read_bytes() == ui_before
    assert (root / "assets/js/admin/tool-registry.js").read_bytes() == registry_before
    assert not (root / "assets/js/admin/trophy-gallery-poc.js").exists()


def test_failed_r4_payload_validation_rolls_back_exactly(tmp_path: Path):
    root, ui_before, registry_before, _ = prepare_tree(tmp_path)
    invalid = tmp_path / "invalid.js"
    invalid.write_text("window.invalidTrophyPayload = true;\n", encoding="utf-8")
    failed = invoke(root, invalid)
    assert failed.returncode != 0
    assert (root / "ui-v2.html").read_bytes() == ui_before
    assert (root / "assets/js/admin/tool-registry.js").read_bytes() == registry_before
    assert not (root / "assets/js/admin/trophy-gallery-poc.js").exists()
