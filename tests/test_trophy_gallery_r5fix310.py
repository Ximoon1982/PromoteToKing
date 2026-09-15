from __future__ import annotations

import hashlib
import re
import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PATCH = ROOT / "assets/js/admin/trophy-gallery-r5fix3.10.js"


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_r5fix310_is_immutable_and_loaded_after_approved_r538_runtime():
    digest = hashlib.sha256(PATCH.read_bytes()).hexdigest()[:12]
    key = f"r5310-{digest}"
    ui = text("ui-v2.html")
    standalone = text("trophies/index.html")
    assert f'trophy-gallery-r5fix3.10.js?v={key}' in ui
    assert f'trophy-gallery-r5fix3.10.js?v={key}' in standalone
    assert ui.index("trophy-gallery-r5fix3.8.js?v=r538-d52193a71712") < ui.index("trophy-gallery-r5fix3.10.js")
    assert standalone.index("trophy-gallery-r5fix3.8.js?v=r538-d52193a71712") < standalone.index("trophy-gallery-r5fix3.10.js")
    assert "trophy-gallery-r5fix3.8.css?v=r538-fdcea54d62ba" in ui


def test_r5fix310_hardens_admin_preview_containment_without_changing_r538():
    js = PATCH.read_text(encoding="utf-8")
    for token in (
        '#adminShellNativeDetailHost[data-native-detail="trophy-gallery"]',
        ".p2k-media-preview",
        "position': 'relative'",
        "height': '280px'",
        "overflow': 'hidden'",
        "contain': 'layout paint'",
        "position': 'absolute'",
        "object-fit': 'contain'",
        "style.setProperty(property, value, 'important')",
        "MutationObserver",
        "preview.dataset.r5310Contained",
    ):
        assert token in js
    assert hashlib.sha256((ROOT / "assets/js/admin/trophy-gallery-r5fix3.8.js").read_bytes()).hexdigest().startswith("d52193a71712")
    assert hashlib.sha256((ROOT / "assets/trophy-gallery/trophy-gallery-r5fix3.8.css").read_bytes()).hexdigest().startswith("fdcea54d62ba")


def test_r5fix310_javascript_syntax():
    node = shutil.which("node")
    if not node:
        pytest.skip("Node.js unavailable")
    subprocess.run([node, "--check", str(PATCH)], check=True)


PACKAGE = ROOT / "tools/poc/trophy-gallery/r5fix3.10"


def test_r5fix310_package_manifest_and_syntax():
    manifest = {}
    for raw in (PACKAGE / "MANIFEST.sha256").read_text(encoding="utf-8").splitlines():
        digest, name = raw.split(None, 1)
        manifest[name.strip()] = digest
    assert set(manifest) == {
        "README.txt",
        "install-trophy-gallery-r5fix3.10.sh",
        "patch-loaders.py",
        "trophy-gallery-r5fix3.10.js",
    }
    for name, digest in manifest.items():
        assert hashlib.sha256((PACKAGE / name).read_bytes()).hexdigest() == digest
    assert (PACKAGE / "trophy-gallery-r5fix3.10.js").read_bytes() == PATCH.read_bytes()
    subprocess.run(["bash", "-n", str(PACKAGE / "install-trophy-gallery-r5fix3.10.sh")], check=True)
    subprocess.run([shutil.which("python3") or "python3", "-m", "py_compile", str(PACKAGE / "patch-loaders.py")], check=True)


def test_r5fix310_incremental_installer_is_idempotent(tmp_path):
    root = tmp_path / "p2k"
    for rel in (
        "VERSION",
        "ui-v2.html",
        "trophies/index.html",
        "assets/js/admin/trophy-gallery-r5fix3.8.js",
        "assets/trophy-gallery/trophy-gallery-r5fix3.8.css",
    ):
        dst = root / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        # This historical installer accepts the qualified 2.11.5 baseline only.
        dst.write_bytes(subprocess.check_output(
            ["git", "show", f"c534b2dbb0346eac0fa6de869621d6b7d785ead8:{rel}"], cwd=ROOT
        ))

    block = re.compile(
        r"\n?<!-- P2K_TROPHY_R5FIX310_BEGIN -->.*?<!-- P2K_TROPHY_R5FIX310_END -->\n?",
        re.S,
    )
    for rel in ("ui-v2.html", "trophies/index.html"):
        path = root / rel
        path.write_text(block.sub("\n", path.read_text(encoding="utf-8")), encoding="utf-8")

    installer = PACKAGE / "install-trophy-gallery-r5fix3.10.sh"
    subprocess.run([str(installer), str(root)], check=True, capture_output=True, text=True)
    subprocess.run([str(installer), str(root), "verify"], check=True, capture_output=True, text=True)

    tracked = [
        root / "ui-v2.html",
        root / "trophies/index.html",
        root / "assets/js/admin/trophy-gallery-r5fix3.10.js",
        root / "assets/js/admin/trophy-gallery-r5fix3.8.js",
        root / "assets/trophy-gallery/trophy-gallery-r5fix3.8.css",
    ]
    first = {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in tracked}
    subprocess.run([str(installer), str(root)], check=True, capture_output=True, text=True)
    second = {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in tracked}
    assert second == first
    assert first["assets/js/admin/trophy-gallery-r5fix3.8.js"].startswith("d52193a71712")
    assert first["assets/trophy-gallery/trophy-gallery-r5fix3.8.css"].startswith("fdcea54d62ba")


def test_r5fix310_does_not_compete_for_last_head_style():
    js = PATCH.read_text(encoding="utf-8")
    assert "head.lastElementChild" not in js
    assert "style.parentNode !== head" in js
