<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/ryerson_bootstrap.php';

const RYERSON_ITEMS_TO_PRESENT = 24;
const RYERSON_TIERS = [10, 20, 30, 40];
const RYERSON_ITEMS_PER_TIER = 6;
const RYERSON_TIER_40_QUEUE_WINDOW = 48;

function ryerson_html(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ryerson_today(): string
{
	return gmdate('Y-m-d');
}

function ryerson_exit_with_message(int $statusCode, string $title, string $message): void
{
	http_response_code($statusCode);
	$safeTitle = ryerson_html($title);
	$safeMessage = ryerson_html($message);

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
    </main>
  </body>
</html>
HTML;
	exit;
}

function ryerson_load_database_or_exit(): mysqli
{
	try {
		load_env_file();
		return create_database_connection();
	} catch (RuntimeException $exception) {
		error_log('Ryerson survey bootstrap error: ' . $exception->getMessage());
		ryerson_exit_with_message(500, 'Survey Configuration Error', 'The survey could not connect to its database.');
	}
}

function ryerson_has_required_survey_context(): bool
{
	return isset($_SESSION['prolific_pid'], $_SESSION['session_id'], $_SESSION['study_id']) &&
		trim((string) $_SESSION['prolific_pid']) !== '' &&
		trim((string) $_SESSION['session_id']) !== '' &&
		trim((string) $_SESSION['study_id']) !== '';
}

function ryerson_require_survey_context(): array
{
	if (!ryerson_has_required_survey_context()) {
		header('Location: 10-begin.php');
		exit;
	}

	return [
		'prolific_pid' => trim((string) $_SESSION['prolific_pid']),
		'session_id' => trim((string) $_SESSION['session_id']),
		'study_id' => trim((string) $_SESSION['study_id']),
	];
}

function ryerson_has_responses_for_today(mysqli $mysqli, string $prolificPid, string $observationDate): bool
{
	$sql = 'SELECT 1 FROM responses WHERE prolific_pid = ? AND observation_date = ? LIMIT 1';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare response-existence query.');
	}

	$statement->bind_param('ss', $prolificPid, $observationDate);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute response-existence query.');
	}

	$statement->store_result();
	$hasResponses = $statement->num_rows > 0;
	$statement->close();

	return $hasResponses;
}

function ryerson_upsert_respondent(mysqli $mysqli, array $context, string $observationDate): void
{
	$prolificPid = (string) $context['prolific_pid'];
	$sessionId = (string) $context['session_id'];
	$studyId = (string) $context['study_id'];
	$sql = '
		INSERT INTO respondents (prolific_pid, observation_date, session_id, study_id, observed_at_utc)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
		ON DUPLICATE KEY UPDATE
			session_id = VALUES(session_id),
			study_id = VALUES(study_id),
			observed_at_utc = UTC_TIMESTAMP()
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare respondent upsert.');
	}

	$statement->bind_param(
		'ssss',
		$prolificPid,
		$observationDate,
		$sessionId,
		$studyId
	);

	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not save respondent.');
	}

	$statement->close();
}

function ryerson_fetch_survey_items(mysqli $mysqli): array
{
	$selectedItems = [];

	foreach (RYERSON_TIERS as $tier) {
		$tierItems = $tier === 40
			? ryerson_fetch_tier_40_items($mysqli)
			: ryerson_fetch_random_tier_items($mysqli, $tier, RYERSON_ITEMS_PER_TIER);

		if (count($tierItems) < RYERSON_ITEMS_PER_TIER) {
			throw new RuntimeException("Not enough active Tier {$tier} items.");
		}

		foreach ($tierItems as $item) {
			$selectedItems[] = $item;
		}
	}

	if (count($selectedItems) !== RYERSON_ITEMS_TO_PRESENT) {
		throw new RuntimeException('Survey item selection produced the wrong number of items.');
	}

	shuffle($selectedItems);

	return $selectedItems;
}

function ryerson_fetch_random_tier_items(mysqli $mysqli, int $tier, int $limit): array
{
	$sql = '
		SELECT survey_item_id, statement_text, current_tier
		FROM survey_items
		WHERE is_active = 1 AND current_tier = ?
		ORDER BY RAND()
		LIMIT ' . (int) $limit;
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException("Could not prepare Tier {$tier} item query.");
	}

	$statement->bind_param('i', $tier);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException("Could not execute Tier {$tier} item query.");
	}

	$items = ryerson_collect_item_results($statement);
	$statement->close();

	return $items;
}

function ryerson_fetch_tier_40_items(mysqli $mysqli): array
{
	$sql = '
		SELECT survey_item_id, statement_text, current_tier
		FROM (
			SELECT survey_item_id, statement_text, current_tier
			FROM survey_items
			WHERE is_active = 1 AND current_tier = 40
			ORDER BY
				CASE WHEN tier_queue_position IS NULL THEN 1 ELSE 0 END,
				tier_queue_position ASC,
				created_at_utc DESC,
				survey_item_id DESC
			LIMIT ' . RYERSON_TIER_40_QUEUE_WINDOW . '
		) AS tier_40_window
		ORDER BY RAND()
		LIMIT ' . RYERSON_ITEMS_PER_TIER;
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare Tier 40 item query.');
	}

	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute Tier 40 item query.');
	}

	$items = ryerson_collect_item_results($statement);
	$statement->close();

	return $items;
}

function ryerson_collect_item_results(mysqli_stmt $statement): array
{
	if (!$statement->bind_result($surveyItemId, $statementText, $currentTier)) {
		throw new RuntimeException('Could not bind survey item results.');
	}

	$items = [];
	while ($statement->fetch()) {
		$items[] = [
			'survey_item_id' => (int) $surveyItemId,
			'statement_text' => (string) $statementText,
			'current_tier' => (int) $currentTier,
		];
	}

	return $items;
}

function ryerson_get_or_create_selected_items(mysqli $mysqli): array
{
	if (isset($_SESSION['selected_survey_items']) && is_array($_SESSION['selected_survey_items'])) {
		$items = $_SESSION['selected_survey_items'];
		if (count($items) === RYERSON_ITEMS_TO_PRESENT) {
			return $items;
		}
	}

	$items = ryerson_fetch_survey_items($mysqli);
	$_SESSION['selected_survey_items'] = $items;

	return $items;
}

function ryerson_validate_response_value($rawValue): int
{
	$value = is_string($rawValue) ? trim($rawValue) : '';
	if ($value === '' || preg_match('/^(10|[0-9])$/', $value) !== 1) {
		throw new RuntimeException('Every response must be an integer from 0 to 10.');
	}

	return (int) $value;
}

function ryerson_clear_survey_session(): void
{
	unset($_SESSION['selected_survey_items']);
}
