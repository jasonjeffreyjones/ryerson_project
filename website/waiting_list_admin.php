<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/ryerson_bootstrap.php';

const ADMIN_PAGE_SIZE = 100;

function exit_with_admin_error(int $statusCode, string $title, string $message): void
{
	http_response_code($statusCode);
	$safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
	$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

	echo <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <link rel="icon" type="image/png" href="images/ryerson-project-logo.png">
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

function safe_html(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_optional_search_term(): string
{
	$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
	if (strlen($search) > 254) {
		$search = substr($search, 0, 254);
	}

	return $search;
}

function fetch_waiting_list_rows(mysqli $mysqli, string $search): array
{
	$rows = [];

	if ($search === '') {
		$sql = 'SELECT id, email_address, orcid_url, created_at_utc FROM `' . WAITING_LIST_TABLE_NAME . '` ORDER BY created_at_utc DESC, id DESC LIMIT ' . ADMIN_PAGE_SIZE;
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
	$sql = 'SELECT id, email_address, orcid_url, created_at_utc FROM `' . WAITING_LIST_TABLE_NAME . '` WHERE email_address LIKE ? OR orcid_url LIKE ? ORDER BY created_at_utc DESC, id DESC LIMIT ' . ADMIN_PAGE_SIZE;
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
	load_env_file();
	require_admin_basic_auth();
	$mysqli = create_database_connection();
	$search = get_optional_search_term();
	$rows = fetch_waiting_list_rows($mysqli, $search);
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
	exit_with_admin_error(500, 'Waiting List Admin Error', 'The admin page could not load waiting list submissions.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waiting List Admin</title>
    <link rel="icon" type="image/png" href="images/ryerson-project-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <h1 class="mb-1">Waiting List Admin</h1>
          <p class="text-muted mb-0">Showing up to <?php echo ADMIN_PAGE_SIZE; ?> submissions, newest first.</p>
        </div>
        <div class="text-end">
          <div class="fw-semibold"><?php echo safe_html((string) $totalCount); ?> total submissions</div>
          <div class="text-muted small">Protected by HTTP Basic authentication</div>
        </div>
      </div>

      <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-sm-8 col-md-6 col-lg-4">
          <label for="search" class="form-label">Search email or ORCID</label>
          <input type="text" class="form-control" id="search" name="search" value="<?php echo safe_html($search); ?>" placeholder="researcher@example.com">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Search</button>
        </div>
        <div class="col-auto">
          <a href="waiting_list_admin.php" class="btn btn-outline-secondary">Clear</a>
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
              <td><?php echo safe_html((string) $row['created_at_utc']); ?></td>
              <td><a href="mailto:<?php echo safe_html((string) $row['email_address']); ?>"><?php echo safe_html((string) $row['email_address']); ?></a></td>
              <td><a href="<?php echo safe_html((string) $row['orcid_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo safe_html((string) $row['orcid_url']); ?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </main>
  </body>
</html>
