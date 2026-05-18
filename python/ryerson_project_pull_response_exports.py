#!/usr/bin/python3

import datetime
import os
from pathlib import Path
import subprocess
import sys

import requests


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
EXPORT_URL = "https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/admin/responses_export.php"
LOCAL_EXPORT_DIR = PROJECT_ROOT / "private" / "response_exports"
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


def create_missing_response_exports():
	username = get_required_env_var("RYERSON_ADMIN_USERNAME")
	password = get_required_env_var("RYERSON_ADMIN_PASSWORD")

	log(f"Requesting missing response export creation from {EXPORT_URL}")
	response = requests.post(
		EXPORT_URL,
		auth=(username, password),
		timeout=REQUEST_TIMEOUT_SECONDS,
	)

	if response.status_code == 401:
		raise RuntimeError("Response export request failed: admin authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError("Response export request failed: admin access was forbidden.")
	if response.status_code != 200:
		raise RuntimeError(
			f"Response export request failed with HTTP {response.status_code}."
		)

	if "Response Export Error" in response.text:
		raise RuntimeError("Response export page reported a response export error.")
	if "Method Not Allowed" in response.text:
		raise RuntimeError("Response export page rejected the request method.")
	if "date(s) failed" in response.text:
		raise RuntimeError("Response export page reported one or more failed export dates.")

	log("Response export creation request completed.")


def build_remote_exports_path():
	remote_path = get_required_env_var("RYERSON_DEPLOY_REMOTE_PATH").rstrip("/")
	return (
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_USER")}@'
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_HOST")}:'
		f"{remote_path}/admin/exports/"
	)


def pull_response_exports():
	LOCAL_EXPORT_DIR.mkdir(parents=True, exist_ok=True)

	rsync_ssh_command = f'ssh -p {get_required_env_var("RYERSON_DEPLOY_SSH_PORT")}'
	command = [
		"rsync",
		"-avz",
		"--include",
		"responses_*.csv.gz",
		"--exclude",
		"*",
		"-e",
		rsync_ssh_command,
		build_remote_exports_path(),
		str(LOCAL_EXPORT_DIR) + "/",
	]

	log(f"Pulling response exports into {LOCAL_EXPORT_DIR}")
	result = subprocess.run(command, capture_output=True, text=True)
	if result.stdout.strip():
		print(result.stdout.strip(), flush=True)
	if result.stderr.strip():
		print(result.stderr.strip(), file=sys.stderr, flush=True)
	if result.returncode != 0:
		raise RuntimeError(f"rsync failed with exit code {result.returncode}.")
	log("Response export rsync completed.")


def main():
	load_env_file()
	create_missing_response_exports()
	pull_response_exports()
	log("Daily response export pull completed.")


if __name__ == "__main__":
	try:
		main()
	except Exception as exception:
		log(f"ERROR: {exception}")
		sys.exit(1)
