#!/usr/bin/env python3
from pathlib import Path
import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile

TARGET_VERSION = "2.11.5"
MANIFEST_NAME = ".p2k-version-2.11.5.json"

ACTIVE_ROOT_DIRS = ("assets", "config", "api", "server")
TEXT_SUFFIXES = {".html", ".htm", ".js", ".mjs", ".css", ".php", ".json", ".webmanifest"}
EXCLUDED_PARTS = {
    ".git", "data", "storage", "cache", "logs", "log", "vendor", "node_modules",
    "tools", "tests", "test", "docs", "documentation", "archive", "archives",
    "releases", "release", "backups", "backup"
}

SEMVER = r"v?2\.\d+(?:\.\d+){1,3}(?:[-._A-Za-z0-9]+)?"
CACHE_RE = re.compile(r"(?P<prefix>(?:\?|&|&amp;)v=)(?P<version>" + SEMVER + r")")
SITE_VERSION_RE = re.compile(r'(?P<prefix>\bversion\s*:\s*["\'])(?P<version>' + SEMVER + r')(?P<suffix>["\'])')
NAMED_VERSION_RE = re.compile(
    r'(?P<prefix>\b(?:P2K|APP|SITE|UI|RELEASE|BUILD)[_-]?VERSION\b\s*[:=]\s*["\'])'
    r'(?P<version>' + SEMVER + r')(?P<suffix>["\'])',
    re.I
)
JSON_VERSION_RE = re.compile(
    r'(?P<prefix>["\']version["\']\s*:\s*["\'])(?P<version>' + SEMVER + r')(?P<suffix>["\'])',
    re.I
)
DATA_VERSION_RE = re.compile(
    r'(?P<prefix>\bdata-(?:app-|site-|ui-)?version\s*=\s*["\'])(?P<version>' + SEMVER + r')(?P<suffix>["\'])',
    re.I
)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def excluded(rel):
    parts = rel.parts
    for part in parts:
        low = part.lower()
        if low in EXCLUDED_PARTS:
            return True
        if low.startswith(".trophy-") and "backup" in low:
            return True
        if low.startswith("trophy-r5fix"):
            return True
    return False

def active_files(root):
    seen = set()
    # Root-level runtime text files.
    for p in root.iterdir():
        if p.is_file() and (p.suffix.lower() in TEXT_SUFFIXES or p.name == "VERSION"):
            rel = p.relative_to(root)
            if not excluded(rel):
                seen.add(rel)
    # Runtime directories only.
    for dirname in ACTIVE_ROOT_DIRS:
        base = root / dirname
        if not base.is_dir():
            continue
        for p in base.rglob("*"):
            if not p.is_file():
                continue
            rel = p.relative_to(root)
            if excluded(rel):
                continue
            if p.suffix.lower() in TEXT_SUFFIXES:
                seen.add(rel)
    return sorted(seen, key=lambda x: x.as_posix())

def build_identity(root, rels):
    h = hashlib.sha256()
    h.update(("P2K_VERSION_ALIGNMENT\0" + TARGET_VERSION + "\0").encode())
    for rel in rels:
        p = root / rel
        data = p.read_bytes()
        h.update(rel.as_posix().encode() + b"\0")
        h.update(hashlib.sha256(data).digest())
    return h.hexdigest()[:12]

def replace_version(match, replacement):
    gd = match.groupdict()
    return gd["prefix"] + replacement + gd.get("suffix", "")

def patch_text(rel, text, build_key):
    changed_kinds = []

    # All numeric semantic-version asset cache keys in active runtime source.
    new, n = CACHE_RE.subn(lambda m: replace_version(m, build_key), text)
    if n:
        text = new
        changed_kinds.append("asset-cache-key")

    # Canonical runtime site configuration version.
    if rel.as_posix() == "assets/js/site-config.js":
        marker = "window.P2K_SITE_CONFIG = Object.freeze({"
        pos = text.find(marker)
        if pos < 0:
            raise RuntimeError("assets/js/site-config.js: P2K_SITE_CONFIG marker not found")
        prefix = text[:pos]
        body = text[pos:]
        body2, n = SITE_VERSION_RE.subn(
            lambda m: replace_version(m, TARGET_VERSION),
            body,
            count=1
        )
        if n != 1:
            raise RuntimeError("assets/js/site-config.js: canonical site version declaration not found exactly once")
        if body2 != body:
            changed_kinds.append("site-runtime-version")
        text = prefix + body2

    # Explicitly named current-version constants in runtime files.
    new, n = NAMED_VERSION_RE.subn(lambda m: replace_version(m, TARGET_VERSION), text)
    if n:
        text = new
        changed_kinds.append("named-runtime-version")

    # Manifest/package-style version field only in files whose name denotes current metadata.
    if rel.name.lower() in {"package.json", "manifest.json", "site.webmanifest", "version.json"}:
        new, n = JSON_VERSION_RE.subn(lambda m: replace_version(m, TARGET_VERSION), text, count=1)
        if n:
            text = new
            changed_kinds.append("metadata-version")

    # Explicit current-version data attributes.
    new, n = DATA_VERSION_RE.subn(lambda m: replace_version(m, TARGET_VERSION), text)
    if n:
        text = new
        changed_kinds.append("data-version")

    return text, sorted(set(changed_kinds))

def active_marker_violations(rel, text, build_key):
    problems = []
    for m in CACHE_RE.finditer(text):
        if m.group("version") != build_key:
            problems.append(f"stale asset cache key {m.group('version')}")
    if rel.as_posix() == "assets/js/site-config.js":
        marker = "window.P2K_SITE_CONFIG = Object.freeze({"
        pos = text.find(marker)
        if pos < 0:
            problems.append("P2K_SITE_CONFIG marker missing")
        else:
            m = SITE_VERSION_RE.search(text[pos:])
            if not m or m.group("version") != TARGET_VERSION:
                problems.append("canonical site runtime version is not 2.11.5")
    for m in NAMED_VERSION_RE.finditer(text):
        if m.group("version") != TARGET_VERSION:
            problems.append(f"named runtime version remains {m.group('version')}")
    if rel.name.lower() in {"package.json", "manifest.json", "site.webmanifest", "version.json"}:
        m = JSON_VERSION_RE.search(text)
        if m and m.group("version") != TARGET_VERSION:
            problems.append(f"metadata version remains {m.group('version')}")
    for m in DATA_VERSION_RE.finditer(text):
        if m.group("version") != TARGET_VERSION:
            problems.append(f"data-version remains {m.group('version')}")
    return problems

def make_plan(root, stage):
    if not root.is_dir():
        raise RuntimeError(f"root does not exist: {root}")
    version_file = root / "VERSION"
    site_config = root / "assets/js/site-config.js"
    if not version_file.is_file():
        raise RuntimeError("VERSION is missing")
    if not site_config.is_file():
        raise RuntimeError("assets/js/site-config.js is missing")

    rels = active_files(root)
    build_id = build_identity(root, rels)
    build_key = f"{TARGET_VERSION}-b{build_id}"

    changes = []
    for rel in rels:
        src = root / rel
        before = src.read_bytes()

        if rel.as_posix() == "VERSION":
            after = (TARGET_VERSION + "\n").encode("utf-8")
            kinds = ["canonical-version-file"]
        else:
            try:
                text = before.decode("utf-8")
            except UnicodeDecodeError:
                continue
            patched, kinds = patch_text(rel, text, build_key)
            after = patched.encode("utf-8")

        if after == before:
            continue

        dest = stage / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(after)
        changes.append({
            "path": rel.as_posix(),
            "before_sha256": sha(before),
            "after_sha256": sha(after),
            "kinds": kinds,
        })

    changed_paths = {c["path"] for c in changes}

    # Validate the effective post-plan tree, not only files that happened to change.
    violations = []
    for rel in rels:
        p = stage / rel if rel.as_posix() in changed_paths else root / rel
        if rel.as_posix() == "VERSION":
            if p.read_text(encoding="utf-8").strip() != TARGET_VERSION:
                violations.append({"path": rel.as_posix(), "problem": "VERSION is not 2.11.5"})
            continue
        try:
            text = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for problem in active_marker_violations(rel, text, build_key):
            violations.append({"path": rel.as_posix(), "problem": problem})

    if violations:
        details = "\n".join(f"{x['path']}: {x['problem']}" for x in violations[:50])
        raise RuntimeError("active version alignment verification failed before commit:\n" + details)

    required = {"VERSION", "assets/js/site-config.js"}
    if not required.issubset(changed_paths):
        # Already-correct files are fine; prove them explicitly.
        for name in sorted(required - changed_paths):
            p = root / name
            if name == "VERSION":
                ok = p.read_text(encoding="utf-8").strip() == TARGET_VERSION
            else:
                text = p.read_text(encoding="utf-8")
                pos = text.find("window.P2K_SITE_CONFIG = Object.freeze({")
                m = SITE_VERSION_RE.search(text[pos:]) if pos >= 0 else None
                ok = bool(m and m.group("version") == TARGET_VERSION)
            if not ok:
                raise RuntimeError(f"{name}: required canonical version surface was not aligned")

    plan = {
        "target_version": TARGET_VERSION,
        "build_id": build_id,
        "asset_cache_key": build_key,
        "active_file_count": len(rels),
        "changed_file_count": len(changes),
        "changes": changes,
    }
    (stage / "plan.json").write_text(json.dumps(plan, indent=2) + "\n", encoding="utf-8")
    return plan

def apply_plan(root, stage, backup):
    plan = json.loads((stage / "plan.json").read_text(encoding="utf-8"))
    changes = plan["changes"]
    backup.mkdir(parents=True, exist_ok=False)

    installed = []
    try:
        # Race guard + backup before first replacement.
        for item in changes:
            rel = Path(item["path"])
            current = root / rel
            if not current.is_file():
                raise RuntimeError(f"target disappeared before commit: {rel}")
            if sha(current.read_bytes()) != item["before_sha256"]:
                raise RuntimeError(f"target changed after staging; refusing commit: {rel}")
            b = backup / rel
            b.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(current, b)

        # Same-filesystem temporary + atomic replace.
        for item in changes:
            rel = Path(item["path"])
            target = root / rel
            staged = stage / rel
            temp = target.with_name(target.name + ".p2k-2115.new")
            shutil.copy2(staged, temp)
            if sha(temp.read_bytes()) != item["after_sha256"]:
                raise RuntimeError(f"temporary replacement checksum mismatch: {rel}")
            os.replace(temp, target)
            installed.append(rel)

        # Exact post-install bytes.
        for item in changes:
            rel = Path(item["path"])
            target = root / rel
            if sha(target.read_bytes()) != item["after_sha256"]:
                raise RuntimeError(f"post-install checksum mismatch: {rel}")

        install_manifest = dict(plan)
        install_manifest["backup"] = str(backup)
        install_manifest["installed_manifest_version"] = 1
        manifest_path = root / MANIFEST_NAME
        tmp = manifest_path.with_name(manifest_path.name + ".new")
        tmp.write_text(json.dumps(install_manifest, indent=2) + "\n", encoding="utf-8")
        os.replace(tmp, manifest_path)

    except Exception:
        # Restore every file from its byte-for-byte backup.
        for item in changes:
            rel = Path(item["path"])
            b = backup / rel
            target = root / rel
            if b.is_file():
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(b, target)
        raise

    return plan

def verify_installed(root):
    manifest_path = root / MANIFEST_NAME
    if not manifest_path.is_file():
        raise RuntimeError(f"{MANIFEST_NAME} is missing")
    plan = json.loads(manifest_path.read_text(encoding="utf-8"))
    if plan.get("target_version") != TARGET_VERSION:
        raise RuntimeError("installed version manifest does not target 2.11.5")
    build_key = plan.get("asset_cache_key", "")
    if not build_key.startswith(TARGET_VERSION + "-b"):
        raise RuntimeError("installed asset cache key is invalid")

    for item in plan.get("changes", []):
        p = root / item["path"]
        if not p.is_file():
            raise RuntimeError(f"installed file missing: {item['path']}")
        if sha(p.read_bytes()) != item["after_sha256"]:
            raise RuntimeError(f"installed file checksum drift: {item['path']}")

    if (root / "VERSION").read_text(encoding="utf-8").strip() != TARGET_VERSION:
        raise RuntimeError("VERSION is not 2.11.5")

    site = (root / "assets/js/site-config.js").read_text(encoding="utf-8")
    pos = site.find("window.P2K_SITE_CONFIG = Object.freeze({")
    m = SITE_VERSION_RE.search(site[pos:]) if pos >= 0 else None
    if not m or m.group("version") != TARGET_VERSION:
        raise RuntimeError("P2K_SITE_CONFIG.version is not 2.11.5")

    # Re-audit all active current-version surfaces.
    violations = []
    for rel in active_files(root):
        p = root / rel
        if rel.as_posix() == "VERSION":
            continue
        try:
            text = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for problem in active_marker_violations(rel, text, build_key):
            violations.append(f"{rel.as_posix()}: {problem}")
    if violations:
        raise RuntimeError("active version markers drifted:\n" + "\n".join(violations[:50]))

    return plan

def print_plan(plan):
    print(f"Target semantic version: {plan['target_version']}")
    print(f"Immutable asset build key: {plan['asset_cache_key']}")
    print(f"Active runtime text files audited: {plan['active_file_count']}")
    print(f"Files requiring alignment: {plan['changed_file_count']}")
    for item in plan["changes"]:
        kinds = ", ".join(item["kinds"]) or "version marker"
        print(f"  {item['path']}  [{kinds}]")

def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("plan")
    p.add_argument("--root", required=True)
    p.add_argument("--stage", required=True)

    p = sub.add_parser("apply")
    p.add_argument("--root", required=True)
    p.add_argument("--stage", required=True)
    p.add_argument("--backup", required=True)

    p = sub.add_parser("verify")
    p.add_argument("--root", required=True)

    args = ap.parse_args()
    root = Path(args.root).resolve()

    if args.cmd == "plan":
        stage = Path(args.stage).resolve()
        stage.mkdir(parents=True, exist_ok=True)
        plan = make_plan(root, stage)
        print_plan(plan)
    elif args.cmd == "apply":
        plan = apply_plan(root, Path(args.stage).resolve(), Path(args.backup).resolve())
        print_plan(plan)
    else:
        plan = verify_installed(root)
        print(f"Version alignment VERIFIED: {TARGET_VERSION}")
        print(f"Immutable asset build key: {plan['asset_cache_key']}")
        print(f"Tracked aligned files: {len(plan.get('changes', []))}")

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print("ERROR:", e, file=sys.stderr)
        sys.exit(1)
