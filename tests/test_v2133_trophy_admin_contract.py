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
    poc=read("assets/js/admin/trophy-gallery-poc.js")
    store=read("server/trophy-gallery/src/TrophyGalleryStore.php")
    assert "[r.id,r.title,r.league,r.competition,r.status,r.award_date]" in admin
    assert "Search existing trophies" in admin
    assert "data-v2121-admin-root" in admin
    assert "state.mutation.then" in admin
    assert "performSave" in admin
    assert "window.scrollTo({left:scroll.x,top:scroll.y,behavior:'auto'})" in admin
    assert "competition:rec.competition" in admin
    assert "pageSize:10" in admin
    assert "p2k-trophy-admin-table" in admin
    assert "data-v2121-page-info" in admin
    assert 'class="p2k-trophy-tools"' in admin
    assert "function normalizeArtworkControls(form)" in admin
    assert "function artworkCandidates(r,slot,form=null)" in admin
    assert "function openArtworkViewer(url" in admin
    assert "f.matches?.('[data-v2121-form]')" in poc
    assert "['id','status','title','league','competition','award_date']" in store

def test_v2133_trophy_mount_race_is_stale_host_safe():
    admin=read("assets/js/admin/trophy-gallery-admin-v2121.js")
    assert "function isCurrentHost(host)" in admin
    assert "function currentHostMounted()" in admin
    assert "function queueRemount(host)" in admin
    assert "if(currentHostMounted())return" in admin
    assert '$$("[data-v2121-select]",list).forEach' in admin
    assert '$$("[data-v2121-row]",list).forEach' in admin
    assert "if(!isCurrentHost(host)||state.host!==host){queueRemount(host);return}" in admin
    assert "const filter=$(\"[data-v2121-filter]\",host),add=$(\"[data-v2121-new]\",host),editor=$(\"[data-v2121-editor]\",host)" in admin
    assert "if(!filter||!add||!editor)" in admin
    assert 'filter.oninput=()=>{state.page=1;renderList()}' in admin
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

def test_v2133_universal_installer_contract():
    build=read("tools/release/v2133/build-universal-2.13x.sh")
    installer=read("tools/release/v2133/install-promote-to-king-v2.13.3-universal.sh.in")
    workflow=read(".github/workflows/p2k-v2133-qualification.yml")
    package=read(".github/workflows/p2k-v2133-package.yml")
    assert 'PromoteToKing_v2.13.3_INCREMENTAL_FROM_2.13.x' in build
    for version in ("2.13.0", "2.13.1", "2.13.2", "2.13.3"):
        assert version in build
        assert version in installer
    assert "BASELINES.tsv" in build and "canonical-hash.py" in build
    assert "P2K_INSTALL_PREFLIGHT_ONLY" in installer
    assert "converge-v2.13.1.php" in installer and "converge-v2.13.2.php" in installer
    assert "test_v2133_universal_installer.sh" in workflow
    assert "browser_gate_trophy_admin_mount_race_v2133.py" in workflow
    assert "build-universal-2.13x.sh" in workflow and "build-universal-2.13x.sh" in package
    assert "082aab7d5b8b30547fb14bd8e6143aa84f74e105" in build

def test_v2133_release_identity_and_branch_qualification():
    assert read("VERSION").strip()=="2.13.3"
    workflow=read(".github/workflows/p2k-v2133-qualification.yml")
    package=read(".github/workflows/p2k-v2133-package.yml")
    assert "workflow_dispatch" in workflow and "branches: [release/v2.13.3]" in workflow.split("jobs:",1)[0]
    assert "workflow_dispatch" in package and "branches: [release/v2.13.3]" in package.split("jobs:",1)[0]
    assert "browser_gate_trophy_gallery_v2121.py" in workflow
    assert "test_v2133_trophy_admin_contract.py" in workflow
    assert "Full canonical P2K regression" in workflow
    assert "Full canonical P2K browser regression" in workflow
