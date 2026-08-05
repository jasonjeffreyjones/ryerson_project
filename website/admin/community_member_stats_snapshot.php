<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_lib.php';

function ryerson_admin_fetch_community_member_stats_snapshot(mysqli $mysqli): array
{
	$sql = '
		SELECT
			cm.community_member_id,
			CASE
				WHEN cm.display_name = SUBSTRING_INDEX(cm.email_address, "@", 1)
					THEN CONCAT("ORCID ", cm.orcid_id)
				ELSE cm.display_name
			END AS public_display_name,
			cm.orcid_id,
			cm.nedbucks_balance,
			cm.approved_at_utc,
			cm.last_login_at_utc,
			COALESCE(suggestion_counts.total_suggested_items, 0) AS total_suggested_items,
			COALESCE(bakeoff_counts.total_bakeoff_votes_submitted, 0) AS total_bakeoff_votes_submitted
		FROM `' . COMMUNITY_MEMBERS_TABLE_NAME . '` cm
		LEFT JOIN (
			SELECT community_member_id, COUNT(*) AS total_suggested_items
			FROM `' . SUGGESTED_ITEMS_TABLE_NAME . '`
			GROUP BY community_member_id
		) suggestion_counts
			ON suggestion_counts.community_member_id = cm.community_member_id
		LEFT JOIN (
			SELECT community_member_id, COUNT(*) AS total_bakeoff_votes_submitted
			FROM `' . ITEM_BAKEOFF_RESULTS_TABLE_NAME . '`
			GROUP BY community_member_id
		) bakeoff_counts
			ON bakeoff_counts.community_member_id = cm.community_member_id
		WHERE cm.membership_status = "active"
		ORDER BY cm.display_name ASC, cm.community_member_id ASC
	';
	$result = $mysqli->query($sql);
	if ($result === false) {
		throw new RuntimeException('Could not fetch active Community Member statistics.');
	}

	$members = [];
	while ($row = $result->fetch_assoc()) {
		$members[] = [
			'community_member_id' => (int) $row['community_member_id'],
			'display_name' => (string) $row['public_display_name'],
			'orcid_id' => (string) $row['orcid_id'],
			'nedbucks_balance' => (int) $row['nedbucks_balance'],
			'approved_at_utc' => $row['approved_at_utc'] === null ? null : (string) $row['approved_at_utc'],
			'last_login_at_utc' => $row['last_login_at_utc'] === null ? null : (string) $row['last_login_at_utc'],
			'total_suggested_items' => (int) $row['total_suggested_items'],
			'total_bakeoff_votes_submitted' => (int) $row['total_bakeoff_votes_submitted'],
		];
	}
	$result->close();

	return [
		'schema_version' => 1,
		'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		'active_member_count' => count($members),
		'members' => $members,
	];
}

try {
	ryerson_admin_bootstrap();
	if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
		http_response_code(405);
		header('Allow: GET');
		throw new RuntimeException('Community Member statistics snapshots use GET.');
	}

	$mysqli = create_database_connection();
	$snapshot = ryerson_admin_fetch_community_member_stats_snapshot($mysqli);
	$mysqli->close();

	$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Could not encode the Community Member statistics snapshot.');
	}

	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo $json . "\n";
} catch (RuntimeException $exception) {
	if (isset($mysqli) && $mysqli instanceof mysqli) {
		$mysqli->close();
	}
	if (http_response_code() < 400) {
		http_response_code(500);
	}
	error_log('Ryerson Community Member statistics snapshot error: ' . $exception->getMessage());
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(['error' => 'Community Member statistics snapshot unavailable.']) . "\n";
}
