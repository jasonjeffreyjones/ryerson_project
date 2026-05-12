<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

function ryerson_admin_get_waiting_list_search(): string
{
	$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
	if (strlen($search) > 254) {
		$search = substr($search, 0, 254);
	}

	return $search;
}

function ryerson_admin_fetch_waiting_list_rows(mysqli $mysqli, string $search): array
{
	$rows = [];

	if ($search === '') {
		$sql = 'SELECT id, email_address, orcid_url, created_at_utc FROM `' . WAITING_LIST_TABLE_NAME . '` ORDER BY created_at_utc DESC, id DESC LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
		$result = $mysqli->query($sql);
		if ($result === false) {
			throw new RuntimeException('Could not load waiting list submissions.');
		}

		while ($row = $result->fetch_assoc()) {
			$rows[] = $row;
		}
		$result->close();
		return $rows;
	}

	$likeSearch = '%' . $search . '%';
	$sql = 'SELECT id, email_address, orcid_url, created_at_utc FROM `' . WAITING_LIST_TABLE_NAME . '` WHERE email_address LIKE ? OR orcid_url LIKE ? ORDER BY created_at_utc DESC, id DESC LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare the waiting list query.');
	}

	$statement->bind_param('ss', $likeSearch, $likeSearch);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute the waiting list query.');
	}

	if (!$statement->bind_result($id, $emailAddress, $orcidUrl, $createdAtUtc)) {
		$statement->close();
		throw new RuntimeException('Could not bind waiting list results.');
	}

	while ($statement->fetch()) {
		$rows[] = [
			'id' => (int) $id,
			'email_address' => (string) $emailAddress,
			'orcid_url' => (string) $orcidUrl,
			'created_at_utc' => (string) $createdAtUtc,
		];
	}

	$statement->close();
	return $rows;
}

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();
	$search = ryerson_admin_get_waiting_list_search();
	$rows = ryerson_admin_fetch_waiting_list_rows($mysqli, $search);

	$totalCountResult = $mysqli->query('SELECT COUNT(*) AS total_count FROM `' . WAITING_LIST_TABLE_NAME . '`');
	if ($totalCountResult === false) {
		throw new RuntimeException('Could not count waiting list submissions.');
	}
	$totalCountRow = $totalCountResult->fetch_assoc();
	$totalCount = isset($totalCountRow['total_count']) ? (int) $totalCountRow['total_count'] : 0;
	$totalCountResult->close();
	$mysqli->close();
} catch (RuntimeException $exception) {
	error_log('Ryerson waiting list admin error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Waiting List Admin Error', 'The waiting list admin page could not load submissions.');
}

ryerson_admin_render_header('Waiting List Admin');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Waiting List Admin</h1>
          <p class="text-muted mb-0">Showing up to <?php echo ryerson_admin_html((string) RYERSON_ADMIN_PAGE_SIZE); ?> submissions, newest first.</p>
        </div>
        <div class="text-end">
          <div class="fw-semibold"><?php echo ryerson_admin_html((string) $totalCount); ?> total submissions</div>
          <?php if ($search !== ''): ?>
          <div class="text-muted small"><?php echo ryerson_admin_html((string) count($rows)); ?> matching this search</div>
          <?php endif; ?>
        </div>
      </div>

      <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-sm-8 col-md-6 col-lg-4">
          <label for="search" class="form-label">Search email or ORCID</label>
          <input type="text" class="form-control" id="search" name="search" value="<?php echo ryerson_admin_html($search); ?>" placeholder="researcher@example.com">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Search</button>
        </div>
        <div class="col-auto">
          <a href="waiting_list.php" class="btn btn-outline-secondary">Clear</a>
        </div>
      </form>

      <?php if (count($rows) === 0): ?>
      <div class="alert alert-secondary" role="alert">
        No waiting list submissions matched this query.
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead>
            <tr>
              <th scope="col">Submitted UTC</th>
              <th scope="col">Email</th>
              <th scope="col">ORCID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
              <td><?php echo ryerson_admin_html((string) $row['created_at_utc']); ?></td>
              <td><a href="mailto:<?php echo ryerson_admin_html((string) $row['email_address']); ?>"><?php echo ryerson_admin_html((string) $row['email_address']); ?></a></td>
              <td><a href="<?php echo ryerson_admin_html((string) $row['orcid_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo ryerson_admin_html((string) $row['orcid_url']); ?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
<?php
ryerson_admin_render_footer();
