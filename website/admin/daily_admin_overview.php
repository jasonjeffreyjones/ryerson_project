<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';
require_once dirname(__DIR__) . '/lib/mail_lib.php';

const RYERSON_ADMIN_OVERVIEW_DEFAULT_TO = 'jason.j.jones@stonybrook.edu';

function ryerson_admin_overview_previous_day_utc(): string
{
	$todayUtc = new DateTimeImmutable('today', new DateTimeZone('UTC'));
	return $todayUtc->modify('-1 day')->format('Y-m-d');
}

function ryerson_admin_overview_scalar_count(mysqli $mysqli, string $sql, string $errorMessage): int
{
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException($errorMessage);
	}

	$row = $result->fetch_assoc();
	$count = isset($row['total_count']) ? (int) $row['total_count'] : 0;
	$result->close();
	return $count;
}

function ryerson_admin_overview_count_for_date(mysqli $mysqli, string $sql, string $date, string $errorMessage): int
{
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException($errorMessage);
	}

	$statement->bind_param('s', $date);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException($errorMessage);
	}

	if (!$statement->bind_result($totalCount)) {
		$statement->close();
		throw new RuntimeException($errorMessage);
	}

	$count = 0;
	if ($statement->fetch()) {
		$count = (int) $totalCount;
	}
	$statement->close();
	return $count;
}

function ryerson_admin_overview_grouped_counts(mysqli $mysqli, string $sql, string $keyName, string $errorMessage): array
{
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException($errorMessage);
	}

	$counts = [];
	while ($row = $result->fetch_assoc()) {
		$key = isset($row[$keyName]) && $row[$keyName] !== null ? (string) $row[$keyName] : '(blank)';
		$counts[$key] = isset($row['total_count']) ? (int) $row['total_count'] : 0;
	}
	$result->close();
	ksort($counts);
	return $counts;
}

function ryerson_admin_overview_grouped_counts_for_date(mysqli $mysqli, string $sql, string $date, string $errorMessage): array
{
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException($errorMessage);
	}

	$statement->bind_param('s', $date);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException($errorMessage);
	}

	if (!$statement->bind_result($groupKey, $totalCount)) {
		$statement->close();
		throw new RuntimeException($errorMessage);
	}

	$counts = [];
	while ($statement->fetch()) {
		$key = $groupKey !== null ? (string) $groupKey : '(blank)';
		$counts[$key] = (int) $totalCount;
	}
	$statement->close();
	ksort($counts);
	return $counts;
}

function ryerson_admin_overview_response_value_counts(mysqli $mysqli, ?string $observationDate): array
{
	if ($observationDate === null) {
		$counts = ryerson_admin_overview_grouped_counts(
			$mysqli,
			'SELECT response_value, COUNT(*) AS total_count FROM responses GROUP BY response_value ORDER BY response_value ASC',
			'response_value',
			'Could not count responses by response value.'
		);
	} else {
		$counts = ryerson_admin_overview_grouped_counts_for_date(
			$mysqli,
			'SELECT response_value, COUNT(*) AS total_count FROM responses WHERE observation_date = ? GROUP BY response_value ORDER BY response_value ASC',
			$observationDate,
			'Could not count previous-day responses by response value.'
		);
	}

	$responseValueCounts = [];
	for ($value = 0; $value <= 10; $value++) {
		$key = (string) $value;
		$responseValueCounts[$key] = isset($counts[$key]) ? (int) $counts[$key] : 0;
	}

	return $responseValueCounts;
}

function ryerson_admin_overview_fetch_counts(mysqli $mysqli): array
{
	$previousDayUtc = ryerson_admin_overview_previous_day_utc();

	return [
		'generated_at_utc' => gmdate('Y-m-d H:i:s'),
		'previous_day_utc' => $previousDayUtc,
		'respondent_observation_dates_all_time' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM respondents',
			'Could not count respondent observation dates.'
		),
		'respondent_observation_dates_previous_day' => ryerson_admin_overview_count_for_date(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM respondents WHERE observation_date = ?',
			$previousDayUtc,
			'Could not count previous-day respondent observation dates.'
		),
		'unique_prolific_pids_all_time' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(DISTINCT prolific_pid) AS total_count FROM respondents',
			'Could not count unique Prolific respondent IDs.'
		),
		'responses_all_time' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM responses',
			'Could not count responses.'
		),
		'responses_previous_day' => ryerson_admin_overview_count_for_date(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM responses WHERE observation_date = ?',
			$previousDayUtc,
			'Could not count previous-day responses.'
		),
		'response_values_all_time' => ryerson_admin_overview_response_value_counts($mysqli, null),
		'response_values_previous_day' => ryerson_admin_overview_response_value_counts($mysqli, $previousDayUtc),
		'active_survey_items' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM survey_items WHERE is_active = 1',
			'Could not count active survey items.'
		),
		'active_survey_items_by_tier' => ryerson_admin_overview_grouped_counts(
			$mysqli,
			'SELECT current_tier, COUNT(*) AS total_count FROM survey_items WHERE is_active = 1 GROUP BY current_tier ORDER BY current_tier ASC',
			'current_tier',
			'Could not count active survey items by tier.'
		),
		'waiting_list_requests' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . WAITING_LIST_TABLE_NAME . '`',
			'Could not count waiting list requests.'
		),
		'community_invitations' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . COMMUNITY_INVITATIONS_TABLE_NAME . '`',
			'Could not count community invitations.'
		),
		'community_invitations_by_status' => ryerson_admin_overview_grouped_counts(
			$mysqli,
			'SELECT status, COUNT(*) AS total_count FROM `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` GROUP BY status ORDER BY status ASC',
			'status',
			'Could not count community invitations by status.'
		),
		'community_members' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . COMMUNITY_MEMBERS_TABLE_NAME . '`',
			'Could not count community members.'
		),
		'item_bakeoffs_all_time' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`',
			'Could not count Item Bakeoff results.'
		),
		'item_bakeoffs_previous_day' => ryerson_admin_overview_count_for_date(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` WHERE submitted_on = ?',
			$previousDayUtc,
			'Could not count previous-day Item Bakeoff results.'
		),
		'item_bakeoff_members_all_time' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(DISTINCT community_member_id) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`',
			'Could not count unique Item Bakeoff members.'
		),
		'item_bakeoff_members_previous_day' => ryerson_admin_overview_count_for_date(
			$mysqli,
			'SELECT COUNT(DISTINCT community_member_id) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` WHERE submitted_on = ?',
			$previousDayUtc,
			'Could not count previous-day unique Item Bakeoff members.'
		),
		'suggested_items' => ryerson_admin_overview_scalar_count(
			$mysqli,
			'SELECT COUNT(*) AS total_count FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '`',
			'Could not count suggested items.'
		),
		'suggested_items_by_status' => ryerson_admin_overview_grouped_counts(
			$mysqli,
			'SELECT moderation_status, COUNT(*) AS total_count FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '` GROUP BY moderation_status ORDER BY moderation_status ASC',
			'moderation_status',
			'Could not count suggested items by moderation status.'
		),
	];
}

function ryerson_admin_overview_format_grouped_counts(array $counts): string
{
	if (count($counts) === 0) {
		return 'none';
	}

	$parts = [];
	foreach ($counts as $key => $count) {
		$parts[] = (string) $key . ': ' . (string) $count;
	}

	return implode(', ', $parts);
}

function ryerson_admin_overview_build_email_body(array $counts): string
{
	$lines = [
		'Ryerson Project Admin Overview',
		'Generated at UTC: ' . (string) $counts['generated_at_utc'],
		'Previous UTC day: ' . (string) $counts['previous_day_utc'],
		'',
		'Respondents',
		'- Total unique respondent-observation_date all-time: ' . (string) $counts['respondent_observation_dates_all_time'],
		'- Total unique respondent-observation_date previous day: ' . (string) $counts['respondent_observation_dates_previous_day'],
		'- Total unique prolific_pid within respondents all-time: ' . (string) $counts['unique_prolific_pids_all_time'],
		'',
		'Responses',
		'- Total responses all-time: ' . (string) $counts['responses_all_time'],
		'- Total responses previous day: ' . (string) $counts['responses_previous_day'],
		'- Response values all-time: ' . ryerson_admin_overview_format_grouped_counts($counts['response_values_all_time']),
		'- Response values previous day: ' . ryerson_admin_overview_format_grouped_counts($counts['response_values_previous_day']),
		'',
		'Survey Items',
		'- Total active survey_items: ' . (string) $counts['active_survey_items'],
		'- Active survey_items by current_tier: ' . ryerson_admin_overview_format_grouped_counts($counts['active_survey_items_by_tier']),
		'',
		'Community',
		'- Total waiting_list_requests: ' . (string) $counts['waiting_list_requests'],
		'- Total invitations: ' . (string) $counts['community_invitations'],
		'- Invitations by status: ' . ryerson_admin_overview_format_grouped_counts($counts['community_invitations_by_status']),
		'- Total community members: ' . (string) $counts['community_members'],
		'- Total suggested_items: ' . (string) $counts['suggested_items'],
		'- Suggested items by moderation_status: ' . ryerson_admin_overview_format_grouped_counts($counts['suggested_items_by_status']),
		'',
		'Item Bakeoffs',
		'- Total all-time Item Bakeoff results: ' . (string) $counts['item_bakeoffs_all_time'],
		'- Item Bakeoff results previous day: ' . (string) $counts['item_bakeoffs_previous_day'],
		'- Total all-time unique members who submitted an Item Bakeoff: ' . (string) $counts['item_bakeoff_members_all_time'],
		'- Unique members who submitted an Item Bakeoff previous day: ' . (string) $counts['item_bakeoff_members_previous_day'],
		'',
		'Links',
		'- Ryerson Home: https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/',
		'- Ryerson Administration: https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/admin/',
		'- Zenodo Data: https://zenodo.org/records/21058880',
	];

	return implode("\n", $lines) . "\n";
}

function ryerson_admin_overview_subject(array $counts): string
{
	return 'Ryerson Admin Overview for ' . (string) $counts['previous_day_utc'];
}

$counts = [];
$emailSent = false;
$recipientAddress = '';
$errorMessage = '';
$mysqli = null;

try {
	ryerson_admin_bootstrap();
	$recipientAddress = get_optional_env_value('RYERSON_DAILY_EMAIL_TO', RYERSON_ADMIN_OVERVIEW_DEFAULT_TO);
	$mysqli = create_database_connection();
	$counts = ryerson_admin_overview_fetch_counts($mysqli);
	$mysqli->close();
	$mysqli = null;

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$subject = ryerson_admin_overview_subject($counts);
		$body = ryerson_admin_overview_build_email_body($counts);
		$emailSent = ryerson_mail_send_text($recipientAddress, $subject, $body);
		if (!$emailSent) {
			$errorMessage = 'The Admin Overview email could not be sent. Check the PHP error log for SMTP details.';
		}
	} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
		ryerson_admin_exit_with_error(405, 'Method Not Allowed', 'Use the admin form to send the Admin Overview email.');
	}
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson Admin Overview error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Admin Overview Error', $exception->getMessage());
}

ryerson_admin_render_header('Admin Overview');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Admin Overview</h1>
          <p class="text-muted mb-0">Daily project counts and the automated Admin Overview email.</p>
        </div>
        <div class="text-muted small">Previous UTC day: <?php echo ryerson_admin_html((string) $counts['previous_day_utc']); ?></div>
      </div>

      <?php if ($emailSent): ?>
      <div class="alert alert-success" role="alert">
        Admin Overview email accepted by SMTP for <?php echo ryerson_admin_html($recipientAddress); ?>.
      </div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert">
        <?php echo ryerson_admin_html($errorMessage); ?>
      </div>
      <?php endif; ?>

      <section class="border rounded p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
          <div>
            <h2 class="h4 mb-1">Send Email</h2>
            <p class="mb-0">Recipient: <?php echo ryerson_admin_html($recipientAddress); ?></p>
          </div>
          <form method="post" action="daily_admin_overview.php">
            <button type="submit" class="btn btn-primary">Send Admin Overview</button>
          </form>
        </div>
      </section>

      <section class="mb-4">
        <h2 class="h4">Current Counts</h2>
        <dl class="row">
          <dt class="col-sm-5">Generated at UTC</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['generated_at_utc']); ?></dd>
          <dt class="col-sm-5">Respondent-observation_date all-time</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['respondent_observation_dates_all_time']); ?></dd>
          <dt class="col-sm-5">Respondent-observation_date previous day</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['respondent_observation_dates_previous_day']); ?></dd>
          <dt class="col-sm-5">Unique prolific_pid all-time</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['unique_prolific_pids_all_time']); ?></dd>
          <dt class="col-sm-5">Responses all-time</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['responses_all_time']); ?></dd>
          <dt class="col-sm-5">Responses previous day</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['responses_previous_day']); ?></dd>
          <dt class="col-sm-5">Response values all-time</dt><dd class="col-sm-7"><?php echo ryerson_admin_html(ryerson_admin_overview_format_grouped_counts($counts['response_values_all_time'])); ?></dd>
          <dt class="col-sm-5">Response values previous day</dt><dd class="col-sm-7"><?php echo ryerson_admin_html(ryerson_admin_overview_format_grouped_counts($counts['response_values_previous_day'])); ?></dd>
          <dt class="col-sm-5">Active survey_items</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['active_survey_items']); ?></dd>
          <dt class="col-sm-5">Active survey_items by tier</dt><dd class="col-sm-7"><?php echo ryerson_admin_html(ryerson_admin_overview_format_grouped_counts($counts['active_survey_items_by_tier'])); ?></dd>
          <dt class="col-sm-5">Waiting list requests</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['waiting_list_requests']); ?></dd>
          <dt class="col-sm-5">Invitations</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['community_invitations']); ?> total; <?php echo ryerson_admin_html(ryerson_admin_overview_format_grouped_counts($counts['community_invitations_by_status'])); ?></dd>
          <dt class="col-sm-5">Community members</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['community_members']); ?></dd>
          <dt class="col-sm-5">Item Bakeoff results</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['item_bakeoffs_all_time']); ?> all-time; <?php echo ryerson_admin_html((string) $counts['item_bakeoffs_previous_day']); ?> previous day</dd>
          <dt class="col-sm-5">Item Bakeoff members</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['item_bakeoff_members_all_time']); ?> all-time; <?php echo ryerson_admin_html((string) $counts['item_bakeoff_members_previous_day']); ?> previous day</dd>
          <dt class="col-sm-5">Suggested items</dt><dd class="col-sm-7"><?php echo ryerson_admin_html((string) $counts['suggested_items']); ?> total; <?php echo ryerson_admin_html(ryerson_admin_overview_format_grouped_counts($counts['suggested_items_by_status'])); ?></dd>
        </dl>
      </section>

      <section>
        <h2 class="h4">Email Preview</h2>
        <pre class="border rounded p-3 bg-light"><?php echo ryerson_admin_html(ryerson_admin_overview_build_email_body($counts)); ?></pre>
      </section>
<?php
ryerson_admin_render_footer();
