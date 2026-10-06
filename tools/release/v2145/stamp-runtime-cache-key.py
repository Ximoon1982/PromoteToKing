#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
key = sys.argv[2]

def stamp_asset(path: Path, asset: str) -> None:
    text = path.read_text(encoding="utf-8")
    pattern = re.compile(rf'({re.escape(asset)}\?v=)[^"\']+')
    text, count = pattern.subn(rf'\g<1>{key}', text)
    if count != 1:
        raise SystemExit(f"Expected one cache-key reference for {asset} in {path}, found {count}")
    path.write_text(text, encoding="utf-8")

registry = root / "assets/js/admin/tool-registry.js"
text = registry.read_text(encoding="utf-8")
text, count = re.subn(
    r'const\s+TROPHY_RUNTIME_KEY\s*=\s*"[^"]*"\s*;',
    f'const TROPHY_RUNTIME_KEY = "{key}";',
    text,
)
if count != 1:
    raise SystemExit(f"Expected one TROPHY_RUNTIME_KEY assignment, found {count}")
registry.write_text(text, encoding="utf-8")

bootstrap = root / "assets/js/pages/recruit-match-v2121-bootstrap.js"
for asset in ("assets/js/pages/recruit-match-v2-core.js", "assets/js/pages/recruit-match.js"):
    stamp_asset(bootstrap, asset)

embed = root / "server/events-showcase/public/embed.php"
for asset in (
    "assets/js/shared/recruitment-lineup-core.js",
    "assets/js/shared/events-showcase-core.js",
    "assets/css/events-showcase-line-v2122.css",
    "assets/js/events-showcase-line-v2122.js",
):
    stamp_asset(embed, asset)

card = root / "server/events-showcase/public/embed-card.php"
for asset in (
    "assets/js/shared/recruitment-lineup-core.js",
    "assets/js/shared/events-showcase-core.js",
):
    stamp_asset(card, asset)
