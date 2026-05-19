#!/usr/bin/python3

"""Pull daily Prolific demographic exports for the Ryerson Project."""

import argparse
import datetime
import gzip
import os
from pathlib import Path
import sys

import requests


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
LOCAL_EXPORT_DIR = PROJECT_ROOT / "private" / "demographic_exports"
PROLIFIC_API_BASE_URL = "https://api.prolific.com/api/v1"
FIRST_EXPORT_DATE = datetime.date(2026, 5, 1)
REQUEST_TIMEOUT_SECONDS = 120
STUDIES_PAGE_SIZE = 100


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


def log(message):
	timestamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
	print(f"{timestamp} {message}", flush=True)


def parse_date(value):
	try:
		return datetime.date.fromisoformat(value)
	except ValueError as exception:
		raise argparse.ArgumentTypeError(f"{value} is not a valid YYYY-MM-DD date.") from exception


def get_default_end_date(include_today):
	today = datetime.datetime.now(datetime.timezone.utc).date()
	if include_today:
		return today
	return today - datetime.timedelta(days=1)


def iter_dates(start_date, end_date):
	current_date = start_date
	while current_date <= end_date:
		yield current_date
		current_date += datetime.timedelta(days=1)


def build_export_filename(observation_date):
	return f"demographics_{observation_date.strftime('%Y_%m_%d')}.csv.gz"


def build_headers(api_token):
	return {
		"Authorization": f"Token {api_token}",
		"Accept": "application/json",
	}


def extract_page_items(payload):
	if isinstance(payload, list):
		return payload
	if not isinstance(payload, dict):
		raise RuntimeError("Prolific API returned an unexpected studies response shape.")

	for key in ("results", "data", "items"):
		items = payload.get(key)
		if isinstance(items, list):
			return items

	raise RuntimeError("Prolific API response did not include a studies list.")


def extract_next_url(payload):
	if not isinstance(payload, dict):
		return None

	next_url = payload.get("next")
	if isinstance(next_url, str) and next_url.strip() != "":
		return next_url

	links = payload.get("_links")
	if isinstance(links, dict):
		next_link = links.get("next")
		if isinstance(next_link, str) and next_link.strip() != "":
			return next_link
		if isinstance(next_link, dict):
			href = next_link.get("href")
			if isinstance(href, str) and href.strip() != "":
				return href

	return None


def request_json(session, url, headers, params=None):
	response = session.get(url, headers=headers, params=params, timeout=REQUEST_TIMEOUT_SECONDS)
	if response.status_code == 401:
		raise RuntimeError("Prolific API request failed: authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError("Prolific API request failed: access was forbidden.")
	if response.status_code != 200:
		raise RuntimeError(f"Prolific API request failed with HTTP {response.status_code}: {response.text}")
	return response.json()


def load_project_studies(session, api_token, project_id):
	headers = build_headers(api_token)
	url = f"{PROLIFIC_API_BASE_URL}/projects/{project_id}/studies/"
	studies = []
	page = 1
	seen_page_signatures = set()

	while url:
		params = None
		if page is not None:
			params = {
				"page": page,
				"page_size": STUDIES_PAGE_SIZE,
				"ordering": "-date_created",
			}
		payload = request_json(session, url, headers, params=params)
		page_items = extract_page_items(payload)
		page_signature = tuple(
			study.get("id") for study in page_items if isinstance(study, dict)
		)
		if page_signature in seen_page_signatures:
			raise RuntimeError("Prolific API returned a repeated studies page.")
		seen_page_signatures.add(page_signature)
		studies.extend(page_items)

		url = extract_next_url(payload)
		if url:
			page = None
			continue
		if len(page_items) < STUDIES_PAGE_SIZE:
			break
		page += 1

	return studies


def index_studies_by_internal_name(studies):
	indexed_studies = {}
	duplicate_names = set()

	for study in studies:
		if not isinstance(study, dict):
			continue
		internal_name = study.get("internal_name")
		if not internal_name:
			continue
		if internal_name in indexed_studies:
			duplicate_names.add(internal_name)
		indexed_studies[internal_name] = study

	if duplicate_names:
		raise RuntimeError(
			"Multiple Prolific studies have the same internal_name: "
			+ ", ".join(sorted(duplicate_names))
		)

	return indexed_studies


def find_study_for_date(studies_by_internal_name, observation_date):
	internal_name = f"Ryerson {observation_date.isoformat()}"
	return studies_by_internal_name.get(internal_name)


def get_study_id(study):
	study_id = study.get("id")
	if not isinstance(study_id, str) or study_id.strip() == "":
		raise RuntimeError(f"Matched Prolific study is missing an id: {study}")
	return study_id


def fetch_demographic_csv(session, api_token, study_id):
	headers = build_headers(api_token)
	url = f"{PROLIFIC_API_BASE_URL}/studies/{study_id}/demographic-export/"
	response = session.post(
		url,
		headers=headers,
		json={"filters": []},
		timeout=REQUEST_TIMEOUT_SECONDS,
	)

	if response.status_code == 204:
		return b""
	if response.status_code == 401:
		raise RuntimeError("Prolific demographic export failed: authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError("Prolific demographic export failed: access was forbidden.")
	if response.status_code == 404:
		log(f"Prolific demographic export for study {study_id} was unavailable; creating an empty file.")
		return b""
	if response.status_code >= 500:
		raise RuntimeError(f"Prolific demographic export failed with HTTP {response.status_code}: {response.text}")
	if response.status_code not in {200, 201}:
		raise RuntimeError(f"Prolific demographic export failed with HTTP {response.status_code}: {response.text}")

	return response.content


def write_gzip_file(destination_path, payload):
	temporary_path = destination_path.with_suffix(destination_path.suffix + ".tmp")
	with gzip.open(temporary_path, "wb") as file:
		file.write(payload)
	temporary_path.replace(destination_path)


def pull_demographic_exports(args):
	start_date = args.start_date
	end_date = args.end_date or get_default_end_date(args.include_today)
	if start_date > end_date:
		log(f"No demographic export dates to process: {start_date} is after {end_date}.")
		return

	load_env_file()
	if not args.dry_run:
		LOCAL_EXPORT_DIR.mkdir(parents=True, exist_ok=True)

	api_token = get_required_env_var("RYERSON_PROLIFIC_API_TOKEN")
	project_id = get_required_env_var("RYERSON_PROLIFIC_PROJECT_ID")
	session = requests.Session()
	studies_by_internal_name = index_studies_by_internal_name(
		load_project_studies(session, api_token, project_id)
	)

	for observation_date in iter_dates(start_date, end_date):
		destination_path = LOCAL_EXPORT_DIR / build_export_filename(observation_date)
		if destination_path.exists() and not args.overwrite:
			log(f"Skipping existing demographic export: {destination_path}")
			continue

		study = find_study_for_date(studies_by_internal_name, observation_date)
		if study is None:
			log(f"No Prolific study found for {observation_date}; creating an empty export.")
			payload = b""
		else:
			study_id = get_study_id(study)
			if args.dry_run:
				log(f"Dry run: would fetch demographic export for {observation_date} from Prolific study {study_id}.")
				log(f"Dry run: would write {destination_path}")
				continue
			log(f"Fetching demographic export for {observation_date} from Prolific study {study_id}.")
			payload = fetch_demographic_csv(session, api_token, study_id)

		if args.dry_run:
			log(f"Dry run: would write {destination_path}")
			continue

		write_gzip_file(destination_path, payload)
		log(f"Wrote demographic export: {destination_path}")

	log("Daily demographic export pull completed.")


def build_arg_parser():
	parser = argparse.ArgumentParser(
		description="Pull daily Prolific demographic CSV exports into private/demographic_exports/."
	)
	parser.add_argument(
		"--start-date",
		type=parse_date,
		default=FIRST_EXPORT_DATE,
		help="First observation date to export in YYYY-MM-DD format. Defaults to 2026-05-01.",
	)
	parser.add_argument(
		"--end-date",
		type=parse_date,
		default=None,
		help="Last observation date to export in YYYY-MM-DD format. Defaults to yesterday UTC.",
	)
	parser.add_argument(
		"--include-today",
		action="store_true",
		help="Use today UTC as the default end date when --end-date is not supplied.",
	)
	parser.add_argument(
		"--overwrite",
		action="store_true",
		help="Replace existing demographic export files.",
	)
	parser.add_argument(
		"--dry-run",
		action="store_true",
		help="Log planned exports without writing files.",
	)
	return parser


def main():
	parser = build_arg_parser()
	args = parser.parse_args()

	try:
		pull_demographic_exports(args)
		return 0
	except Exception as exception:
		log(f"ERROR: {exception}")
		return 1


if __name__ == "__main__":
	sys.exit(main())
