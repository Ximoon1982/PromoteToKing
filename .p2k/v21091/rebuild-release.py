#!/usr/bin/env python3
from pathlib import Path
import argparse, base64, gzip, hashlib, io, json, lzma, shutil, stat, subprocess, tarfile, tempfile

BASE_COMMIT = "42f179bee4f10c88b8b4a133a904666ab62305f7"
EXPECTED_SHA256 = "38416bbc8518b8443c75371bcfb5d6dec4361ee0fda1b933a7a8c9d6ec5820d2"
GZIP_MTIME = 1788023323
HERE = Path(__file__).resolve().parent


def apply_patch(root: Path) -> None:
    patch_xz = base64.b85decode((HERE / "remaining-source.patch.xz.b85").read_text().strip())
    patch = lzma.decompress(patch_xz)
    p = subprocess.run(["patch", "-p1", "--forward", "--batch"], cwd=root, input=patch,
                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if p.returncode not in (0, 1):
        raise SystemExit(p.stdout.decode(errors="replace"))
    # return code 1 is acceptable only when all hunks are already applied (current canonical tree).
    if p.returncode == 1:
        text = p.stdout.decode(errors="replace")
        if "Reversed (or previously applied) patch detected" not in text and "Skipping patch" not in text:
            raise SystemExit(text)


def build(source: Path, output: Path) -> None:
    manifest_bytes = (HERE / "payload-manifest.json").read_bytes()
    manifest = json.loads(manifest_bytes)
    ordered = [row["path"] for row in manifest["files"]]
    with tempfile.TemporaryDirectory(prefix="p2k-v21091-build-") as td:
        work = Path(td) / "tree"
        shutil.copytree(source, work, symlinks=True)
        apply_patch(work)
        bio = io.BytesIO()
        with tarfile.open(fileobj=bio, mode="w", format=tarfile.USTAR_FORMAT) as tf:
            entries = [(".p2k-v21091-r3-payload-manifest.json", manifest_bytes)]
            entries += [(rel, (work / rel).read_bytes()) for rel in ordered]
            for name, data in entries:
                ti = tarfile.TarInfo(name)
                ti.size = len(data); ti.mode = 0o644; ti.uid = 0; ti.gid = 0
                ti.uname = "root"; ti.gname = "root"; ti.mtime = 0
                tf.addfile(ti, io.BytesIO(data))
        gz = io.BytesIO()
        with gzip.GzipFile(fileobj=gz, mode="wb", filename="", compresslevel=9, mtime=GZIP_MTIME) as z:
            z.write(bio.getvalue())
        data = (HERE / "installer-header.sh").read_bytes() + gz.getvalue()
        got = hashlib.sha256(data).hexdigest()
        if got != EXPECTED_SHA256:
            raise SystemExit(f"rebuild SHA mismatch: {got} != {EXPECTED_SHA256}")
        output.write_bytes(data); output.chmod(output.stat().st_mode | stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH)
        print(f"rebuilt {output} SHA256={got}")

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("source", nargs="?", default=".")
    ap.add_argument("output", nargs="?", default="PromoteToKing_v2.10.9.1_INCREMENTAL_INSTALLER_FINAL.run")
    a = ap.parse_args(); build(Path(a.source).resolve(), Path(a.output).resolve())
