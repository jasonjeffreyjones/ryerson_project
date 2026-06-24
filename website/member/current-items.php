<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/lib/community_lib.php';

function ryerson_member_current_items_search_query(): string
{
	$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
	if (strlen($search) > 200) {
		$search = substr($search, 0, 200);
	}

	return $search;
}

function ryerson_member_current_items_like_pattern(string $search): string
{
	return '%' . $search . '%';
}

function ryerson_member_fetch_current_items(mysqli $mysqli, string $search): array
{
	$sql = '
		SELECT survey_item_id, statement_text, current_tier, tier_queue_position
		FROM survey_items
		WHERE is_active = 1
	';
	$parameters = [];
	if ($search !== '') {
		$sql .= ' AND statement_text LIKE ?';
		$parameters[] = ryerson_member_current_items_like_pattern($search);
	}

	$sql .= '
		ORDER BY
			current_tier ASC,
			CASE WHEN tier_queue_position IS NULL THEN 1 ELSE 0 END,
			tier_queue_position ASC,
			survey_item_id ASC
	';

	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare current items query.');
	}

	if (count($parameters) > 0) {
		$statement->bind_param('s', $parameters[0]);
	}

	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute current items query.');
	}

	if (!$statement->bind_result($surveyItemId, $statementText, $currentTier, $tierQueuePosition)) {
		$statement->close();
		throw new RuntimeException('Could not bind current item results.');
	}

	$items = [];
	while ($statement->fetch()) {
		$items[] = [
			'survey_item_id' => (int) $surveyItemId,
			'statement_text' => (string) $statementText,
			'current_tier' => (int) $currentTier,
			'tier_queue_position' => $tierQueuePosition === null ? null : (int) $tierQueuePosition,
		];
	}

	$statement->close();
	return $items;
}

function ryerson_member_current_items_tier_class(int $tier): string
{
	if ($tier === 10) {
		return 'text-bg-primary';
	}
	if ($tier === 20) {
		return 'text-bg-success';
	}
	if ($tier === 30) {
		return 'text-bg-warning';
	}
	if ($tier === 40) {
		return 'text-bg-secondary';
	}

	return 'text-bg-light';
}

$member = [];
$items = [];
$search = '';

try {
	load_env_file();
	$mysqli = create_database_connection();
	$member = ryerson_community_current_member($mysqli);
	if (count($member) === 0) {
		$mysqli->close();
		header('Location: index.php');
		exit;
	}

	$search = ryerson_member_current_items_search_query();
	$items = ryerson_member_fetch_current_items($mysqli, $search);
	$mysqli->close();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson member current items error: ' . $exception->getMessage());
	ryerson_community_exit_with_message(500, 'Current Items Error', 'The current items page could not load.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Current Items</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Member navigation">
        <a class="btn btn-sm btn-outline-secondary" href="index.php">Member Home</a>
        <a class="btn btn-sm btn-outline-secondary" href="suggest-item.php">Suggest Item</a>
        <a class="btn btn-sm btn-outline-secondary" href="logout.php">Log Out</a>
      </nav>

      <div class="mb-4">
        <h1 class="mb-1">Current Items</h1>
        <p class="text-muted mb-0">Active survey items sorted by current tier.</p>
      </div>

      <form method="get" class="row gy-2 gx-2 align-items-end mb-4">
        <div class="col-sm-8 col-md-6">
          <label for="q" class="form-label">Keyword</label>
          <input type="search" class="form-control" id="q" name="q" value="<?php echo ryerson_community_html($search); ?>" maxlength="200">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Search</button>
        </div>
        <?php if ($search !== ''): ?>
        <div class="col-auto">
          <a class="btn btn-outline-secondary" href="current-items.php">Clear</a>
        </div>
        <?php endif; ?>
      </form>

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Items</h2>
        <span class="text-muted small"><?php echo ryerson_community_html((string) count($items)); ?> shown</span>
      </div>

      <?php if (count($items) === 0): ?>
      <p class="text-muted">No active items matched this keyword.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Tier</th>
              <th scope="col">Statement</th>
              <th scope="col">Community ELO</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
              <td><?php echo ryerson_community_html((string) $item['survey_item_id']); ?></td>
              <td>
                <span class="badge <?php echo ryerson_community_html(ryerson_member_current_items_tier_class((int) $item['current_tier'])); ?>">
                  Tier <?php echo ryerson_community_html((string) $item['current_tier']); ?>
                </span>
              </td>
              <td><?php echo ryerson_community_html((string) $item['statement_text']); ?></td>
              <td><span class="text-muted">Not yet scored</span></td>
              <td><button class="btn btn-sm btn-outline-secondary" type="button" disabled>Promote</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
