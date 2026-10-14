from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

MIAC = read("server/team-points/src/MiacService.php")
STATS = read("server/team-points/public/player-stats.php")
CARDS = read("server/team-points/public/player-cards.php")
ICONS = read("server/team-points/public/opponent-icons.php")
ISOLATION = read("server/team-points/src/PreviewIsolation.php")

def test_preview_database_enforcement_remains_fail_closed():
    assert "SET SESSION TRANSACTION READ ONLY" in ISOLATION
    assert "did not verify as read-only" in ISOLATION

def test_miac_generation_preserves_production_initialization_but_preview_is_read_only():
    generation = MIAC.split("public function generation(): int", 1)[1].split("private function ensureState", 1)[0]
    assert "if (!PreviewIsolation::active()) $this->ensureState();" in generation
    assert "SELECT identity_map_generation" in generation

def test_miac_resolve_only_suppresses_mutation_in_preview():
    resolve = MIAC.split("public function resolve(string $username): array", 1)[1].split("private function ensureName", 1)[0]
    assert "$preview=PreviewIsolation::active();" in resolve
    assert "if(!$preview)$this->ensureName($username);" in resolve
    assert "if(!is_array($r)&&!$preview)" in resolve

def test_miac_summary_preserves_production_automatic_policy():
    summary = MIAC.split("public function summary(int $limit=500,string $search=''): array", 1)[1]
    assert "if(PreviewIsolation::active())" in summary
    assert "'preview_read_only'=>true" in summary
    assert "$this->ensureState();$auto=$this->applyAutomaticEvidencePolicy();$gen=$this->generation();" in summary

def test_player_rating_persistence_is_preview_only_suppressed():
    assert "$stored=PreviewIsolation::active()?false:$repo->storeMemberRatings" in STATS
    assert "'stored'=>$stored" in STATS

def test_profile_and_opponent_refreshes_keep_production_persistence():
    assert "if(PreviewIsolation::active())" in CARDS
    assert "$repo->storePlayerProfileSnapshot" in CARDS
    assert "if(!PreviewIsolation::active())$repo->recordOpponentCheck" in ICONS
    assert "$repo->storeOpponentProfileSnapshot" in ICONS
