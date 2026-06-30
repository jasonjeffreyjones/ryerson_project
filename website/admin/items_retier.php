<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

const RYERSON_RETIER_INITIAL_ELO = 1500.0;
const RYERSON_RETIER_K_FACTOR = 32.0;
const RYERSON_RETIER_ITEMS_PER_TIER = 9;
const RYERSON_RETIER_TIER_10_CAPACITY = RYERSON_RETIER_ITEMS_PER_TIER;
const RYERSON_RETIER_TIER_20_CAPACITY = RYERSON_RETIER_ITEMS_PER_TIER * 2;
const RYERSON_RETIER_TIER_30_CAPACITY = RYERSON_RETIER_ITEMS_PER_TIER * 4;

function ryerson_admin_retier_fetch_active_items(mysqli $mysqli): array
{
	$sql = '
		SELECT survey_item_id, current_tier
		FROM survey_items
		WHERE is_active = 1
		ORDER BY survey_item_id ASC
	';
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch active survey items.');
	}

	$items = [];
	while ($row = $result->fetch_assoc()) {
		$surveyItemId = (int) $row['survey_item_id'];
		$items[$surveyItemId] = [
			'survey_item_id' => $surveyItemId,
			'current_tier' => (int) $row['current_tier'],
			'new_tier' => (int) $row['current_tier'],
			'score' => RYERSON_RETIER_INITIAL_ELO,
		];
	}
	$result->close();
	return $items;
}

function ryerson_admin_retier_completed_bakeoff_count(mysqli $mysqli): int
{
	$result = $mysqli->query('SELECT COUNT(*) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` WHERE created_at_utc < UTC_DATE()');
	if ($result === false) {
		throw new RuntimeException('Could not count completed-day Item Bakeoff results.');
	}

	$row = $result->fetch_assoc();
	$count = isset($row['total_count']) ? (int) $row['total_count'] : 0;
	$result->close();
	return $count;
}

function ryerson_admin_retier_fetch_bakeoff_results(mysqli $mysqli): array
{
	$sql = '
		SELECT bakeoff_result_id, left_survey_item_id, right_survey_item_id, chosen_survey_item_id, created_at_utc
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
		WHERE created_at_utc < UTC_DATE()
		ORDER BY created_at_utc ASC, bakeoff_result_id ASC
	';
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch Item Bakeoff results.');
	}

	$rows = [];
	while ($row = $result->fetch_assoc()) {
		$rows[] = [
			'bakeoff_result_id' => (int) $row['bakeoff_result_id'],
			'left_survey_item_id' => (int) $row['left_survey_item_id'],
			'right_survey_item_id' => (int) $row['right_survey_item_id'],
			'chosen_survey_item_id' => (int) $row['chosen_survey_item_id'],
			'created_at_utc' => (string) $row['created_at_utc'],
		];
	}
	$result->close();
	return $rows;
}

function ryerson_admin_retier_timestamp_utc(string $timestampText): int
{
	$timezone = new DateTimeZone('UTC');
	$date = DateTime::createFromFormat('Y-m-d H:i:s', $timestampText, $timezone);
	if ($date === false) {
		return 0;
	}

	return (int) $date->getTimestamp();
}

function ryerson_admin_retier_recency_weight(string $createdAtUtc): float
{
	$cutoff = new DateTime(gmdate('Y-m-d 00:00:00'), new DateTimeZone('UTC'));
	$createdTimestamp = ryerson_admin_retier_timestamp_utc($createdAtUtc);
	if ($createdTimestamp <= 0) {
		return 0.01;
	}

	$ageDays = max(0.0, ((float) $cutoff->getTimestamp() - (float) $createdTimestamp) / 86400.0);
	if ($ageDays <= 1.0) {
		return 1.0;
	}
	if ($ageDays >= 366.0) {
		return 0.01;
	}
	if ($ageDays > 365.0) {
		return 0.02;
	}

	$rawWeight = 100.0 - (($ageDays - 1.0) * (98.0 / 364.0));
	return max(0.02, min(1.0, $rawWeight / 100.0));
}

function ryerson_admin_retier_expected_score(float $itemScore, float $opponentScore): float
{
	return 1.0 / (1.0 + pow(10.0, (($opponentScore - $itemScore) / 400.0)));
}

function ryerson_admin_retier_apply_bakeoff(array &$items, array $bakeoff): bool
{
	$leftItemId = (int) $bakeoff['left_survey_item_id'];
	$rightItemId = (int) $bakeoff['right_survey_item_id'];
	$chosenItemId = (int) $bakeoff['chosen_survey_item_id'];
	if (!isset($items[$leftItemId]) || !isset($items[$rightItemId])) {
		return false;
	}
	if ($chosenItemId !== $leftItemId && $chosenItemId !== $rightItemId) {
		return false;
	}

	$winnerId = $chosenItemId;
	$loserId = $chosenItemId === $leftItemId ? $rightItemId : $leftItemId;
	$winnerScore = (float) $items[$winnerId]['score'];
	$loserScore = (float) $items[$loserId]['score'];
	$weight = ryerson_admin_retier_recency_weight((string) $bakeoff['created_at_utc']);
	$effectiveK = RYERSON_RETIER_K_FACTOR * $weight;
	$winnerExpected = ryerson_admin_retier_expected_score($winnerScore, $loserScore);
	$loserExpected = ryerson_admin_retier_expected_score($loserScore, $winnerScore);

	$items[$winnerId]['score'] = $winnerScore + ($effectiveK * (1.0 - $winnerExpected));
	$items[$loserId]['score'] = $loserScore + ($effectiveK * (0.0 - $loserExpected));
	return true;
}

function ryerson_admin_retier_assign_tiers(array &$items): void
{
	$orderedItems = array_values($items);
	usort($orderedItems, function (array $left, array $right): int {
		$scoreDifference = (float) $right['score'] - (float) $left['score'];
		if (abs($scoreDifference) > 0.000001) {
			return $scoreDifference > 0 ? 1 : -1;
		}
		if ((int) $left['current_tier'] !== (int) $right['current_tier']) {
			return (int) $left['current_tier'] < (int) $right['current_tier'] ? -1 : 1;
		}
		if ((int) $left['survey_item_id'] === (int) $right['survey_item_id']) {
			return 0;
		}
		return (int) $left['survey_item_id'] < (int) $right['survey_item_id'] ? -1 : 1;
	});

	foreach ($orderedItems as $index => $item) {
		$newTier = 40;
		if ($index < RYERSON_RETIER_TIER_10_CAPACITY) {
			$newTier = 10;
		} elseif ($index < RYERSON_RETIER_TIER_10_CAPACITY + RYERSON_RETIER_TIER_20_CAPACITY) {
			$newTier = 20;
		} elseif ($index < RYERSON_RETIER_TIER_10_CAPACITY + RYERSON_RETIER_TIER_20_CAPACITY + RYERSON_RETIER_TIER_30_CAPACITY) {
			$newTier = 30;
		}

		$surveyItemId = (int) $item['survey_item_id'];
		$items[$surveyItemId]['new_tier'] = $newTier;
	}
}

function ryerson_admin_retier_update_items(mysqli $mysqli, array $items): array
{
	$sql = '
		UPDATE survey_items
		SET
			current_community_score = ?,
			tier_started_on = CASE WHEN current_tier <> ? THEN UTC_DATE() ELSE tier_started_on END,
			current_tier = ?,
			updated_at_utc = UTC_TIMESTAMP()
		WHERE survey_item_id = ?
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare survey item retier update.');
	}

	$updatedCount = 0;
	$tierChangedCount = 0;
	$tierCounts = [
		10 => 0,
		20 => 0,
		30 => 0,
		40 => 0,
	];

	foreach ($items as $item) {
		$score = round((float) $item['score'], 3);
		$newTier = (int) $item['new_tier'];
		$surveyItemId = (int) $item['survey_item_id'];
		if ((int) $item['current_tier'] !== $newTier) {
			$tierChangedCount++;
		}
		if (isset($tierCounts[$newTier])) {
			$tierCounts[$newTier]++;
		}

		$statement->bind_param('diii', $score, $newTier, $newTier, $surveyItemId);
		if (!$statement->execute()) {
			$statement->close();
			throw new RuntimeException('Could not update survey item #' . (string) $surveyItemId . '.');
		}
		$updatedCount++;
	}

	$statement->close();
	return [
		'updated_count' => $updatedCount,
		'tier_changed_count' => $tierChangedCount,
		'tier_counts' => $tierCounts,
	];
}

function ryerson_admin_retier_items(mysqli $mysqli): array
{
	$items = ryerson_admin_retier_fetch_active_items($mysqli);
	$bakeoffs = ryerson_admin_retier_fetch_bakeoff_results($mysqli);
	$appliedBakeoffCount = 0;
	$skippedBakeoffCount = 0;

	foreach ($bakeoffs as $bakeoff) {
		if (ryerson_admin_retier_apply_bakeoff($items, $bakeoff)) {
			$appliedBakeoffCount++;
		} else {
			$skippedBakeoffCount++;
		}
	}

	ryerson_admin_retier_assign_tiers($items);
	$mysqli->begin_transaction();
	try {
		$updateSummary = ryerson_admin_retier_update_items($mysqli, $items);
		$mysqli->commit();
	} catch (RuntimeException $exception) {
		$mysqli->rollback();
		throw $exception;
	}

	return [
		'active_item_count' => count($items),
		'bakeoff_count' => count($bakeoffs),
		'applied_bakeoff_count' => $appliedBakeoffCount,
		'skipped_bakeoff_count' => $skippedBakeoffCount,
		'updated_count' => (int) $updateSummary['updated_count'],
		'tier_changed_count' => (int) $updateSummary['tier_changed_count'],
		'tier_counts' => $updateSummary['tier_counts'],
		'scored_through_utc' => gmdate('Y-m-d', strtotime('-1 day')),
	];
}

$status = [];
$result = [];
$didRun = false;

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$result = ryerson_admin_retier_items($mysqli);
		$didRun = true;
	} else {
		$status = [
			'active_item_count' => count(ryerson_admin_retier_fetch_active_items($mysqli)),
			'completed_bakeoff_count' => ryerson_admin_retier_completed_bakeoff_count($mysqli),
			'scored_through_utc' => gmdate('Y-m-d', strtotime('-1 day')),
		];
	}
	$mysqli->close();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson item retiering error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Item Retiering Error', $exception->getMessage());
}

ryerson_admin_render_header('Item Retiering');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Item Retiering</h1>
          <p class="text-muted mb-0">Recalculate Community Elo and assign active items to tiers.</p>
        </div>
        <div class="text-muted small">Scoring excludes the current UTC day.</div>
      </div>

      <?php if ($didRun): ?>
      <div class="alert alert-success" role="alert">
        Items retiered through <?php echo ryerson_admin_html((string) $result['scored_through_utc']); ?> UTC.
      </div>
      <dl class="row">
        <dt class="col-sm-4">Active items</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $result['active_item_count']); ?></dd>
        <dt class="col-sm-4">Bakeoffs processed</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $result['applied_bakeoff_count']); ?> of <?php echo ryerson_admin_html((string) $result['bakeoff_count']); ?></dd>
        <dt class="col-sm-4">Bakeoffs skipped</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $result['skipped_bakeoff_count']); ?></dd>
        <dt class="col-sm-4">Items updated</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $result['updated_count']); ?></dd>
        <dt class="col-sm-4">Tier changes</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $result['tier_changed_count']); ?></dd>
        <dt class="col-sm-4">Tier counts</dt>
        <dd class="col-sm-8">
          Tier 10: <?php echo ryerson_admin_html((string) $result['tier_counts'][10]); ?>,
          Tier 20: <?php echo ryerson_admin_html((string) $result['tier_counts'][20]); ?>,
          Tier 30: <?php echo ryerson_admin_html((string) $result['tier_counts'][30]); ?>,
          Tier 40: <?php echo ryerson_admin_html((string) $result['tier_counts'][40]); ?>
        </dd>
      </dl>
      <?php else: ?>
      <dl class="row">
        <dt class="col-sm-4">Active items</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $status['active_item_count']); ?></dd>
        <dt class="col-sm-4">Completed-day bakeoffs</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $status['completed_bakeoff_count']); ?></dd>
        <dt class="col-sm-4">Scored through</dt><dd class="col-sm-8"><?php echo ryerson_admin_html((string) $status['scored_through_utc']); ?> UTC</dd>
      </dl>
      <?php endif; ?>

      <form method="post">
        <button type="submit" class="btn btn-primary">Run Item Retiering</button>
        <a class="btn btn-outline-secondary" href="index.php">Admin Home</a>
      </form>
<?php
ryerson_admin_render_footer();
