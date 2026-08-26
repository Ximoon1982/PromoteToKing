ALTER TABLE p2k_an_match_facts ADD COLUMN IF NOT EXISTS p2k_avg_rating SMALLINT UNSIGNED NULL AFTER opponent_score;
ALTER TABLE p2k_an_match_facts ADD COLUMN IF NOT EXISTS opponent_avg_rating SMALLINT UNSIGNED NULL AFTER p2k_avg_rating;
ALTER TABLE p2k_lr_players ADD COLUMN IF NOT EXISTS first_place_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER best_rank;
CREATE TABLE IF NOT EXISTS p2k_an_achievement_unlocks (
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  achievement_key VARCHAR(120) NOT NULL,
  earned_at DATETIME NULL,
  earned_at_precision VARCHAR(24) NOT NULL DEFAULT 'first-recorded',
  first_recorded_at DATETIME NOT NULL,
  last_verified_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key,achievement_key),
  KEY idx_an_achievement_key(club_slug,achievement_key,earned_at),
  KEY idx_an_achievement_user(club_slug,username_key,earned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(2);
