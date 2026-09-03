#!/usr/bin/env python3
"""Stamp and verify immutable build-specific cache keys for qualified assets."""

from __future__ import annotations

import argparse
import hashlib
import re
from pathlib import Path

ASSETS = (
    "assets/js/shared/api-request-semantics.js",
    "assets/js/shared/api-oauth-context.js",
    "assets/js/shared/api-transport.js",
    "assets/js/shared/api-request-coordinator.js",
    "assets/js/shared/api-client.js",
)


def make_key(version: str, source_head: str, build_id: str) -> str:
    if not source_head.strip() or not build_id.strip():
        raise ValueError("source head and build ID are required")
    identity = hashlib.sha256(f"{source_head}\0{build_id}".encode()).hexdigest()[:16]
    return f"p2k-{version}-{source_head[:12]}-{identity}"


def html_files(root: Path):
    yield from root.glob("*.html")
    yield from root.glob("*.htm")


def stamp(root: Path, key: str) -> None:
    changed = 0
    for path in html_files(root):
        source = path.read_text(encoding="utf-8")
        for asset in ASSETS:
            source, count = re.subn(re.escape(asset) + r"\?v=[^\"'<>\s]+", f"{asset}?v={key}", source)
            changed += count
        path.write_text(source, encoding="utf-8")
    if changed == 0:
        raise SystemExit("No qualified static-asset references were stamped")


def verify(root: Path, key: str) -> None:
    if not re.fullmatch(r"p2k-[0-9.]+-[0-9a-f]{12}-[0-9a-f]{16}", key):
        raise SystemExit(f"Invalid build-specific cache key: {key!r}")
    references = 0
    failures: list[str] = []
    for path in html_files(root):
        source = path.read_text(encoding="utf-8")
        for asset in ASSETS:
            for found in re.findall(re.escape(asset) + r"\?v=([^\"'<>\s]+)", source):
                references += 1
                if found != key:
                    failures.append(f"{path.name}: {asset}?v={found}")
    if references == 0:
        failures.append("no qualified static-asset references found")
    if failures:
        raise SystemExit("Static-asset cache-key qualification failed:\n" + "\n".join(failures))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("stamp", "verify", "key"))
    parser.add_argument("--root", type=Path)
    parser.add_argument("--version", required=True)
    parser.add_argument("--source-head", required=True)
    parser.add_argument("--build-id", required=True)
    args = parser.parse_args()
    key = make_key(args.version, args.source_head, args.build_id)
    if args.mode == "key":
        print(key)
        return 0
    if args.root is None:
        parser.error("--root is required for stamp and verify")
    (stamp if args.mode == "stamp" else verify)(args.root, key)
    print(key)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
