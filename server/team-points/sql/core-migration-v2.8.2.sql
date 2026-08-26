-- Promote to King v2.8.2 additive Core migration.
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS max_rating SMALLINT UNSIGNED NULL AFTER opponent_avg_rating;
ALTER TABLE p2k_tp_match_metadata ADD COLUMN IF NOT EXISTS first_discovered_at DATETIME NULL AFTER max_rating;
UPDATE p2k_tp_match_metadata SET first_discovered_at='1970-01-01 00:00:00' WHERE first_discovered_at IS NULL;
ALTER TABLE p2k_tp_match_metadata ADD KEY IF NOT EXISTS idx_tp_match_discovered (club_slug,first_discovered_at);
ALTER TABLE p2k_tp_match_metadata ADD KEY IF NOT EXISTS idx_tp_match_max_rating (club_slug,max_rating,status);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(4);
