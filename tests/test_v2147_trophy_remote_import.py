from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STORE = (ROOT / "server/trophy-gallery/src/TrophyGalleryStore.php").read_text(encoding="utf-8")
IMPORTER = (ROOT / "server/trophy-gallery/src/TrophyRemoteArtworkImporter.php").read_text(encoding="utf-8")
API = (ROOT / "server/trophy-gallery/public/api.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "assets/js/admin/trophy-gallery-admin-v2121.js").read_text(encoding="utf-8")
VIEW = (ROOT / "assets/js/admin/trophy-gallery-admin-view-v2121.js").read_text(encoding="utf-8")


def test_external_urls_are_imported_to_managed_media():
    assert "function importUrlAndAssign(" in STORE
    assert "'source'=>'external_url'" in STORE
    assert "'source_url'=>$url" in STORE
    assert "$c['records'][$i][$field]=$id" in STORE
    assert "import-url" in API
    assert "importExternalUrls" in ADMIN
    assert "req('import-url'" in ADMIN
    assert "Image URL (imported locally on save)" in VIEW


def test_managed_media_wins_after_successful_import():
    fn = ADMIN.split("function managedMediaUrl", 1)[1].split("function legacyUrl", 1)[0]
    assert 'slot+"_media_id"' in fn
    assert 'slot+"_url"' in fn
    assert fn.index('slot+"_media_id"') < fn.index('slot+"_url"')


def test_remote_fetch_is_ssrf_hardened_and_bounded():
    for token in [
        "FILTER_FLAG_NO_PRIV_RANGE",
        "FILTER_FLAG_NO_RES_RANGE",
        "CURLOPT_RESOLVE",
        "CURLOPT_PROXY=>''",
        "CURLOPT_FOLLOWLOCATION=>false",
        "MAX_REDIRECTS = 4",
        "MAX_BYTES = 10485760",
        "MAX_DIMENSION = 6000",
        "getimagesizefromstring",
        "image/png",
        "image/jpeg",
        "image/webp",
    ]:
        assert token in IMPORTER
    assert "Remote artwork URL must not contain credentials." in IMPORTER
    assert "unsupported port" in IMPORTER


def test_external_url_is_provenance_not_public_render_dependency():
    assert "'vignette_media_id'" in STORE
    assert "'modal_media_id'" in STORE
    assert "source_url" in STORE
