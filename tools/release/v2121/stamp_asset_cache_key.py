#!/usr/bin/env python3
from pathlib import Path
import re, sys
root=Path(sys.argv[1]); key=sys.argv[2]
attr=re.compile(r'((?:src|href)=["\'][^"\']+\.(?:js|css)\?v=)[^"\'&]+', re.I)
p2k=re.compile(r'p2k-2\.12\.[01]-[0-9a-f]{12}-[0-9a-f]{16}')
for p in root.rglob('*'):
    if not p.is_file() or p.suffix.lower() not in {'.html','.htm','.js'}: continue
    try: text=p.read_text()
    except UnicodeDecodeError: continue
    new=attr.sub(lambda m:m.group(1)+key, text)
    new=p2k.sub(key,new)
    if new!=text: p.write_text(new)
