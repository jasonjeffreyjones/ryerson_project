ALTER TABLE survey_items
  ADD COLUMN current_community_score DECIMAL(10,3) NOT NULL DEFAULT 1500.000 COMMENT 'Purpose: current community Elo score calculated from Item Bakeoff results.' AFTER current_tier;

ALTER TABLE survey_items
  DROP INDEX idx_survey_items_tier_queue_position;

ALTER TABLE survey_items
  DROP COLUMN tier_queue_position;

ALTER TABLE survey_items
  ADD KEY idx_survey_items_community_score (current_community_score),
  ADD KEY idx_survey_items_tier_score (current_tier, current_community_score);
