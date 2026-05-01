<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/ryerson_bootstrap.php';

const SUCCESS_MESSAGE = 'Thank you for your interest. Dr. Jones will email you when community features are added in the future.';

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

try {
	load_env_file();
	$mysqli = create_database_connection();
} catch (RuntimeException $exception) {
	error_log('Ryerson waiting list bootstrap error: ' . $exception->getMessage());
	$title = strpos($exception->getMessage(), 'Environment') === 0 || strpos($exception->getMessage(), 'Could not read the environment file.') === 0
		? 'Configuration Error'
		: 'Database Error';
	$message = $title === 'Configuration Error'
		? 'Server configuration is incomplete.'
		: 'Could not connect to the database.';
	exit_with_html_message(500, $title, $message);
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
