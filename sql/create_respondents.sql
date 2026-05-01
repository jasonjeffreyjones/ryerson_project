DROP TABLE IF EXISTS respondents;

CREATE TABLE respondents (
  prolific_pid VARCHAR(64) NOT NULL COMMENT 'Purpose: stable Prolific respondent identifier.',
  observation_date DATE NOT NULL COMMENT 'Purpose: the day this respondent snapshot was observed.',
  session_id VARCHAR(128) NOT NULL COMMENT 'Purpose: Prolific session identifier for this survey completion instance.',
  study_id VARCHAR(128) NULL COMMENT 'Purpose: useful if Prolific supplies it and you may run multiple studies or backtrack source studies later.',
  observed_at_utc DATETIME NULL COMMENT 'Purpose: exact ingest or completion timestamp if available.',
  age TINYINT UNSIGNED NULL COMMENT 'Purpose: demographic snapshot as provided by Prolific that day.',
  sex VARCHAR(64) NULL COMMENT 'Purpose: demographic snapshot.',
  country_of_residence VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  nationality VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  language VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  employment_status VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  student_status VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  education_level VARCHAR(128) NULL COMMENT 'Purpose: demographic snapshot.',
  source_payload_json JSON NULL COMMENT 'Purpose: raw Prolific demographic payload snapshot for audit or debugging.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: when this row was stored locally.',
  PRIMARY KEY (prolific_pid, observation_date),
  UNIQUE KEY uniq_respondents_session_id (session_id),
  KEY idx_respondents_observation_date (observation_date),
  KEY idx_respondents_study_id (study_id)
);
