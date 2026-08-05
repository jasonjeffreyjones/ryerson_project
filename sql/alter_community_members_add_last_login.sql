ALTER TABLE community_members
  ADD COLUMN last_login_at_utc DATETIME NULL
    COMMENT 'Purpose: most recent successful ORCID authentication, used by public Community Member statistics.'
    AFTER approved_at_utc;

UPDATE community_members cm
LEFT JOIN (
  SELECT
    community_member_id,
    MAX(accepted_at_utc) AS latest_accepted_at_utc
  FROM community_invitations
  WHERE status = 'accepted'
    AND accepted_at_utc IS NOT NULL
  GROUP BY community_member_id
) accepted_invitation
  ON accepted_invitation.community_member_id = cm.community_member_id
SET cm.last_login_at_utc = COALESCE(
  accepted_invitation.latest_accepted_at_utc,
  cm.approved_at_utc
)
WHERE cm.membership_status = 'active'
  AND cm.last_login_at_utc IS NULL;
