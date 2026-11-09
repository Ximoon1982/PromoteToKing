#!/usr/bin/env python3
"""Ensure preview rewrite conditions are not accidentally attached to TGP rules."""
from pathlib import Path
rules = Path(__file__).resolve().parents[1].joinpath(".htaccess").read_text().splitlines()
def preceding_conditions(fragment):
    positions = [i for i, line in enumerate(rules) if line.lstrip().startswith("RewriteRule ") and fragment in line]
    if len(positions) != 1:
        raise AssertionError(f"Expected exactly one rewrite rule for {fragment}, found {len(positions)}")
    conditions = []
    for line in reversed(rules[:positions[0]]):
        stripped = line.strip()
        if stripped.startswith("RewriteCond "):
            conditions.append(stripped)
        elif stripped and not stripped.startswith("#"):
            break
    return list(reversed(conditions))
general = preceding_conditions("^(?!PreviewRouter\\.php$)(.*)$ /PreviewRouter.php")
assert len(general) == 3, general
assert "HTTP:Cookie" in general[0], general
assert "REQUEST_URI" in general[1] and "auth/callback" in general[1], general
assert "REQUEST_URI" in general[2] and "TGP/" not in general[2], general
tgp = preceding_conditions("^TGP/MatchAnalyzer(?:/|$) /PreviewRouter.php")
assert len(tgp) == 1 and "HTTP:Cookie" in tgp[0], tgp
public = preceding_conditions("^TGP/MatchAnalyzer(?:/|$) /PublicRouter.php")
assert not public, public
print("TGP preview OAuth callback rewrite-scope regression: PASS")
