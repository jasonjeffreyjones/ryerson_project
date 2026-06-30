<?php

declare(strict_types=1);

require_once __DIR__ . '/ryerson_bootstrap.php';
require_once __DIR__ . '/mail_lib.php';

const RYERSON_INITIAL_NEDBUCKS_BALANCE = 10;
const RYERSON_DEFAULT_INVITATION_TTL_DAYS = 30;
const RYERSON_MEMBER_SESSION_LIFETIME_SECONDS = 604800;
const RYERSON_ORCID_MINIMUM_PROFILE_AGE_DAYS = 180;
const RYERSON_SUGGESTED_ITEM_MAX_LENGTH = 2000;

function ryerson_community_html(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ryerson_community_site_base_url(): string
{
	return rtrim(get_required_env_value('RYERSON_SITE_BASE_URL'), '/');
}

function ryerson_community_exit_with_message(int $statusCode, string $title, string $message): void
{
	http_response_code($statusCode);
	$safeTitle = ryerson_community_html($title);
	$safeMessage = ryerson_community_html($message);

	echo <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-5">
      <h1 class="mb-3">{$safeTitle}</h1>
      <p>{$safeMessage}</p>
      <p><a href="index.php">Member Home</a></p>
    </main>
  </body>
</html>
HTML;
	exit;
}

function ryerson_community_normalize_orcid_id(string $orcidValue): string
{
	$orcidValue = trim($orcidValue);
	$orcidValue = preg_replace('/^https:\/\/orcid\.org\//i', '', $orcidValue);
	if ($orcidValue === null) {
		throw new RuntimeException('Could not normalize ORCID.');
	}

	$orcidValue = strtoupper($orcidValue);
	if (preg_match('/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/', $orcidValue) !== 1) {
		throw new RuntimeException('Invalid ORCID identifier.');
	}

	return $orcidValue;
}

function ryerson_community_orcid_url(string $orcidId): string
{
	return 'https://orcid.org/' . ryerson_community_normalize_orcid_id($orcidId);
}

function ryerson_community_generate_token(): string
{
	return bin2hex(random_bytes(32));
}

function ryerson_community_member_session_cookie_secure(): bool
{
	return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function ryerson_community_member_session_cookie_params(): array
{
	return [
		'lifetime' => RYERSON_MEMBER_SESSION_LIFETIME_SECONDS,
		'path' => '/',
		'secure' => ryerson_community_member_session_cookie_secure(),
		'httponly' => true,
		'samesite' => 'Lax',
	];
}

function ryerson_community_refresh_member_session_cookie(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
		return;
	}

	$params = ryerson_community_member_session_cookie_params();
	setcookie(session_name(), session_id(), [
		'expires' => time() + RYERSON_MEMBER_SESSION_LIFETIME_SECONDS,
		'path' => (string) $params['path'],
		'secure' => (bool) $params['secure'],
		'httponly' => (bool) $params['httponly'],
		'samesite' => (string) $params['samesite'],
	]);
}

function ryerson_community_start_member_session(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		ini_set('session.gc_maxlifetime', (string) RYERSON_MEMBER_SESSION_LIFETIME_SECONDS);
		session_set_cookie_params(ryerson_community_member_session_cookie_params());
		session_start();
	}

	ryerson_community_refresh_member_session_cookie();
}

function ryerson_community_hash_token(string $token): string
{
	return hash('sha256', $token);
}

function ryerson_community_invitation_ttl_days(): int
{
	$value = get_optional_env_value('RYERSON_INVITATION_TTL_DAYS', (string) RYERSON_DEFAULT_INVITATION_TTL_DAYS);
	$days = (int) $value;
	if ($days < 1) {
		return RYERSON_DEFAULT_INVITATION_TTL_DAYS;
	}

	return $days;
}

function ryerson_community_nested_value(array $source, array $path)
{
	$current = $source;
	foreach ($path as $key) {
		if (!is_array($current) || !array_key_exists($key, $current)) {
			return null;
		}
		$current = $current[$key];
	}

	return $current;
}

function ryerson_community_string_from_nested(array $source, array $path): string
{
	$value = ryerson_community_nested_value($source, $path);
	if (!is_string($value) && !is_numeric($value)) {
		return '';
	}

	return trim((string) $value);
}

function ryerson_community_http_request(string $url, string $method, array $headers, string $body): array
{
	if (!function_exists('curl_init')) {
		throw new RuntimeException('PHP curl extension is required for ORCID requests.');
	}

	$curl = curl_init($url);
	if ($curl === false) {
		throw new RuntimeException('Could not initialize HTTP request.');
	}

	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_TIMEOUT, 20);
	curl_setopt($curl, CURLOPT_USERAGENT, 'Ryerson Project Community Login');
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

	if ($method === 'POST') {
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
	}

	$responseBody = curl_exec($curl);
	$curlError = curl_error($curl);
	$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
	curl_close($curl);

	if ($responseBody === false) {
		throw new RuntimeException('ORCID request failed: ' . $curlError);
	}

	return [
		'status_code' => $statusCode,
		'body' => (string) $responseBody,
	];
}

function ryerson_community_decode_json(string $jsonText): array
{
	$decoded = json_decode($jsonText, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('ORCID returned malformed JSON.');
	}

	return $decoded;
}

function ryerson_community_fetch_orcid_public_record(string $orcidId): array
{
	$apiBaseUrl = rtrim(get_optional_env_value('RYERSON_ORCID_API_BASE_URL', 'https://pub.orcid.org'), '/');
	$url = $apiBaseUrl . '/v3.0/' . rawurlencode(ryerson_community_normalize_orcid_id($orcidId)) . '/record';
	$response = ryerson_community_http_request(
		$url,
		'GET',
		[
			'Accept: application/json',
		],
		''
	);

	if ((int) $response['status_code'] < 200 || (int) $response['status_code'] >= 300) {
		throw new RuntimeException('ORCID public record request failed with HTTP ' . (string) $response['status_code'] . '.');
	}

	return ryerson_community_decode_json((string) $response['body']);
}

function ryerson_community_orcid_record_created_timestamp(array $record): int
{
	$value = ryerson_community_nested_value($record, ['history', 'submission-date', 'value']);
	if (is_numeric($value)) {
		$timestamp = (int) $value;
		if ($timestamp > 9999999999) {
			$timestamp = (int) floor($timestamp / 1000);
		}
		return $timestamp;
	}

	if (is_string($value) && trim($value) !== '') {
		$timestamp = strtotime($value);
		if ($timestamp !== false) {
			return (int) $timestamp;
		}
	}

	return 0;
}

function ryerson_community_record_has_public_signal(array $record): bool
{
	$stringPaths = [
		['person', 'name', 'credit-name', 'value'],
		['person', 'name', 'given-names', 'value'],
		['person', 'name', 'family-name', 'value'],
		['person', 'biography', 'content'],
	];

	foreach ($stringPaths as $path) {
		if (ryerson_community_string_from_nested($record, $path) !== '') {
			return true;
		}
	}

	$arrayPaths = [
		['person', 'researcher-urls', 'researcher-url'],
		['person', 'keywords', 'keyword'],
		['person', 'external-identifiers', 'external-identifier'],
		['person', 'addresses', 'address'],
		['person', 'emails', 'email'],
		['activities-summary', 'educations', 'affiliation-group'],
		['activities-summary', 'employments', 'affiliation-group'],
		['activities-summary', 'works', 'group'],
		['activities-summary', 'fundings', 'group'],
		['activities-summary', 'peer-reviews', 'group'],
		['activities-summary', 'research-resources', 'group'],
	];

	foreach ($arrayPaths as $path) {
		$value = ryerson_community_nested_value($record, $path);
		if (is_array($value) && count($value) > 0) {
			return true;
		}
	}

	return false;
}

function ryerson_community_display_name_from_record(array $record, string $fallbackEmail): string
{
	$creditName = ryerson_community_string_from_nested($record, ['person', 'name', 'credit-name', 'value']);
	if ($creditName !== '') {
		return $creditName;
	}

	$givenNames = ryerson_community_string_from_nested($record, ['person', 'name', 'given-names', 'value']);
	$familyName = ryerson_community_string_from_nested($record, ['person', 'name', 'family-name', 'value']);
	$fullName = trim($givenNames . ' ' . $familyName);
	if ($fullName !== '') {
		return $fullName;
	}

	$emailName = trim((string) strstr($fallbackEmail, '@', true));
	if ($emailName !== '') {
		return $emailName;
	}

	return 'Ryerson member';
}

function ryerson_community_validate_orcid_record(array $record, string $fallbackEmail): array
{
	$createdTimestamp = ryerson_community_orcid_record_created_timestamp($record);
	if ($createdTimestamp <= 0) {
		return [
			'eligible' => false,
			'reason' => 'ORCID record creation date was not available.',
			'display_name' => ryerson_community_display_name_from_record($record, $fallbackEmail),
			'orcid_record_created_on' => null,
		];
	}

	$minimumTimestamp = time() - (RYERSON_ORCID_MINIMUM_PROFILE_AGE_DAYS * 86400);
	if ($createdTimestamp > $minimumTimestamp) {
		return [
			'eligible' => false,
			'reason' => 'ORCID record is not yet 180 days old.',
			'display_name' => ryerson_community_display_name_from_record($record, $fallbackEmail),
			'orcid_record_created_on' => gmdate('Y-m-d', $createdTimestamp),
		];
	}

	if (!ryerson_community_record_has_public_signal($record)) {
		return [
			'eligible' => false,
			'reason' => 'ORCID public record appears to be empty.',
			'display_name' => ryerson_community_display_name_from_record($record, $fallbackEmail),
			'orcid_record_created_on' => gmdate('Y-m-d', $createdTimestamp),
		];
	}

	return [
		'eligible' => true,
		'reason' => '',
		'display_name' => ryerson_community_display_name_from_record($record, $fallbackEmail),
		'orcid_record_created_on' => gmdate('Y-m-d', $createdTimestamp),
	];
}

function ryerson_community_exchange_orcid_code(string $code): array
{
	$baseUrl = rtrim(get_optional_env_value('RYERSON_ORCID_BASE_URL', 'https://orcid.org'), '/');
	$postFields = http_build_query([
		'client_id' => get_required_env_value('RYERSON_ORCID_CLIENT_ID'),
		'client_secret' => get_required_env_value('RYERSON_ORCID_CLIENT_SECRET'),
		'grant_type' => 'authorization_code',
		'code' => $code,
		'redirect_uri' => get_required_env_value('RYERSON_ORCID_REDIRECT_URI'),
	], '', '&');

	$response = ryerson_community_http_request(
		$baseUrl . '/oauth/token',
		'POST',
		[
			'Accept: application/json',
			'Content-Type: application/x-www-form-urlencoded',
		],
		$postFields
	);

	if ((int) $response['status_code'] < 200 || (int) $response['status_code'] >= 300) {
		throw new RuntimeException('ORCID token exchange failed with HTTP ' . (string) $response['status_code'] . '.');
	}

	return ryerson_community_decode_json((string) $response['body']);
}

function ryerson_community_orcid_authorization_url(string $state): string
{
	$baseUrl = rtrim(get_optional_env_value('RYERSON_ORCID_BASE_URL', 'https://orcid.org'), '/');
	$query = http_build_query([
		'client_id' => get_required_env_value('RYERSON_ORCID_CLIENT_ID'),
		'response_type' => 'code',
		'scope' => '/authenticate',
		'redirect_uri' => get_required_env_value('RYERSON_ORCID_REDIRECT_URI'),
		'state' => $state,
	], '', '&');

	return $baseUrl . '/oauth/authorize?' . $query;
}

function ryerson_community_send_invitation_email(string $emailAddress, string $token): bool
{
	$link = ryerson_community_site_base_url() . '/member/accept-invitation.php?token=' . rawurlencode($token);
	$subject = 'Invitation to join the Ryerson Project community';
	$message = "You have been invited to join the Ryerson Project community.\n\n";
	$message .= "To create your member account, use this invitation link:\n{$link}\n\n";
	$message .= "You will be asked to sign in with ORCID. The ORCID account must match the ORCID URL submitted with your waiting list request.\n\n";
	$message .= "If you did not request this invitation, you can ignore this email.\n";

	return ryerson_mail_send_text($emailAddress, $subject, $message);
}

function ryerson_community_send_suggestion_moderation_email(array $member, string $status, string $statementText, string $rejectionReason): bool
{
	$emailAddress = (string) $member['email_address'];
	$displayName = (string) $member['display_name'];
	$subject = $status === 'approved'
		? 'Your Ryerson suggested item was approved'
		: 'Your Ryerson suggested item was reviewed';

	$message = "Hello {$displayName},\n\n";
	if ($status === 'approved') {
		$message .= "Your suggested item was approved and added to the Ryerson Tier 40 item pool.\n\n";
		$message .= "Approved item:\n{$statementText}\n\n";
	} else {
		$message .= "Your suggested item was not approved at this time.\n\n";
		$message .= "Suggested item:\n{$statementText}\n\n";
		if ($rejectionReason !== '') {
			$message .= "Reason:\n{$rejectionReason}\n\n";
		}
	}
	$message .= "Thank you for helping build the Ryerson Project.\n";

	return ryerson_mail_send_text($emailAddress, $subject, $message);
}

function ryerson_community_fetch_pending_invitation_by_token(mysqli $mysqli, string $token): array
{
	$tokenHash = ryerson_community_hash_token($token);
	$sql = '
		SELECT
			ci.invitation_id,
			ci.waiting_list_request_id,
			ci.community_member_id,
			ci.email_address,
			ci.orcid_id,
			ci.expires_at_utc,
			cm.display_name,
			cm.membership_status
		FROM `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` ci
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = ci.community_member_id
		WHERE ci.token_hash = ?
			AND ci.status = "pending"
			AND ci.expires_at_utc > UTC_TIMESTAMP()
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare invitation lookup.');
	}

	$statement->bind_param('s', $tokenHash);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute invitation lookup.');
	}

	if (!$statement->bind_result($invitationId, $waitingListRequestId, $communityMemberId, $emailAddress, $orcidId, $expiresAtUtc, $displayName, $membershipStatus)) {
		$statement->close();
		throw new RuntimeException('Could not bind invitation lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'invitation_id' => (int) $invitationId,
			'waiting_list_request_id' => (int) $waitingListRequestId,
			'community_member_id' => (int) $communityMemberId,
			'email_address' => (string) $emailAddress,
			'orcid_id' => (string) $orcidId,
			'expires_at_utc' => (string) $expiresAtUtc,
			'display_name' => (string) $displayName,
			'membership_status' => (string) $membershipStatus,
		];
	}

	$statement->close();
	if (count($row) === 0) {
		throw new RuntimeException('Invitation token is invalid, expired, or already used.');
	}

	return $row;
}

function ryerson_community_fetch_active_member_by_orcid(mysqli $mysqli, string $orcidId): array
{
	$normalizedOrcidId = ryerson_community_normalize_orcid_id($orcidId);
	$sql = '
		SELECT community_member_id, orcid_id, email_address, display_name, nedbucks_balance
		FROM `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
		WHERE orcid_id = ? AND membership_status = "active"
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare active member lookup.');
	}

	$statement->bind_param('s', $normalizedOrcidId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute active member lookup.');
	}

	if (!$statement->bind_result($communityMemberId, $orcidIdResult, $emailAddress, $displayName, $nedbucksBalance)) {
		$statement->close();
		throw new RuntimeException('Could not bind active member lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'community_member_id' => (int) $communityMemberId,
			'orcid_id' => (string) $orcidIdResult,
			'email_address' => (string) $emailAddress,
			'display_name' => (string) $displayName,
			'nedbucks_balance' => (int) $nedbucksBalance,
		];
	}

	$statement->close();
	if (count($row) === 0) {
		throw new RuntimeException('No active Ryerson community member matched this ORCID.');
	}

	return $row;
}

function ryerson_community_set_member_session(array $member): void
{
	session_regenerate_id(true);
	$_SESSION['ryerson_member_id'] = (int) $member['community_member_id'];
	$_SESSION['ryerson_member_orcid_id'] = (string) $member['orcid_id'];
	$_SESSION['ryerson_member_display_name'] = (string) $member['display_name'];
	$_SESSION['ryerson_member_expires_at'] = time() + RYERSON_MEMBER_SESSION_LIFETIME_SECONDS;
	ryerson_community_refresh_member_session_cookie();
}

function ryerson_community_clear_member_session(): void
{
	unset($_SESSION['ryerson_member_id']);
	unset($_SESSION['ryerson_member_orcid_id']);
	unset($_SESSION['ryerson_member_display_name']);
	unset($_SESSION['ryerson_member_expires_at']);
}

function ryerson_community_current_member(mysqli $mysqli): array
{
	if (!isset($_SESSION['ryerson_member_id'])) {
		return [];
	}

	$expiresAt = isset($_SESSION['ryerson_member_expires_at']) ? (int) $_SESSION['ryerson_member_expires_at'] : 0;
	if ($expiresAt > 0 && $expiresAt < time()) {
		ryerson_community_clear_member_session();
		return [];
	}

	$communityMemberId = (int) $_SESSION['ryerson_member_id'];
	$sql = '
		SELECT community_member_id, orcid_id, email_address, display_name, nedbucks_balance
		FROM `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
		WHERE community_member_id = ? AND membership_status = "active"
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare current member lookup.');
	}

	$statement->bind_param('i', $communityMemberId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute current member lookup.');
	}

	if (!$statement->bind_result($memberId, $orcidId, $emailAddress, $displayName, $nedbucksBalance)) {
		$statement->close();
		throw new RuntimeException('Could not bind current member lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'community_member_id' => (int) $memberId,
			'orcid_id' => (string) $orcidId,
			'email_address' => (string) $emailAddress,
			'display_name' => (string) $displayName,
			'nedbucks_balance' => (int) $nedbucksBalance,
		];
	}

	$statement->close();
	if (count($row) > 0) {
		$_SESSION['ryerson_member_expires_at'] = time() + RYERSON_MEMBER_SESSION_LIFETIME_SECONDS;
	}
	return $row;
}

function ryerson_community_trim_statement_text(string $statementText): string
{
	return trim($statementText);
}

function ryerson_community_validate_suggested_statement_text(string $statementText): string
{
	$statementText = ryerson_community_trim_statement_text($statementText);
	if ($statementText === '') {
		throw new RuntimeException('Suggested item text is required.');
	}

	if (strlen($statementText) > RYERSON_SUGGESTED_ITEM_MAX_LENGTH) {
		throw new RuntimeException('Suggested item text is too long.');
	}

	return $statementText;
}

function ryerson_community_member_submitted_suggestion_today(mysqli $mysqli, int $communityMemberId): bool
{
	$sql = '
		SELECT 1
		FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '`
		WHERE community_member_id = ? AND submitted_on = UTC_DATE()
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare suggested item daily limit query.');
	}

	$statement->bind_param('i', $communityMemberId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute suggested item daily limit query.');
	}

	$statement->store_result();
	$hasSuggestion = $statement->num_rows > 0;
	$statement->close();

	return $hasSuggestion;
}

function ryerson_community_fetch_member_suggestions(mysqli $mysqli, int $communityMemberId): array
{
	$sql = '
		SELECT suggested_item_id, original_statement_text, edited_statement_text, moderation_status, rejection_reason, submitted_on, reviewed_at_utc
		FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '`
		WHERE community_member_id = ?
		ORDER BY created_at_utc DESC, suggested_item_id DESC
		LIMIT 20
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare member suggested item query.');
	}

	$statement->bind_param('i', $communityMemberId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute member suggested item query.');
	}

	if (!$statement->bind_result($suggestedItemId, $originalStatementText, $editedStatementText, $moderationStatus, $rejectionReason, $submittedOn, $reviewedAtUtc)) {
		$statement->close();
		throw new RuntimeException('Could not bind member suggested item query.');
	}

	$rows = [];
	while ($statement->fetch()) {
		$rows[] = [
			'suggested_item_id' => (int) $suggestedItemId,
			'original_statement_text' => (string) $originalStatementText,
			'edited_statement_text' => $editedStatementText === null ? '' : (string) $editedStatementText,
			'moderation_status' => (string) $moderationStatus,
			'rejection_reason' => $rejectionReason === null ? '' : (string) $rejectionReason,
			'submitted_on' => (string) $submittedOn,
			'reviewed_at_utc' => $reviewedAtUtc === null ? '' : (string) $reviewedAtUtc,
		];
	}

	$statement->close();
	return $rows;
}
