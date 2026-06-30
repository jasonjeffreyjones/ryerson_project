<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community_lib.php';

ryerson_community_start_member_session();

const RYERSON_ITEM_BAKEOFF_DAILY_LIMIT = 100;
const RYERSON_ITEM_BAKEOFF_EARLY_NO_REPEAT_SUBMISSIONS = 20;
const RYERSON_ITEM_BAKEOFF_RECENT_DAYS = 7;

function ryerson_member_item_bakeoff_flash(string $type, string $message): void
{
	$_SESSION['ryerson_member_item_bakeoff_flash'] = [
		'type' => $type,
		'message' => $message,
	];
}

function ryerson_member_item_bakeoff_pop_flash(): array
{
	if (!isset($_SESSION['ryerson_member_item_bakeoff_flash']) || !is_array($_SESSION['ryerson_member_item_bakeoff_flash'])) {
		return [];
	}

	$flash = $_SESSION['ryerson_member_item_bakeoff_flash'];
	unset($_SESSION['ryerson_member_item_bakeoff_flash']);
	return [
		'type' => isset($flash['type']) ? (string) $flash['type'] : 'info',
		'message' => isset($flash['message']) ? (string) $flash['message'] : '',
	];
}

function ryerson_member_item_bakeoff_pair_key(int $firstSurveyItemId, int $secondSurveyItemId): string
{
	$low = min($firstSurveyItemId, $secondSurveyItemId);
	$high = max($firstSurveyItemId, $secondSurveyItemId);
	return (string) $low . ':' . (string) $high;
}

function ryerson_member_item_bakeoff_count_today(mysqli $mysqli, int $communityMemberId): int
{
	$sql = '
		SELECT COUNT(*) AS total_count
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
		WHERE community_member_id = ? AND submitted_on = UTC_DATE()
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare daily bakeoff count query.');
	}

	$statement->bind_param('i', $communityMemberId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute daily bakeoff count query.');
	}

	if (!$statement->bind_result($totalCount)) {
		$statement->close();
		throw new RuntimeException('Could not bind daily bakeoff count query.');
	}

	$count = 0;
	if ($statement->fetch()) {
		$count = (int) $totalCount;
	}
	$statement->close();
	return $count;
}

function ryerson_member_item_bakeoff_fetch_candidates(mysqli $mysqli): array
{
	$sql = '
		SELECT
			si.survey_item_id,
			si.statement_text,
			COALESCE(recent_appearances.appearance_count, 0) AS appearance_count
		FROM survey_items si
		LEFT JOIN (
			SELECT item_id, COUNT(*) AS appearance_count
			FROM (
				SELECT left_survey_item_id AS item_id
				FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
				WHERE created_at_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . RYERSON_ITEM_BAKEOFF_RECENT_DAYS . ' DAY)
				UNION ALL
				SELECT right_survey_item_id AS item_id
				FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
				WHERE created_at_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . RYERSON_ITEM_BAKEOFF_RECENT_DAYS . ' DAY)
			) AS appearances
			GROUP BY item_id
		) AS recent_appearances
			ON recent_appearances.item_id = si.survey_item_id
		WHERE si.is_active = 1
		ORDER BY
			COALESCE(recent_appearances.appearance_count, 0) ASC,
			RAND()
	';
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch bakeoff candidate items.');
	}

	$items = [];
	while ($row = $result->fetch_assoc()) {
		$items[] = [
			'survey_item_id' => (int) $row['survey_item_id'],
			'statement_text' => (string) $row['statement_text'],
			'appearance_count' => (int) $row['appearance_count'],
		];
	}
	$result->close();
	return $items;
}

function ryerson_member_item_bakeoff_fetch_seen_item_ids_today(mysqli $mysqli, int $communityMemberId): array
{
	$sql = '
		SELECT left_survey_item_id, right_survey_item_id
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
		WHERE community_member_id = ? AND submitted_on = UTC_DATE()
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare daily seen item query.');
	}

	$statement->bind_param('i', $communityMemberId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute daily seen item query.');
	}

	if (!$statement->bind_result($leftSurveyItemId, $rightSurveyItemId)) {
		$statement->close();
		throw new RuntimeException('Could not bind daily seen item query.');
	}

	$seen = [];
	while ($statement->fetch()) {
		$seen[(int) $leftSurveyItemId] = true;
		$seen[(int) $rightSurveyItemId] = true;
	}
	$statement->close();
	return $seen;
}

function ryerson_member_item_bakeoff_pair_was_used_today(mysqli $mysqli, int $communityMemberId, string $pairKey): bool
{
	$sql = '
		SELECT 1
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
		WHERE community_member_id = ? AND submitted_on = UTC_DATE() AND pair_key = ?
		LIMIT 1
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare pair repeat query.');
	}

	$statement->bind_param('is', $communityMemberId, $pairKey);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute pair repeat query.');
	}

	$statement->store_result();
	$wasUsed = $statement->num_rows > 0;
	$statement->close();
	return $wasUsed;
}

function ryerson_member_item_bakeoff_attempt_assignment(mysqli $mysqli, int $communityMemberId, array $pool): array
{
	$poolSize = min(count($pool), 60);
	for ($i = 0; $i < $poolSize; $i++) {
		for ($j = $i + 1; $j < $poolSize; $j++) {
			$left = $pool[$i];
			$right = $pool[$j];
			$pairKey = ryerson_member_item_bakeoff_pair_key((int) $left['survey_item_id'], (int) $right['survey_item_id']);
			if (!ryerson_member_item_bakeoff_pair_was_used_today($mysqli, $communityMemberId, $pairKey)) {
				if (random_int(0, 1) === 1) {
					$temporary = $left;
					$left = $right;
					$right = $temporary;
				}

				return [
					'left' => $left,
					'right' => $right,
					'pair_key' => $pairKey,
					'csrf_token' => ryerson_community_generate_token(),
				];
			}
		}
	}

	return [];
}

function ryerson_member_item_bakeoff_select_assignment(mysqli $mysqli, int $communityMemberId, int $dailyCount, array $candidates): array
{
	if (count($candidates) < 2) {
		return [];
	}

	if ($dailyCount < RYERSON_ITEM_BAKEOFF_EARLY_NO_REPEAT_SUBMISSIONS) {
		$seen = ryerson_member_item_bakeoff_fetch_seen_item_ids_today($mysqli, $communityMemberId);
		$unseen = [];
		foreach ($candidates as $candidate) {
			if (!isset($seen[(int) $candidate['survey_item_id']])) {
				$unseen[] = $candidate;
			}
		}

		if (count($unseen) >= 2) {
			$assignment = ryerson_member_item_bakeoff_attempt_assignment($mysqli, $communityMemberId, $unseen);
			if (count($assignment) > 0) {
				return $assignment;
			}
		}
	}

	return ryerson_member_item_bakeoff_attempt_assignment($mysqli, $communityMemberId, $candidates);
}

function ryerson_member_item_bakeoff_store_assignment(array $assignment): void
{
	$_SESSION['ryerson_member_item_bakeoff_assignment'] = [
		'left_survey_item_id' => (int) $assignment['left']['survey_item_id'],
		'right_survey_item_id' => (int) $assignment['right']['survey_item_id'],
		'pair_key' => (string) $assignment['pair_key'],
		'csrf_token' => (string) $assignment['csrf_token'],
	];
}

function ryerson_member_item_bakeoff_current_assignment(): array
{
	if (!isset($_SESSION['ryerson_member_item_bakeoff_assignment']) || !is_array($_SESSION['ryerson_member_item_bakeoff_assignment'])) {
		return [];
	}

	return $_SESSION['ryerson_member_item_bakeoff_assignment'];
}

function ryerson_member_item_bakeoff_clear_assignment(): void
{
	unset($_SESSION['ryerson_member_item_bakeoff_assignment']);
}

function ryerson_member_item_bakeoff_insert_result(mysqli $mysqli, int $communityMemberId, array $assignment, int $chosenSurveyItemId): void
{
	$leftSurveyItemId = (int) $assignment['left_survey_item_id'];
	$rightSurveyItemId = (int) $assignment['right_survey_item_id'];
	$pairKey = (string) $assignment['pair_key'];
	if ($chosenSurveyItemId !== $leftSurveyItemId && $chosenSurveyItemId !== $rightSurveyItemId) {
		ryerson_community_exit_with_message(400, 'Invalid Choice', 'The selected item was not part of the assigned bakeoff pair.');
	}

	$sql = '
		INSERT INTO `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` (
			community_member_id,
			left_survey_item_id,
			right_survey_item_id,
			chosen_survey_item_id,
			pair_key,
			submitted_on,
			created_at_utc
		)
		VALUES (?, ?, ?, ?, ?, UTC_DATE(), UTC_TIMESTAMP())
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare bakeoff result insert.');
	}

	$statement->bind_param('iiiis', $communityMemberId, $leftSurveyItemId, $rightSurveyItemId, $chosenSurveyItemId, $pairKey);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not save bakeoff choice.');
	}
	$statement->close();
}

$member = [];
$dailyCount = 0;
$remainingCount = RYERSON_ITEM_BAKEOFF_DAILY_LIMIT;
$assignment = [];
$flash = [];

try {
	load_env_file();
	$mysqli = create_database_connection();
	$member = ryerson_community_current_member($mysqli);
	if (count($member) === 0) {
		$mysqli->close();
		header('Location: index.php');
		exit;
	}

	$communityMemberId = (int) $member['community_member_id'];
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$currentAssignment = ryerson_member_item_bakeoff_current_assignment();
		$providedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
		if (
			count($currentAssignment) === 0 ||
			$providedToken === '' ||
			!isset($currentAssignment['csrf_token']) ||
			!hash_equals((string) $currentAssignment['csrf_token'], $providedToken)
		) {
			ryerson_community_exit_with_message(400, 'Invalid Request', 'The form token was invalid. Please reload the page and try again.');
		}

		$dailyCount = ryerson_member_item_bakeoff_count_today($mysqli, $communityMemberId);
		if ($dailyCount >= RYERSON_ITEM_BAKEOFF_DAILY_LIMIT) {
			ryerson_member_item_bakeoff_clear_assignment();
			ryerson_member_item_bakeoff_flash('secondary', 'You have reached today\'s Item Bakeoff limit.');
			$mysqli->close();
			header('Location: item-bakeoff.php');
			exit;
		}

		$chosenSurveyItemId = isset($_POST['chosen_survey_item_id']) ? (int) $_POST['chosen_survey_item_id'] : 0;
		ryerson_member_item_bakeoff_insert_result($mysqli, $communityMemberId, $currentAssignment, $chosenSurveyItemId);
		ryerson_member_item_bakeoff_clear_assignment();
		ryerson_member_item_bakeoff_flash('success', 'Your bakeoff choice was recorded.');
		$mysqli->close();
		header('Location: item-bakeoff.php');
		exit;
	}

	$dailyCount = ryerson_member_item_bakeoff_count_today($mysqli, $communityMemberId);
	$remainingCount = max(0, RYERSON_ITEM_BAKEOFF_DAILY_LIMIT - $dailyCount);
	if ($dailyCount < RYERSON_ITEM_BAKEOFF_DAILY_LIMIT) {
		$candidates = ryerson_member_item_bakeoff_fetch_candidates($mysqli);
		$assignment = ryerson_member_item_bakeoff_select_assignment($mysqli, $communityMemberId, $dailyCount, $candidates);
		if (count($assignment) > 0) {
			ryerson_member_item_bakeoff_store_assignment($assignment);
		} else {
			ryerson_member_item_bakeoff_clear_assignment();
		}
	} else {
		ryerson_member_item_bakeoff_clear_assignment();
	}

	$mysqli->close();
	$flash = ryerson_member_item_bakeoff_pop_flash();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson member item bakeoff error: ' . $exception->getMessage());
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_member_item_bakeoff_clear_assignment();
		ryerson_member_item_bakeoff_flash('danger', $exception->getMessage());
		header('Location: item-bakeoff.php');
		exit;
	}
	ryerson_community_exit_with_message(500, 'Item Bakeoff Error', 'The Item Bakeoff page could not load.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Item Bakeoff</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Member navigation">
        <a class="btn btn-sm btn-outline-secondary" href="index.php">Member Home</a>
        <a class="btn btn-sm btn-outline-secondary" href="current-items.php">Current Items</a>
        <a class="btn btn-sm btn-outline-secondary" href="logout.php">Log Out</a>
      </nav>

      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Item Bakeoff</h1>
          <p class="text-muted mb-0">Click on the Item you prefer to be prioritized higher. Choose one. Close calls are expected.</p>
        </div>
        <div class="text-end">
          <div class="text-muted small">Remaining Today</div>
          <div class="display-6"><?php echo ryerson_community_html((string) $remainingCount); ?></div>
        </div>
      </div>

      <?php if (count($flash) > 0 && $flash['message'] !== ''): ?>
      <div class="alert alert-<?php echo ryerson_community_html($flash['type']); ?>" role="alert">
        <?php echo ryerson_community_html($flash['message']); ?>
      </div>
      <?php endif; ?>

      <?php if ($dailyCount >= RYERSON_ITEM_BAKEOFF_DAILY_LIMIT): ?>
      <div class="alert alert-secondary" role="alert">
        You have reached today's Item Bakeoff limit.
      </div>
      <?php elseif (count($assignment) === 0): ?>
      <div class="alert alert-secondary" role="alert">
        Item Bakeoff is unavailable because there are not enough eligible active items.
      </div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo ryerson_community_html((string) $assignment['csrf_token']); ?>">
        <div class="row g-3 align-items-stretch">
          <div class="col-md-6">
            <section class="border rounded p-3 h-100 d-flex flex-column">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <span class="text-muted small">Item #<?php echo ryerson_community_html((string) $assignment['left']['survey_item_id']); ?></span>
              </div>
              <p class="fs-5 flex-grow-1"><?php echo ryerson_community_html((string) $assignment['left']['statement_text']); ?></p>
              <button class="btn btn-primary w-100" type="submit" name="chosen_survey_item_id" value="<?php echo ryerson_community_html((string) $assignment['left']['survey_item_id']); ?>">Choose Left Item</button>
            </section>
          </div>
          <div class="col-md-6">
            <section class="border rounded p-3 h-100 d-flex flex-column">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <span class="text-muted small">Item #<?php echo ryerson_community_html((string) $assignment['right']['survey_item_id']); ?></span>
              </div>
              <p class="fs-5 flex-grow-1"><?php echo ryerson_community_html((string) $assignment['right']['statement_text']); ?></p>
              <button class="btn btn-primary w-100" type="submit" name="chosen_survey_item_id" value="<?php echo ryerson_community_html((string) $assignment['right']['survey_item_id']); ?>">Choose Right Item</button>
            </section>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
