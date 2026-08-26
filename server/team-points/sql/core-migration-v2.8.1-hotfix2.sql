-- v2.8.1 Hotfix 2 integrated convergence migration.
-- Idempotently converges either historical schema-2 branch (v2.8.1 or RecoveryFix7)
-- and the original v2.8 schema-1 pair to the combined schema 3.
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS daily_rating INT UNSIGNED NULL AFTER joined_at;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS chess960_rating INT UNSIGNED NULL AFTER daily_rating;
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS rating_updated_at DATETIME NULL AFTER chess960_rating;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS p2k_avg_rating SMALLINT UNSIGNED NULL AFTER opponent_score;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS opponent_avg_rating SMALLINT UNSIGNED NULL AFTER p2k_avg_rating;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(3);
