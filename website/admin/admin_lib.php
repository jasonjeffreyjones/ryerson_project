<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ryerson_bootstrap.php';

const RYERSON_ADMIN_PAGE_SIZE = 100;
const RYERSON_RESPONSE_EXPORT_START_DATE = '2026-05-01';
const RYERSON_RESPONSE_EXPORT_DIR = __DIR__ . '/exports';

function ryerson_admin_bootstrap(): void
{
	load_env_file();
	require_admin_basic_auth();
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
}

function ryerson_admin_html(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ryerson_admin_exit_with_error(int $statusCode, string $title, string $message): void
{
	http_response_code($statusCode);
	ryerson_admin_render_header($title);
	echo '<div class="alert alert-danger" role="alert">';
	echo '<h1 class="h4 alert-heading">' . ryerson_admin_html($title) . '</h1>';
	echo '<p class="mb-0">' . ryerson_admin_html($message) . '</p>';
	echo '</div>';
	ryerson_admin_render_footer();
	exit;
}

function ryerson_admin_get_csrf_token(): string
{
	if (!isset($_SESSION['ryerson_admin_csrf_token']) || !is_string($_SESSION['ryerson_admin_csrf_token']) || $_SESSION['ryerson_admin_csrf_token'] === '') {
		$_SESSION['ryerson_admin_csrf_token'] = bin2hex(random_bytes(32));
	}

	return (string) $_SESSION['ryerson_admin_csrf_token'];
}

function ryerson_admin_verify_csrf_token(): void
{
	$providedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
	$expectedToken = ryerson_admin_get_csrf_token();
	if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
		ryerson_admin_exit_with_error(400, 'Invalid Request', 'The admin form token was invalid. Please reload the page and try again.');
	}
}

function ryerson_admin_set_flash(string $type, string $message): void
{
	$_SESSION['ryerson_admin_flash'] = [
		'type' => $type,
		'message' => $message,
	];
}

function ryerson_admin_pop_flash(): array
{
	if (!isset($_SESSION['ryerson_admin_flash']) || !is_array($_SESSION['ryerson_admin_flash'])) {
		return [];
	}

	$flash = $_SESSION['ryerson_admin_flash'];
	unset($_SESSION['ryerson_admin_flash']);

	return [
		'type' => isset($flash['type']) ? (string) $flash['type'] : 'info',
		'message' => isset($flash['message']) ? (string) $flash['message'] : '',
	];
}

function ryerson_admin_render_header(string $title): void
{
	$safeTitle = ryerson_admin_html($title);
	echo <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <link rel="icon" type="image/png" href="../images/ryerson-project-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Admin navigation">
        <a class="btn btn-sm btn-outline-secondary" href="index.php">Admin Home</a>
        <a class="btn btn-sm btn-outline-secondary" href="waiting_list.php">Waiting List</a>
        <a class="btn btn-sm btn-outline-secondary" href="suggested_items.php">Suggested Items</a>
        <a class="btn btn-sm btn-outline-secondary" href="item_bakeoffs.php">Item Bakeoffs</a>
        <a class="btn btn-sm btn-outline-secondary" href="items_retier.php">Item Retiering</a>
        <a class="btn btn-sm btn-outline-secondary" href="daily_admin_overview.php">Admin Overview</a>
        <a class="btn btn-sm btn-outline-secondary" href="responses_export.php">Response Exports</a>
        <a class="btn btn-sm btn-outline-secondary" href="smtp_test.php">SMTP Test</a>
      </nav>
HTML;
}

function ryerson_admin_render_footer(): void
{
	echo <<<HTML
    </main>
  </body>
</html>
HTML;
}

function ryerson_admin_response_export_file_name(string $observationDate): string
{
	return 'responses_' . str_replace('-', '_', $observationDate) . '.csv.gz';
}

function ryerson_admin_response_export_path(string $observationDate): string
{
	return RYERSON_RESPONSE_EXPORT_DIR . '/' . ryerson_admin_response_export_file_name($observationDate);
}

function ryerson_admin_get_export_observation_dates(): array
{
	$dates = [];
	$startDate = DateTime::createFromFormat('!Y-m-d', RYERSON_RESPONSE_EXPORT_START_DATE);
	$endDate = DateTime::createFromFormat('!Y-m-d', date('Y-m-d', strtotime('-1 day')));
	if ($startDate === false || $endDate === false || $endDate < $startDate) {
		return $dates;
	}

	while ($startDate <= $endDate) {
		$dates[] = $startDate->format('Y-m-d');
		$startDate->modify('+1 day');
	}

	return $dates;
}

function ryerson_admin_get_response_export_status(): array
{
	$dates = ryerson_admin_get_export_observation_dates();
	$existing = [];
	$missing = [];

	foreach ($dates as $date) {
		if (is_file(ryerson_admin_response_export_path($date))) {
			$existing[] = $date;
		} else {
			$missing[] = $date;
		}
	}

	return [
		'total_dates' => count($dates),
		'existing_dates' => $existing,
		'missing_dates' => $missing,
	];
}
