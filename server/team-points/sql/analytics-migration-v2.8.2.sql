-- Promote to King v2.8.2 additive Analytics migration.
ALTER TABLE p2k_an_match_facts ADD COLUMN IF NOT EXISTS max_rating SMALLINT UNSIGNED NULL AFTER opponent_avg_rating;
ALTER TABLE p2k_an_match_facts ADD COLUMN IF NOT EXISTS first_discovered_at DATETIME NULL AFTER max_rating;
ALTER TABLE p2k_an_match_facts ADD KEY IF NOT EXISTS idx_an_match_max_rating (club_slug,max_rating,status);
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(4);
