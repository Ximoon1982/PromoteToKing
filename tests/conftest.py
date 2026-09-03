"""Compatibility bridge for pre-modular static source assertions only."""

from __future__ import annotations

from pathlib import Path

from source_contract import ADMIN_PARTS, DASHBOARD_PARTS

_READ_TEXT = Path.read_text
_ROOT = Path(__file__).resolve().parents[1]
_TARGETS = {
    (_ROOT / "assets/js/pages/dashboard-v2.js").resolve(): DASHBOARD_PARTS,
    (_ROOT / "assets/js/pages/admin-features.js").resolve(): ADMIN_PARTS,
}


def _composed_read_text(path: Path, *args, **kwargs) -> str:
    parts = _TARGETS.get(path.resolve())
    if parts is None:
        return _READ_TEXT(path, *args, **kwargs)
    encoding = kwargs.get("encoding") or (args[0] if args else "utf-8")
    errors = kwargs.get("errors") or (args[1] if len(args) > 1 else None)
    return "\n".join(
        _READ_TEXT(_ROOT / relative, encoding=encoding, errors=errors)
        for relative in parts
    )


def pytest_configure() -> None:
    Path.read_text = _composed_read_text


def pytest_unconfigure() -> None:
    Path.read_text = _READ_TEXT
