<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community_lib.php';

ryerson_community_start_member_session();

function ryerson_member_clear_oauth_session(): void
{
	unset($_SESSION['ryerson_orcid_oauth_state']);
	unset($_SESSION['ryerson_orcid_oauth_mode']);
	unset($_SESSION['ryerson_pending_invitation_id']);
	unset($_SESSION['ryerson_pending_invitation_orcid_id']);
}

function ryerson_member_fetch_pending_invitation_for_callback(mysqli $mysqli, int $invitationId): array
{
	$sql = '
		SELECT
			ci.invitation_id,
			ci.community_member_id,
			ci.email_address,
			ci.orcid_id,
			cm.display_name
		FROM `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` ci
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = ci.community_member_id
		WHERE ci.invitation_id = ?
			AND ci.status = "pending"
			AND ci.expires_at_utc > UTC_TIMESTAMP()
			AND cm.membership_status = "pending"
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare pending invitation callback lookup.');
	}

	$statement->bind_param('i', $invitationId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute pending invitation callback lookup.');
	}

	if (!$statement->bind_result($invitationIdResult, $communityMemberId, $emailAddress, $orcidId, $displayName)) {
		$statement->close();
		throw new RuntimeException('Could not bind pending invitation callback lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'invitation_id' => (int) $invitationIdResult,
			'community_member_id' => (int) $communityMemberId,
			'email_address' => (string) $emailAddress,
			'orcid_id' => (string) $orcidId,
			'display_name' => (string) $displayName,
		];
	}

	$statement->close();
	if (count($row) === 0) {
		throw new RuntimeException('Pending invitation was not found.');
	}

	return $row;
}

function ryerson_member_activate_invited_member(mysqli $mysqli, array $invitation, array $orcidEligibility): array
{
	$communityMemberId = (int) $invitation['community_member_id'];
	$invitationId = (int) $invitation['invitation_id'];
	$orcidId = (string) $invitation['orcid_id'];
	$displayName = (string) $orcidEligibility['display_name'];
	$orcidRecordCreatedOn = $orcidEligibility['orcid_record_created_on'];

	$mysqli->begin_transaction();
	try {
		$activeStatus = 'active';
		$memberSql = '
			UPDATE `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
			SET
				display_name = ?,
				orcid_profile_fetched_at_utc = UTC_TIMESTAMP(),
				orcid_record_created_on = ?,
				membership_status = ?,
				updated_at_utc = UTC_TIMESTAMP()
			WHERE community_member_id = ? AND orcid_id = ?
		';
		$memberStatement = $mysqli->prepare($memberSql);
		if ($memberStatement === false) {
			throw new RuntimeException('Could not prepare member activation.');
		}

		$memberStatement->bind_param('sssis', $displayName, $orcidRecordCreatedOn, $activeStatus, $communityMemberId, $orcidId);
		if (!$memberStatement->execute()) {
			$memberStatement->close();
			throw new RuntimeException('Could not activate member.');
		}
		$memberStatement->close();

		$acceptedStatus = 'accepted';
		$invitationSql = '
			UPDATE `' . COMMUNITY_INVITATIONS_TABLE_NAME . '`
			SET status = ?, accepted_at_utc = UTC_TIMESTAMP(), updated_at_utc = UTC_TIMESTAMP()
			WHERE invitation_id = ? AND status = "pending"
		';
		$invitationStatement = $mysqli->prepare($invitationSql);
		if ($invitationStatement === false) {
			throw new RuntimeException('Could not prepare invitation acceptance.');
		}

		$invitationStatement->bind_param('si', $acceptedStatus, $invitationId);
		if (!$invitationStatement->execute()) {
			$invitationStatement->close();
			throw new RuntimeException('Could not accept invitation.');
		}
		if ($invitationStatement->affected_rows !== 1) {
			$invitationStatement->close();
			throw new RuntimeException('Invitation was not accepted.');
		}
		$invitationStatement->close();

		$mysqli->commit();
	} catch (RuntimeException $exception) {
		$mysqli->rollback();
		throw $exception;
	}

	return ryerson_community_fetch_active_member_by_orcid($mysqli, $orcidId);
}

try {
	load_env_file();

	if (isset($_GET['error'])) {
		throw new RuntimeException('ORCID returned an error: ' . (string) $_GET['error']);
	}

	$expectedState = isset($_SESSION['ryerson_orcid_oauth_state']) ? (string) $_SESSION['ryerson_orcid_oauth_state'] : '';
	$providedState = isset($_GET['state']) ? (string) $_GET['state'] : '';
	if ($expectedState === '' || $providedState === '' || !hash_equals($expectedState, $providedState)) {
		throw new RuntimeException('ORCID state did not match the active session.');
	}

	$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
	if ($code === '') {
		throw new RuntimeException('ORCID did not return an authorization code.');
	}

	$tokenResponse = ryerson_community_exchange_orcid_code($code);
	$orcidId = isset($tokenResponse['orcid']) ? ryerson_community_normalize_orcid_id((string) $tokenResponse['orcid']) : '';
	if ($orcidId === '') {
		throw new RuntimeException('ORCID did not return an ORCID identifier.');
	}

	$mode = isset($_SESSION['ryerson_orcid_oauth_mode']) ? (string) $_SESSION['ryerson_orcid_oauth_mode'] : '';
	$mysqli = create_database_connection();

	if ($mode === 'login') {
		$member = ryerson_community_fetch_active_member_by_orcid($mysqli, $orcidId);
		$mysqli->close();
		ryerson_community_set_member_session($member);
		ryerson_member_clear_oauth_session();
		header('Location: index.php');
		exit;
	}

	if ($mode !== 'invitation') {
		$mysqli->close();
		throw new RuntimeException('No active member login flow was found.');
	}

	$expectedOrcidId = isset($_SESSION['ryerson_pending_invitation_orcid_id']) ? (string) $_SESSION['ryerson_pending_invitation_orcid_id'] : '';
	if ($expectedOrcidId === '' || !hash_equals($expectedOrcidId, $orcidId)) {
		$mysqli->close();
		throw new RuntimeException('Authenticated ORCID did not match the invitation.');
	}

	$invitationId = isset($_SESSION['ryerson_pending_invitation_id']) ? (int) $_SESSION['ryerson_pending_invitation_id'] : 0;
	if ($invitationId < 1) {
		$mysqli->close();
		throw new RuntimeException('Invitation session was missing.');
	}

	$invitation = ryerson_member_fetch_pending_invitation_for_callback($mysqli, $invitationId);
	$record = ryerson_community_fetch_orcid_public_record($orcidId);
	$orcidEligibility = ryerson_community_validate_orcid_record($record, (string) $invitation['email_address']);
	if ($orcidEligibility['eligible'] !== true) {
		$mysqli->close();
		throw new RuntimeException('ORCID eligibility check failed: ' . (string) $orcidEligibility['reason']);
	}

	$member = ryerson_member_activate_invited_member($mysqli, $invitation, $orcidEligibility);
	$mysqli->close();
	ryerson_community_set_member_session($member);
	ryerson_member_clear_oauth_session();

	header('Location: index.php');
	exit;
} catch (RuntimeException $exception) {
	error_log('Ryerson ORCID callback error: ' . $exception->getMessage());
	ryerson_member_clear_oauth_session();
	ryerson_community_exit_with_message(400, 'ORCID Login Error', 'ORCID login could not be completed for this Ryerson member account.');
}
