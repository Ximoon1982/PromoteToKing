#!/usr/bin/env python3
"""Stamp and verify one immutable build key across local HTML JS/CSS references."""

from __future__ import annotations

import argparse
import hashlib
import re
import sys
from pathlib import Path
from typing import NamedTuple
from urllib.parse import urlsplit, urlunsplit

TAG_PATTERN = re.compile(r"<(?:script|link)\b[^>]*>", re.IGNORECASE | re.DOTALL)
ATTRIBUTE_PATTERN = re.compile(
    r"(?P<prefix>\b(?P<name>src|href|rel)\s*=\s*)(?P<quote>['\"])(?P<value>.*?)(?P=quote)",
    re.IGNORECASE | re.DOTALL,
)
SCHEME_PATTERN = re.compile(r"^[a-z][a-z0-9+.-]*:", re.IGNORECASE)


class StaticReference(NamedTuple):
    html: Path
    asset: str
    key: str | None
    key_count: int


def make_key(version: str, source_head: str, build_id: str) -> str:
    if not source_head.strip() or not build_id.strip():
        raise ValueError("source head and build ID are required")
    identity = hashlib.sha256(f"{source_head}\0{build_id}".encode()).hexdigest()[:16]
    return f"p2k-{version}-{source_head[:12]}-{identity}"


def html_files(root: Path):
    yield from sorted(path for path in root.rglob("*") if path.suffix.lower() in {".html", ".htm"})


def attributes(tag: str) -> dict[str, str]:
    return {match.group("name").lower(): match.group("value") for match in ATTRIBUTE_PATTERN.finditer(tag)}


def cache_sensitive_url(tag: str) -> tuple[str, str] | None:
    attrs = attributes(tag)
    if tag.lower().startswith("<script"):
        attribute, suffix = "src", ".js"
    else:
        if "stylesheet" not in attrs.get("rel", "").lower().split():
            return None
        attribute, suffix = "href", ".css"
    url = attrs.get(attribute, "").strip()
    if not url or url.startswith(("//", "#")) or SCHEME_PATTERN.match(url):
        return None
    if not urlsplit(url).path.lower().endswith(suffix):
        return None
    return attribute, url


def replace_cache_key(url: str, key: str) -> str:
    parts = urlsplit(url)
    tokens = parts.query.split("&") if parts.query else []
    rewritten: list[str] = []
    replaced = False
    for token in tokens:
        if token.partition("=")[0] == "v":
            if not replaced:
                rewritten.append(f"v={key}")
                replaced = True
            continue
        rewritten.append(token)
    if not replaced:
        rewritten.append(f"v={key}")
    return urlunsplit((parts.scheme, parts.netloc, parts.path, "&".join(rewritten), parts.fragment))


def rewrite_tag(tag: str, key: str) -> tuple[str, bool]:
    found = cache_sensitive_url(tag)
    if found is None:
        return tag, False
    attribute, url = found
    stamped = replace_cache_key(url, key)

    def replace(match: re.Match[str]) -> str:
        if match.group("name").lower() != attribute:
            return match.group(0)
        return f"{match.group('prefix')}{match.group('quote')}{stamped}{match.group('quote')}"

    return ATTRIBUTE_PATTERN.sub(replace, tag), stamped != url


def stamp(root: Path, key: str) -> int:
    reference_count = 0
    for path in html_files(root):
        source = path.read_text(encoding="utf-8")

        def replace(match: re.Match[str]) -> str:
            nonlocal reference_count
            tag, _ = rewrite_tag(match.group(0), key)
            if cache_sensitive_url(tag) is not None:
                reference_count += 1
            return tag

        stamped = TAG_PATTERN.sub(replace, source)
        if stamped != source:
            path.write_text(stamped, encoding="utf-8")
    if reference_count == 0:
        raise SystemExit("No local JavaScript or stylesheet references were found")
    return reference_count


def references(root: Path) -> list[StaticReference]:
    result: list[StaticReference] = []
    for path in html_files(root):
        source = path.read_text(encoding="utf-8")
        for tag_match in TAG_PATTERN.finditer(source):
            found = cache_sensitive_url(tag_match.group(0))
            if found is None:
                continue
            _, url = found
            tokens = urlsplit(url).query.split("&") if urlsplit(url).query else []
            keys = [token.partition("=")[2] for token in tokens if token.partition("=")[0] == "v"]
            result.append(StaticReference(path.relative_to(root), urlsplit(url).path, keys[0] if keys else None, len(keys)))
    return result


def verify(root: Path, key: str, required_basenames: tuple[str, ...] = ()) -> int:
    if not re.fullmatch(r"p2k-[0-9.]+-[0-9a-f]{12}-[0-9a-f]{16}", key):
        raise SystemExit(f"Invalid build-specific cache key: {key!r}")
    found = references(root)
    failures = [
        f"{item.html}: {item.asset} has "
        + ("no cache key" if item.key_count == 0 else f"{item.key_count} cache keys" if item.key_count != 1 else f"stale key {item.key!r}")
        for item in found
        if item.key_count != 1 or item.key != key
    ]
    basenames = {Path(item.asset).name for item in found if item.key_count == 1 and item.key == key}
    failures.extend(f"required packaged asset reference missing: {name}" for name in required_basenames if name not in basenames)
    if not found:
        failures.append("no local JavaScript or stylesheet references found")
    if failures:
        raise SystemExit("Static-asset cache-key qualification failed:\n" + "\n".join(failures))
    return len(found)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("stamp", "verify", "key"))
    parser.add_argument("--root", type=Path)
    parser.add_argument("--version", required=True)
    parser.add_argument("--source-head", required=True)
    parser.add_argument("--build-id", required=True)
    parser.add_argument("--require-basename", action="append", default=[])
    args = parser.parse_args()
    key = make_key(args.version, args.source_head, args.build_id)
    if args.mode == "key":
        print(key)
        return 0
    if args.root is None:
        parser.error("--root is required for stamp and verify")
    count = stamp(args.root, key) if args.mode == "stamp" else verify(args.root, key, tuple(args.require_basename))
    print(key)
    print(f"Verified {count} local JS/CSS references.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
