DROP TABLE IF EXISTS community_invitations;

CREATE TABLE community_invitations (
  invitation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Purpose: stable primary key for member invitation lifecycle records.',
  waiting_list_request_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: links invitation back to the original waiting list request.',
  community_member_id BIGINT UNSIGNED NOT NULL COMMENT 'Purpose: links invitation to the pending or active community member.',
  email_address VARCHAR(254) NOT NULL COMMENT 'Purpose: destination address used for the invitation email.',
  orcid_id CHAR(19) NOT NULL COMMENT 'Purpose: canonical ORCID identifier that must match the OAuth login.',
  token_hash CHAR(64) NOT NULL COMMENT 'Purpose: SHA-256 hash of the single-use invitation token; raw tokens are never stored.',
  status VARCHAR(32) NOT NULL COMMENT 'Purpose: invitation lifecycle state such as pending, accepted, expired, or email_failed.',
  sent_at_utc DATETIME NULL COMMENT 'Purpose: records when PHP mail reported that the invitation was accepted for delivery.',
  expires_at_utc DATETIME NOT NULL COMMENT 'Purpose: invitation links are accepted only before this UTC timestamp.',
  accepted_at_utc DATETIME NULL COMMENT 'Purpose: records when the invitation was successfully redeemed through ORCID.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: auditability and ordering.',
  updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Purpose: operational convenience.',
  PRIMARY KEY (invitation_id),
  UNIQUE KEY uniq_community_invitations_token_hash (token_hash),
  KEY idx_community_invitations_waiting_list_request_id (waiting_list_request_id),
  KEY idx_community_invitations_community_member_id (community_member_id),
  KEY idx_community_invitations_status_expires_at_utc (status, expires_at_utc),
  KEY idx_community_invitations_orcid_id (orcid_id)
);
