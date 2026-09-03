"""Composed source views for historical static contract assertions.

Production executes classic scripts separately in the entrypoint order. Historical
tests predate the v2.11.2 split and intentionally assert implementation anchors that
now span those files. These helpers expose the complete active source without making
the production compatibility facades monolithic again.
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

DASHBOARD_PARTS = (
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

ADMIN_PARTS = (
    "assets/js/admin/admin-runtime.js",
    "assets/js/admin/logs-controller.js",
    "assets/js/admin/diagnostics-controller.js",
    "assets/js/admin/history-controller.js",
    "assets/js/admin/match-management.js",
    "assets/js/admin/recording-controller.js",
    "assets/js/pages/admin-features.js",
)


def composed_source(parts: tuple[str, ...]) -> str:
    return "\n".join((ROOT / relative).read_text(encoding="utf-8") for relative in parts)


def dashboard_source() -> str:
    return composed_source(DASHBOARD_PARTS)


def admin_source() -> str:
    return composed_source(ADMIN_PARTS)
