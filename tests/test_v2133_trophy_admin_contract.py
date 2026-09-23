from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path):
    return (ROOT/path).read_text(encoding="utf-8")

def test_v2133_trophy_admin_has_single_canonical_owner():
    shell=read("assets/js/admin/admin-shell.js")
    poc=read("assets/js/admin/trophy-gallery-poc.js")
    registry=read("assets/js/admin/tool-registry.js")
    assert "P2K_TROPHY_GALLERY_POC?.mountAdmin" not in shell
    assert "P2K_TROPHY_ADMIN_V2121?.mount?.()" in shell
    assert "async function mountAdmin(_host){window.P2K_TROPHY_ADMIN_V2121?.mount?.()}" in poc
    assert "function mountActiveAdmin(){window.P2K_TROPHY_ADMIN_V2121?.mount?.()}" in poc
    assert "if(root.querySelector('[data-v2121-list]'))return;" in poc
    assert "window.P2K_TROPHY_ADMIN_V2121?.mount?.();" in registry

def test_v2133_trophy_lookup_and_in_place_save_contract():
    admin=read("assets/js/admin/trophy-gallery-admin-v2121.js")
    store=read("server/trophy-gallery/src/TrophyGalleryStore.php")
    assert "[r.id,r.title,r.league,r.competition,r.status,r.award_date]" in admin
    assert "Search existing trophies" in admin
    assert "data-v2121-admin-root" in admin
    assert "state.mutation.then" in admin
    assert "performSave" in admin
    assert "window.scrollTo({left:scroll.x,top:scroll.y,behavior:'auto'})" in admin
    assert "competition:rec.competition" in admin
    assert "['id','status','title','league','competition','award_date']" in store

def test_v2133_pre_save_artwork_and_engraver_cleanup_contract():
    admin=read("assets/js/admin/trophy-gallery-admin-v2121.js")
    engraver=read("assets/js/admin/trophy-gallery-engraver-v2121.js")
    assert "state.pending[slot]=file" in admin
    assert "await uploadPending(out.record.id)" in admin
    assert "Save the Trophy before assigning artwork." not in admin
    assert "Save the Trophy before creating artwork." not in admin
    assert 'classList.add("p2k-engraver-open")' in engraver
    assert 'classList.remove("p2k-engraver-open")' in engraver
    assert "window.addEventListener('p2k-admin-shell-route'" in engraver
    assert "window.addEventListener('pagehide',close)" in engraver

def test_v2133_release_identity_and_manual_validation_handoff():
    assert read("VERSION").strip()=="2.13.3"
    workflow=read(".github/workflows/p2k-v2133-qualification.yml")
    package=read(".github/workflows/p2k-v2133-package.yml")
    assert "workflow_dispatch" in workflow and "branches:" not in workflow.split("jobs:",1)[0]
    assert "workflow_dispatch" in package and "branches:" not in package.split("jobs:",1)[0]
    assert "browser_gate_trophy_gallery_v2121.py" in workflow
    assert "test_v2133_trophy_admin_contract.py" in workflow
    assert "Full canonical P2K regression" in workflow
    assert "Full canonical P2K browser regression" in workflow
