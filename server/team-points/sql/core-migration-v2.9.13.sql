-- v2.9.13 Core schema 13: canonical outstanding-work identity and coalescing generations.
ALTER TABLE p2k_tp_job_items
  ADD COLUMN canonical_scope VARCHAR(120) NOT NULL DEFAULT '' AFTER item_key,
  ADD COLUMN canonical_key VARCHAR(190) NOT NULL DEFAULT '' AFTER canonical_scope,
  ADD COLUMN priority_rank SMALLINT NOT NULL DEFAULT 100 AFTER canonical_key,
  ADD COLUMN generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER priority_rank,
  ADD COLUMN requested_generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER generation,
  ADD COLUMN requested_item_type VARCHAR(40) NULL AFTER requested_generation,
  ADD COLUMN requested_item_key VARCHAR(255) NULL AFTER requested_item_type,
  ADD COLUMN requested_payload_json MEDIUMTEXT NULL AFTER requested_item_key,
  ADD COLUMN coalesced_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER requested_payload_json,
  ADD COLUMN last_requested_at DATETIME NULL AFTER coalesced_count,
  ADD COLUMN active_dedupe_key VARCHAR(320)
    GENERATED ALWAYS AS (
      CASE
        WHEN status IN ('pending','running','retry') AND canonical_scope <> '' AND canonical_key <> ''
        THEN CONCAT(canonical_scope,'|',canonical_key)
        ELSE NULL
      END
    ) STORED AFTER last_requested_at,
  DROP INDEX uq_tp_job_item,
  ADD KEY idx_tp_job_item_legacy(job_id,item_type,item_key),
  ADD KEY idx_tp_job_canonical(canonical_scope,canonical_key,status),
  ADD KEY idx_tp_job_priority(job_id,status,priority_rank,available_at,id),
  ADD UNIQUE KEY uq_tp_active_canonical(active_dedupe_key);

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(13);
