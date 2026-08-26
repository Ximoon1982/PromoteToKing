from pathlib import Path
import hashlib
import json
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
LEAGUES = ["1wl", "pcl", "tcmac", "tmcl", "kotml"]
SUFFIXES = ["competitor", "veteran", "legend", "first-point", "scorer", "specialist", "master"]
KEYS = [f"{league}-{suffix}" for league in LEAGUES for suffix in SUFFIXES]

def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def test_post_v2915_all_approved_league_artwork_is_integrated():
    manifest = json.loads((ROOT / "POST_v2.9.15_LEAGUE_ARTWORK_INTEGRATION.json").read_text())
    assert manifest["baseline"]["version"] == "2.9.15"
    records = {row["key"]: row for row in manifest["items"]}
    assert set(records) == set(KEYS)
    for key in KEYS:
        row = records[key]
        master = ROOT / row["master"]
        thumb = ROOT / row["thumb_128"]
        mini = ROOT / row["mini_64"]
        legacy = ROOT / row["mini_legacy"]
        for path in (master, thumb, mini, legacy): assert path.is_file(), path
        with Image.open(master) as image: assert image.size == (640, 640) and image.format == "PNG", key
        with Image.open(thumb) as image: assert image.size == (128, 128) and image.format == "WEBP", key
        with Image.open(mini) as image: assert image.size == (64, 64) and image.format == "WEBP", key
        with Image.open(legacy) as image: assert image.size == (64, 64) and image.format == "WEBP", key
        assert sha256(master) == row["master_sha256"]
        assert sha256(thumb) == row["thumb_128_sha256"]
        assert sha256(mini) == row["mini_64_sha256"]
        assert sha256(legacy) == row["mini_legacy_sha256"]

def test_post_v2915_league_catalogue_uses_raster_artwork_not_placeholders():
    catalog = (ROOT / "server/team-points/src/AchievementCatalog.php").read_text()
    for key in KEYS:
        line = next(line for line in catalog.splitlines() if f"self::item('{key}'" in line)
        assert f"'assets/images/achievements/{key}.png','assets/images/achievements/thumbs/128/{key}.webp'" in line
        assert "placeholders/" not in line
