<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/survey_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	ryerson_exit_with_message(405, 'Method Not Allowed', 'Use the survey form to submit responses.');
}

$context = ryerson_require_survey_context();
$observationDate = ryerson_today();
$prolificPid = (string) $context['prolific_pid'];

if (!isset($_SESSION['selected_survey_items']) || !is_array($_SESSION['selected_survey_items']) || count($_SESSION['selected_survey_items']) !== RYERSON_ITEMS_TO_PRESENT) {
	ryerson_exit_with_message(400, 'Survey Expired', 'The survey session expired. Please reopen the study from Prolific.');
}

$items = $_SESSION['selected_survey_items'];
$postedResponses = isset($_POST['responses']) && is_array($_POST['responses']) ? $_POST['responses'] : [];
$responsesToInsert = [];

try {
	foreach ($items as $index => $item) {
		$surveyItemId = (int) $item['survey_item_id'];
		$key = (string) $surveyItemId;
		if (!array_key_exists($key, $postedResponses)) {
			throw new RuntimeException('A required response was missing.');
		}

		$responsesToInsert[] = [
			'survey_item_id' => $surveyItemId,
			'response_value' => ryerson_validate_response_value($postedResponses[$key]),
			'presented_order' => $index + 1,
		];
	}

	if (count($responsesToInsert) !== RYERSON_ITEMS_TO_PRESENT) {
		throw new RuntimeException('Wrong number of responses submitted.');
	}
} catch (RuntimeException $exception) {
	ryerson_exit_with_message(400, 'Invalid Survey Submission', $exception->getMessage());
}

$mysqli = ryerson_load_database_or_exit();

try {
	if (ryerson_has_responses_for_today($mysqli, $prolificPid, $observationDate)) {
		$mysqli->close();
		header('Location: 15-return.php');
		exit;
	}

	$mysqli->begin_transaction();
	ryerson_upsert_respondent($mysqli, $context, $observationDate);

	$sql = '
		INSERT INTO responses (prolific_pid, observation_date, survey_item_id, response_value, presented_order)
		VALUES (?, ?, ?, ?, ?)
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare response insert.');
	}

	foreach ($responsesToInsert as $response) {
		$surveyItemId = (int) $response['survey_item_id'];
		$responseValue = (int) $response['response_value'];
		$presentedOrder = (int) $response['presented_order'];
		$statement->bind_param(
			'ssiii',
			$prolificPid,
			$observationDate,
			$surveyItemId,
			$responseValue,
			$presentedOrder
		);

		if (!$statement->execute()) {
			throw new RuntimeException('Could not save survey response.');
		}
	}

	$statement->close();
	$mysqli->commit();
	$mysqli->close();
	ryerson_clear_survey_session();

	header('Location: 30-complete.php');
	exit;
} catch (RuntimeException $exception) {
	$mysqli->rollback();
	$mysqli->close();
	error_log('Ryerson survey submit error: ' . $exception->getMessage());
	ryerson_exit_with_message(500, 'Survey Save Error', 'The survey responses could not be saved.');
}
