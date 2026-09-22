from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def read(path):
    return (ROOT/path).read_text(encoding="utf-8")

def test_v2131_green_runtime_schema_contract():
    green=read("server/team-points-green/src/GreenRepository.php")
    assert "$repo->ensureRuntimeSchema();" in green
    assert "public function ensureRuntimeSchema(): array" in green
    assert "runtimeSchemaContractIssues" in green
    assert "'fact_source','max_rating'" in green
    assert "'idx_g_match_discovered'" in green
    assert "Green runtime schema convergence failed" in green
    assert "Green schema initialization did not satisfy the runtime contract" in green

def test_v2131_compatibility_physical_contract_ignores_stale_version_markers():
    compat=read("server/team-points-green/src/GreenCompatibility.php")
    assert "compatibilitySchemaIssues" in compat
    assert "ADD COLUMN max_rating SMALLINT UNSIGNED NULL AFTER rated_board_count" in compat
    assert "ADD INDEX idx_tp_match_discovered (club_slug,first_discovered_at)" in compat
    assert "'physical_verified'=>true" in compat
    assert "Green compatibility physical schema contract failed" in compat
    for table in ["p2k_tp_members","p2k_tp_state","p2k_tp_match_metadata","p2k_tp_opponents","p2k_tp_boards","p2k_tp_games"]:
        assert table in compat

def test_v2131_mca_schema_convergence_never_swallows_alter_failure():
    service=read("server/team-points/src/McaResultsCronService.php")
    start=service.index("private function ensureState")
    end=service.index("private function stateRow",start)
    block=service[start:end]
    assert "catch(\\\\Throwable){}" not in block
    assert "MCA schema convergence failed while adding" in block
    assert "MCA schema convergence verification failed" in block
    assert block.index("syncSchemaReady=true") > block.index("MCA schema convergence verification failed")

def test_v2131_release_identity_and_dynamic_gate():
    assert read("VERSION").strip()=="2.13.1"
    workflow=read(".github/workflows/p2k-v2131-qualification.yml")
    assert "release/v2.13.1" in workflow
    assert "mariadb:" in workflow
    assert "php tests/test_v2131_schema_convergence.php" in workflow
    assert "Full canonical P2K regression" in workflow
    assert "Full canonical P2K browser regression" in workflow
