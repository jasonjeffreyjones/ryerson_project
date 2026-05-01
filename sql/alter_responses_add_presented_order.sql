ALTER TABLE responses
  ADD COLUMN presented_order TINYINT UNSIGNED NOT NULL COMMENT 'Purpose: records the item presentation order within this respondent survey, from 1 to 24.' AFTER response_value,
  ADD UNIQUE KEY uniq_responses_presented_order (prolific_pid, observation_date, presented_order);
