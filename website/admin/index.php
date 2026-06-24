<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();

	$waitingListCountResult = $mysqli->query('SELECT COUNT(*) AS total_count FROM `' . WAITING_LIST_TABLE_NAME . '`');
	if ($waitingListCountResult === false) {
		throw new RuntimeException('Could not count waiting list submissions.');
	}
	$waitingListCountRow = $waitingListCountResult->fetch_assoc();
	$waitingListCount = isset($waitingListCountRow['total_count']) ? (int) $waitingListCountRow['total_count'] : 0;
	$waitingListCountResult->close();
	$suggestedItemsCountResult = $mysqli->query('SELECT COUNT(*) AS total_count FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '` WHERE moderation_status = "pending"');
	if ($suggestedItemsCountResult === false) {
		throw new RuntimeException('Could not count pending suggested items.');
	}
	$suggestedItemsCountRow = $suggestedItemsCountResult->fetch_assoc();
	$pendingSuggestedItemsCount = isset($suggestedItemsCountRow['total_count']) ? (int) $suggestedItemsCountRow['total_count'] : 0;
	$suggestedItemsCountResult->close();
	$mysqli->close();

	$exportStatus = ryerson_admin_get_response_export_status();
} catch (RuntimeException $exception) {
	error_log('Ryerson admin home error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Admin Error', 'The administration interface could not load.');
}

ryerson_admin_render_header('Ryerson Admin');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Ryerson Admin</h1>
          <p class="text-muted mb-0">Protected tools for operating the Ryerson Project.</p>
        </div>
        <div class="text-muted small">HTTP Basic authentication active</div>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <section class="border rounded p-3 h-100">
            <h2 class="h4">Waiting List</h2>
            <p class="mb-3"><?php echo ryerson_admin_html((string) $waitingListCount); ?> total waiting list submissions.</p>
            <a class="btn btn-primary" href="waiting_list.php">View Waiting List</a>
          </section>
        </div>

        <div class="col-md-6">
          <section class="border rounded p-3 h-100">
            <h2 class="h4">Suggested Items</h2>
            <p class="mb-3"><?php echo ryerson_admin_html((string) $pendingSuggestedItemsCount); ?> pending suggested items.</p>
            <a class="btn btn-primary" href="suggested_items.php">Review Suggested Items</a>
          </section>
        </div>

        <div class="col-md-6">
          <section class="border rounded p-3 h-100">
            <h2 class="h4">Response Exports</h2>
            <p class="mb-2">
              <?php echo ryerson_admin_html((string) count($exportStatus['existing_dates'])); ?>
              of
              <?php echo ryerson_admin_html((string) $exportStatus['total_dates']); ?>
              daily export files exist.
            </p>
            <p class="text-muted small mb-3">
              Missing files:
              <?php echo ryerson_admin_html((string) count($exportStatus['missing_dates'])); ?>
            </p>
            <form method="post" action="responses_export.php" class="d-inline">
              <button type="submit" class="btn btn-primary">Create Missing Exports</button>
            </form>
            <a class="btn btn-outline-secondary" href="responses_export.php">View Export Status</a>
          </section>
        </div>
      </div>
<?php
ryerson_admin_render_footer();
