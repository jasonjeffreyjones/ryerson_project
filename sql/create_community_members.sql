DROP TABLE IF EXISTS community_members;

CREATE TABLE community_members (
  community_member_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Purpose: stable primary key for links from items, votes, purchases, and activity tables.',
  orcid_id CHAR(19) NOT NULL COMMENT 'Purpose: the canonical ORCID identifier, stored in normalized form like 0000-0002-1825-0097.',
  orcid_url VARCHAR(38) NOT NULL COMMENT 'Purpose: the full ORCID URL as submitted or displayed, like https://orcid.org/....',
  email_address VARCHAR(254) NOT NULL COMMENT 'Purpose: contact email for welcome emails, admin contact, and account communication.',
  display_name VARCHAR(255) NOT NULL COMMENT 'Purpose: member-facing name shown on the member home page.',
  orcid_profile_fetched_at_utc DATETIME NULL COMMENT 'Purpose: when local profile data was last synced from ORCID.',
  orcid_record_created_on DATE NULL COMMENT 'Purpose: useful because the spec requires an ORCID profile older than 180 days.',
  membership_status VARCHAR(32) NOT NULL COMMENT 'Purpose: lifecycle state such as pending, active, suspended, rejected.',
  approved_at_utc DATETIME NULL COMMENT 'Purpose: when Dr. Jones accepted the join request.',
  welcome_email_sent_at_utc DATETIME NULL COMMENT 'Purpose: tracks whether the required welcome email has been sent.',
  nedbucks_balance INT NOT NULL DEFAULT 0 COMMENT 'Purpose: current spendable balance shown on the member home page.',
  notes_internal TEXT NULL COMMENT 'Purpose: admin-only notes about approvals, moderation, or exceptions.',
  created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Purpose: auditability and ordering.',
  updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Purpose: operational convenience.',
  PRIMARY KEY (community_member_id),
  UNIQUE KEY uniq_community_members_orcid_id (orcid_id),
  UNIQUE KEY uniq_community_members_email_address (email_address),
  KEY idx_community_members_membership_status (membership_status),
  KEY idx_community_members_approved_at_utc (approved_at_utc)
);
