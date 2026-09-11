#!/usr/bin/env python3
from pathlib import Path
import argparse,re
BEGIN='<!-- P2K_TROPHY_R5FIX38_BEGIN -->'
END='<!-- P2K_TROPHY_R5FIX38_END -->'

def strip_known(text):
    for n in ('35','36','37','38'):
        text=re.sub(r'<!-- P2K_TROPHY_R5FIX'+n+r'_BEGIN -->.*?<!-- P2K_TROPHY_R5FIX'+n+r'_END -->','\n',text,flags=re.S)
    text=re.sub(r'\s*<script[^>]+trophy-gallery-r5fix3\.(?:3|4|5|6|7|8)\.js[^>]*></script>\s*','\n',text,flags=re.I)
    text=re.sub(r'\s*<link[^>]+trophy-gallery-r5fix3\.(?:3|4|5|6|7|8)\.css[^>]*>\s*','\n',text,flags=re.I)
    return text

def patch(path,js_key,css_key,standalone):
    original=path.read_text(encoding='utf-8')
    base=strip_known(original)
    if '</head>' not in base: raise SystemExit(str(path)+': </head> not found')
    prefix='../' if standalone else ''
    block=('\n'+BEGIN+'\n'
      +f'<link rel="stylesheet" href="{prefix}assets/trophy-gallery/trophy-gallery-r5fix3.8.css?v={css_key}">\n'
      +f'<script defer src="{prefix}assets/js/admin/trophy-gallery-r5fix3.8.js?v={js_key}"></script>\n'
      +END+'\n')
    patched=base.replace('</head>',block+'</head>',1)
    if patched.replace(block,'',1)!=base: raise SystemExit(str(path)+': loader patch escaped managed block')
    if patched.count(BEGIN)!=1 or patched.count(END)!=1: raise SystemExit(str(path)+': managed block count != 1')
    path.write_text(patched,encoding='utf-8')

ap=argparse.ArgumentParser(); ap.add_argument('--file',required=True); ap.add_argument('--js-key',required=True); ap.add_argument('--css-key',required=True); ap.add_argument('--standalone',action='store_true')
a=ap.parse_args(); patch(Path(a.file),a.js_key,a.css_key,a.standalone)
