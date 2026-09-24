#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
from pathlib import Path

root = Path(sys.argv[1])
key = sys.argv[2]

registry = root / "assets/js/admin/tool-registry.js"
text = registry.read_text(encoding="utf-8")
text, n = re.subn(
    r'const\s+TROPHY_RUNTIME_KEY\s*=\s*"[^"]*"\s*;',
    f'const TROPHY_RUNTIME_KEY = "{key}";',
    text,
)
if n != 1:
    raise SystemExit(f"Expected one TROPHY_RUNTIME_KEY assignment, found {n}")
registry.write_text(text, encoding="utf-8")

bootstrap = root / "assets/js/pages/recruit-match-v2121-bootstrap.js"
text = bootstrap.read_text(encoding="utf-8")
for asset in ("assets/js/pages/recruit-match-v2-core.js", "assets/js/pages/recruit-match.js"):
    pattern = re.compile(rf'({re.escape(asset)}\?v=)[^"\']+')
    text, n = pattern.subn(rf'\g<1>{key}', text)
    if n != 1:
        raise SystemExit(f"Expected one cache-key reference for {asset}, found {n}")
bootstrap.write_text(text, encoding="utf-8")
