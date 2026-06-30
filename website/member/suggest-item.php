<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/community_lib.php';

ryerson_community_start_member_session();

function ryerson_member_suggest_item_flash(string $type, string $message): void
{
	$_SESSION['ryerson_member_suggest_item_flash'] = [
		'type' => $type,
		'message' => $message,
	];
}

function ryerson_member_suggest_item_pop_flash(): array
{
	if (!isset($_SESSION['ryerson_member_suggest_item_flash']) || !is_array($_SESSION['ryerson_member_suggest_item_flash'])) {
		return [];
	}

	$flash = $_SESSION['ryerson_member_suggest_item_flash'];
	unset($_SESSION['ryerson_member_suggest_item_flash']);
	return [
		'type' => isset($flash['type']) ? (string) $flash['type'] : 'info',
		'message' => isset($flash['message']) ? (string) $flash['message'] : '',
	];
}

function ryerson_member_suggest_item_csrf_token(): string
{
	if (!isset($_SESSION['ryerson_member_suggest_item_csrf']) || !is_string($_SESSION['ryerson_member_suggest_item_csrf']) || $_SESSION['ryerson_member_suggest_item_csrf'] === '') {
		$_SESSION['ryerson_member_suggest_item_csrf'] = ryerson_community_generate_token();
	}

	return (string) $_SESSION['ryerson_member_suggest_item_csrf'];
}

function ryerson_member_suggest_item_verify_csrf(): void
{
	$expectedToken = ryerson_member_suggest_item_csrf_token();
	$providedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
	if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
		ryerson_community_exit_with_message(400, 'Invalid Request', 'The form token was invalid. Please reload the page and try again.');
	}
}

$member = [];
$hasSubmittedToday = false;
$suggestions = [];
$flash = [];
$csrfToken = '';

try {
	load_env_file();
	$mysqli = create_database_connection();
	$member = ryerson_community_current_member($mysqli);
	if (count($member) === 0) {
		$mysqli->close();
		header('Location: index.php');
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_member_suggest_item_verify_csrf();
		$statementText = ryerson_community_validate_suggested_statement_text((string) ($_POST['statement_text'] ?? ''));
		$communityMemberId = (int) $member['community_member_id'];

		if (ryerson_community_member_submitted_suggestion_today($mysqli, $communityMemberId)) {
			throw new RuntimeException('You have already suggested an item today.');
		}

		$status = 'pending';
		$sql = '
			INSERT INTO `' . SUGGESTED_ITEMS_TABLE_NAME . '` (
				community_member_id,
				original_statement_text,
				moderation_status,
				submitted_on
			)
			VALUES (?, ?, ?, UTC_DATE())
		';
		$statement = $mysqli->prepare($sql);
		if ($statement === false) {
			throw new RuntimeException('Could not prepare suggested item insert.');
		}

		$statement->bind_param('iss', $communityMemberId, $statementText, $status);
		if (!$statement->execute()) {
			$statement->close();
			throw new RuntimeException('Could not save suggested item.');
		}
		$statement->close();

		ryerson_member_suggest_item_flash('success', 'Your suggested item was submitted for review.');
		$mysqli->close();
		header('Location: suggest-item.php');
		exit;
	}

	$hasSubmittedToday = ryerson_community_member_submitted_suggestion_today($mysqli, (int) $member['community_member_id']);
	$suggestions = ryerson_community_fetch_member_suggestions($mysqli, (int) $member['community_member_id']);
	$mysqli->close();
	$flash = ryerson_member_suggest_item_pop_flash();
	$csrfToken = ryerson_member_suggest_item_csrf_token();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson member suggested item error: ' . $exception->getMessage());
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_member_suggest_item_flash('danger', $exception->getMessage());
		header('Location: suggest-item.php');
		exit;
	}
	ryerson_community_exit_with_message(500, 'Suggested Item Error', 'The suggested item page could not load.');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suggest Item</title>
    <link rel="icon" type="image/png" href="../images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
  </head>
  <body>
    <main class="container py-4">
      <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Member navigation">
        <a class="btn btn-sm btn-outline-secondary" href="index.php">Member Home</a>
        <a class="btn btn-sm btn-outline-secondary" href="logout.php">Log Out</a>
      </nav>

      <div class="mb-4">
        <h1 class="mb-1">Suggest Item</h1>
        <p class="text-muted mb-0">One suggested item per UTC day.</p>
      </div>

      <?php if (count($flash) > 0 && $flash['message'] !== ''): ?>
      <div class="alert alert-<?php echo ryerson_community_html($flash['type']); ?>" role="alert">
        <?php echo ryerson_community_html($flash['message']); ?>
      </div>
      <?php endif; ?>

      <?php if ($hasSubmittedToday): ?>
      <div class="alert alert-secondary" role="alert">
        You have already suggested an item today.
      </div>
      <?php else: ?>
      <form method="post" class="mb-5" id="suggest-item-form">
        <input type="hidden" name="csrf_token" value="<?php echo ryerson_community_html($csrfToken); ?>">
        <div class="mb-3">
          <label for="statement_text" class="form-label">Suggested item</label>
          <textarea class="form-control" id="statement_text" name="statement_text" rows="5" maxlength="<?php echo ryerson_community_html((string) RYERSON_SUGGESTED_ITEM_MAX_LENGTH); ?>" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Submit Suggested Item</button>
      </form>
      <?php endif; ?>

      <h2 class="h4 mb-3">Your Recent Suggestions</h2>
      <?php if (count($suggestions) === 0): ?>
      <p class="text-muted">No suggested items yet.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th scope="col">Submitted</th>
              <th scope="col">Status</th>
              <th scope="col">Suggestion</th>
              <th scope="col">Review</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($suggestions as $suggestion): ?>
            <?php
              $displayText = (string) $suggestion['edited_statement_text'] !== ''
                ? (string) $suggestion['edited_statement_text']
                : (string) $suggestion['original_statement_text'];
            ?>
            <tr>
              <td><?php echo ryerson_community_html((string) $suggestion['submitted_on']); ?></td>
              <td><?php echo ryerson_community_html((string) $suggestion['moderation_status']); ?></td>
              <td><?php echo ryerson_community_html($displayText); ?></td>
              <td>
                <?php if ((string) $suggestion['rejection_reason'] !== ''): ?>
                <?php echo ryerson_community_html((string) $suggestion['rejection_reason']); ?>
                <?php elseif ((string) $suggestion['reviewed_at_utc'] !== ''): ?>
                Reviewed <?php echo ryerson_community_html((string) $suggestion['reviewed_at_utc']); ?>
                <?php else: ?>
                <span class="text-muted">Pending</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </main>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('suggest-item-form');
        var textarea = document.getElementById('statement_text');
        if (!form || !textarea) {
          return;
        }

        form.addEventListener('submit', function (event) {
          textarea.value = textarea.value.trim();
          textarea.setCustomValidity('');
          if (textarea.value === '') {
            textarea.setCustomValidity('Suggested item text is required.');
            event.preventDefault();
            textarea.reportValidity();
          }
        });
      });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
  </body>
</html>
