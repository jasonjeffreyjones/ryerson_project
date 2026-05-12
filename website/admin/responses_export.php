<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

function ryerson_admin_ensure_response_export_dir(): void
{
	if (!is_dir(RYERSON_RESPONSE_EXPORT_DIR)) {
		if (!mkdir(RYERSON_RESPONSE_EXPORT_DIR, 0775, true)) {
			throw new RuntimeException('Could not create the response export directory.');
		}
	}

	if (!is_writable(RYERSON_RESPONSE_EXPORT_DIR)) {
		throw new RuntimeException('The response export directory is not writable.');
	}
}

function ryerson_admin_gzip_file(string $sourcePath, string $destinationPath): void
{
	$sourceHandle = fopen($sourcePath, 'rb');
	if ($sourceHandle === false) {
		throw new RuntimeException('Could not open temporary CSV file for compression.');
	}

	$gzipHandle = gzopen($destinationPath, 'wb9');
	if ($gzipHandle === false) {
		fclose($sourceHandle);
		throw new RuntimeException('Could not open temporary gzip file.');
	}

	while (!feof($sourceHandle)) {
		$chunk = fread($sourceHandle, 1048576);
		if ($chunk === false) {
			gzclose($gzipHandle);
			fclose($sourceHandle);
			throw new RuntimeException('Could not read temporary CSV file during compression.');
		}

		if ($chunk !== '' && gzwrite($gzipHandle, $chunk) === false) {
			gzclose($gzipHandle);
			fclose($sourceHandle);
			throw new RuntimeException('Could not write temporary gzip file.');
		}
	}

	gzclose($gzipHandle);
	fclose($sourceHandle);
}

function ryerson_admin_copy_file_exclusive(string $sourcePath, string $destinationPath): bool
{
	if (is_file($destinationPath)) {
		return false;
	}

	$sourceHandle = fopen($sourcePath, 'rb');
	if ($sourceHandle === false) {
		throw new RuntimeException('Could not open temporary gzip file for final copy.');
	}

	$destinationHandle = fopen($destinationPath, 'xb');
	if ($destinationHandle === false) {
		fclose($sourceHandle);
		if (is_file($destinationPath)) {
			return false;
		}
		throw new RuntimeException('Could not create the final export file.');
	}

	$copiedBytes = stream_copy_to_stream($sourceHandle, $destinationHandle);
	$closedDestination = fclose($destinationHandle);
	fclose($sourceHandle);

	if ($copiedBytes === false || !$closedDestination) {
		if (is_file($destinationPath)) {
			unlink($destinationPath);
		}
		throw new RuntimeException('Could not finish writing the final export file.');
	}

	return true;
}

function ryerson_admin_create_response_export_for_date(mysqli $mysqli, string $observationDate): array
{
	$finalPath = ryerson_admin_response_export_path($observationDate);
	if (is_file($finalPath)) {
		return [
			'date' => $observationDate,
			'status' => 'skipped',
			'row_count' => 0,
			'file_name' => ryerson_admin_response_export_file_name($observationDate),
		];
	}

	ryerson_admin_ensure_response_export_dir();

	$tempCsvPath = tempnam(RYERSON_RESPONSE_EXPORT_DIR, 'responses_tmp_');
	if ($tempCsvPath === false) {
		throw new RuntimeException('Could not create a temporary CSV file.');
	}
	$tempGzipPath = $tempCsvPath . '.gz';

	try {
		$csvHandle = fopen($tempCsvPath, 'wb');
		if ($csvHandle === false) {
			throw new RuntimeException('Could not open temporary CSV file.');
		}

		fputcsv($csvHandle, [
			'prolific_pid',
			'observation_date',
			'response_value',
			'statement_text',
			'survey_item_id',
			'presented_order',
		]);

		$sql = "
			SELECT
				r.prolific_pid,
				DATE_FORMAT(r.observation_date, '%Y-%m-%d') AS observation_date,
				r.response_value,
				si.statement_text,
				r.survey_item_id,
				r.presented_order
			FROM responses r
			INNER JOIN survey_items si ON r.survey_item_id = si.survey_item_id
			WHERE r.observation_date = ?
			ORDER BY r.prolific_pid, r.presented_order
		";
		$statement = $mysqli->prepare($sql);
		if ($statement === false) {
			fclose($csvHandle);
			throw new RuntimeException('Could not prepare the response export query.');
		}

		$statement->bind_param('s', $observationDate);
		if (!$statement->execute()) {
			$statement->close();
			fclose($csvHandle);
			throw new RuntimeException('Could not execute the response export query.');
		}

		if (!$statement->bind_result($prolificPid, $dateValue, $responseValue, $statementText, $surveyItemId, $presentedOrder)) {
			$statement->close();
			fclose($csvHandle);
			throw new RuntimeException('Could not bind response export results.');
		}

		$rowCount = 0;
		while ($statement->fetch()) {
			fputcsv($csvHandle, [
				(string) $prolificPid,
				(string) $dateValue,
				(string) $responseValue,
				(string) $statementText,
				(string) $surveyItemId,
				(string) $presentedOrder,
			]);
			$rowCount++;
		}

		$statement->close();
		fclose($csvHandle);

		ryerson_admin_gzip_file($tempCsvPath, $tempGzipPath);
		$created = ryerson_admin_copy_file_exclusive($tempGzipPath, $finalPath);

		return [
			'date' => $observationDate,
			'status' => $created ? 'created' : 'skipped',
			'row_count' => $created ? $rowCount : 0,
			'file_name' => ryerson_admin_response_export_file_name($observationDate),
		];
	} finally {
		if (is_file($tempCsvPath)) {
			unlink($tempCsvPath);
		}
		if (is_file($tempGzipPath)) {
			unlink($tempGzipPath);
		}
	}
}

function ryerson_admin_create_missing_response_exports(mysqli $mysqli): array
{
	$status = ryerson_admin_get_response_export_status();
	$results = [];
	$errors = [];

	foreach ($status['missing_dates'] as $observationDate) {
		try {
			$results[] = ryerson_admin_create_response_export_for_date($mysqli, $observationDate);
		} catch (RuntimeException $exception) {
			$errors[] = [
				'date' => $observationDate,
				'message' => $exception->getMessage(),
			];
			error_log('Ryerson response export error for ' . $observationDate . ': ' . $exception->getMessage());
		}
	}

	return [
		'results' => $results,
		'errors' => $errors,
	];
}

$runResult = null;

try {
	ryerson_admin_bootstrap();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$mysqli = create_database_connection();
		$runResult = ryerson_admin_create_missing_response_exports($mysqli);
		$mysqli->close();
	} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
		ryerson_admin_exit_with_error(405, 'Method Not Allowed', 'Use the admin form to create response exports.');
	}

	$exportStatus = ryerson_admin_get_response_export_status();
} catch (RuntimeException $exception) {
	error_log('Ryerson response export admin error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Response Export Error', 'The response export tool could not load.');
}

ryerson_admin_render_header('Response Exports');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Response Exports</h1>
          <p class="text-muted mb-0">Daily response CSV gzip files from <?php echo ryerson_admin_html(RYERSON_RESPONSE_EXPORT_START_DATE); ?> through yesterday.</p>
        </div>
        <form method="post">
          <button type="submit" class="btn btn-primary">Create Missing Exports</button>
        </form>
      </div>

      <?php if ($runResult !== null): ?>
      <div class="alert alert-info" role="alert">
        Created <?php
          $createdCount = 0;
          foreach ($runResult['results'] as $result) {
          	if ($result['status'] === 'created') {
          		$createdCount++;
          	}
          }
          echo ryerson_admin_html((string) $createdCount);
        ?> export file(s).
        <?php if (count($runResult['errors']) > 0): ?>
        <?php echo ryerson_admin_html((string) count($runResult['errors'])); ?> date(s) failed.
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <section class="border rounded p-3 mb-4">
        <h2 class="h4">Current Status</h2>
        <dl class="row mb-0">
          <dt class="col-sm-3">Expected daily files</dt>
          <dd class="col-sm-9"><?php echo ryerson_admin_html((string) $exportStatus['total_dates']); ?></dd>
          <dt class="col-sm-3">Existing files</dt>
          <dd class="col-sm-9"><?php echo ryerson_admin_html((string) count($exportStatus['existing_dates'])); ?></dd>
          <dt class="col-sm-3">Missing files</dt>
          <dd class="col-sm-9"><?php echo ryerson_admin_html((string) count($exportStatus['missing_dates'])); ?></dd>
        </dl>
      </section>

      <?php if ($runResult !== null && count($runResult['results']) > 0): ?>
      <section class="mb-4">
        <h2 class="h4">Run Results</h2>
        <div class="table-responsive">
          <table class="table table-striped align-middle">
            <thead>
              <tr>
                <th scope="col">Observation Date</th>
                <th scope="col">Status</th>
                <th scope="col">Rows</th>
                <th scope="col">File</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($runResult['results'] as $result): ?>
              <tr>
                <td><?php echo ryerson_admin_html((string) $result['date']); ?></td>
                <td><?php echo ryerson_admin_html((string) $result['status']); ?></td>
                <td><?php echo ryerson_admin_html((string) $result['row_count']); ?></td>
                <td><?php echo ryerson_admin_html((string) $result['file_name']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($runResult !== null && count($runResult['errors']) > 0): ?>
      <section class="mb-4">
        <h2 class="h4">Errors</h2>
        <div class="table-responsive">
          <table class="table table-striped align-middle">
            <thead>
              <tr>
                <th scope="col">Observation Date</th>
                <th scope="col">Message</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($runResult['errors'] as $error): ?>
              <tr>
                <td><?php echo ryerson_admin_html((string) $error['date']); ?></td>
                <td><?php echo ryerson_admin_html((string) $error['message']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <?php if (count($exportStatus['missing_dates']) > 0): ?>
      <section>
        <h2 class="h4">Missing Dates</h2>
        <p class="text-muted small">Showing the first <?php echo ryerson_admin_html((string) min(50, count($exportStatus['missing_dates']))); ?> missing date(s).</p>
        <ul class="list-inline">
          <?php foreach (array_slice($exportStatus['missing_dates'], 0, 50) as $missingDate): ?>
          <li class="list-inline-item"><code><?php echo ryerson_admin_html($missingDate); ?></code></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php else: ?>
      <div class="alert alert-success" role="alert">No response export files are missing.</div>
      <?php endif; ?>
<?php
ryerson_admin_render_footer();
