<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../survey/survey_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	ryerson_exit_with_message(405, 'Method Not Allowed', 'Use the demo survey form to submit responses.');
}

if (!isset($_SESSION['selected_demo_survey_items']) || !is_array($_SESSION['selected_demo_survey_items']) || count($_SESSION['selected_demo_survey_items']) !== RYERSON_ITEMS_TO_PRESENT) {
	ryerson_exit_with_message(400, 'Demo Survey Expired', 'The demo survey session expired. Please start the demo survey again.');
}

$items = $_SESSION['selected_demo_survey_items'];
$postedResponses = isset($_POST['responses']) && is_array($_POST['responses']) ? $_POST['responses'] : [];

try {
	foreach ($items as $item) {
		$surveyItemId = (int) $item['survey_item_id'];
		$key = (string) $surveyItemId;
		if (!array_key_exists($key, $postedResponses)) {
			throw new RuntimeException('A required response was missing.');
		}

		ryerson_validate_response_value($postedResponses[$key]);
	}
} catch (RuntimeException $exception) {
	ryerson_exit_with_message(400, 'Invalid Demo Survey Submission', $exception->getMessage());
}

unset($_SESSION['selected_demo_survey_items']);

header('Location: 30-complete.php');
exit;
