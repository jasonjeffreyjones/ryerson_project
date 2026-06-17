<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/lib/community_lib.php';

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
	ryerson_community_exit_with_message(400, 'Invalid Invitation', 'This invitation link is invalid or incomplete.');
}

try {
	load_env_file();
	$mysqli = create_database_connection();
	$invitation = ryerson_community_fetch_pending_invitation_by_token($mysqli, $token);
	$mysqli->close();

	$state = ryerson_community_generate_token();
	$_SESSION['ryerson_orcid_oauth_state'] = $state;
	$_SESSION['ryerson_orcid_oauth_mode'] = 'invitation';
	$_SESSION['ryerson_pending_invitation_id'] = (int) $invitation['invitation_id'];
	$_SESSION['ryerson_pending_invitation_orcid_id'] = (string) $invitation['orcid_id'];

	header('Location: ' . ryerson_community_orcid_authorization_url($state));
	exit;
} catch (RuntimeException $exception) {
	error_log('Ryerson invitation acceptance error: ' . $exception->getMessage());
	ryerson_community_exit_with_message(400, 'Invitation Error', 'This invitation link is invalid, expired, or already used.');
}
