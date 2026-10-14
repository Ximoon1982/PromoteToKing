from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

REPOSITORY = read("server/team-points/src/Repository.php")
SCHEMA = read("server/team-points/sql/core-schema.sql")
MIGRATION = read("server/team-points/sql/core-migration-v2.15.0.sql")
CRON = read("server/team-points/public/cron.php")
CARDS = read("server/team-points/public/player-cards.php")
CSV = read("server/team-points/public/members-insights-export.php")
BUILDER = read("tools/release/v2150/build-incremental.sh")

def test_title_is_additive_schema_18_but_schema_17_remains_runtime_compatible():
    assert "private const CORE_SCHEMA_VERSION = 17;" in REPOSITORY
    assert "private const CURRENT_CORE_SCHEMA_VERSION = 18;" in REPOSITORY
    assert "ADD COLUMN IF NOT EXISTS title VARCHAR(8) NULL" in MIGRATION
    assert "title VARCHAR(8) NULL" in SCHEMA
    assert "VALUES(18)" in MIGRATION
    assert "if (!$repository->schemaCurrent()) $repository->upgradeExistingSchema();" in CRON

def test_profile_refresh_persists_title_without_extra_api_path():
    store = REPOSITORY.split("public function storePlayerProfileSnapshot",1)[1].split("public function playerProfileSnapshot",1)[0]
    assert "$profile['title']" in store
    assert "title=NULLIF(?,'')" in store
    assert "memberTitleColumnAvailable()" in store
    assert "SharedChessGateway" not in store

def test_profile_reads_tolerate_pre_migration_candidate_database():
    assert "SHOW COLUMNS FROM p2k_tp_members LIKE 'title'" in REPOSITORY
    assert "'NULL AS title'" in REPOSITORY
    assert "$profile['title']" in REPOSITORY

def test_player_cards_and_members_csv_expose_title():
    assert "'title'=>strtoupper" in CARDS
    assert "'title'=>(string)($snapshot['title']??'')" in CARDS
    assert "'profile_status','title','country_code'" in CSV
    assert "(string)($m['title'] ?? '')" in CSV

def test_incremental_package_contains_runtime_and_schema_changes():
    for path in [
        "server/team-points/src/MiacService.php",
        "server/team-points/src/Repository.php",
        "server/team-points/public/player-stats.php",
        "server/team-points/public/player-cards.php",
        "server/team-points/public/opponent-icons.php",
        "server/team-points/public/members-insights-export.php",
        "server/team-points/sql/core-schema.sql",
        "server/team-points/sql/core-migration-v2.15.0.sql",
    ]:
        assert path in BUILDER
