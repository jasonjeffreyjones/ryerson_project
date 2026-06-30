<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';
require_once dirname(__DIR__) . '/lib/community_lib.php';

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
	$sql = '
		SELECT
			w.id,
			w.email_address,
			w.orcid_url,
			w.created_at_utc,
			ci.invitation_id,
			ci.status AS invitation_status,
			ci.sent_at_utc,
			ci.expires_at_utc,
			(ci.expires_at_utc <= UTC_TIMESTAMP()) AS invitation_is_expired,
			cm.community_member_id,
			cm.membership_status,
			cm.display_name
		FROM `' . WAITING_LIST_TABLE_NAME . '` w
		LEFT JOIN `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` ci
			ON ci.invitation_id = (
				SELECT MAX(ci2.invitation_id)
				FROM `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` ci2
				WHERE ci2.waiting_list_request_id = w.id
			)
		LEFT JOIN `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
			ON cm.community_member_id = ci.community_member_id
	';

	if ($search !== '') {
		$sql .= ' WHERE w.email_address LIKE ? OR w.orcid_url LIKE ? ';
	}

	$sql .= ' ORDER BY w.created_at_utc DESC, w.id DESC LIMIT ' . RYERSON_ADMIN_PAGE_SIZE;
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare waiting list query.');
	}

	if ($search !== '') {
		$likeSearch = '%' . $search . '%';
		$statement->bind_param('ss', $likeSearch, $likeSearch);
	}

	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute waiting list query.');
	}

	if (!$statement->bind_result($id, $emailAddress, $orcidUrl, $createdAtUtc, $invitationId, $invitationStatus, $sentAtUtc, $expiresAtUtc, $invitationIsExpired, $communityMemberId, $membershipStatus, $displayName)) {
		$statement->close();
		throw new RuntimeException('Could not bind waiting list results.');
	}

	while ($statement->fetch()) {
		$rows[] = [
			'id' => (int) $id,
			'email_address' => (string) $emailAddress,
			'orcid_url' => (string) $orcidUrl,
			'created_at_utc' => (string) $createdAtUtc,
			'invitation_id' => $invitationId === null ? null : (int) $invitationId,
			'invitation_status' => $invitationStatus === null ? '' : (string) $invitationStatus,
			'sent_at_utc' => $sentAtUtc === null ? '' : (string) $sentAtUtc,
			'expires_at_utc' => $expiresAtUtc === null ? '' : (string) $expiresAtUtc,
			'invitation_is_expired' => (bool) $invitationIsExpired,
			'community_member_id' => $communityMemberId === null ? null : (int) $communityMemberId,
			'membership_status' => $membershipStatus === null ? '' : (string) $membershipStatus,
			'display_name' => $displayName === null ? '' : (string) $displayName,
		];
	}

	$statement->close();
	return $rows;
}

function ryerson_admin_fetch_waiting_list_request(mysqli $mysqli, int $waitingListRequestId, bool $forUpdate): array
{
	$sql = 'SELECT id, email_address, orcid_url FROM `' . WAITING_LIST_TABLE_NAME . '` WHERE id = ?';
	if ($forUpdate) {
		$sql .= ' FOR UPDATE';
	}

	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare waiting list request lookup.');
	}

	$statement->bind_param('i', $waitingListRequestId);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute waiting list request lookup.');
	}

	if (!$statement->bind_result($id, $emailAddress, $orcidUrl)) {
		$statement->close();
		throw new RuntimeException('Could not bind waiting list request lookup.');
	}

	$row = [];
	if ($statement->fetch()) {
		$row = [
			'id' => (int) $id,
			'email_address' => (string) $emailAddress,
			'orcid_url' => (string) $orcidUrl,
		];
	}

	$statement->close();
	if (count($row) === 0) {
		throw new RuntimeException('Waiting list request was not found.');
	}

	return $row;
}

function ryerson_admin_find_existing_member(mysqli $mysqli, string $orcidId, string $emailAddress): array
{
	$sql = '
		SELECT community_member_id, orcid_id, email_address, membership_status
		FROM `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
		WHERE orcid_id = ? OR email_address = ?
		ORDER BY community_member_id ASC
		LIMIT 2
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare community member lookup.');
	}

	$statement->bind_param('ss', $orcidId, $emailAddress);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not execute community member lookup.');
	}

	if (!$statement->bind_result($communityMemberId, $memberOrcidId, $memberEmailAddress, $membershipStatus)) {
		$statement->close();
		throw new RuntimeException('Could not bind community member lookup.');
	}

	$rows = [];
	while ($statement->fetch()) {
		$rows[] = [
			'community_member_id' => (int) $communityMemberId,
			'orcid_id' => (string) $memberOrcidId,
			'email_address' => (string) $memberEmailAddress,
			'membership_status' => (string) $membershipStatus,
		];
	}

	$statement->close();
	if (count($rows) > 1) {
		throw new RuntimeException('The waiting list email and ORCID match different existing community members.');
	}

	if (count($rows) === 0) {
		return [];
	}

	if ($rows[0]['orcid_id'] !== $orcidId || $rows[0]['email_address'] !== $emailAddress) {
		throw new RuntimeException('An existing community member has the same email or ORCID with conflicting account details.');
	}

	return $rows[0];
}

function ryerson_admin_upsert_pending_member(mysqli $mysqli, array $waitingListRequest, array $orcidEligibility): int
{
	$emailAddress = (string) $waitingListRequest['email_address'];
	$orcidId = ryerson_community_normalize_orcid_id((string) $waitingListRequest['orcid_url']);
	$orcidUrl = ryerson_community_orcid_url($orcidId);
	$displayName = (string) $orcidEligibility['display_name'];
	$orcidRecordCreatedOn = $orcidEligibility['orcid_record_created_on'];
	$existingMember = ryerson_admin_find_existing_member($mysqli, $orcidId, $emailAddress);

	if (count($existingMember) > 0) {
		if ((string) $existingMember['membership_status'] === 'active') {
			throw new RuntimeException('This applicant is already an active community member.');
		}

		$communityMemberId = (int) $existingMember['community_member_id'];
		$sql = '
			UPDATE `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
			SET
				orcid_url = ?,
				display_name = ?,
				orcid_profile_fetched_at_utc = UTC_TIMESTAMP(),
				orcid_record_created_on = ?,
				membership_status = "pending",
				approved_at_utc = COALESCE(approved_at_utc, UTC_TIMESTAMP()),
				updated_at_utc = UTC_TIMESTAMP()
			WHERE community_member_id = ?
		';
		$statement = $mysqli->prepare($sql);
		if ($statement === false) {
			throw new RuntimeException('Could not prepare community member update.');
		}

		$statement->bind_param('sssi', $orcidUrl, $displayName, $orcidRecordCreatedOn, $communityMemberId);
		if (!$statement->execute()) {
			$statement->close();
			throw new RuntimeException('Could not update community member.');
		}

		$statement->close();
		return $communityMemberId;
	}

	$status = 'pending';
	$initialBalance = RYERSON_INITIAL_NEDBUCKS_BALANCE;
	$sql = '
		INSERT INTO `' . COMMUNITY_MEMBERS_TABLE_NAME . '` (
			orcid_id,
			orcid_url,
			email_address,
			display_name,
			orcid_profile_fetched_at_utc,
			orcid_record_created_on,
			membership_status,
			approved_at_utc,
			nedbucks_balance
		)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, UTC_TIMESTAMP(), ?)
	';
	$statement = $mysqli->prepare($sql);
	if ($statement === false) {
		throw new RuntimeException('Could not prepare community member insert.');
	}

	$statement->bind_param('ssssssi', $orcidId, $orcidUrl, $emailAddress, $displayName, $orcidRecordCreatedOn, $status, $initialBalance);
	if (!$statement->execute()) {
		$statement->close();
		throw new RuntimeException('Could not create community member.');
	}

	$communityMemberId = (int) $mysqli->insert_id;
	$statement->close();

	return $communityMemberId;
}

function ryerson_admin_create_invitation(mysqli $mysqli, int $waitingListRequestId): array
{
	$waitingListRequest = ryerson_admin_fetch_waiting_list_request($mysqli, $waitingListRequestId, false);
	$orcidId = ryerson_community_normalize_orcid_id((string) $waitingListRequest['orcid_url']);
	$record = ryerson_community_fetch_orcid_public_record($orcidId);
	$orcidEligibility = ryerson_community_validate_orcid_record($record, (string) $waitingListRequest['email_address']);
	if ($orcidEligibility['eligible'] !== true) {
		throw new RuntimeException('ORCID eligibility check failed: ' . (string) $orcidEligibility['reason']);
	}

	$token = ryerson_community_generate_token();
	$tokenHash = ryerson_community_hash_token($token);
	$ttlDays = ryerson_community_invitation_ttl_days();
	$invitationId = 0;
	$communityMemberId = 0;

	$mysqli->begin_transaction();
	try {
		$lockedWaitingListRequest = ryerson_admin_fetch_waiting_list_request($mysqli, $waitingListRequestId, true);
		$communityMemberId = ryerson_admin_upsert_pending_member($mysqli, $lockedWaitingListRequest, $orcidEligibility);

		$expireStatus = 'expired';
		$pendingStatus = 'pending';
		$expireSql = '
			UPDATE `' . COMMUNITY_INVITATIONS_TABLE_NAME . '`
			SET status = ?, updated_at_utc = UTC_TIMESTAMP()
			WHERE status = ?
				AND (community_member_id = ? OR waiting_list_request_id = ?)
		';
		$expireStatement = $mysqli->prepare($expireSql);
		if ($expireStatement === false) {
			throw new RuntimeException('Could not prepare old invitation expiry.');
		}

		$expireStatement->bind_param('ssii', $expireStatus, $pendingStatus, $communityMemberId, $waitingListRequestId);
		if (!$expireStatement->execute()) {
			$expireStatement->close();
			throw new RuntimeException('Could not expire old invitations.');
		}
		$expireStatement->close();

		$insertStatus = 'pending';
		$insertSql = '
			INSERT INTO `' . COMMUNITY_INVITATIONS_TABLE_NAME . '` (
				waiting_list_request_id,
				community_member_id,
				email_address,
				orcid_id,
				token_hash,
				status,
				expires_at_utc
			)
			VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $ttlDays . ' DAY))
		';
		$insertStatement = $mysqli->prepare($insertSql);
		if ($insertStatement === false) {
			throw new RuntimeException('Could not prepare invitation insert.');
		}

		$emailAddress = (string) $lockedWaitingListRequest['email_address'];
		$insertStatement->bind_param('iissss', $waitingListRequestId, $communityMemberId, $emailAddress, $orcidId, $tokenHash, $insertStatus);
		if (!$insertStatement->execute()) {
			$insertStatement->close();
			throw new RuntimeException('Could not create invitation.');
		}

		$invitationId = (int) $mysqli->insert_id;
		$insertStatement->close();
		$mysqli->commit();
	} catch (RuntimeException $exception) {
		$mysqli->rollback();
		throw $exception;
	}

	$emailSent = ryerson_community_send_invitation_email((string) $waitingListRequest['email_address'], $token);
	if ($emailSent) {
		$sentStatus = 'pending';
		$sentSql = '
			UPDATE `' . COMMUNITY_INVITATIONS_TABLE_NAME . '`
			SET status = ?, sent_at_utc = UTC_TIMESTAMP(), updated_at_utc = UTC_TIMESTAMP()
			WHERE invitation_id = ?
		';
		$sentStatement = $mysqli->prepare($sentSql);
		if ($sentStatement === false) {
			throw new RuntimeException('Could not prepare invitation sent update.');
		}
		$sentStatement->bind_param('si', $sentStatus, $invitationId);
		if (!$sentStatement->execute()) {
			$sentStatement->close();
			throw new RuntimeException('Could not mark invitation sent.');
		}
		$sentStatement->close();

		$memberSql = '
			UPDATE `' . COMMUNITY_MEMBERS_TABLE_NAME . '`
			SET welcome_email_sent_at_utc = UTC_TIMESTAMP(), updated_at_utc = UTC_TIMESTAMP()
			WHERE community_member_id = ?
		';
		$memberStatement = $mysqli->prepare($memberSql);
		if ($memberStatement === false) {
			throw new RuntimeException('Could not prepare member email timestamp update.');
		}
		$memberStatement->bind_param('i', $communityMemberId);
		if (!$memberStatement->execute()) {
			$memberStatement->close();
			throw new RuntimeException('Could not update member email timestamp.');
		}
		$memberStatement->close();

		return [
			'sent' => true,
			'message' => 'Invitation email sent to ' . (string) $waitingListRequest['email_address'] . '.',
		];
	}

	$failureStatus = 'email_failed';
	$failureSql = '
		UPDATE `' . COMMUNITY_INVITATIONS_TABLE_NAME . '`
		SET status = ?, updated_at_utc = UTC_TIMESTAMP()
		WHERE invitation_id = ?
	';
	$failureStatement = $mysqli->prepare($failureSql);
	if ($failureStatement === false) {
		throw new RuntimeException('Could not prepare invitation failure update.');
	}
	$failureStatement->bind_param('si', $failureStatus, $invitationId);
	if (!$failureStatement->execute()) {
		$failureStatement->close();
		throw new RuntimeException('Could not mark invitation email failure.');
	}
	$failureStatement->close();

	return [
		'sent' => false,
		'message' => 'Invitation was created, but SMTP reported a delivery failure.',
	];
}

function ryerson_admin_waiting_list_status_label(array $row): string
{
	if ((string) $row['membership_status'] === 'active') {
		return 'Active member';
	}

	$status = (string) $row['invitation_status'];
	if ($status === '') {
		return 'Not invited';
	}

	if ($status === 'pending' && (bool) $row['invitation_is_expired']) {
		return 'Expired invite';
	}

	if ($status === 'pending') {
		return 'Pending invite';
	}

	if ($status === 'accepted') {
		return 'Accepted invite';
	}

	if ($status === 'email_failed') {
		return 'Email failed';
	}

	if ($status === 'expired') {
		return 'Expired invite';
	}

	return $status;
}

try {
	ryerson_admin_bootstrap();
	$mysqli = create_database_connection();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		ryerson_admin_verify_csrf_token();
		$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
		$waitingListRequestId = isset($_POST['waiting_list_request_id']) ? (int) $_POST['waiting_list_request_id'] : 0;
		if ($action !== 'send_invitation' || $waitingListRequestId < 1) {
			throw new RuntimeException('Invalid waiting list admin action.');
		}

		$result = ryerson_admin_create_invitation($mysqli, $waitingListRequestId);
		ryerson_admin_set_flash($result['sent'] ? 'success' : 'warning', (string) $result['message']);
		$mysqli->close();
		$redirectUrl = 'waiting_list.php';
		$search = isset($_POST['search']) ? trim((string) $_POST['search']) : '';
		if ($search !== '') {
			$redirectUrl .= '?search=' . rawurlencode($search);
		}
		header('Location: ' . $redirectUrl);
		exit;
	}

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
	$flash = ryerson_admin_pop_flash();
	$csrfToken = ryerson_admin_get_csrf_token();
} catch (RuntimeException $exception) {
	error_log('Ryerson waiting list admin error: ' . $exception->getMessage());
	ryerson_admin_exit_with_error(500, 'Waiting List Admin Error', $exception->getMessage());
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

      <?php if (count($flash) > 0 && $flash['message'] !== ''): ?>
      <div class="alert alert-<?php echo ryerson_admin_html($flash['type']); ?>" role="alert">
        <?php echo ryerson_admin_html($flash['message']); ?>
      </div>
      <?php endif; ?>

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
              <th scope="col">Status</th>
              <th scope="col">Invitation</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <?php
              $statusLabel = ryerson_admin_waiting_list_status_label($row);
              $canSendInvitation = (string) $row['membership_status'] !== 'active';
              $buttonLabel = $row['invitation_id'] === null ? 'Approve and Send Invitation' : 'Resend Invitation';
            ?>
            <tr>
              <td><?php echo ryerson_admin_html((string) $row['created_at_utc']); ?></td>
              <td><a href="mailto:<?php echo ryerson_admin_html((string) $row['email_address']); ?>"><?php echo ryerson_admin_html((string) $row['email_address']); ?></a></td>
              <td><a href="<?php echo ryerson_admin_html((string) $row['orcid_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo ryerson_admin_html((string) $row['orcid_url']); ?></a></td>
              <td>
                <div class="fw-semibold"><?php echo ryerson_admin_html($statusLabel); ?></div>
                <?php if ((string) $row['display_name'] !== ''): ?>
                <div class="small text-muted"><?php echo ryerson_admin_html((string) $row['display_name']); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((string) $row['sent_at_utc'] !== ''): ?>
                <div>Sent <?php echo ryerson_admin_html((string) $row['sent_at_utc']); ?></div>
                <?php endif; ?>
                <?php if ((string) $row['expires_at_utc'] !== ''): ?>
                <div class="small text-muted">Expires <?php echo ryerson_admin_html((string) $row['expires_at_utc']); ?></div>
                <?php else: ?>
                <span class="text-muted">No invitation</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($canSendInvitation): ?>
                <form method="post" class="m-0">
                  <input type="hidden" name="csrf_token" value="<?php echo ryerson_admin_html($csrfToken); ?>">
                  <input type="hidden" name="action" value="send_invitation">
                  <input type="hidden" name="waiting_list_request_id" value="<?php echo ryerson_admin_html((string) $row['id']); ?>">
                  <input type="hidden" name="search" value="<?php echo ryerson_admin_html($search); ?>">
                  <button type="submit" class="btn btn-sm btn-primary"><?php echo ryerson_admin_html($buttonLabel); ?></button>
                </form>
                <?php else: ?>
                <span class="text-muted">No action</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
<?php
ryerson_admin_render_footer();
