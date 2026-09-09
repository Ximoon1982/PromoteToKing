from __future__ import annotations

import hashlib
import json
from pathlib import Path
import shutil
import subprocess

import pytest


ROOT = Path(__file__).resolve().parents[1]
POC_SHA256 = "8c1308e10f343f95dda26628c83014a0c3102fe48afeb1253dcb5de6cd26d7b1"


def test_exact_engraving_poc_is_preserved_and_externalized_without_data_uris():
    provenance = (ROOT / "tools/poc/engraving/README.md").read_text(encoding="utf-8")
    assert POC_SHA256 in provenance
    editor = (ROOT / "assets/trophy-gallery/engraving/editor.html").read_text(encoding="utf-8")
    assert "data:image" not in editor
    assert "data:font" not in editor
    assert "p2k-trophy-engraving" in editor
    for kind in ("medal", "cup", "crystal"):
        for finish in ("gold", "silver", "bronze"):
            assert (ROOT / f"assets/trophy-gallery/engraving/{kind}_{finish}.png").is_file()
    assert (ROOT / "assets/trophy-gallery/engraving/P2KFullSans.ttf").is_file()


def test_runtime_uses_persistent_api_and_has_no_player_model_or_local_save():
    runtime = (ROOT / "assets/js/admin/trophy-gallery-poc.js").read_text(encoding="utf-8")
    assert 'server/trophy-gallery/public/api.php' in runtime
    assert 'endpointRequest(API' in runtime
    assert "localStorage.setItem" not in runtime
    assert "players" not in runtime
    assert 'mountAdmin' in runtime and 'mountPublic' in runtime
    assert 'operational_status' not in runtime


def test_admin_shell_uses_standard_native_team_detail_and_deep_link():
    shell = (ROOT / "assets/js/admin/admin-shell.js").read_text(encoding="utf-8")
    navigation = (ROOT / "assets/js/pages/dashboard-v2.js").read_text(encoding="utf-8")
    assert 'trophies: { title:"Trophy Gallery"' in shell
    assert 'nativeKey:"trophy-gallery"' in shell
    assert 'window.P2K_TROPHY_GALLERY_POC?.mountAdmin?.(nativeHost)' in shell
    assert 'params.get("trophy") === "1" ? "trophies"' in navigation


def test_catalog_store_executes_atomic_crud_and_published_filter(tmp_path: Path):
    php = shutil.which("php")
    if not php:
        pytest.skip("PHP CLI is not installed in this local runner")
    store = ROOT / "server/trophy-gallery/src/TrophyGalleryStore.php"
    script = tmp_path / "exercise.php"
    script.write_text(
        "<?php\n"
        f"require {json.dumps(str(store))};\n"
        f"$s=new P2K\\TrophyGallery\\TrophyGalleryStore({json.dumps(str(tmp_path / 'data'))});\n"
        "$draft=$s->save(['status'=>'draft','league'=>'OWL','competition'=>'Cup','award'=>'Gold','title'=>'Draft','award_date'=>'2026-09-09','description_md'=>'**safe**','source_url'=>'https://www.chess.com/','matches'=>[123,123,456]]);\n"
        "$published=$s->save(['status'=>'published','league'=>'OWL','competition'=>'Cup','award'=>'Gold','title'=>'Published','award_date'=>'2026-09-08','matches'=>[789]]);\n"
        "$before=['all'=>$s->records(false),'public'=>$s->records(true),'audit'=>$s->audit(false)];\n"
        "$s->delete($draft['id']);\n"
        "echo json_encode(['before'=>$before,'after'=>$s->records(false)]);\n",
        encoding="utf-8",
    )
    run = subprocess.run([php, str(script)], check=True, text=True, capture_output=True)
    result = json.loads(run.stdout)
    assert len(result["before"]["all"]) == 2
    assert [r["title"] for r in result["before"]["public"]] == ["Published"]
    assert result["before"]["all"][0]["matches"] in ([123, 456], [789])
    assert len(result["after"]) == 1
    catalog = tmp_path / "data/catalog.json"
    assert catalog.is_file() and not list((tmp_path / "data").glob("catalog.json.tmp.*"))


def test_public_standalone_contains_no_dashboard_or_auth_shell():
    page = (ROOT / "trophies/index.html").read_text(encoding="utf-8")
    assert 'id="p2kTrophyStandalone"' in page
    assert "dashboardAdministrationTab" not in page
    assert "OAuth" not in page and "login" not in page.lower()
