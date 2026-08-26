ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS p2k_avg_rating SMALLINT UNSIGNED NULL AFTER opponent_score;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS opponent_avg_rating SMALLINT UNSIGNED NULL AFTER p2k_avg_rating;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(2);
