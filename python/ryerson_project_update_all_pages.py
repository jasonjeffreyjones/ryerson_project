#!/usr/bin/python3

import argparse
import datetime
import json
import os
from pathlib import Path
import re
import subprocess

PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / ".env"


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


def parse_args():
	parser = argparse.ArgumentParser(description="Update Ryerson Project HTML pages.")
	parser.add_argument(
		"--pages",
		nargs="+",
		choices=["index", "participate", "results", "download", "about"],
		default=["index", "participate", "results", "download", "about"],
		help="Page names to update.",
	)
	parser.add_argument(
		"--skip-deploy",
		action="store_true",
		help="Write local website files without rsyncing them to production.",
	)
	return parser.parse_args()


def run_dictionary_scripts(pageList):
	for thisPage in pageList:
		script_path = PROJECT_ROOT / "R" / f"create_{thisPage}_dictionary.R"
		if not script_path.is_file():
			continue

		command = ["Rscript", str(script_path)]
		try:
			result = subprocess.run(command, check=True, capture_output=True, text=True)
			print("Rscript output:", result.stdout.strip())
		except subprocess.CalledProcessError as e:
			print("Attempted:", " ".join(command))
			print("Failed with error:", e.stderr.strip())
			raise


def write_pages(pageList):
	# The second loop uses the HTML templates to write out new HTML pages (locally) with data from the dictionaries.
	for thisPage in pageList:
		# Define file paths
		input_file_path = PROJECT_ROOT / f'templates-html/template-{thisPage}.html'
		dictionary_file_path = PROJECT_ROOT / f'json/{thisPage}.json'
		output_file_path = PROJECT_ROOT / f'website/{thisPage}.html'

		# Read the input file
		with open(input_file_path, 'r') as file:
			content = file.read()

		# Read dictionary_file_path into a dictionary.
		key_value_pairs = {}
		with open(dictionary_file_path, "r") as file:
			key_value_pairs = json.load(file)
		
		# Convert values to their first element if they are lists
		# R saved each value as a list (enclosed in square brackets in the json)
		for key, value in key_value_pairs.items():
			if isinstance(value, list) and len(value) == 1:
				key_value_pairs[key] = value[0]
		
		# Replace the text
		for theKey in key_value_pairs:
			findTheKeyPattern = "\\b" + theKey + "\\b"
			findTheKeyPattern = re.compile(findTheKeyPattern)
			content = re.sub(findTheKeyPattern, str(key_value_pairs[theKey]), content)
		
		# Get the current date
		current_date = datetime.date.today()
		
		# Date in YYYY-MM-DD format
		current_date = current_date.strftime('%Y-%m-%d')
		
		# TODAYS_DATE_PYTHON indicates the date this script ran.
		content = content.replace('TODAYS_DATE_PYTHON', current_date)
		
		# Write the updated content to the output file
		with open(output_file_path, 'w') as file:
			file.write(content)
		
		print(f"{current_date} updated {thisPage}.html completed by {__file__}")


def deploy_pages():
	rsync_ssh_command = f'ssh -p {get_required_env_var("RYERSON_DEPLOY_SSH_PORT")}'
	source_path = str(PROJECT_ROOT / "website") + "/"
	destination = (
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_USER")}@'
		f'{get_required_env_var("RYERSON_DEPLOY_SSH_HOST")}:'
		f'{get_required_env_var("RYERSON_DEPLOY_REMOTE_PATH")}'
	)
	command = [f'rsync -avz -e "{rsync_ssh_command}" {source_path} {destination}']
	try:
		result = subprocess.run(command, shell=True, check=True, capture_output=True, text=True)
		print(f'rsynced to {get_required_env_var("RYERSON_DEPLOY_SSH_HOST")}')
	except subprocess.CalledProcessError as e:
		print("rsync failed with error:", e.stderr.strip())


def main():
	args = parse_args()
	pageList = args.pages
	
	# There will be three loops through pageList.
	# The first executes existing R scripts to create updated dictionaries.
	# The second uses the HTML templates to write out new HTML pages (locally) with data from the dictionaries.
	# The third uses ssh to overwrite the live HTML pages with the newly updated local versions.
	run_dictionary_scripts(pageList)
	write_pages(pageList)
	
	if args.skip_deploy:
		return
	
	# Instead of a final loop, try rsync.
	load_env_file()
	deploy_pages()
	

if __name__ == "__main__":
        main()
