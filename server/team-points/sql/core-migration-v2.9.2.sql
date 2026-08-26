ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS rated_board_count INT UNSIGNED NULL AFTER opponent_avg_rating;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(9);
