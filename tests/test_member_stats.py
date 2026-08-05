#!/usr/bin/python3

import importlib.util
from pathlib import Path
import tempfile
import unittest


PROJECT_ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = PROJECT_ROOT / "python" / "ryerson_project_update_member_stats.py"
SPEC = importlib.util.spec_from_file_location("ryerson_project_update_member_stats", MODULE_PATH)
member_stats = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(member_stats)


def snapshot_with_members(members):
	return {
		"schema_version": 1,
		"generated_at_utc": "2026-08-05T00:15:00Z",
		"active_member_count": len(members),
		"members": members,
	}


def member_row(
	member_id,
	display_name,
	orcid_id,
	suggestions,
	bakeoffs,
	nedbucks,
	approved_at,
	last_login_at,
):
	return {
		"community_member_id": member_id,
		"display_name": display_name,
		"orcid_id": orcid_id,
		"total_suggested_items": suggestions,
		"total_bakeoff_votes_submitted": bakeoffs,
		"nedbucks_balance": nedbucks,
		"approved_at_utc": approved_at,
		"last_login_at_utc": last_login_at,
	}


class MemberStatsTests(unittest.TestCase):
	def test_strict_comparisons_and_literal_days(self):
		snapshot = snapshot_with_members(
			[
				member_row(
					1,
					"Alice",
					"0000-0001-0000-0001",
					2,
					10,
					10,
					"2026-08-01 12:00:00",
					"2026-08-04 12:00:00",
				),
				member_row(
					2,
					"Bob",
					"0000-0001-0000-0002",
					2,
					5,
					10,
					"2026-07-01 12:00:00",
					"2026-08-01 12:00:00",
				),
				member_row(
					3,
					"Eve",
					"0000-0001-0000-0003",
					0,
					10,
					5,
					"2026-08-05 00:00:00",
					"2026-08-05 00:00:00",
				),
			]
		)

		generated_on, members = member_stats.prepare_members(snapshot)
		by_id = {member["community_member_id"]: member for member in members}

		self.assertEqual("2026-08-05", generated_on.isoformat())
		self.assertEqual(1, by_id[1]["metrics"]["days_since_last_login"])
		self.assertEqual(4, by_id[2]["metrics"]["days_since_last_login"])
		self.assertEqual(100, by_id[2]["percentiles"]["days_since_last_login"])
		self.assertEqual(50, by_id[1]["percentiles"]["total_suggested_items"])
		self.assertEqual(50, by_id[1]["percentiles"]["total_bakeoff_votes_submitted"])
		self.assertEqual(0, by_id[3]["percentiles"]["total_suggested_items"])

	def test_single_member_percentiles_are_zero(self):
		snapshot = snapshot_with_members(
			[
				member_row(
					9,
					"Only Member",
					"0000-0001-0000-0009",
					5,
					50,
					10,
					"2026-08-01 00:00:00",
					"2026-08-05 00:00:00",
				)
			]
		)

		_generated_on, members = member_stats.prepare_members(snapshot)
		self.assertTrue(all(value == 0 for value in members[0]["percentiles"].values()))

	def test_pages_escape_names_link_orcid_and_remove_stale_files(self):
		snapshot = snapshot_with_members(
			[
				member_row(
					7,
					'<script>alert("member")</script>',
					"0000-0002-1825-0097",
					1,
					2,
					10,
					"2026-08-01 00:00:00",
					"2026-08-04 00:00:00",
				)
			]
		)

		with tempfile.TemporaryDirectory() as temporary_dir:
			output_dir = Path(temporary_dir) / "member-stats"
			output_dir.mkdir()
			(output_dir / "stale.html").write_text("stale", encoding="utf-8")

			result = member_stats.write_pages(snapshot, output_dir)
			member_html = (output_dir / "7.html").read_text(encoding="utf-8")
			index_html = (output_dir / "index.html").read_text(encoding="utf-8")

			self.assertEqual(1, result["member_count"])
			self.assertFalse((output_dir / "stale.html").exists())
			self.assertEqual(0o755, output_dir.stat().st_mode & 0o777)
			self.assertEqual(0o644, (output_dir / "index.html").stat().st_mode & 0o777)
			self.assertEqual(0o644, (output_dir / "7.html").stat().st_mode & 0o777)
			self.assertNotIn("<script>alert", member_html)
			self.assertIn("&lt;script&gt;alert(&quot;member&quot;)&lt;/script&gt;", member_html)
			self.assertIn("https://orcid.org/0000-0002-1825-0097", member_html)
			self.assertIn('data-metric="days_since_last_login"', member_html)
			self.assertIn('href="7.html"', index_html)
			self.assertNotIn("MEMBER_", member_html)
			self.assertNotIn("GENERATED_ON_UTC", member_html)
			self.assertNotIn("MEMBER_INDEX_CONTENT", index_html)

	def test_missing_login_timestamp_is_rejected(self):
		row = member_row(
			5,
			"Missing Login",
			"0000-0001-0000-0005",
			0,
			0,
			10,
			"2026-08-01 00:00:00",
			None,
		)
		with self.assertRaisesRegex(ValueError, "last_login_at_utc"):
			member_stats.prepare_members(snapshot_with_members([row]))


if __name__ == "__main__":
	unittest.main()
