DROP TABLE IF EXISTS survey_items;

CREATE TABLE survey_items (
  survey_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Purpose: stable primary key for responses, promotions, bakeoffs, and admin tools.',
  statement_text TEXT NOT NULL COMMENT 'Purpose: the survey statement itself. This is the core content from the spec.',
  current_tier TINYINT UNSIGNED NOT NULL COMMENT 'Purpose: current live tier only, since the spec explicitly says tier history is not important right now.',
  tier_queue_position INT UNSIGNED NULL COMMENT 'Purpose: needed for Tier 40 queue behavior.',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Purpose: lets you retire or hide items without deleting them.',
  created_by_member_id BIGINT UNSIGNED NULL COMMENT 'Purpose: nullable for seed or admin-created items; later used when community members add Tier 4 items.',
  tier_started_on DATE NULL COMMENT 'Purpose: when the item entered its current tier.',
  tier_expires_on DATE NULL COMMENT 'Purpose: when the current promotion or run should end.',
  notes_internal TEXT NULL COMMENT 'Purpose: optional admin-only notes on wording, moderation, or why an item was retired.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: auditability and ordering.',
  updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Purpose: operational convenience when items are edited or promoted.',
  PRIMARY KEY (survey_item_id),
  KEY idx_survey_items_current_tier (current_tier),
  KEY idx_survey_items_is_active (is_active),
  KEY idx_survey_items_tier_queue_position (tier_queue_position),
  KEY idx_survey_items_created_by_member_id (created_by_member_id),
  KEY idx_survey_items_tier_expires_on (tier_expires_on)
);
