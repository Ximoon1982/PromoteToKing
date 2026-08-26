-- v2.10.4 Analytics schema 8: MCA event-date provenance.
ALTER TABLE p2k_lr_files
  ADD COLUMN IF NOT EXISTS actual_event_date DATE NULL AFTER replaced_at,
  ADD COLUMN IF NOT EXISTS effective_event_date DATE NULL AFTER actual_event_date,
  ADD COLUMN IF NOT EXISTS event_date_precision ENUM('known','interpolated','upload-fallback') NOT NULL DEFAULT 'upload-fallback' AFTER effective_event_date,
  ADD COLUMN IF NOT EXISTS event_date_updated_at DATETIME NULL AFTER event_date_precision;
UPDATE p2k_lr_files
   SET effective_event_date=COALESCE(effective_event_date,DATE(uploaded_at)),
       event_date_precision=CASE WHEN actual_event_date IS NOT NULL THEN 'known' ELSE 'upload-fallback' END,
       event_date_updated_at=COALESCE(event_date_updated_at,UTC_TIMESTAMP());
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(8);
