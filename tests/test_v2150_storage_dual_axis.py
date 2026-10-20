from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
JS=(ROOT/"assets/js/pages/team-points-admin.js").read_text(encoding="utf-8")
HTML=(ROOT/"TeamPointsAdmin.html").read_text(encoding="utf-8")

def test_storage_chart_uses_independent_database_and_filesystem_axes():
    block=JS[JS.index("function renderStorageChart"):JS.index("function renderProjection")]
    assert "const dbMax" in block
    assert "const fsMax" in block
    assert "const yDb" in block
    assert "const yFs" in block
    assert "path('filesystem_bytes',yFs)" in block
    assert "path('core_bytes',yDb)" in block
    assert "Filesystem</text>" in block
    assert "Databases</text>" in block

def test_storage_copy_explains_dual_scale():
    assert "own left-hand scale" in HTML
    assert "right-hand scale" in HTML
