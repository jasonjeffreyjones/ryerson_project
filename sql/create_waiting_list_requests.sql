CREATE TABLE waiting_list_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_address VARCHAR(254) NOT NULL,
  orcid_url VARCHAR(38) NOT NULL,
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_waiting_list_requests_created_at_utc (created_at_utc)
);
