-- v2.9.18 Core schema 14: MIAC identity graph + PIR integrity state.
ALTER TABLE p2k_tp_members ADD COLUMN IF NOT EXISTS player_id BIGINT UNSIGNED NULL AFTER username;
ALTER TABLE p2k_tp_members ADD KEY IF NOT EXISTS idx_tp_members_player_id (club_slug,player_id);

CREATE TABLE IF NOT EXISTS p2k_miac_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  seed_format VARCHAR(40) NULL,
  seed_archive_sha256 CHAR(64) NULL,
  seed_imported_at DATETIME NULL,
  map_rebuilt_at DATETIME NULL,
  last_change_reason VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_names (
  name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  player_id BIGINT UNSIGNED NULL,
  joined_epoch BIGINT UNSIGNED NULL,
  current_member TINYINT(1) NOT NULL DEFAULT 0,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  source_flags VARCHAR(255) NULL,
  player_id_checked_at DATETIME NULL,
  hard_conflict TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miac_name(club_slug,username_key),
  KEY idx_miac_player_id(club_slug,player_id),
  KEY idx_miac_current(club_slug,current_member,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_edges (
  edge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  old_username_key VARCHAR(80) NOT NULL,
  new_username_key VARCHAR(80) NOT NULL,
  confidence VARCHAR(24) NOT NULL DEFAULT 'Possible',
  shared_boards INT UNSIGNED NOT NULL DEFAULT 0,
  same_joined TINYINT(1) NOT NULL DEFAULT 0,
  roster_handover TINYINT(1) NOT NULL DEFAULT 0,
  coexists TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('candidate','confirmed','rejected','conflict') NOT NULL DEFAULT 'candidate',
  evidence_source VARCHAR(40) NOT NULL DEFAULT 'seed',
  evidence_json MEDIUMTEXT NULL,
  reviewed_by VARCHAR(80) NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miac_edge(club_slug,old_username_key,new_username_key),
  KEY idx_miac_edge_status(club_slug,status,confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_miac_canonical_map (
  club_slug VARCHAR(120) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  canonical_username_key VARCHAR(80) NOT NULL,
  canonical_username VARCHAR(80) NOT NULL,
  resolution_reason ENUM('self','player_id','admin_confirmed','hard_conflict') NOT NULL DEFAULT 'self',
  identity_map_generation BIGINT UNSIGNED NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,username_key),
  KEY idx_miac_canonical(club_slug,canonical_username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_pir_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  cursor_match_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at DATETIME NULL,
  last_completed_cycle_at DATETIME NULL,
  checked_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  issues_found BIGINT UNSIGNED NOT NULL DEFAULT 0,
  repairs_queued BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_pir_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  issue_type VARCHAR(64) NOT NULL,
  severity ENUM('warning','error','critical') NOT NULL DEFAULT 'error',
  details_json MEDIUMTEXT NULL,
  status ENUM('open','queued','resolved','ignored') NOT NULL DEFAULT 'open',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_pir_issue(club_slug,match_id,board_id,issue_type),
  KEY idx_pir_open(club_slug,status,last_seen_at),
  KEY idx_pir_match(club_slug,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(14);
