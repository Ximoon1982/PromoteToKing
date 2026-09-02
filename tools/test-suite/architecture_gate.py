#!/usr/bin/env python3
"""Static architecture and reference integrity gate for v2.11.2."""

from __future__ import annotations

import json
import re
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def dependency_errors() -> list[str]:
    data = json.loads((ROOT / "tests/v2.11.2-frontend-boundaries.json").read_text(encoding="utf-8"))
    layers, modules = data["layers"], data["modules"]
    errors: list[str] = []
    for name, module in modules.items():
        if not (ROOT / module["path"]).is_file(): errors.append(f"missing JS module: {module['path']}")
        for dependency in module["depends_on"]:
            if dependency not in modules: errors.append(f"unknown dependency: {name} -> {dependency}"); continue
            if layers[modules[dependency]["layer"]] > layers[module["layer"]]: errors.append(f"upward dependency: {name} -> {dependency}")
    visiting: set[str] = set()
    visited: set[str] = set()
    def visit(name: str) -> None:
        if name in visiting: errors.append(f"circular dependency at {name}"); return
        if name in visited: return
        visiting.add(name)
        for dependency in modules[name]["depends_on"]: visit(dependency)
        visiting.remove(name); visited.add(name)
    for name in modules: visit(name)
    return errors


def php_errors() -> list[str]:
    names: list[str] = []
    errors: list[str] = []
    for path in (ROOT / "server").rglob("*.php"):
        source = path.read_text(encoding="utf-8", errors="ignore")
        namespace = re.search(r"namespace\s+([^;]+);", source)
        for kind, name in re.findall(r"^\s*(?:final\s+)?(class|interface|trait|enum)\s+(\w+)", source, re.M):
            names.append(((namespace.group(1) + "\\") if namespace else "") + name)
    errors.extend(f"duplicate PHP type: {name}" for name, count in Counter(names).items() if count > 1)
    for facade, component in {"ClubIntelligenceService.php":"SqlReadGateway", "AnalyticsBuilder.php":"AnalyticsRefreshRuntime", "AchievementCatalog.php":"AchievementArtwork"}.items():
        if component not in (ROOT / "server/team-points/src" / facade).read_text(encoding="utf-8"): errors.append(f"facade delegation missing: {facade} -> {component}")
    return errors


def reference_errors() -> list[str]:
    errors: list[str] = []
    for html in ROOT.glob("*.html"):
        source = html.read_text(encoding="utf-8", errors="ignore")
        for reference in re.findall(r"<(?:script|link)[^>]+(?:src|href)=[\"']([^\"'?#]+)", source, re.I):
            if reference.startswith(("http://", "https://", "//")): continue
            if reference.startswith("/PromoteToKing/"):
                target = ROOT / reference.removeprefix("/PromoteToKing/")
            elif reference.startswith("/"):
                target = ROOT / reference.lstrip("/")
            else:
                target = (html.parent / reference).resolve()
            if ROOT not in target.parents and target != ROOT: errors.append(f"unsafe HTML reference: {html.name}: {reference}")
            elif not target.is_file(): errors.append(f"missing HTML reference: {html.name}: {reference}")
    return errors


def main() -> int:
    errors = dependency_errors() + php_errors() + reference_errors()
    if errors:
        print("\n".join(errors)); return 1
    print("v2.11.2 architecture boundaries and references passed.")
    return 0


if __name__ == "__main__": raise SystemExit(main())
