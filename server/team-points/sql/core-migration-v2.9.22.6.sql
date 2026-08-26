CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_actions (
  action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id CHAR(36) NOT NULL,
  scope ENUM('club','player') NOT NULL,
  entity_key VARCHAR(120) NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  queue_superseded INT UNSIGNED NOT NULL DEFAULT 0,
  applied_by VARCHAR(80) NULL,
  applied_at DATETIME NOT NULL,
  KEY idx_tp_reconstruction_action_entity (run_id,scope,entity_key,applied_at),
  KEY idx_tp_reconstruction_action_scope (run_id,scope,applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(16);
