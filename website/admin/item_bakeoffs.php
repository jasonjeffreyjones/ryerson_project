<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

function ryerson_admin_item_bakeoffs_count_today(mysqli $mysqli): int
{
	$result = $mysqli->query('SELECT COUNT(*) AS total_count FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` WHERE submitted_on = UTC_DATE()');
	if ($result === false) {
		throw new RuntimeException('Could not count today\'s item bakeoffs.');
	}

	$row = $result->fetch_assoc();
	$count = isset($row['total_count']) ? (int) $row['total_count'] : 0;
	$result->close();
	return $count;
}

function ryerson_admin_item_bakeoffs_fetch_recent(mysqli $mysqli): array
{
	$sql = '
		SELECT
			ibr.bakeoff_result_id,
			ibr.created_at_utc,
			ibr.submitted_on,
			cm.display_name,
			cm.email_address,
			left_item.survey_item_id AS left_survey_item_id,
			left_item.statement_text AS left_statement_text,
			right_item.survey_item_id AS right_survey_item_id,
			right_item.statement_text AS right_statement_text,
			chosen_item.survey_item_id AS chosen_survey_item_id,
			chosen_item.statement_text AS chosen_statement_text
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` ibr
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = ibr.community_member_id
		INNER JOIN survey_items left_item
			ON left_item.survey_item_id = ibr.left_survey_item_id
		INNER JOIN survey_items right_item
			ON right_item.survey_item_id = ibr.right_survey_item_id
		INNER JOIN survey_items chosen_item
			ON chosen_item.survey_item_id = ibr.chosen_survey_item_id
		ORDER BY ibr.created_at_utc DESC, ibr.bakeoff_result_id DESC
		LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch recent item bakeoffs.');
	}

	$rows = [];
	while ($row = $result->fetch_assoc()) {
		$rows[] = [
			'bakeoff_result_id' => (int) $row['bakeoff_result_id'],
			'created_at_utc' => (string) $row['created_at_utc'],
			'submitted_on' => (string) $row['submitted_on'],
			'display_name' => (string) $row['display_name'],
			'email_address' => (string) $row['email_address'],
			'left_survey_item_id' => (int) $row['left_survey_item_id'],
			'left_statement_text' => (string) $row['left_statement_text'],
			'right_survey_item_id' => (int) $row['right_survey_item_id'],
			'right_statement_text' => (string) $row['right_statement_text'],
			'chosen_survey_item_id' => (int) $row['chosen_survey_item_id'],
			'chosen_statement_text' => (string) $row['chosen_statement_text'],
		];
	}
	$result->close();
	return $rows;
}

function ryerson_admin_item_bakeoffs_fetch_member_counts(mysqli $mysqli): array
{
	$sql = '
		SELECT
			cm.display_name,
			cm.email_address,
			COUNT(*) AS choice_count
		FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '` ibr
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = ibr.community_member_id
		WHERE ibr.submitted_on = UTC_DATE()
		GROUP BY cm.community_member_id, cm.display_name, cm.email_address
		ORDER BY choice_count DESC, cm.display_name ASC
		LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch member bakeoff counts.');
	}

	$rows = [];
	while ($row = $result->fetch_assoc()) {
		$rows[] = [
			'display_name' => (string) $row['display_name'],
			'email_address' => (string) $row['email_address'],
			'choice_count' => (int) $row['choice_count'],
		];
	}
	$result->close();
	return $rows;
}

function ryerson_admin_item_bakeoffs_fetch_item_exposures(mysqli $mysqli): array
{
	$sql = '
		SELECT
			si.survey_item_id,
			si.statement_text,
			si.current_tier,
			COUNT(*) AS appearance_count,
			SUM(CASE WHEN appearances.was_chosen = 1 THEN 1 ELSE 0 END) AS chosen_count
		FROM (
			SELECT left_survey_item_id AS survey_item_id, CASE WHEN chosen_survey_item_id = left_survey_item_id THEN 1 ELSE 0 END AS was_chosen
			FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
			WHERE submitted_on = UTC_DATE()
			UNION ALL
			SELECT right_survey_item_id AS survey_item_id, CASE WHEN chosen_survey_item_id = right_survey_item_id THEN 1 ELSE 0 END AS was_chosen
			FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
			WHERE submitted_on = UTC_DATE()
		) AS appearances
		INNER JOIN survey_items si
			ON si.survey_item_id = appearances.survey_item_id
		GROUP BY si.survey_item_id, si.statement_text, si.current_tier
		ORDER BY appearance_count DESC, chosen_count DESC, si.survey_item_id ASC
		LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch item bakeoff exposures.');
	}

	$rows = [];
	while ($row = $result->fetch_assoc()) {
		$rows[] = [
			'survey_item_id' => (int) $row['survey_item_id'],
			'statement_text' => (string) $row['statement_text'],
			'current_tier' => (int) $row['current_tier'],
			'appearance_count' => (int) $row['appearance_count'],
			'chosen_count' => (int) $row['chosen_count'],
		];
	}
	$result->close();
	return $rows;
}

$todayCount = 0;
$recentRows = [];
$memberCounts = [];
$itemExposures = [];

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();
	$todayCount = ryerson_admin_item_bakeoffs_count_today($mysqli);
	$recentRows = ryerson_admin_item_bakeoffs_fetch_recent($mysqli);
	$memberCounts = ryerson_admin_item_bakeoffs_fetch_member_counts($mysqli);
	$itemExposures = ryerson_admin_item_bakeoffs_fetch_item_exposures($mysqli);
	$mysqli->close();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson item bakeoffs admin error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Item Bakeoffs Admin Error', $exception->getMessage());
}

ryerson_admin_render_header('Item Bakeoffs Admin');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Item Bakeoffs</h1>
          <p class="text-muted mb-0">Community choices for item prioritization.</p>
        </div>
        <div class="text-end">
          <div class="text-muted small">Submitted Today</div>
          <div class="display-6"><?php echo ryerson_admin_html((string) $todayCount); ?></div>
        </div>
      </div>

      <section class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h4 mb-0">Member Counts Today</h2>
          <span class="text-muted small"><?php echo ryerson_admin_html((string) count($memberCounts)); ?> shown</span>
        </div>
        <?php if (count($memberCounts) === 0): ?>
        <p class="text-muted">No Item Bakeoff choices have been submitted today.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped align-middle">
            <thead>
              <tr>
                <th scope="col">Member</th>
                <th scope="col">Email</th>
                <th scope="col">Choices</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($memberCounts as $row): ?>
              <tr>
                <td><?php echo ryerson_admin_html((string) $row['display_name']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['email_address']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['choice_count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <section class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h4 mb-0">Item Exposure Today</h2>
          <span class="text-muted small"><?php echo ryerson_admin_html((string) count($itemExposures)); ?> shown</span>
        </div>
        <?php if (count($itemExposures) === 0): ?>
        <p class="text-muted">No item exposures have been recorded today.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped align-middle">
            <thead>
              <tr>
                <th scope="col">Item</th>
                <th scope="col">Tier</th>
                <th scope="col">Statement</th>
                <th scope="col">Shown</th>
                <th scope="col">Chosen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($itemExposures as $row): ?>
              <tr>
                <td><?php echo ryerson_admin_html((string) $row['survey_item_id']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['current_tier']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['statement_text']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['appearance_count']); ?></td>
                <td><?php echo ryerson_admin_html((string) $row['chosen_count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <section>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h4 mb-0">Recent Choices</h2>
          <span class="text-muted small"><?php echo ryerson_admin_html((string) count($recentRows)); ?> shown</span>
        </div>
        <?php if (count($recentRows) === 0): ?>
        <p class="text-muted">No Item Bakeoff choices have been submitted.</p>
        <?php else: ?>
        <div class="vstack gap-3">
          <?php foreach ($recentRows as $row): ?>
          <section class="border rounded p-3">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
              <div>
                <div class="fw-semibold"><?php echo ryerson_admin_html((string) $row['display_name']); ?></div>
                <div class="text-muted small"><?php echo ryerson_admin_html((string) $row['email_address']); ?></div>
              </div>
              <div class="text-end">
                <div class="fw-semibold">Result #<?php echo ryerson_admin_html((string) $row['bakeoff_result_id']); ?></div>
                <div class="text-muted small"><?php echo ryerson_admin_html((string) $row['created_at_utc']); ?> UTC</div>
              </div>
            </div>
            <p class="mb-2"><span class="fw-semibold">Chosen:</span> #<?php echo ryerson_admin_html((string) $row['chosen_survey_item_id']); ?> <?php echo ryerson_admin_html((string) $row['chosen_statement_text']); ?></p>
            <p class="text-muted mb-0">
              Left #<?php echo ryerson_admin_html((string) $row['left_survey_item_id']); ?>
              versus
              Right #<?php echo ryerson_admin_html((string) $row['right_survey_item_id']); ?>
            </p>
          </section>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>
<?php
ryerson_admin_render_footer();
