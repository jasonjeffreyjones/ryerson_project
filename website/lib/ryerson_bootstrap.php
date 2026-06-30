<?php

declare(strict_types=1);

const WAITING_LIST_TABLE_NAME = 'waiting_list_requests';
const COMMUNITY_MEMBERS_TABLE_NAME = 'community_members';
const COMMUNITY_INVITATIONS_TABLE_NAME = 'community_invitations';
const SUGGESTED_ITEMS_TABLE_NAME = 'suggested_items';
const ITEM_BAKEOFF_RESULTS_TABLE_NAME = 'item_bakeoff_results';
define('DEFAULT_ENV_PATH', dirname(__DIR__, 2) . '/.env');

function load_env_file(): void
{
	$envPath = getenv('RYERSON_ENV_FILE');
	$candidatePaths = [];
	if ($envPath !== false && $envPath !== '') {
		$candidatePaths[] = $envPath;
	} else {
		$candidatePaths = [DEFAULT_ENV_PATH];
	}

	$readableEnvPath = null;
	foreach ($candidatePaths as $candidatePath) {
		if (is_readable($candidatePath)) {
			$readableEnvPath = $candidatePath;
			break;
		}
	}

	if ($readableEnvPath === null) {
		throw new RuntimeException('Environment file is missing or unreadable.');
	}

	$lines = file($readableEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		throw new RuntimeException('Could not read the environment file.');
	}

	foreach ($lines as $line) {
		$trimmedLine = trim($line);
		if ($trimmedLine === '' || substr($trimmedLine, 0, 1) === '#') {
			continue;
		}

		$separatorPosition = strpos($trimmedLine, '=');
		if ($separatorPosition === false) {
			continue;
		}

		$key = trim(substr($trimmedLine, 0, $separatorPosition));
		$value = trim(substr($trimmedLine, $separatorPosition + 1));
		$firstCharacter = substr($value, 0, 1);
		$lastCharacter = substr($value, -1);
		if (strlen($value) >= 2 && ($firstCharacter === '"' || $firstCharacter === "'") && $firstCharacter === $lastCharacter) {
			$value = substr($value, 1, -1);
		}

		putenv("{$key}={$value}");
		$_ENV[$key] = $value;
		$_SERVER[$key] = $value;
	}
}

function get_required_env_value(string $key): string
{
	$value = getenv($key);
	if ($value === false || $value === '') {
		throw new RuntimeException("Environment variable {$key} is missing.");
	}

	return $value;
}

function get_optional_env_value(string $key, string $defaultValue): string
{
	$value = getenv($key);
	if ($value === false || $value === '') {
		return $defaultValue;
	}

	return $value;
}

function create_database_connection(): mysqli
{
	$mysqli = mysqli_init();
	if ($mysqli === false) {
		throw new RuntimeException('Could not initialize the database connection.');
	}

	$connected = $mysqli->real_connect(
		get_required_env_value('RYERSON_DB_HOST'),
		get_required_env_value('RYERSON_DB_USER'),
		get_required_env_value('RYERSON_DB_PASSWORD'),
		get_required_env_value('RYERSON_DB_NAME'),
		(int) get_required_env_value('RYERSON_DB_PORT')
	);

	if ($connected !== true || $mysqli->connect_errno !== 0) {
		throw new RuntimeException('Could not connect to the database.');
	}

	return $mysqli;
}

function require_admin_basic_auth(): void
{
	$expectedUsername = get_required_env_value('RYERSON_ADMIN_USERNAME');
	$expectedPassword = get_required_env_value('RYERSON_ADMIN_PASSWORD');
	$providedCredentials = get_provided_basic_auth_credentials();
	$providedUsername = $providedCredentials['username'];
	$providedPassword = $providedCredentials['password'];

	if (!hash_equals($expectedUsername, $providedUsername) || !hash_equals($expectedPassword, $providedPassword)) {
		header('WWW-Authenticate: Basic realm="Ryerson Admin"');
		http_response_code(401);
		header('Content-Type: text/plain; charset=utf-8');
		echo "Authentication required.\n";
		exit;
	}
}

function get_provided_basic_auth_credentials(): array
{
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		return [
			'username' => (string) $_SERVER['PHP_AUTH_USER'],
			'password' => (string) $_SERVER['PHP_AUTH_PW'],
		];
	}

	$authorizationHeader = '';
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
		$authorizationHeader = (string) $_SERVER['HTTP_AUTHORIZATION'];
	} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
		$authorizationHeader = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	}

	if (stripos($authorizationHeader, 'Basic ') !== 0) {
		return [
			'username' => '',
			'password' => '',
		];
	}

	$encodedCredentials = substr($authorizationHeader, 6);
	$decodedCredentials = base64_decode($encodedCredentials, true);
	if ($decodedCredentials === false) {
		return [
			'username' => '',
			'password' => '',
		];
	}

	$separatorPosition = strpos($decodedCredentials, ':');
	if ($separatorPosition === false) {
		return [
			'username' => '',
			'password' => '',
		];
	}

	return [
		'username' => substr($decodedCredentials, 0, $separatorPosition),
		'password' => substr($decodedCredentials, $separatorPosition + 1),
	];
}
