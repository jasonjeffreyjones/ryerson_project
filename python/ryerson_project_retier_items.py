#!/usr/bin/python3

import datetime
import os
from pathlib import Path
import sys

import requests


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
RETIER_URL = "https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/admin/items_retier.php"
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


def retier_items():
	username = get_required_env_var("RYERSON_ADMIN_USERNAME")
	password = get_required_env_var("RYERSON_ADMIN_PASSWORD")

	log(f"Requesting Item Retiering from {RETIER_URL}")
	response = requests.post(
		RETIER_URL,
		auth=(username, password),
		timeout=REQUEST_TIMEOUT_SECONDS,
	)

	if response.status_code == 401:
		raise RuntimeError("Item Retiering request failed: admin authentication was rejected.")
	if response.status_code == 403:
		raise RuntimeError("Item Retiering request failed: admin access was forbidden.")
	if response.status_code != 200:
		raise RuntimeError(f"Item Retiering request failed with HTTP {response.status_code}.")

	if "Item Retiering Error" in response.text:
		raise RuntimeError("Item Retiering page reported an error.")
	if "Items retiered through" not in response.text:
		raise RuntimeError("Item Retiering page did not report successful completion.")

	log("Item Retiering request completed.")


def main():
	load_env_file()
	retier_items()
	log("Daily Item Retiering completed.")


if __name__ == "__main__":
	try:
		main()
	except Exception as exception:
		log(f"ERROR: {exception}")
		sys.exit(1)
