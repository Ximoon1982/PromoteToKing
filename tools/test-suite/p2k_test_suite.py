#!/usr/bin/env python3
"""Audit and run the Promote to King regression suite without product writes."""

from __future__ import annotations

import argparse
import ast
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
TESTS = ROOT / "tests"
POLICY = TESTS / "test-suite-policy.json"
DEBT = TESTS / "known-regression-debt-v2.11.1.json"


def python_inventory() -> dict:
    modules, parse_errors, duplicate_names = [], [], []
    pytest_functions = 0
    for path in sorted(TESTS.glob("*.py")):
        relative = path.relative_to(ROOT).as_posix()
        try:
            tree = ast.parse(path.read_text(encoding="utf-8"), filename=relative)
        except (OSError, SyntaxError, UnicodeError) as error:
            parse_errors.append({"path": relative, "error": str(error)})
            continue
        names = [node.name for node in ast.walk(tree) if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name.startswith("test_")]
        counts = {name: names.count(name) for name in set(names)}
        duplicates = sorted(name for name, count in counts.items() if count > 1)
        if duplicates:
            duplicate_names.append({"path": relative, "names": duplicates})
        has_main = any(isinstance(node, ast.If) and any(isinstance(value, ast.Constant) and value.value == "__main__" for value in ast.walk(node.test)) for node in tree.body)
        # Historical releases contain executable assertion scripts named test_*.py.
        # Importing those through pytest can raise SystemExit during collection.
        if path.name.startswith("browser"):
            kind = "browser"
        elif path.name == "validate_package.py":
            kind = "package"
        elif names:
            kind = "pytest"
        elif path.name.startswith("test_"):
            kind = "standalone"
        else:
            kind = "helper"
        modules.append({"path": relative, "kind": kind, "tests": len(names)})
        pytest_functions += len(names)
    return {"modules": modules, "module_count": len(modules), "pytest_functions": pytest_functions, "parse_errors": parse_errors, "duplicate_test_names": duplicate_names}


def inventory() -> dict:
    return {
        "python": python_inventory(),
        "browser_gates": sorted(path.relative_to(ROOT).as_posix() for path in TESTS.glob("browser*.py")),
        "php_harnesses": sorted(path.relative_to(ROOT).as_posix() for path in TESTS.glob("*.php")),
        "javascript_runner": "tests/run-tests.js" if (TESTS / "run-tests.js").is_file() else None,
        "workflows": sorted(path.relative_to(ROOT).as_posix() for path in (ROOT / ".github/workflows").glob("*.yml")),
    }


def validate_policy(report: dict) -> list[str]:
    policy = json.loads(POLICY.read_text(encoding="utf-8"))
    failures, py = [], report["python"]
    checks = {"python_modules": py["module_count"], "pytest_functions": py["pytest_functions"], "browser_gates": len(report["browser_gates"]), "php_harnesses": len(report["php_harnesses"])}
    for key, actual in checks.items():
        minimum = int(policy["minimum_counts"][key])
        if actual < minimum:
            failures.append(f"{key}: found {actual}, policy requires at least {minimum}")
    available = {item["path"] for item in py["modules"]} | set(report["browser_gates"]) | set(report["php_harnesses"])
    if report["javascript_runner"]:
        available.add(report["javascript_runner"])
    for required in policy["required_gates"]:
        if required not in available and not (ROOT / required).is_file():
            failures.append(f"required gate missing: {required}")
    debt = json.loads(DEBT.read_text(encoding="utf-8"))
    known_pytest = debt.get("known_failures", [])
    known_standalone = debt.get("known_standalone_failures", [])
    if len(known_pytest) > int(policy["maximum_known_debt"]["pytest"]):
        failures.append(f"pytest known debt grew to {len(known_pytest)}")
    if len(known_standalone) > int(policy["maximum_known_debt"]["standalone"]):
        failures.append(f"standalone known debt grew to {len(known_standalone)}")
    if len(known_pytest) != len(set(known_pytest)) or len(known_standalone) != len(set(known_standalone)):
        failures.append("known regression debt contains duplicates")
    failures.extend(f"Python parse failure: {row['path']}: {row['error']}" for row in py["parse_errors"])
    failures.extend(f"duplicate test names in {row['path']}: {', '.join(row['names'])}" for row in py["duplicate_test_names"])
    return failures


def run(command: list[str], label: str) -> None:
    print(f"\n== {label} ==", flush=True)
    completed = subprocess.run(command, cwd=ROOT, check=False)
    if completed.returncode:
        raise SystemExit(f"{label} failed with exit code {completed.returncode}")


def syntax_gate(report: dict) -> None:
    for row in report["python"]["modules"]:
        source = (ROOT / row["path"]).read_text(encoding="utf-8")
        compile(source, row["path"], "exec")
    node = shutil.which("node")
    if not node:
        raise SystemExit("Node.js is required for the JavaScript regression gate.")
    for path in sorted(path for base in (ROOT / "assets/js", ROOT / "config", ROOT / "tests") for path in base.rglob("*.js")):
        run([node, "--check", str(path)], f"JavaScript syntax: {path.relative_to(ROOT)}")
    php = shutil.which("php")
    if not php:
        raise SystemExit("PHP CLI is required for the PHP syntax regression gate.")
    php_files = sorted(ROOT.glob("*.php")) + sorted((ROOT / "api").rglob("*.php")) + sorted((ROOT / "server").rglob("*.php")) + sorted(TESTS.glob("*.php"))
    for path in php_files:
        run([php, "-l", str(path)], f"PHP syntax: {path.relative_to(ROOT)}")


def pytest_gate(report: dict) -> None:
    try:
        import pytest  # noqa: F401
    except ImportError as error:
        raise SystemExit("pytest is required; install tests/requirements.txt") from error
    modules = [row["path"] for row in report["python"]["modules"] if row["kind"] == "pytest"]
    with tempfile.TemporaryDirectory(prefix="p2k-pytest-") as tmp:
        junit = Path(tmp) / "results.xml"
        print("\n== Complete pytest-style suite ==", flush=True)
        completed = subprocess.run([sys.executable, "-m", "pytest", "-q", f"--junitxml={junit}", *modules], cwd=ROOT, text=True, capture_output=True, check=False)
        if not junit.is_file() or completed.returncode not in {0, 1}:
            sys.stdout.write(completed.stdout)
            sys.stderr.write(completed.stderr)
            raise SystemExit(f"pytest collection/runtime failed with exit code {completed.returncode}")
        tree = ET.parse(junit)
        cases = list(tree.getroot().iter("testcase"))
        actual = set()
        for case in cases:
            if case.find("failure") is None and case.find("error") is None:
                continue
            module = case.attrib.get("classname", "").replace(".", "/") + ".py"
            if not module.startswith("tests/"):
                module = "tests/" + module
            actual.add(f"{module}::{case.attrib.get('name', '')}")
        debt = json.loads(DEBT.read_text(encoding="utf-8"))
        known = set(debt["known_failures"])
        new = sorted(actual - known)
        resolved = sorted(known - actual)
        print(f"pytest result: {len(cases)} executed, {len(actual)} known failures, {len(resolved)} inherited failures resolved in this environment.")
        if new:
            sys.stdout.write(completed.stdout)
            sys.stderr.write(completed.stderr)
            raise SystemExit("New pytest regressions:\n" + "\n".join(new))


def standalone_gate(report: dict) -> None:
    debt = json.loads(DEBT.read_text(encoding="utf-8"))
    known = set(debt["known_standalone_failures"])
    actual = set()
    for relative in [row["path"] for row in report["python"]["modules"] if row["kind"] == "standalone"]:
        completed = subprocess.run([sys.executable, relative], cwd=ROOT, text=True, capture_output=True, check=False)
        if completed.returncode:
            actual.add(relative)
            if relative not in known:
                sys.stdout.write(completed.stdout)
                sys.stderr.write(completed.stderr)
        else:
            print(f"PASS standalone: {relative}")
    new = sorted(actual - known)
    resolved = sorted(known - actual)
    print(f"standalone result: {len(actual)} known failures, {len(resolved)} inherited failures resolved in this environment.")
    if new:
        raise SystemExit("New standalone regressions:\n" + "\n".join(new))


def browser_gate(report: dict) -> None:
    for relative in report["browser_gates"]:
        run([sys.executable, relative], f"Browser regression: {relative}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("profile", choices=("audit", "static", "regression", "browser", "full"), nargs="?", default="audit")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()
    report = inventory()
    failures = validate_policy(report)
    if args.json:
        print(json.dumps(report, indent=2, sort_keys=True))
    print(f"P2K test inventory: {report['python']['module_count']} Python modules, {report['python']['pytest_functions']} pytest tests, {len(report['browser_gates'])} browser gates, {len(report['php_harnesses'])} PHP harnesses.")
    if failures:
        for failure in failures:
            print(f"ERROR: {failure}", file=sys.stderr)
        return 1
    if args.profile in {"static", "regression", "full"}:
        syntax_gate(report)
        run([shutil.which("node") or "node", "tests/run-tests.js"], "Canonical JavaScript feature regression")
    if args.profile in {"regression", "full"}:
        pytest_gate(report)
        standalone_gate(report)
    if args.profile in {"browser", "full"}:
        browser_gate(report)
    print(f"P2K {args.profile} test profile passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
