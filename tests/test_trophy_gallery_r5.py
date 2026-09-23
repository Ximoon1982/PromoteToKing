from __future__ import annotations
import hashlib, json, shutil, subprocess
from pathlib import Path
import pytest

ROOT=Path(__file__).resolve().parents[1]
POC_SHA256="8c1308e10f343f95dda26628c83014a0c3102fe48afeb1253dcb5de6cd26d7b1"

def text(path):return (ROOT/path).read_text(encoding="utf-8")

def test_exact_engraving_poc_is_retained_and_integrated_assets_are_externalized():
    ref=ROOT/"tools/poc/engraving/reference/P2K_1WL_Engraving_POC_v1.9.1.html"
    assert hashlib.sha256(ref.read_bytes()).hexdigest()==POC_SHA256
    assert POC_SHA256 in text("tools/poc/engraving/README.md")
    editor=text("assets/trophy-gallery/engraving/editor.html")
    assert "data:image" not in editor and "data:font" not in editor
    assert "p2k-trophy-engraving" in editor
    for kind in ("medal","cup","crystal"):
        for finish in ("gold","silver","bronze"):
            assert (ROOT/f"assets/trophy-gallery/engraving/{kind}_{finish}.png").is_file()
    assert (ROOT/"assets/trophy-gallery/engraving/P2KFullSans.ttf").is_file()

def test_runtime_contract_has_persistence_filters_safe_markdown_and_no_old_model():
    js=text("assets/js/admin/trophy-gallery-poc.js")
    for token in ("award_date","description_md","award_page","competition_page","result_table_url","vignette_media_id","modal_media_id","Search match name…","Enlarge image","Open engraving editor","Undated"):
        assert token in js
    # v2.13.3 retires the obsolete POC-owned admin UI so it cannot race the
    # canonical v2.12.1 admin controller. Keep the legacy r4 data reader for
    # compatibility, but do not require its former independent admin button.
    assert "legacyImport" in js and "p2k-trophy-gallery-poc-v1" in js
    for forbidden in ("localStorage.setItem","players","source_url"):
        assert forbidden not in js
    assert "javascript:" not in js and "safeUrl" in js and "target=\"_blank\" rel=\"noopener noreferrer\"" in js

def test_admin_shell_uses_canonical_team_card_and_deep_link():
    shell=text("assets/js/admin/admin-shell.js");navigation=text("assets/js/pages/dashboard-v2.js");js=text("assets/js/admin/trophy-gallery-poc.js")
    assert 'trophies: { title:"Trophy Gallery"' in shell
    assert 'nativeKey:"trophy-gallery"' in shell
    assert 'const trophyCompatibilityRoute = params.get("trophy") === "1";' in text("assets/js/pages/dashboard-v2.js")
    assert 'const requestedView = trophyCompatibilityRoute ||' in text("assets/js/pages/dashboard-v2.js")
    assert 'window.addEventListener("p2k-admin-shell-route"' in js
    assert 'mountActiveAdmin()' in js
    assert 'adminShellCard({key:"trophies",category:"team"' in shell
    assert 'window.P2K_TROPHY_ADMIN_V2121?.mount?.()' in shell
    assert 'window.P2K_TROPHY_GALLERY_POC?.mountAdmin?.(nativeHost)' not in shell
    assert 'async function mountAdmin(_host){window.P2K_TROPHY_ADMIN_V2121?.mount?.()}' in js
    assert 'adminDetail: trophyCompatibilityRoute ? "trophies"' in navigation

def test_backend_contract_is_bounded_owned_atomic_and_parameterized():
    store=text("server/trophy-gallery/src/TrophyGalleryStore.php");api=text("server/trophy-gallery/public/api.php")
    for token in ("SCHEMA_VERSION=2","revision","LOCK_EX","rename($tmp,$this->catalog)","owner_trophy_id","slot","source","uploadAndAssign","duplicate",".trash","orphan_media_ids"):
        assert token in store
    assert "is_uploaded_file" in store and "getimagesize" in store
    assert "basename($_" not in store and "../" not in store
    assert "Auth::requireAdmin()" in api and "$pdo->prepare($sql)" in api and "$statement->execute([$club,$like,$like,$like])" in api
    assert "ChessApi" not in api and "api.chess.com" not in api
    public_branch=api.split("Auth::requireAdmin();",1)[0]
    assert "action==='list'" in public_branch and "admin-list" not in public_branch

def test_catalog_crud_revision_public_redaction_and_undated(tmp_path):
    php=shutil.which("php")
    if not php:pytest.skip("PHP CLI unavailable")
    store=ROOT/"server/trophy-gallery/src/TrophyGalleryStore.php";script=tmp_path/"test.php"
    script.write_text("<?php\n"+f"require {json.dumps(str(store))};\n"+f"$s=new P2K\\TrophyGallery\\TrophyGalleryStore({json.dumps(str(tmp_path/'data'))});\n"+"$a=$s->save(['status'=>'draft','league'=>'OWL','title'=>'Draft','award_date'=>'','description_md'=>'<script>x</script>','migration_review'=>['review'],'matches'=>[['match_id'=>2,'name'=>'Two']]],0);$b=$s->save(['status'=>'published','league'=>'PCL','title'=>'Published','award_date'=>'2026-09-08','award_page'=>'https://example.test/a'],1);$public=$s->catalogue(true);try{$s->save(['status'=>'draft','league'=>'X','title'=>'Conflict'],0);$conflict=false;}catch(Throwable $e){$conflict=true;}echo json_encode(['a'=>$a,'b'=>$b,'public'=>$public,'conflict'=>$conflict]);",encoding="utf-8")
    result=json.loads(subprocess.run([php,str(script)],check=True,capture_output=True,text=True).stdout)
    assert result["a"]["revision"]==1 and result["b"]["revision"]==2 and result["conflict"]
    assert len(result["public"]["records"])==1
    row=result["public"]["records"][0]
    assert row["title"]=="Published" and "migration_review" not in row and "created_at" not in row and "status" not in row

def test_standalone_has_only_public_gallery_chrome():
    page=text("trophies/index.html")
    assert 'id="p2kTrophyStandalone"' in page
    assert "dashboardAdministrationTab" not in page and "OAuth" not in page and "login" not in page.lower()

def test_seed_preserves_r4_records_and_draft_without_invented_dates():
    seed=json.loads(text("server/trophy-gallery/resources/catalog.seed.json"))
    assert seed["schema_version"]==2 and len(seed["records"])==7
    assert sum(r["status"]=="published" for r in seed["records"])==6
    assert all(r["award_date"]=="" for r in seed["records"])
    assert all("players" not in r and "image" not in r for r in seed["records"])
