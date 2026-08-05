#!/usr/bin/python3

import argparse
import datetime
import html
import json
import math
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

import requests


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
DEFAULT_OUTPUT_DIR = PROJECT_ROOT / "website" / "member-stats"
MEMBER_TEMPLATE_PATH = PROJECT_ROOT / "templates-html" / "member-stats" / "member.html"
INDEX_TEMPLATE_PATH = PROJECT_ROOT / "templates-html" / "member-stats" / "index.html"
SNAPSHOT_URL = "https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/admin/community_member_stats_snapshot.php"
REQUEST_TIMEOUT_SECONDS = 120
PUBLIC_DIRECTORY_MODE = 0o755
PUBLIC_FILE_MODE = 0o644
ORCID_PATTERN = re.compile(r"^\d{4}-\d{4}-\d{4}-[\dX]{4}$")
METRICS = [
	("total_suggested_items", "Total Suggested Items"),
	("total_bakeoff_votes_submitted", "Total Bakeoff Votes Submitted"),
	("current_nedbucks_balance", "Current NEDbucks Balance"),
	("days_since_last_login", "Days Since Last Login"),
	("days_since_approved", "Days Since Approved"),
]


def parse_args():
	parser = argparse.ArgumentParser(
		description="Build and deploy public Ryerson Community Member statistics pages."
	)
	parser.add_argument(
		"--snapshot-file",
		type=Path,
		help="Use a local snapshot JSON file instead of requesting production.",
	)
	parser.add_argument(
		"--output-dir",
		type=Path,
		default=DEFAULT_OUTPUT_DIR,
		help="Directory where the generated static pages are written.",
	)
	parser.add_argument(
		"--skip-deploy",
		action="store_true",
		help="Generate local static pages without syncing them to production.",
	)
	return parser.parse_args()


def load_env_file():
	env_path_override = os.environ.get("RYERSON_ENV_FILE")
	if env_path_override:
		candidate_paths = [Path(env_path_override)]
	else:
		candidate_paths = [DEFAULT_ENV_PATH]

	env_path = next((path for path in candidate_paths if path.is_file()), None)
	if env_path is None:
		raise FileNotFoundError(
			"Environment file not found. Checked: "
			+ ", ".join(str(path) for path in candidate_paths)
		)

	with open(env_path, "r", encoding="utf-8") as file:
		for raw_line in file:
			line = raw_line.strip()
			if line == "" or line.startswith("#"):
				continue

			key, separator, value = line.partition("=")
			if separator == "":
				continue

			key = key.strip()
			value = value.strip()
			if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
				value = value[1:-1]
			os.environ[key] = value


def get_required_env_var(name):
	value = os.environ.get(name)
	if value in {None, ""}:
		raise ValueError(f"Missing required environment variable: {name}")
	return value


def log(message):
	timestamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
	print(f"{timestamp} {message}", flush=True)


def fetch_snapshot():
	username = get_required_env_var("RYERSON_ADMIN_USERNAME")
	password = get_required_env_var("RYERSON_ADMIN_PASSWORD")
	log(f"Requesting Community Member statistics snapshot from {SNAPSHOT_URL}")
	response = requests.get(
		SNAPSHOT_URL,
		auth=(username, password),
		timeout=REQUEST_TIMEOUT_SECONDS,
	)

	if response.status_code == 401:
		raise RuntimeError("Community Member statistics request failed: admin authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError("Community Member statistics request failed: admin access was forbidden.")
	if response.status_code != 200:
		raise RuntimeError(
			f"Community Member statistics request failed with HTTP {response.status_code}."
		)

	try:
		return response.json()
	except ValueError as exception:
		raise RuntimeError("Community Member statistics endpoint returned malformed JSON.") from exception


def read_snapshot(path):
	with open(path, "r", encoding="utf-8") as file:
		return json.load(file)


def parse_utc_timestamp(value, field_name):
	if not isinstance(value, str) or value.strip() == "":
		raise ValueError(f"{field_name} must be a non-empty UTC timestamp.")

	formats = ["%Y-%m-%dT%H:%M:%SZ", "%Y-%m-%d %H:%M:%S"]
	for timestamp_format in formats:
		try:
			parsed = datetime.datetime.strptime(value, timestamp_format)
			return parsed.replace(tzinfo=datetime.timezone.utc)
		except ValueError:
			continue

	raise ValueError(f"{field_name} is not a supported UTC timestamp: {value}")


def require_integer(member, field_name, minimum=None):
	value = member.get(field_name)
	if isinstance(value, bool) or not isinstance(value, int):
		raise ValueError(f"{field_name} must be an integer.")
	if minimum is not None and value < minimum:
		raise ValueError(f"{field_name} must be at least {minimum}.")
	return value


def days_since(timestamp, generated_on):
	return max(0, (generated_on - timestamp.date()).days)


def prepare_members(snapshot):
	if not isinstance(snapshot, dict):
		raise ValueError("Community Member statistics snapshot must be a JSON object.")
	if snapshot.get("schema_version") != 1:
		raise ValueError("Unsupported Community Member statistics snapshot schema version.")

	generated_at = parse_utc_timestamp(snapshot.get("generated_at_utc"), "generated_at_utc")
	generated_on = generated_at.date()
	raw_members = snapshot.get("members")
	if not isinstance(raw_members, list):
		raise ValueError("Community Member statistics snapshot members must be a list.")
	if snapshot.get("active_member_count") != len(raw_members):
		raise ValueError("Community Member statistics snapshot member count did not match its rows.")

	members = []
	seen_member_ids = set()
	for raw_member in raw_members:
		if not isinstance(raw_member, dict):
			raise ValueError("Each Community Member statistics row must be an object.")

		member_id = require_integer(raw_member, "community_member_id", minimum=1)
		if member_id in seen_member_ids:
			raise ValueError(f"Duplicate community_member_id in snapshot: {member_id}")
		seen_member_ids.add(member_id)

		display_name = raw_member.get("display_name")
		if not isinstance(display_name, str) or display_name.strip() == "":
			raise ValueError(f"Community Member #{member_id} has no display name.")
		display_name = display_name.strip()

		orcid_id = raw_member.get("orcid_id")
		if not isinstance(orcid_id, str) or ORCID_PATTERN.fullmatch(orcid_id) is None:
			raise ValueError(f"Community Member #{member_id} has an invalid ORCID identifier.")

		approved_at = parse_utc_timestamp(
			raw_member.get("approved_at_utc"),
			f"Community Member #{member_id} approved_at_utc",
		)
		last_login_at = parse_utc_timestamp(
			raw_member.get("last_login_at_utc"),
			f"Community Member #{member_id} last_login_at_utc",
		)

		members.append(
			{
				"community_member_id": member_id,
				"display_name": display_name,
				"orcid_id": orcid_id,
				"orcid_url": f"https://orcid.org/{orcid_id}",
				"metrics": {
					"total_suggested_items": require_integer(
						raw_member, "total_suggested_items", minimum=0
					),
					"total_bakeoff_votes_submitted": require_integer(
						raw_member, "total_bakeoff_votes_submitted", minimum=0
					),
					"current_nedbucks_balance": require_integer(
						raw_member, "nedbucks_balance"
					),
					"days_since_last_login": days_since(last_login_at, generated_on),
					"days_since_approved": days_since(approved_at, generated_on),
				},
				"percentiles": {},
			}
		)

	member_count = len(members)
	for metric_key, _metric_label in METRICS:
		for member in members:
			if member_count <= 1:
				percentile = 0
			else:
				lower_count = sum(
					1
					for other_member in members
					if other_member["community_member_id"] != member["community_member_id"]
					and other_member["metrics"][metric_key] < member["metrics"][metric_key]
				)
				percentile = math.floor((100.0 * lower_count / (member_count - 1)) + 0.5)
			member["percentiles"][metric_key] = percentile

	members.sort(key=lambda member: (member["display_name"].casefold(), member["community_member_id"]))
	return generated_on, members


def read_template(path):
	with open(path, "r", encoding="utf-8") as file:
		return file.read()


def replace_placeholders(template, replacements):
	content = template
	for placeholder, value in replacements.items():
		content = content.replace(placeholder, value)
	return content


def render_metric_rows(member):
	rows = []
	for metric_key, metric_label in METRICS:
		rows.append(
			"\n".join(
				[
					f'              <tr data-metric="{html.escape(metric_key, quote=True)}">',
					f'                <td class="text-end">{member["metrics"][metric_key]:,}</td>',
					f'                <th scope="row">{html.escape(metric_label)}</th>',
					f'                <td class="text-end">{member["percentiles"][metric_key]}%</td>',
					"              </tr>",
				]
			)
		)
	return "\n".join(rows)


def render_member_page(template, member, generated_on):
	escaped_name = html.escape(member["display_name"], quote=True)
	return replace_placeholders(
		template,
		{
			"MEMBER_DISPLAY_NAME": escaped_name,
			"MEMBER_ORCID_ID": html.escape(member["orcid_id"], quote=True),
			"MEMBER_ORCID_URL": html.escape(member["orcid_url"], quote=True),
			"GENERATED_ON_UTC": generated_on.isoformat(),
			"MEMBER_METRIC_ROWS": render_metric_rows(member),
		},
	)


def render_index_content(members):
	if len(members) == 0:
		return '<div class="alert alert-info" role="status">No active Community Members are available yet.</div>'

	rows = []
	for member in members:
		member_id = member["community_member_id"]
		display_name = html.escape(member["display_name"], quote=True)
		orcid_id = html.escape(member["orcid_id"], quote=True)
		orcid_url = html.escape(member["orcid_url"], quote=True)
		rows.append(
			"\n".join(
				[
					"            <tr>",
					f'              <th scope="row"><a href="{member_id}.html">{display_name}</a></th>',
					f'              <td><a href="{orcid_url}" target="_blank" rel="me noopener">{orcid_id} <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></td>',
					f'              <td><a href="{member_id}.html">View statistics</a></td>',
					"            </tr>",
				]
			)
		)

	return "\n".join(
		[
			'<div class="table-responsive">',
			'  <table class="table table-striped table-hover align-middle">',
			"    <caption>Active Ryerson Community Members with public statistics pages.</caption>",
			'    <thead class="table-light">',
			"      <tr>",
			'        <th scope="col">Community Member</th>',
			'        <th scope="col">ORCID</th>',
			'        <th scope="col">Statistics</th>',
			"      </tr>",
			"    </thead>",
			"    <tbody>",
			"\n".join(rows),
			"    </tbody>",
			"  </table>",
			"</div>",
		]
	)


def render_index_page(template, members, generated_on):
	return replace_placeholders(
		template,
		{
			"GENERATED_ON_UTC": generated_on.isoformat(),
			"ACTIVE_MEMBER_COUNT": f"{len(members):,}",
			"MEMBER_INDEX_CONTENT": render_index_content(members),
		},
	)


def write_public_html(path, content):
	path.write_text(content, encoding="utf-8")
	path.chmod(PUBLIC_FILE_MODE)


def write_pages(snapshot, output_dir=DEFAULT_OUTPUT_DIR):
	generated_on, members = prepare_members(snapshot)
	member_template = read_template(MEMBER_TEMPLATE_PATH)
	index_template = read_template(INDEX_TEMPLATE_PATH)

	output_dir = Path(output_dir)
	output_dir.parent.mkdir(parents=True, exist_ok=True)
	staging_dir = Path(tempfile.mkdtemp(prefix=".member-stats-staging-", dir=str(output_dir.parent)))
	staging_dir.chmod(PUBLIC_DIRECTORY_MODE)
	backup_dir = None

	try:
		write_public_html(
			staging_dir / "index.html",
			render_index_page(index_template, members, generated_on),
		)
		for member in members:
			member_path = staging_dir / f'{member["community_member_id"]}.html'
			write_public_html(
				member_path,
				render_member_page(member_template, member, generated_on),
			)

		if output_dir.exists():
			if not output_dir.is_dir():
				raise RuntimeError(f"Member statistics output path is not a directory: {output_dir}")
			backup_dir = Path(tempfile.mkdtemp(prefix=".member-stats-backup-", dir=str(output_dir.parent)))
			backup_dir.rmdir()
			os.replace(output_dir, backup_dir)

		try:
			os.replace(staging_dir, output_dir)
			output_dir.chmod(PUBLIC_DIRECTORY_MODE)
		except Exception:
			if backup_dir is not None and backup_dir.exists() and not output_dir.exists():
				os.replace(backup_dir, output_dir)
			raise

		if backup_dir is not None and backup_dir.exists():
			shutil.rmtree(backup_dir)
			backup_dir = None
	finally:
		if staging_dir.exists():
			shutil.rmtree(staging_dir)
		if backup_dir is not None and backup_dir.exists():
			shutil.rmtree(backup_dir)

	return {
		"generated_on": generated_on.isoformat(),
		"member_count": len(members),
		"output_dir": output_dir,
	}


def deploy_pages(output_dir):
	rsync_ssh_command = f'ssh -p {get_required_env_var("RYERSON_DEPLOY_SSH_PORT")}'
	remote_root = get_required_env_var("RYERSON_DEPLOY_REMOTE_PATH").rstrip("/")
	destination = (
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_USER")}@'
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_HOST")}:'
		f"{remote_root}/member-stats/"
	)
	command = [
		"rsync",
		"-avz",
		"--delete",
		"--chmod=D755,F644",
		"-e",
		rsync_ssh_command,
		str(Path(output_dir)) + "/",
		destination,
	]

	log("Deploying Community Member statistics pages to production.")
	result = subprocess.run(command, capture_output=True, text=True)
	if result.stdout.strip():
		print(result.stdout.strip(), flush=True)
	if result.stderr.strip():
		print(result.stderr.strip(), file=sys.stderr, flush=True)
	if result.returncode != 0:
		raise RuntimeError(f"Community Member statistics rsync failed with exit code {result.returncode}.")


def main():
	args = parse_args()
	if args.snapshot_file is None or not args.skip_deploy:
		load_env_file()

	if args.snapshot_file is None:
		snapshot = fetch_snapshot()
	else:
		log(f"Reading Community Member statistics snapshot from {args.snapshot_file}")
		snapshot = read_snapshot(args.snapshot_file)

	result = write_pages(snapshot, args.output_dir)
	log(
		f'Generated {result["member_count"]} Community Member statistics page(s) '
		f'through {result["generated_on"]} in {result["output_dir"]}.'
	)

	if not args.skip_deploy:
		deploy_pages(result["output_dir"])
		log("Community Member statistics deployment completed.")


if __name__ == "__main__":
	try:
		main()
	except Exception as exception:
		log(f"ERROR: {exception}")
		sys.exit(1)
