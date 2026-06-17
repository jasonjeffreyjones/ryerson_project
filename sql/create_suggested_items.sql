DROP TABLE IF EXISTS suggested_items;

CREATE TABLE suggested_items (
  suggested_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Purpose: stable primary key for community suggested item moderation.',
  community_member_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: member who submitted the suggested item.',
  original_statement_text VARCHAR(2000) NOT NULL COMMENT 'Purpose: exact trimmed statement submitted by the member.',
  edited_statement_text VARCHAR(2000) NULL COMMENT 'Purpose: admin-edited statement used if the suggestion is approved.',
  moderation_status VARCHAR(32) NOT NULL COMMENT 'Purpose: pending, approved, or rejected.',
  rejection_reason TEXT NULL COMMENT 'Purpose: explanation sent to the member when a suggestion is rejected.',
  approved_survey_item_id BIGINT UNSIGNED NULL COMMENT 'Purpose: survey_items row created after approval.',
  submitted_on DATE NOT NULL COMMENT 'Purpose: enforces one suggested item per member per UTC day.',
  reviewed_at_utc DATETIME NULL COMMENT 'Purpose: when admin approved or rejected the suggestion.',
  notification_sent_at_utc DATETIME NULL COMMENT 'Purpose: when PHP mail reported the approval/rejection email accepted for delivery.',
  notification_status VARCHAR(32) NULL COMMENT 'Purpose: sent or email_failed for the moderation outcome notification.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: auditability and ordering.',
  updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Purpose: operational convenience.',
  PRIMARY KEY (suggested_item_id),
  UNIQUE KEY uniq_suggested_items_member_day (community_member_id, submitted_on),
  KEY idx_suggested_items_moderation_status (moderation_status),
  KEY idx_suggested_items_created_at_utc (created_at_utc),
  KEY idx_suggested_items_approved_survey_item_id (approved_survey_item_id)
);
