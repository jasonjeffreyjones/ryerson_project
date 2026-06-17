<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/lib/community_lib.php';

try {
	load_env_file();
	$state = ryerson_community_generate_token();
	$_SESSION['ryerson_orcid_oauth_state'] = $state;
	$_SESSION['ryerson_orcid_oauth_mode'] = 'login';

	header('Location: ' . ryerson_community_orcid_authorization_url($state));
	exit;
} catch (RuntimeException $exception) {
	error_log('Ryerson member login error: ' . $exception->getMessage());
	ryerson_community_exit_with_message(500, 'Member Login Error', 'ORCID login could not be started.');
}
