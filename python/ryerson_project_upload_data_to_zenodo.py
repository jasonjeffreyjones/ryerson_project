#!/usr/bin/python3

"""Publish the current Ryerson public data files as a new Zenodo version."""

import argparse
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
from urllib.parse import quote

import requests


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
STATE_PATH = PROJECT_ROOT / "private" / "zenodo_upload_state.json"
DATA_DIR = PROJECT_ROOT / "website" / "data"
DATA_FILENAMES = [
	"ryerson.csv.gz",
	"monthly-aggregated-ryerson.csv.gz",
	"all-time-aggregated-ryerson.csv.gz",
]
DEFAULT_ZENODO_API_BASE = "https://zenodo.org/api"
REQUEST_TIMEOUT_SECONDS = 120


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


def parse_args():
	parser = argparse.ArgumentParser(
		description="Upload website/data files to Zenodo as a new published version."
	)
	parser.add_argument(
		"--dry-run",
		action="store_true",
		help="Validate files and configuration without contacting Zenodo.",
	)
	parser.add_argument(
		"--force",
		action="store_true",
		help="Publish even if today's exact file set was already recorded in local state.",
	)
	return parser.parse_args()


def today_utc():
	return datetime.datetime.now(datetime.timezone.utc).date()


def normalize_api_base(value):
	return value.rstrip("/")


def read_state():
	if not STATE_PATH.is_file():
		return {}
	with open(STATE_PATH, "r") as file:
		return json.load(file)


def write_state(state):
	STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
	temp_path = STATE_PATH.with_suffix(".json.tmp")
	with open(temp_path, "w") as file:
		json.dump(state, file, indent=2, sort_keys=True)
		file.write("\n")
	temp_path.replace(STATE_PATH)


def file_hashes(path):
	sha256 = hashlib.sha256()
	md5 = hashlib.md5()
	with open(path, "rb") as file:
		for chunk in iter(lambda: file.read(1024 * 1024), b""):
			sha256.update(chunk)
			md5.update(chunk)
	return {
		"sha256": sha256.hexdigest(),
		"md5": md5.hexdigest(),
	}


def gzip_data_row_count(path):
	with gzip.open(path, "rt", encoding="utf-8", newline="") as file:
		line_count = sum(1 for _ in file)
	return max(0, line_count - 1)


def collect_data_files():
	files = []
	for filename in DATA_FILENAMES:
		path = DATA_DIR / filename
		if not path.is_file():
			raise FileNotFoundError(f"Missing data file: {path}")

		size = path.stat().st_size
		if size == 0:
			raise ValueError(f"Data file is empty: {path}")

		hashes = file_hashes(path)
		files.append({
			"filename": filename,
			"path": str(path),
			"size": size,
			"rows": gzip_data_row_count(path),
			"sha256": hashes["sha256"],
			"md5": hashes["md5"],
		})

	return files


def signature_for_files(files):
	return [
		{
			"filename": file_info["filename"],
			"size": file_info["size"],
			"sha256": file_info["sha256"],
		}
		for file_info in files
	]


def already_published_today(state, api_base, publish_date, files):
	if state.get("api_base") != api_base:
		return False
	last_publish = state.get("last_publish")
	if not isinstance(last_publish, dict):
		return False
	if last_publish.get("date") != publish_date.isoformat():
		return False
	return last_publish.get("files") == signature_for_files(files)


def auth_headers(access_token):
	return {
		"Authorization": f"Bearer {access_token}",
		"Accept": "application/json",
	}


def json_headers(access_token):
	headers = auth_headers(access_token)
	headers["Content-Type"] = "application/json"
	return headers


def request_json(session, method, url, expected_statuses, **kwargs):
	response = session.request(method, url, timeout=REQUEST_TIMEOUT_SECONDS, **kwargs)
	if response.status_code not in expected_statuses:
		try:
			payload = response.json()
		except ValueError:
			payload = response.text
		raise RuntimeError(f"Zenodo API {method} {url} failed with HTTP {response.status_code}: {payload}")
	if response.status_code == 204 or response.text.strip() == "":
		return None
	return response.json()


def latest_deposition_id(state, api_base):
	if state.get("api_base") == api_base and state.get("latest_published_deposition_id"):
		return str(state["latest_published_deposition_id"])
	return get_required_env_var("RYERSON_ZENODO_LATEST_DEPOSITION_ID")


def deposition_url(api_base, deposition_id):
	return f"{api_base}/deposit/depositions/{deposition_id}"


def create_or_get_new_version(session, api_base, access_token, source_deposition_id):
	url = f"{deposition_url(api_base, source_deposition_id)}/actions/newversion"
	response = request_json(
		session,
		"POST",
		url,
		{201},
		headers=auth_headers(access_token),
	)
	latest_draft_url = response.get("links", {}).get("latest_draft")
	if not latest_draft_url:
		raise RuntimeError("Zenodo new version response did not include links.latest_draft.")
	return request_json(
		session,
		"GET",
		latest_draft_url,
		{200},
		headers=auth_headers(access_token),
	)


def delete_existing_draft_files(session, access_token, draft):
	files_url = draft.get("links", {}).get("files")
	if not files_url:
		files_url = f"{draft.get('links', {}).get('self')}/files"

	files = request_json(
		session,
		"GET",
		files_url,
		{200},
		headers=auth_headers(access_token),
	)
	for file_info in files:
		delete_url = file_info.get("links", {}).get("self")
		if not delete_url:
			file_id = file_info.get("id")
			if not file_id:
				raise RuntimeError(f"Zenodo draft file did not include a delete URL or id: {file_info}")
			delete_url = f"{files_url}/{file_id}"
		name = file_info.get("filename") or file_info.get("name") or file_info.get("key") or file_info.get("id")
		request_json(
			session,
			"DELETE",
			delete_url,
			{204},
			headers=auth_headers(access_token),
		)
		log(f"Deleted inherited Zenodo draft file {name}.")


def upload_files_to_bucket(session, access_token, draft, files):
	bucket_url = draft.get("links", {}).get("bucket")
	if not bucket_url:
		raise RuntimeError("Zenodo draft did not include links.bucket.")

	for file_info in files:
		filename = file_info["filename"]
		upload_url = f"{bucket_url}/{quote(filename)}"
		with open(file_info["path"], "rb") as file:
			response = request_json(
				session,
				"PUT",
				upload_url,
				{200, 201},
				data=file,
				headers=auth_headers(access_token),
			)
		zenodo_checksum = response.get("checksum")
		if zenodo_checksum and zenodo_checksum != f"md5:{file_info['md5']}":
			raise RuntimeError(
				f"Zenodo checksum mismatch for {filename}: "
				f"expected md5:{file_info['md5']}, got {zenodo_checksum}"
			)
		log(
			f"Uploaded {filename} to Zenodo "
			f"({file_info['size']} bytes, {file_info['rows']} data row(s))."
		)


def update_draft_metadata(session, api_base, access_token, draft, publish_date):
	deposition_id = draft["id"]
	metadata = dict(draft.get("metadata") or {})
	if not metadata:
		raise RuntimeError("Zenodo draft did not include inherited metadata.")

	version_label = publish_date.isoformat()
	metadata["publication_date"] = version_label
	metadata["version"] = version_label

	return request_json(
		session,
		"PUT",
		deposition_url(api_base, deposition_id),
		{200},
		headers=json_headers(access_token),
		data=json.dumps({"metadata": metadata}),
	)


def publish_draft(session, api_base, access_token, draft):
	deposition_id = draft["id"]
	published = request_json(
		session,
		"POST",
		f"{deposition_url(api_base, deposition_id)}/actions/publish",
		{202},
		headers=auth_headers(access_token),
	)
	if isinstance(published, dict):
		return published
	return request_json(
		session,
		"GET",
		deposition_url(api_base, deposition_id),
		{200},
		headers=auth_headers(access_token),
	)


def build_state(api_base, publish_date, files, published):
	return {
		"api_base": api_base,
		"latest_published_deposition_id": published.get("id"),
		"latest_published_record_id": published.get("record_id"),
		"latest_record_url": published.get("record_url"),
		"latest_doi": published.get("doi"),
		"last_publish": {
			"date": publish_date.isoformat(),
			"published_at_utc": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
			"deposition_id": published.get("id"),
			"record_id": published.get("record_id"),
			"record_url": published.get("record_url"),
			"doi": published.get("doi"),
			"files": signature_for_files(files),
		},
	}


def main():
	args = parse_args()
	load_env_file()

	access_token = get_required_env_var("RYERSON_ZENODO_ACCESS_TOKEN")
	api_base = normalize_api_base(os.environ.get("RYERSON_ZENODO_API_BASE", DEFAULT_ZENODO_API_BASE))
	publish_date = today_utc()
	files = collect_data_files()
	state = read_state()

	for file_info in files:
		log(
			f"Prepared {file_info['filename']} "
			f"({file_info['size']} bytes, {file_info['rows']} data row(s), sha256 {file_info['sha256']})."
		)

	if already_published_today(state, api_base, publish_date, files) and not args.force:
		log("Today's exact data file set is already recorded as published to Zenodo; skipping.")
		return

	source_deposition_id = latest_deposition_id(state, api_base)
	log(f"Using Zenodo deposition {source_deposition_id} as the latest published source version.")

	if args.dry_run:
		log("Dry run complete; no Zenodo API calls were made.")
		return

	with requests.Session() as session:
		draft = create_or_get_new_version(session, api_base, access_token, source_deposition_id)
		log(f"Using Zenodo draft deposition {draft['id']} for the new version.")
		delete_existing_draft_files(session, access_token, draft)
		upload_files_to_bucket(session, access_token, draft, files)
		draft = update_draft_metadata(session, api_base, access_token, draft, publish_date)
		log(f"Updated Zenodo draft metadata for version {publish_date.isoformat()}.")
		published = publish_draft(session, api_base, access_token, draft)

	write_state(build_state(api_base, publish_date, files, published))
	log(
		"Published Zenodo version "
		f"{published.get('id')} at {published.get('record_url')} "
		f"with DOI {published.get('doi')}."
	)


if __name__ == "__main__":
	main()
