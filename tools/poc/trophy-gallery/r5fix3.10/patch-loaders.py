#!/usr/bin/env python3
from __future__ import annotations

import argparse
from pathlib import Path

BEGIN = "<!-- P2K_TROPHY_R5FIX310_BEGIN -->"
END = "<!-- P2K_TROPHY_R5FIX310_END -->"
R538 = "trophy-gallery-r5fix3.8.js?v=r538-d52193a71712"


def patch(path: Path, key: str, standalone: bool) -> None:
    text = path.read_text(encoding="utf-8")
    prefix = "../" if standalone else ""
    src = f'{prefix}assets/js/admin/trophy-gallery-r5fix3.10.js?v={key}'
    block = f'{BEGIN}\n<script defer src="{src}"></script>\n{END}'

    if BEGIN in text or END in text:
        if text.count(BEGIN) != 1 or text.count(END) != 1:
            raise SystemExit(f"{path}: malformed r5fix3.10 loader markers")
        start = text.index(BEGIN)
        stop = text.index(END, start) + len(END)
        text = text[:start] + block + text[stop:]
    else:
        needle = f'<script defer src="{prefix}assets/js/admin/{R538}"></script>'
        if text.count(needle) != 1:
            raise SystemExit(f"{path}: approved r5fix3.8 loader not found exactly once")
        text = text.replace(needle, needle + "\n" + block, 1)

    if text.count(src) != 1:
        raise SystemExit(f"{path}: r5fix3.10 loader count != 1")
    if text.index(R538) > text.index(src):
        raise SystemExit(f"{path}: r5fix3.10 must load after r5fix3.8")
    path.write_text(text, encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True, type=Path)
    parser.add_argument("--js-key", required=True)
    parser.add_argument("--standalone", action="store_true")
    args = parser.parse_args()
    patch(args.file, args.js_key, args.standalone)


if __name__ == "__main__":
    main()
