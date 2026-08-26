#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, shutil
from pathlib import Path
import PIL
from PIL import Image

EXPECTED_PILLOW = "12.3.0"
ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "POST_v2.10.6.24_MATCHES_ARTWORK_INTEGRATION.json"

def sha256(path: Path) -> str:
    h=hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda:f.read(1024*1024), b''): h.update(chunk)
    return h.hexdigest()

def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument('--verify-only', action='store_true')
    args=ap.parse_args()
    if PIL.__version__ != EXPECTED_PILLOW:
        raise SystemExit(f"Pillow {EXPECTED_PILLOW} required for byte-reproducible WebP derivatives; found {PIL.__version__}")
    data=json.loads(MANIFEST.read_text('utf-8'))
    changed=False
    for item in data['items']:
        master=ROOT/item['master']; thumb=ROOT/item['thumb_128']; mini=ROOT/item['mini_64']; legacy=ROOT/item['mini_legacy']
        if not args.verify_only:
            thumb.parent.mkdir(parents=True,exist_ok=True); mini.parent.mkdir(parents=True,exist_ok=True); legacy.parent.mkdir(parents=True,exist_ok=True)
            with Image.open(master) as src:
                src=src.convert('RGB')
                src.resize((128,128),Image.Resampling.LANCZOS).save(thumb,'WEBP',quality=92,method=6)
                src.resize((64,64),Image.Resampling.LANCZOS).save(mini,'WEBP',quality=92,method=6)
            shutil.copyfile(mini,legacy)
        for path,size in [(master,(640,640)),(thumb,(128,128)),(mini,(64,64)),(legacy,(64,64))]:
            if not path.is_file(): raise SystemExit(f"Missing artwork file: {path.relative_to(ROOT)}")
            with Image.open(path) as im:
                if im.size != size: raise SystemExit(f"Wrong dimensions for {path.relative_to(ROOT)}: {im.size}, expected {size}")
        fields=[('master_sha256',master),('thumb_128_sha256',thumb),('mini_64_sha256',mini),('mini_legacy_sha256',legacy)]
        for field,path in fields:
            digest=sha256(path)
            if args.verify_only:
                if item.get(field)!=digest: raise SystemExit(f"SHA mismatch {path.relative_to(ROOT)}: {digest} != {item.get(field)}")
            elif item.get(field)!=digest:
                item[field]=digest; changed=True
        if mini.read_bytes()!=legacy.read_bytes(): raise SystemExit(f"Legacy mini alias differs for {item['key']}")
    if not args.verify_only and changed:
        MANIFEST.write_text(json.dumps(data,indent=2,ensure_ascii=False)+"\n",'utf-8')
    print(f"Matches artwork derivatives: PASS ({len(data['items'])} masters; Pillow {PIL.__version__})")
    return 0
if __name__=='__main__': raise SystemExit(main())
