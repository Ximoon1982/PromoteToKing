-- v2.10.9.6 fair-play reconciliation auxiliary schema.
-- Idempotent; FairPlayReconciliationService::ensureSchema() applies the same DDL for existing installations.
CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_match_state (
  club_slug VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  removals_json LONGTEXT NOT NULL,
  source_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
  checked_at DATETIME NOT NULL,
  finalized_at DATETIME NULL,
  backfill_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  affected_games INT UNSIGNED NOT NULL DEFAULT 0,
  corrected_games INT UNSIGNED NOT NULL DEFAULT 0,
  points_added_x2 INT NOT NULL DEFAULT 0,
  raw_score_x2 INT NULL,
  effective_score_x2 INT NULL,
  official_score_x2 INT NULL,
  mismatch_before_x2 INT NULL,
  mismatch_after_x2 INT NULL,
  last_error TEXT NULL,
  PRIMARY KEY (club_slug,match_id),
  KEY idx_tp_fp_match_backfill (club_slug,backfill_version,match_id),
  KEY idx_tp_fp_match_checked (club_slug,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_game_adjustments (
  game_row_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_id BIGINT UNSIGNED NOT NULL,
  opponent_username VARCHAR(80) NOT NULL,
  raw_points_x2 TINYINT UNSIGNED NOT NULL,
  effective_points_x2 TINYINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL,
  last_verified_at DATETIME NOT NULL,
  KEY idx_tp_fp_adjust_match (club_slug,match_id),
  KEY idx_tp_fp_adjust_opponent (club_slug,opponent_username),
  CONSTRAINT fk_tp_fp_adjust_game FOREIGN KEY (game_row_id) REFERENCES p2k_tp_games(game_row_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_backfill_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  status ENUM('running','paused','complete') NOT NULL DEFAULT 'running',
  cursor_match_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checked_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  matches_with_removals BIGINT UNSIGNED NOT NULL DEFAULT 0,
  affected_games BIGINT UNSIGNED NOT NULL DEFAULT 0,
  corrected_games BIGINT UNSIGNED NOT NULL DEFAULT 0,
  points_added_x2 BIGINT NOT NULL DEFAULT 0,
  mismatches_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mismatches_resolved BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mismatches_remaining BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at DATETIME NULL,
  completed_at DATETIME NULL,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO p2k_tp_fair_play_backfill_state(club_slug,status)
VALUES ('promote-to-king','running')
ON DUPLICATE KEY UPDATE club_slug=VALUES(club_slug);
