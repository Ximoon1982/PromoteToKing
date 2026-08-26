#!/usr/bin/env python3
# Restore P2K large canonical files stored as repository chunks.
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / ".p2k" / "large-files" / "manifest.json"


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for block in iter(lambda: f.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def main() -> int:
    obj = json.loads(MANIFEST.read_text("utf-8"))
    for item in obj["files"]:
        target = ROOT.joinpath(*item["target"].split("/"))
        if target.is_file() and target.stat().st_size == item["size"] and sha256_file(target) == item["sha256"]:
            print(f"PASS already restored: {item['target']}")
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        tmp = target.with_name(target.name + ".p2k-restoring")
        h = hashlib.sha256()
        total = 0
        with tmp.open("wb") as dst:
            for chunk in item["chunks"]:
                cp = ROOT.joinpath(*chunk["path"].split("/"))
                data = cp.read_bytes()
                if len(data) != chunk["size"]:
                    raise SystemExit(f"Chunk size mismatch: {chunk['path']}")
                got = hashlib.sha256(data).hexdigest()
                if got != chunk["sha256"]:
                    raise SystemExit(f"Chunk hash mismatch: {chunk['path']}")
                dst.write(data)
                h.update(data)
                total += len(data)
        if total != item["size"] or h.hexdigest() != item["sha256"]:
            tmp.unlink(missing_ok=True)
            raise SystemExit(f"Restored file verification failed: {item['target']}")
        os.replace(tmp, target)
        print(f"RESTORED {item['target']} ({total} bytes, {h.hexdigest()})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
