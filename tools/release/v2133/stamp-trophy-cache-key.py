#!/usr/bin/env python3
from pathlib import Path
import re,sys

root=Path(sys.argv[1])
key=sys.argv[2]
ui=root/"ui-v2.html"
registry=root/"assets/js/admin/tool-registry.js"

ui_text=ui.read_text(encoding="utf-8")
for asset in ("assets/js/admin/admin-shell.js","assets/js/admin/tool-registry.js"):
    pattern=re.compile(rf'({re.escape(asset)}\?v=)[^"\'&]+')
    ui_text,n=pattern.subn(rf'\g<1>{key}',ui_text)
    if n!=1:
        raise SystemExit(f"Expected exactly one cache-key reference for {asset}, found {n}")
ui.write_text(ui_text,encoding="utf-8")

text=registry.read_text(encoding="utf-8")
text,n=re.subn(r'const TROPHY_RUNTIME_KEY = "[^"]+";',f'const TROPHY_RUNTIME_KEY = "{key}";',text)
if n!=1:
    raise SystemExit(f"Expected one TROPHY_RUNTIME_KEY assignment, found {n}")
registry.write_text(text,encoding="utf-8")
