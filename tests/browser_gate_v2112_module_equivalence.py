#!/usr/bin/env python3
"""Execute baseline and modular dashboards and compare their settled DOM/state."""

from __future__ import annotations

import json
import os
import shutil
import subprocess
from pathlib import Path

from playwright.sync_api import sync_playwright

from browser_startup_gate_v289 import DASH_BOOTSTRAP, clean_ui_html

ROOT = Path(__file__).resolve().parents[1]
BASELINE = "db5aecebd95067ba343f12b14be49380da2dc410"
CHROMIUM = os.environ.get("P2K_CHROMIUM") or shutil.which("chromium")
MODULES = (
    "assets/js/admin/admin-shell.js",
    "assets/js/admin/admin-session-controller.js",
    "assets/js/admin/embedded-detail-host.js",
    "assets/js/admin/tool-registry.js",
    "assets/js/dashboard/personal-home.js",
    "assets/js/dashboard/insights-controller.js",
    "assets/js/dashboard/team-summary.js",
    "assets/js/dashboard/match-assistant.js",
    "assets/js/dashboard/match-list-dialog.js",
    "assets/js/dashboard/dashboard-bootstrap.js",
    "assets/js/pages/dashboard-v2.js",
)


def baseline_dashboard() -> str:
    return subprocess.run(
        ["git", "show", f"{BASELINE}:assets/js/pages/dashboard-v2.js"],
        cwd=ROOT, check=True, text=True, capture_output=True,
    ).stdout


def settle(page) -> dict:
    page.wait_for_timeout(500)
    return page.evaluate("""() => ({
      body: document.body.innerHTML,
      status: document.getElementById('teamStatusBadge')?.textContent || '',
      members: document.getElementById('teamMembers')?.textContent || '',
      adminMode: window.P2K_ADMIN_MODE === true,
      adminHidden: document.getElementById('dashboardAdministrationTab')?.hidden ?? null,
      publicPage: new URL(location.href).searchParams.get('page') || 'dashboard'
    })""")


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True, executable_path=CHROMIUM,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        snapshots = {}
        errors = {}
        for mode in ("baseline", "modular"):
            page = browser.new_page()
            errors[mode] = []
            page.on("pageerror", lambda error, key=mode: errors[key].append(str(error)))
            page.set_content(clean_ui_html(), wait_until="domcontentloaded")
            page.add_script_tag(content=DASH_BOOTSTRAP)
            if mode == "baseline":
                page.add_script_tag(content=baseline_dashboard())
            else:
                for relative in MODULES:
                    page.add_script_tag(path=str(ROOT / relative))
            snapshots[mode] = settle(page)
            if mode == "modular":
                missing = page.evaluate("""() => [
                  'adminShell','adminSession','embeddedHost','adminTools',
                  'personalHome','insights','teamSummary','matchAssistant',
                  'matchListDialog','dashboardBootstrap'
                ].filter(name => typeof window.P2K_DASHBOARD_MODULES?.[name]?.create !== 'function')""")
                if missing:
                    raise AssertionError(f"Missing modular factories: {missing}")
            page.close()
        browser.close()
    # The historical fixture itself emits one invalid-URL error under about:blank.
    # It is acceptable only when the modular runtime reproduces the exact baseline
    # error set; any modular-only error remains a release-blocking regression.
    fixture_errors = ["Failed to construct 'URL': Invalid URL"]
    errors_match = errors["baseline"] == errors["modular"]
    errors_are_fixture_only = errors["baseline"] in ([], fixture_errors)
    if not errors_match or not errors_are_fixture_only or snapshots["baseline"] != snapshots["modular"]:
        comparable = {
            mode: {key: value for key, value in snapshot.items() if key != "body"}
            for mode, snapshot in snapshots.items()
        }
        comparable["body_equal"] = snapshots["baseline"]["body"] == snapshots["modular"]["body"]
        raise AssertionError(json.dumps({"errors": errors, "snapshots": comparable}, indent=2))
    print(json.dumps({
        "module_equivalence": "passed",
        "shared_fixture_errors": errors["baseline"],
        "modular_only_errors": [],
    }, indent=2))


if __name__ == "__main__":
    main()
