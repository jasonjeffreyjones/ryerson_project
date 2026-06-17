<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';
require_once dirname(__DIR__) . '/lib/community_lib.php';

function ryerson_admin_suggested_items_status_filter(): string
{
	$status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'pending';
	if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
		return 'pending';
	}

	return $status;
}

function ryerson_admin_fetch_suggested_items(mysqli $mysqli, string $status): array
{
	$sql = '
		SELECT
			si.suggested_item_id,
			si.community_member_id,
			si.original_statement_text,
			si.edited_statement_text,
			si.moderation_status,
			si.rejection_reason,
			si.approved_survey_item_id,
			si.submitted_on,
			si.reviewed_at_utc,
			si.notification_status,
			cm.display_name,
			cm.email_address
		FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '` si
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = si.community_member_id
	';

	if ($status !== 'all') {
		$sql .= ' WHERE si.moderation_status = ? ';
	}

	$sql .= ' ORDER BY si.created_at_utc DESC, si.suggested_item_id DESC LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare suggested items query.');
	}

	if ($status !== 'all') {
		$statement->bind_param('s', $status);
	}

	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute suggested items query.');
	}

	if (!$statement->bind_result($suggestedItemId, $communityMemberId, $originalStatementText, $editedStatementText, $moderationStatus, $rejectionReason, $approvedSurveyItemId, $submittedOn, $reviewedAtUtc, $notificationStatus, $displayName, $emailAddress)) {
		$statement->close();
		throw new RuntimeException('Could not bind suggested items query.');
	}

	$rows = [];
	while ($statement->fetch()) {
		$rows[] = [
			'suggested_item_id' => (int) $suggestedItemId,
			'community_member_id' => (int) $communityMemberId,
			'original_statement_text' => (string) $originalStatementText,
			'edited_statement_text' => $editedStatementText === null ? '' : (string) $editedStatementText,
			'moderation_status' => (string) $moderationStatus,
			'rejection_reason' => $rejectionReason === null ? '' : (string) $rejectionReason,
			'approved_survey_item_id' => $approvedSurveyItemId === null ? null : (int) $approvedSurveyItemId,
			'submitted_on' => (string) $submittedOn,
			'reviewed_at_utc' => $reviewedAtUtc === null ? '' : (string) $reviewedAtUtc,
			'notification_status' => $notificationStatus === null ? '' : (string) $notificationStatus,
			'display_name' => (string) $displayName,
			'email_address' => (string) $emailAddress,
		];
	}

	$statement->close();
	return $rows;
}

function ryerson_admin_fetch_suggested_item_for_update(mysqli $mysqli, int $suggestedItemId): array
{
	$sql = '
		SELECT
			si.suggested_item_id,
			si.community_member_id,
			si.original_statement_text,
			si.moderation_status,
			cm.display_name,
			cm.email_address,
			cm.orcid_id
		FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '` si
		INNER JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = si.community_member_id
		WHERE si.suggested_item_id = ?
		LIMIT 1
		FOR UPDATE
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare suggested item lookup.');
	}

	$statement->bind_param('i', $suggestedItemId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute suggested item lookup.');
	}

	if (!$statement->bind_result($suggestedItemIdResult, $communityMemberId, $originalStatementText, $moderationStatus, $displayName, $emailAddress, $orcidId)) {
		$statement->close();
		throw new RuntimeException('Could not bind suggested item lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'suggested_item_id' => (int) $suggestedItemIdResult,
			'community_member_id' => (int) $communityMemberId,
			'original_statement_text' => (string) $originalStatementText,
			'moderation_status' => (string) $moderationStatus,
			'display_name' => (string) $displayName,
			'email_address' => (string) $emailAddress,
			'orcid_id' => (string) $orcidId,
		];
	}

	$statement->close();
	if (count($row) === 0) {
		throw new RuntimeException('Suggested item was not found.');
	}

	if ((string) $row['moderation_status'] !== 'pending') {
		throw new RuntimeException('Suggested item has already been reviewed.');
	}

	return $row;
}

function ryerson_admin_insert_approved_survey_item(mysqli $mysqli, string $statementText, int $communityMemberId): int
{
	$currentTier = 40;
	$isActive = 1;
	$notesInternal = 'Approved community suggested item.';
	$sql = '
		INSERT INTO survey_items (
			statement_text,
			current_tier,
			tier_queue_position,
			is_active,
			created_by_member_id,
			tier_started_on,
			notes_internal
		)
		VALUES (?, ?, NULL, ?, ?, UTC_DATE(), ?)
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare approved survey item insert.');
	}

	$statement->bind_param('siiis', $statementText, $currentTier, $isActive, $communityMemberId, $notesInternal);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not create approved survey item.');
	}

	$surveyItemId = (int) $mysqli->insert_id;
	$statement->close();
	return $surveyItemId;
}

function ryerson_admin_review_suggested_item(mysqli $mysqli, int $suggestedItemId, string $action, string $editedStatementText, string $rejectionReason): array
{
	$editedStatementText = ryerson_community_validate_suggested_statement_text($editedStatementText);
	$rejectionReason = trim($rejectionReason);
	if ($action === 'reject' && $rejectionReason === '') {
		throw new RuntimeException('A rejection reason is required.');
	}

	$suggestion = [];
	$surveyItemId = null;
	$mysqli->begin_transaction();
	try {
		$suggestion = ryerson_admin_fetch_suggested_item_for_update($mysqli, $suggestedItemId);
		if ($action === 'approve') {
			$surveyItemId = ryerson_admin_insert_approved_survey_item($mysqli, $editedStatementText, (int) $suggestion['community_member_id']);
			$status = 'approved';
			$updateSql = '
				UPDATE `' . SUGGESTED_ITEMS_TABLE_NAME . '`
				SET
					edited_statement_text = ?,
					moderation_status = ?,
					rejection_reason = NULL,
					approved_survey_item_id = ?,
					reviewed_at_utc = UTC_TIMESTAMP(),
					updated_at_utc = UTC_TIMESTAMP()
				WHERE suggested_item_id = ?
			';
			$updateStatement = $mysqli->prepare($updateSql);
			if ($updateStatement === false) {
				throw new RuntimeException('Could not prepare suggested item approval update.');
			}

			$updateStatement->bind_param('ssii', $editedStatementText, $status, $surveyItemId, $suggestedItemId);
		} elseif ($action === 'reject') {
			$status = 'rejected';
			$updateSql = '
				UPDATE `' . SUGGESTED_ITEMS_TABLE_NAME . '`
				SET
					edited_statement_text = ?,
					moderation_status = ?,
					rejection_reason = ?,
					approved_survey_item_id = NULL,
					reviewed_at_utc = UTC_TIMESTAMP(),
					updated_at_utc = UTC_TIMESTAMP()
				WHERE suggested_item_id = ?
			';
			$updateStatement = $mysqli->prepare($updateSql);
			if ($updateStatement === false) {
				throw new RuntimeException('Could not prepare suggested item rejection update.');
			}

			$updateStatement->bind_param('sssi', $editedStatementText, $status, $rejectionReason, $suggestedItemId);
		} else {
			throw new RuntimeException('Invalid moderation action.');
		}

		if (!$updateStatement->execute()) {
			$updateStatement->close();
			throw new RuntimeException('Could not update suggested item.');
		}
		$updateStatement->close();
		$mysqli->commit();
	} catch (RuntimeException $exception) {
		$mysqli->rollback();
		throw $exception;
	}

	$member = [
		'email_address' => (string) $suggestion['email_address'],
		'display_name' => (string) $suggestion['display_name'],
	];
	$emailSent = ryerson_community_send_suggestion_moderation_email($member, $status, $editedStatementText, $rejectionReason);
	$notificationStatus = $emailSent ? 'sent' : 'email_failed';
	$notificationSql = '
		UPDATE `' . SUGGESTED_ITEMS_TABLE_NAME . '`
		SET
			notification_status = ?,
			notification_sent_at_utc = CASE WHEN ? = \'sent\' THEN UTC_TIMESTAMP() ELSE notification_sent_at_utc END,
			updated_at_utc = UTC_TIMESTAMP()
		WHERE suggested_item_id = ?
	';
	$notificationStatement = $mysqli->prepare($notificationSql);
	if ($notificationStatement === false) {
		throw new RuntimeException('Could not prepare suggested item notification update.');
	}

	$notificationStatement->bind_param('ssi', $notificationStatus, $notificationStatus, $suggestedItemId);
	if (!$notificationStatement->execute()) {
		$notificationStatement->close();
		throw new RuntimeException('Could not update suggested item notification status.');
	}
	$notificationStatement->close();

	return [
		'status' => $status,
		'email_sent' => $emailSent,
		'survey_item_id' => $surveyItemId,
	];
}

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_admin_verify_csrf_token();
		$suggestedItemId = isset($_POST['suggested_item_id']) ? (int) $_POST['suggested_item_id'] : 0;
		$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
		$editedStatementText = (string) ($_POST['edited_statement_text'] ?? '');
		$rejectionReason = (string) ($_POST['rejection_reason'] ?? '');
		if ($suggestedItemId < 1) {
			throw new RuntimeException('Suggested item ID was missing.');
		}

		$result = ryerson_admin_review_suggested_item($mysqli, $suggestedItemId, $action, $editedStatementText, $rejectionReason);
		$message = $result['status'] === 'approved'
			? 'Suggested item approved as survey item #' . (string) $result['survey_item_id'] . '.'
			: 'Suggested item rejected.';
		if ($result['email_sent'] !== true) {
			$message .= ' Notification email failed.';
		}
		ryerson_admin_set_flash($result['email_sent'] === true ? 'success' : 'warning', $message);
		$mysqli->close();
		header('Location: suggested_items.php');
		exit;
	}

	$statusFilter = ryerson_admin_suggested_items_status_filter();
	$rows = ryerson_admin_fetch_suggested_items($mysqli, $statusFilter);
	$mysqli->close();
	$csrfToken = ryerson_admin_get_csrf_token();
	$flash = ryerson_admin_pop_flash();
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	error_log('Ryerson suggested items admin error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Suggested Items Admin Error', $exception->getMessage());
}

ryerson_admin_render_header('Suggested Items Admin');
?>
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="mb-1">Suggested Items</h1>
          <p class="text-muted mb-0">Review community suggested items.</p>
        </div>
      </div>

      <?php if (count($flash) > 0 && $flash['message'] !== ''): ?>
      <div class="alert alert-<?php echo ryerson_admin_html($flash['type']); ?>" role="alert">
        <?php echo ryerson_admin_html($flash['message']); ?>
      </div>
      <?php endif; ?>

      <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-sm <?php echo $statusFilter === 'pending' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="suggested_items.php?status=pending">Pending</a>
        <a class="btn btn-sm <?php echo $statusFilter === 'approved' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="suggested_items.php?status=approved">Approved</a>
        <a class="btn btn-sm <?php echo $statusFilter === 'rejected' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="suggested_items.php?status=rejected">Rejected</a>
        <a class="btn btn-sm <?php echo $statusFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="suggested_items.php?status=all">All</a>
      </div>

      <?php if (count($rows) === 0): ?>
      <div class="alert alert-secondary" role="alert">
        No suggested items matched this view.
      </div>
      <?php else: ?>
      <div class="vstack gap-3">
        <?php foreach ($rows as $row): ?>
        <?php $currentText = (string) $row['edited_statement_text'] !== '' ? (string) $row['edited_statement_text'] : (string) $row['original_statement_text']; ?>
        <section class="border rounded p-3">
          <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
            <div>
              <div class="fw-semibold"><?php echo ryerson_admin_html((string) $row['display_name']); ?></div>
              <div class="text-muted small"><?php echo ryerson_admin_html((string) $row['email_address']); ?></div>
            </div>
            <div class="text-end">
              <div class="fw-semibold"><?php echo ryerson_admin_html((string) $row['moderation_status']); ?></div>
              <div class="text-muted small">Submitted <?php echo ryerson_admin_html((string) $row['submitted_on']); ?></div>
            </div>
          </div>

          <?php if ((string) $row['moderation_status'] === 'pending'): ?>
          <form method="post" class="suggested-item-review-form">
            <input type="hidden" name="csrf_token" value="<?php echo ryerson_admin_html($csrfToken); ?>">
            <input type="hidden" name="suggested_item_id" value="<?php echo ryerson_admin_html((string) $row['suggested_item_id']); ?>">
            <div class="mb-3">
              <label class="form-label" for="edited_statement_text_<?php echo ryerson_admin_html((string) $row['suggested_item_id']); ?>">Statement text</label>
              <textarea class="form-control suggested-item-statement" id="edited_statement_text_<?php echo ryerson_admin_html((string) $row['suggested_item_id']); ?>" name="edited_statement_text" rows="4" maxlength="<?php echo ryerson_admin_html((string) RYERSON_SUGGESTED_ITEM_MAX_LENGTH); ?>" required><?php echo ryerson_admin_html($currentText); ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label" for="rejection_reason_<?php echo ryerson_admin_html((string) $row['suggested_item_id']); ?>">Rejection reason</label>
              <textarea class="form-control suggested-item-rejection-reason" id="rejection_reason_<?php echo ryerson_admin_html((string) $row['suggested_item_id']); ?>" name="rejection_reason" rows="2"></textarea>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="submit" name="action" value="approve" class="btn btn-primary">Approve as Tier 40</button>
              <button type="submit" name="action" value="reject" class="btn btn-outline-danger">Reject</button>
            </div>
          </form>
          <?php else: ?>
          <p class="mb-2"><?php echo ryerson_admin_html($currentText); ?></p>
          <?php if ((string) $row['rejection_reason'] !== ''): ?>
          <p class="text-muted mb-2">Reason: <?php echo ryerson_admin_html((string) $row['rejection_reason']); ?></p>
          <?php endif; ?>
          <?php if ($row['approved_survey_item_id'] !== null): ?>
          <p class="text-muted mb-2">Survey item #<?php echo ryerson_admin_html((string) $row['approved_survey_item_id']); ?></p>
          <?php endif; ?>
          <p class="text-muted small mb-0">
            Reviewed <?php echo ryerson_admin_html((string) $row['reviewed_at_utc']); ?>.
            Notification: <?php echo ryerson_admin_html((string) $row['notification_status']); ?>.
          </p>
          <?php endif; ?>
        </section>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var forms = document.querySelectorAll('.suggested-item-review-form');
          Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
              var statement = form.querySelector('.suggested-item-statement');
              var reason = form.querySelector('.suggested-item-rejection-reason');
              var submitter = event.submitter || document.activeElement;
              var action = submitter && submitter.name === 'action' ? submitter.value : '';

              if (statement) {
                statement.value = statement.value.trim();
                statement.setCustomValidity('');
                if (statement.value === '') {
                  statement.setCustomValidity('Statement text is required.');
                  event.preventDefault();
                  statement.reportValidity();
                  return;
                }
              }

              if (reason) {
                reason.value = reason.value.trim();
                reason.setCustomValidity('');
                if (action === 'reject' && reason.value === '') {
                  reason.setCustomValidity('A rejection reason is required.');
                  event.preventDefault();
                  reason.reportValidity();
                }
              }
            });
          });
        });
      </script>
<?php
ryerson_admin_render_footer();
