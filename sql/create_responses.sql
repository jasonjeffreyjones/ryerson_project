DROP TABLE IF EXISTS responses;

CREATE TABLE responses (
  prolific_pid VARCHAR(64) NOT NULL COMMENT 'Purpose: identifies the respondent snapshot by Prolific respondent identifier.',
  observation_date DATE NOT NULL COMMENT 'Purpose: identifies the respondent snapshot by the day this respondent snapshot was observed.',
  survey_item_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: identifies which survey item this response belongs to.',
  response_value TINYINT UNSIGNED NOT NULL COMMENT 'Purpose: stores the required survey response as an integer from 0 to 10 inclusive.',
  presented_order TINYINT UNSIGNED NOT NULL COMMENT 'Purpose: records the item presentation order within this respondent survey, from 1 to 36.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: when this response row was stored locally.',
  PRIMARY KEY (prolific_pid, observation_date, survey_item_id),
  UNIQUE KEY uniq_responses_presented_order (prolific_pid, observation_date, presented_order),
  KEY idx_responses_survey_item_id (survey_item_id),
  KEY idx_responses_observation_date (observation_date),
  KEY idx_responses_response_value (response_value)
);
