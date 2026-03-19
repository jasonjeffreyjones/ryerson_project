<?php

declare(strict_types=1);

const SUCCESS_MESSAGE = 'Thank you for your interest. Dr. Jones will email you when community features are added in the future.';
const WAITING_LIST_TABLE_NAME = 'waiting_list_requests';
const DEFAULT_ENV_PATH = '/home/ec2-user/ryerson_project/.env';
const FALLBACK_ENV_PATHS = [
	'/home/jasodfzw/ryerson.env',
	DEFAULT_ENV_PATH,
];

function exit_with_html_message(int $statusCode, string $title, string $message): void
{
	http_response_code($statusCode);
	$safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
	$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

	echo <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <link rel="icon" type="image/png" href="images/ryerson-project-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-5">
      <h1 class="mb-3">{$safeTitle}</h1>
      <p>{$safeMessage}</p>
      <p><a href="participate.html">Return to Participate</a></p>
    </main>
  </body>
</html>
HTML;
	exit;
}

function load_env_file(): void
{
	$envPath = getenv('RYERSON_ENV_FILE');
	$candidatePaths = [];
	if ($envPath !== false && $envPath !== '') {
		$candidatePaths[] = $envPath;
	} else {
		$candidatePaths = FALLBACK_ENV_PATHS;
	}

	$readableEnvPath = null;
	foreach ($candidatePaths as $candidatePath) {
		if (is_readable($candidatePath)) {
			$readableEnvPath = $candidatePath;
			break;
		}
	}

	if ($readableEnvPath === null) {
		exit_with_html_message(500, 'Configuration Error', 'Environment file is missing or unreadable.');
	}

	$lines = file($readableEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		exit_with_html_message(500, 'Configuration Error', 'Could not read the environment file.');
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
		exit_with_html_message(500, 'Configuration Error', "Environment variable {$key} is missing.");
	}

	return $value;
}

function validate_email_address(string $emailAddress): string
{
	$emailAddress = trim($emailAddress);
	if ($emailAddress === '' || strlen($emailAddress) > 254) {
		exit_with_html_message(400, 'Invalid Submission', 'Please provide a valid email address.');
	}

	if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
		exit_with_html_message(400, 'Invalid Submission', 'Please provide a valid email address.');
	}

	return $emailAddress;
}

function validate_orcid_url(string $orcidUrl): string
{
	$orcidUrl = trim($orcidUrl);
	$pattern = '/^https:\/\/orcid\.org\/\d{4}-\d{4}-\d{4}-[\dX]{4}$/';
	if ($orcidUrl === '' || preg_match($pattern, $orcidUrl) !== 1) {
		exit_with_html_message(400, 'Invalid Submission', 'Please provide a valid ORCID URL.');
	}

	return $orcidUrl;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	exit_with_html_message(405, 'Method Not Allowed', 'Use the Participate form to submit a waiting list request.');
}

$emailAddress = validate_email_address($_POST['emailAddress'] ?? '');
$orcidUrl = validate_orcid_url($_POST['orcidUrl'] ?? '');
load_env_file();

$mysqli = mysqli_init();
if ($mysqli === false) {
	exit_with_html_message(500, 'Database Error', 'Could not initialize the database connection.');
}

$mysqli->real_connect(
	get_required_env_value('RYERSON_DB_HOST'),
	get_required_env_value('RYERSON_DB_USER'),
	get_required_env_value('RYERSON_DB_PASSWORD'),
	get_required_env_value('RYERSON_DB_NAME'),
	(int) get_required_env_value('RYERSON_DB_PORT')
);

if ($mysqli->connect_errno !== 0) {
	error_log('Ryerson DB connect error: ' . $mysqli->connect_error);
	exit_with_html_message(500, 'Database Error', 'Could not connect to the database.');
}

$sql = "INSERT INTO `" . WAITING_LIST_TABLE_NAME . "` (email_address, orcid_url) VALUES (?, ?)";
$statement = $mysqli->prepare($sql);
if ($statement === false) {
	exit_with_html_message(500, 'Database Error', 'Could not prepare the database insert statement.');
}

$statement->bind_param('ss', $emailAddress, $orcidUrl);
if (!$statement->execute()) {
	exit_with_html_message(500, 'Database Error', 'Could not save the waiting list request.');
}

$statement->close();
$mysqli->close();

exit_with_html_message(200, 'Thank You', SUCCESS_MESSAGE);
