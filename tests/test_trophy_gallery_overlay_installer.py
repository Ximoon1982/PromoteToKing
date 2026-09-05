from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "tools/poc/PromoteToKing_TrophyGallery_POC_2.11x.run"
QUALIFIED_REGISTRY_SRC = (
    "assets/js/admin/tool-registry.js?"
    "v=p2k-2.11.3-dcd71c8e76c0-f2065dcf0f2da32e"
)
POC_TOKEN = "p2k_trophy_poc=poc-aed6ad0fcebd-20260905-r3"
BEGIN = "/* P2K_TROPHY_GALLERY_POC_OVERLAY_BEGIN */"
END = "/* P2K_TROPHY_GALLERY_POC_OVERLAY_END */"
SENTINEL = "return Object.freeze({ tools, renderTools, routeFallback });"


REGISTRY_BASELINE = f'''(() => {{
"use strict";
window.P2K_DASHBOARD_MODULES = window.P2K_DASHBOARD_MODULES || {{}};
window.P2K_DASHBOARD_MODULES.adminTools = Object.freeze({{create(context) {{
const tools = [];
function renderTools() {{}}
function routeFallback(key) {{ return "index.html"; }}
{SENTINEL}
}}}});
}})();
'''


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


def prepare_tree(tmp_path: Path, registry_src: str = QUALIFIED_REGISTRY_SRC) -> tuple[Path, bytes, bytes, Path]:
    root = tmp_path / "PromoteToKing"
    admin = root / "assets/js/admin"
    admin.mkdir(parents=True)
    ui = (
        '<!doctype html>\n'
        '<meta name="p2k-test-version" content="2.11.3">\n'
        f'<script defer src="{registry_src}"></script>\n'
    )
    (root / "ui-v2.html").write_text(ui, encoding="utf-8")
    (admin / "tool-registry.js").write_text(REGISTRY_BASELINE, encoding="utf-8")
    payload = tmp_path / "dummy-trophy-gallery-poc.js"
    payload.write_text(DUMMY_PAYLOAD, encoding="utf-8")
    return (
        root,
        (root / "ui-v2.html").read_bytes(),
        (admin / "tool-registry.js").read_bytes(),
        payload,
    )


def invoke(root: Path, payload: Path, action: str = "install") -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env["P2K_TROPHY_POC_SOURCE_FILE"] = str(payload)
    return subprocess.run(
        ["bash", str(INSTALLER), str(root), action],
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
    assert state["overlay_version"] == 3
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
    legacy_registry = REGISTRY_BASELINE.replace(
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
    root, ui_before_r2, _, payload = prepare_tree(tmp_path, r2_src)
    r2_registry = REGISTRY_BASELINE.replace(
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
    assert ui_before_r2 != (root / "ui-v2.html").read_bytes()
