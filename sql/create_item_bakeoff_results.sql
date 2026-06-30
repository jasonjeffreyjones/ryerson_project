DROP TABLE IF EXISTS item_bakeoff_results;

CREATE TABLE item_bakeoff_results (
  bakeoff_result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Purpose: stable primary key for item bakeoff submissions and future Elo scoring.',
  community_member_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: member who submitted the bakeoff choice.',
  left_survey_item_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: survey item presented on the left side.',
  right_survey_item_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: survey item presented on the right side.',
  chosen_survey_item_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: survey item the member chose to prioritize higher.',
  pair_key VARCHAR(64) NOT NULL COMMENT 'Purpose: canonical unordered pair key used to prevent same-day pair repeats per member.',
  submitted_on DATE NOT NULL COMMENT 'Purpose: UTC date for daily limits and same-day pair repeat checks.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: chronological scoring input and auditability.',
  PRIMARY KEY (bakeoff_result_id),
  UNIQUE KEY uniq_item_bakeoff_member_day_pair (community_member_id, submitted_on, pair_key),
  KEY idx_item_bakeoff_member_day (community_member_id, submitted_on),
  KEY idx_item_bakeoff_created_at_utc (created_at_utc),
  KEY idx_item_bakeoff_left_item (left_survey_item_id),
  KEY idx_item_bakeoff_right_item (right_survey_item_id),
  KEY idx_item_bakeoff_chosen_item (chosen_survey_item_id)
);
