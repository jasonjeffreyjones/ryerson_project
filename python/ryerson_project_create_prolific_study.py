#!/usr/bin/python3

"""Create and publish today's Prolific study for the Ryerson Project."""

import argparse
import csv
import datetime
import gzip
import json
import os
from pathlib import Path
import sys

import requests

PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
DEFAULT_RESPONSE_EXPORTS_DIR = PROJECT_ROOT / "private" / "response_exports"
PROLIFIC_API_BASE_URL = "https://api.prolific.com/api/v1"
COOLDOWN_DAYS = 7
PARTICIPANT_GROUP_BATCH_SIZE = 500
DEFAULT_EXTERNAL_STUDY_URL = (
	"https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/survey/"
	"?PROLIFIC_PID={{%PROLIFIC_PID%}}&STUDY_ID={{%STUDY_ID%}}&sess_id={{%SESSION_ID%}}"
)


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

	with open(env_path, "r") as file:
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


def get_int_env_var(name, default_value):
	value = os.environ.get(name)
	if value in {None, ""}:
		return default_value

	try:
		return int(value)
	except ValueError as exception:
		raise ValueError(f"Environment variable {name} must be an integer.") from exception


def log(message):
	timestamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
	print(f"{timestamp} {message}", flush=True)


def today_utc():
	return datetime.datetime.now(datetime.timezone.utc).date()


def cooldown_observation_dates(observation_date):
	return [
		observation_date - datetime.timedelta(days=days_ago)
		for days_ago in range(COOLDOWN_DAYS, 0, -1)
	]


def date_token(observation_date):
	return observation_date.strftime("%Y_%m_%d")


def response_exports_dir():
	path = os.environ.get("RYERSON_RESPONSE_EXPORTS_DIR")
	if path in {None, ""}:
		return DEFAULT_RESPONSE_EXPORTS_DIR
	return Path(path)


def response_export_path(exports_dir, observation_date):
	return exports_dir / f"responses_{date_token(observation_date)}.csv.gz"


def read_prolific_pids_from_response_export(path):
	with gzip.open(path, "rt", newline="") as file:
		reader = csv.DictReader(file)
		if reader.fieldnames is None:
			raise RuntimeError(f"{path} is missing a CSV header.")
		if "prolific_pid" not in reader.fieldnames:
			raise RuntimeError(f"{path} is missing required column prolific_pid.")

		prolific_pids = set()
		for row in reader:
			prolific_pid = (row.get("prolific_pid") or "").strip()
			if prolific_pid != "":
				prolific_pids.add(prolific_pid)

	return prolific_pids


def read_recent_prolific_pids(observation_date):
	exports_dir = response_exports_dir()
	prolific_pids = set()

	for cooldown_date in cooldown_observation_dates(observation_date):
		path = response_export_path(exports_dir, cooldown_date)
		if not path.is_file():
			log(f"Cooldown export missing for {cooldown_date}: {path}")
			continue

		daily_pids = read_prolific_pids_from_response_export(path)
		prolific_pids.update(daily_pids)
		log(f"Cooldown export {path.name} contributed {len(daily_pids)} distinct Prolific PID(s).")

	return prolific_pids


def chunked(values, chunk_size):
	values = list(values)
	for index in range(0, len(values), chunk_size):
		yield values[index:index + chunk_size]


def ensure_prolific_response_ok(response, expected_status_codes, action):
	if response.status_code in expected_status_codes:
		return
	if response.status_code == 401:
		raise RuntimeError(f"{action} failed: authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError(f"{action} failed: access was forbidden.")
	raise RuntimeError(f"{action} failed with HTTP {response.status_code}: {response.text}")


def fetch_participant_group_pids(session, headers, participant_group_id):
	url = f"{PROLIFIC_API_BASE_URL}/participant-groups/{participant_group_id}/participants/"
	participant_pids = set()
	seen_urls = set()

	while url:
		if url in seen_urls:
			raise RuntimeError("Prolific API returned a repeated participant-group page.")
		seen_urls.add(url)

		response = session.get(url, headers=headers, timeout=30)
		ensure_prolific_response_ok(response, {200}, "Participant group fetch")
		payload = response.json()
		results = payload.get("results")
		if not isinstance(results, list):
			raise RuntimeError("Prolific participant-group response did not include a results list.")

		for item in results:
			if not isinstance(item, dict):
				continue
			participant_id = item.get("participant_id")
			if isinstance(participant_id, str) and participant_id.strip() != "":
				participant_pids.add(participant_id.strip())

		next_url = payload.get("next")
		url = next_url if isinstance(next_url, str) and next_url.strip() != "" else None

	return participant_pids


def add_participants_to_group(session, headers, participant_group_id, participant_pids):
	if len(participant_pids) == 0:
		return

	url = f"{PROLIFIC_API_BASE_URL}/participant-groups/{participant_group_id}/participants/"
	for batch in chunked(sorted(participant_pids), PARTICIPANT_GROUP_BATCH_SIZE):
		response = session.post(
			url,
			headers=headers,
			data=json.dumps({"participant_ids": batch}),
			timeout=30,
		)
		ensure_prolific_response_ok(response, {200}, "Participant group add")


def remove_participants_from_group(session, headers, participant_group_id, participant_pids):
	if len(participant_pids) == 0:
		return

	url = f"{PROLIFIC_API_BASE_URL}/participant-groups/{participant_group_id}/participants/"
	for batch in chunked(sorted(participant_pids), PARTICIPANT_GROUP_BATCH_SIZE):
		response = session.delete(
			url,
			headers=headers,
			data=json.dumps({"participant_ids": batch}),
			timeout=30,
		)
		ensure_prolific_response_ok(response, {200}, "Participant group remove")


def sync_cooldown_participant_group(session, headers, observation_date, dry_run=False):
	participant_group_id = os.environ.get("RYERSON_PROLIFIC_COOLDOWN_PARTICIPANT_GROUP_ID")
	if participant_group_id in {None, ""}:
		raise RuntimeError("RYERSON_PROLIFIC_COOLDOWN_PARTICIPANT_GROUP_ID is not configured.")

	target_pids = read_recent_prolific_pids(observation_date)
	current_pids = fetch_participant_group_pids(session, headers, participant_group_id)
	pids_to_add = target_pids - current_pids
	pids_to_remove = current_pids - target_pids

	log(
		"Cooldown sync prepared: "
		f"{len(target_pids)} target PID(s), "
		f"{len(current_pids)} current group PID(s), "
		f"{len(pids_to_add)} to add, "
		f"{len(pids_to_remove)} to remove."
	)

	if dry_run:
		log("Dry run: cooldown participant group was not modified.")
		return participant_group_id

	add_participants_to_group(session, headers, participant_group_id, pids_to_add)
	remove_participants_from_group(session, headers, participant_group_id, pids_to_remove)
	log("Cooldown participant group sync completed.")
	return participant_group_id


def build_completion_code_actions(cooldown_participant_group_id=None):
	actions = [{"action": "AUTOMATICALLY_APPROVE"}]
	if cooldown_participant_group_id is not None:
		actions.append({
			"action": "ADD_TO_PARTICIPANT_GROUP",
			"participant_group": cooldown_participant_group_id,
		})
	return actions


def build_study_filters(cooldown_participant_group_id=None):
	filters = [
		{
			"filter_id": "current-country-of-residence",
			"selected_values": ["1"],
		}
	]
	if cooldown_participant_group_id is not None:
		filters.append({
			"filter_id": "participant_group_blocklist",
			"selected_values": [cooldown_participant_group_id],
		})
	return filters


def build_study_payload(observation_date, cooldown_participant_group_id=None):
	completion_code = get_required_env_var("RYERSON_PROLIFIC_COMPLETION_CODE")
	project_id = get_required_env_var("RYERSON_PROLIFIC_PROJECT_ID")
	external_study_url = os.environ.get("RYERSON_PROLIFIC_EXTERNAL_STUDY_URL", DEFAULT_EXTERNAL_STUDY_URL)

	return {
		"name": f"Survey {observation_date}",
		"internal_name": f"Ryerson {observation_date}",
		"description": "Survey intended for American adults.",
		"external_study_url": external_study_url,
		"prolific_id_option": "url_parameters",
		"completion_codes": [
			{
				"code": completion_code,
				"code_type": "COMPLETED",
				"actions": build_completion_code_actions(cooldown_participant_group_id),
			}
		],
		"filters": build_study_filters(cooldown_participant_group_id),
		"total_available_places": get_int_env_var("RYERSON_PROLIFIC_TOTAL_AVAILABLE_PLACES", 12),
		"estimated_completion_time": get_int_env_var("RYERSON_PROLIFIC_ESTIMATED_COMPLETION_MINUTES", 5),
		"maximum_allowed_time": get_int_env_var("RYERSON_PROLIFIC_MAXIMUM_ALLOWED_MINUTES", 20),
		"reward": get_int_env_var("RYERSON_PROLIFIC_REWARD_CENTS", 75),
		"project": project_id,
		"study_labels": ["survey"],
	}


def create_draft_study(headers, observation_date, cooldown_participant_group_id=None):
	create_url = f"{PROLIFIC_API_BASE_URL}/studies/"
	payload = build_study_payload(observation_date, cooldown_participant_group_id)
	response = requests.post(create_url, headers=headers, data=json.dumps(payload), timeout=30)
	return response, payload


def publish_study(headers, study_id):
	publish_url = f"{PROLIFIC_API_BASE_URL}/studies/{study_id}/transition/"
	return requests.post(
		publish_url,
		headers=headers,
		data=json.dumps({"action": "PUBLISH"}),
		timeout=30,
	)


def sanitized_payload_for_log(payload):
	sanitized_payload = json.loads(json.dumps(payload))
	for completion_code in sanitized_payload.get("completion_codes", []):
		if isinstance(completion_code, dict) and "code" in completion_code:
			completion_code["code"] = "[redacted]"
	return sanitized_payload


def create_and_publish_prolific_study(dry_run=False, sync_cooldown_only=False):
	load_env_file()

	api_token = get_required_env_var("RYERSON_PROLIFIC_API_TOKEN")
	observation_date = today_utc()
	observation_date_string = observation_date.strftime("%Y-%m-%d")
	headers = {
		"Authorization": f"Token {api_token}",
		"Content-Type": "application/json",
	}
	session = requests.Session()
	cooldown_participant_group_id = None

	try:
		cooldown_participant_group_id = sync_cooldown_participant_group(
			session,
			headers,
			observation_date,
			dry_run=dry_run,
		)
	except Exception as exception:
		log(f"Cooldown sync failed; continuing without cooldown: {exception}")

	if sync_cooldown_only:
		return cooldown_participant_group_id is not None

	if dry_run:
		payload = build_study_payload(observation_date_string, cooldown_participant_group_id)
		print(json.dumps(sanitized_payload_for_log(payload), indent=2, sort_keys=True))
		log("Dry run: study was not created or published.")
		return True

	response, payload = create_draft_study(headers, observation_date_string, cooldown_participant_group_id)
	if response.status_code != 201:
		print(f"{__file__} failed Prolific API request to create study. Status code: {response.status_code}")
		print("Response:", response.text)
		if cooldown_participant_group_id is None:
			return False

		log("Retrying study creation without cooldown.")
		response, payload = create_draft_study(headers, observation_date_string)
		if response.status_code != 201:
			print(f"{__file__} failed Prolific API request to create fallback study. Status code: {response.status_code}")
			print("Response:", response.text)
			return False

	study = response.json()
	study_id = study["id"]
	study_name = study["name"]
	print(f"{__file__} successfully created a draft study named: {study_name}")

	publish_response = publish_study(headers, study_id)

	if publish_response.status_code != 200:
		print(f"{__file__} failed to publish study {study_id}. Status code: {publish_response.status_code}")
		print("Response:", publish_response.text)
		return False

	published_study = publish_response.json()
	print(f"{__file__} successfully published study {study_name} as {published_study['id']}")
	return True


def parse_args():
	parser = argparse.ArgumentParser(
		description="Create and publish today's Prolific study for the Ryerson Project."
	)
	parser.add_argument(
		"--dry-run",
		action="store_true",
		help="Read inputs and fetch current cooldown group membership, but do not mutate Prolific.",
	)
	parser.add_argument(
		"--sync-cooldown-only",
		action="store_true",
		help="Only sync the cooldown participant group; do not create or publish a study.",
	)
	return parser.parse_args()


def main():
	args = parse_args()
	try:
		return 0 if create_and_publish_prolific_study(
			dry_run=args.dry_run,
			sync_cooldown_only=args.sync_cooldown_only,
		) else 1
	except Exception as exception:
		print(f"{__file__} failed: {exception}")
		return 1


if __name__ == "__main__":
	sys.exit(main())
