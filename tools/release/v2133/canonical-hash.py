#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import re
import sys
from pathlib import Path

CACHE_PARAM = re.compile(r'([?&]v=)[^"\'&<>\s]+')
TROPHY_RUNTIME = re.compile(r'const\s+TROPHY_RUNTIME_KEY\s*=\s*"[^"]*"\s*;')
BOOTSTRAP_CACHE = re.compile(r'(assets/js/pages/(?:recruit-match-v2-core|recruit-match)\.js\?v=)[^"\']+')


def canonical_bytes(logical_path: str, raw: bytes) -> bytes:
    text = raw.decode("utf-8").replace("\r\n", "\n").replace("\r", "\n")
    if logical_path.lower().endswith((".html", ".htm")):
        text = CACHE_PARAM.sub(r'\1__P2K_CACHE__', text)
    if logical_path == "assets/js/admin/tool-registry.js":
        text = TROPHY_RUNTIME.sub('const TROPHY_RUNTIME_KEY = "__P2K_CACHE__";', text)
    if logical_path == "assets/js/pages/recruit-match-v2121-bootstrap.js":
        text = BOOTSTRAP_CACHE.sub(r'\1__P2K_CACHE__', text)
    return text.encode("utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--logical-path", required=True)
    parser.add_argument("file", nargs="?")
    args = parser.parse_args()
    raw = Path(args.file).read_bytes() if args.file else sys.stdin.buffer.read()
    print(hashlib.sha256(canonical_bytes(args.logical_path, raw)).hexdigest())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
