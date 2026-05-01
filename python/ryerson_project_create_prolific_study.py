#!/usr/bin/python3

"""Create and publish today's Prolific study for the Ryerson Project."""

import datetime
import json
import os
from pathlib import Path
import sys

import requests

PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"
PROLIFIC_API_BASE_URL = "https://api.prolific.com/api/v1"
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


def build_study_payload(observation_date):
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
				"actions": [{"action": "AUTOMATICALLY_APPROVE"}],
			}
		],
		"filters": [
			{
				"filter_id": "current-country-of-residence",
				"selected_values": ["1"],
			}
		],
		"total_available_places": get_int_env_var("RYERSON_PROLIFIC_TOTAL_AVAILABLE_PLACES", 12),
		"estimated_completion_time": get_int_env_var("RYERSON_PROLIFIC_ESTIMATED_COMPLETION_MINUTES", 5),
		"maximum_allowed_time": get_int_env_var("RYERSON_PROLIFIC_MAXIMUM_ALLOWED_MINUTES", 20),
		"reward": get_int_env_var("RYERSON_PROLIFIC_REWARD_CENTS", 75),
		"project": project_id,
		"study_labels": ["survey"],
	}


def create_and_publish_prolific_study():
	load_env_file()

	api_token = get_required_env_var("RYERSON_PROLIFIC_API_TOKEN")
	observation_date = datetime.date.today().strftime("%Y-%m-%d")
	headers = {
		"Authorization": f"Token {api_token}",
		"Content-Type": "application/json",
	}

	create_url = f"{PROLIFIC_API_BASE_URL}/studies/"
	payload = build_study_payload(observation_date)
	response = requests.post(create_url, headers=headers, data=json.dumps(payload), timeout=30)

	if response.status_code != 201:
		print(f"{__file__} failed Prolific API request to create study. Status code: {response.status_code}")
		print("Response:", response.text)
		return False

	study = response.json()
	study_id = study["id"]
	study_name = study["name"]
	print(f"{__file__} successfully created a draft study named: {study_name}")

	publish_url = f"{PROLIFIC_API_BASE_URL}/studies/{study_id}/transition/"
	publish_response = requests.post(
		publish_url,
		headers=headers,
		data=json.dumps({"action": "PUBLISH"}),
		timeout=30,
	)

	if publish_response.status_code != 200:
		print(f"{__file__} failed to publish study {study_id}. Status code: {publish_response.status_code}")
		print("Response:", publish_response.text)
		return False

	published_study = publish_response.json()
	print(f"{__file__} successfully published study {study_name} as {published_study['id']}")
	return True


def main():
	try:
		return 0 if create_and_publish_prolific_study() else 1
	except Exception as exception:
		print(f"{__file__} failed: {exception}")
		return 1


if __name__ == "__main__":
	sys.exit(main())
