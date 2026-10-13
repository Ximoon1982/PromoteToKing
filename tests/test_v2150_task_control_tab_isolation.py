from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_team_points_tab_hard_hides_scheduled_task_surfaces():
    js = read("assets/js/pages/task-control.js")
    css = read("assets/css/task-control.css")
    assert 'section.classList.toggle("is-task-surface-hidden",inactive);' in js
    assert 'trackedHistory.classList.toggle("is-task-surface-hidden",green);' in js
    assert 'if(green)trackedHistory.hidden=true;' in js
    assert ".is-task-surface-hidden" in css
    assert "display: none !important;" in css


def test_task_control_uses_v2150_cache_keys():
    html = read("TaskControl.html")
    key = "p2k-2.15.0-task-tab-isolation-1"
    assert f"assets/css/task-control.css?v={key}" in html
    assert f"assets/js/pages/task-control.js?v={key}" in html
