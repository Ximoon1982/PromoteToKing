#!/usr/bin/env python3
from pathlib import Path
import re,sys
root=Path(sys.argv[1]);key=sys.argv[2]
attr=re.compile(r'((?:src|href)=["\'][^"\']+\.(?:js|css)\?v=)[^"\'&]+',re.I)
standard=re.compile(r'p2k-2\.12\.[0-2]-[0-9a-f]{12}-[0-9a-f]{16}')
source=re.compile(r'p2k-2\.12\.2-src-[0-9a-f]{16}')
for p in root.rglob('*'):
    if not p.is_file() or p.suffix.lower() not in {'.html','.htm','.js','.php'}:continue
    try:text=p.read_text()
    except UnicodeDecodeError:continue
    new=attr.sub(lambda m:m.group(1)+key,text)
    new=standard.sub(key,new);new=source.sub(key,new)
    if new!=text:p.write_text(new)
