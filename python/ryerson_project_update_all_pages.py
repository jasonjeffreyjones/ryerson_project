#!/usr/bin/python3

# Updates each HTML page in pageList.
# Put this script on a server, and run it daily using cron.

import datetime
import json
from pathlib import Path
import re
import subprocess

PROJECT_ROOT = Path("/home/ec2-user/ryerson_project")
DEPLOY_CONFIG_PATH = PROJECT_ROOT / "deploy_config.json"


def load_deploy_config():
	with open(DEPLOY_CONFIG_PATH, "r") as file:
		return json.load(file)

def main():
	pageList = ["index", "participate", "results", "download", "about"]
	deploy_config = load_deploy_config()
	
	# There will be three loops through pageList.
	# The first executes each R script to create the updated dictionaries.
	# The second uses the HTML templates to write out new HTML pages (locally) with data from the dictionaries.
	# The third uses ssh to overwrite the live HTML pages with the newly updated local versions.
	
	# TODO: Bring this loop back.
	if False:
		# The first loop executes each R script to create updated dictionaries.
		for thisPage in pageList:
			# Command to run dictionary-making R scripts with Rscript.
			command = [f"Rscript /home/ec2-user/ryerson_project/R/create-{thisPage}-dictionary.R"]
			
			try:
				result = subprocess.run(command, shell=True, check=True, capture_output=True, text=True)
				print("Rscript output:", result.stdout.strip())
			except subprocess.CalledProcessError as e:
				print("Attempted:", command)
				print("Failed with error:", e.stderr.strip())
	
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
	
	# Instead of a final loop, try rsync.
	rsync_ssh_command = f'ssh -p {deploy_config["ssh_port"]}'
	source_path = str(PROJECT_ROOT / "website") + "/"
	destination = (
		f'{deploy_config["ssh_user"]}@{deploy_config["ssh_host"]}:'
		f'{deploy_config["remote_path"]}'
	)
	command = [f'rsync -avz -e "{rsync_ssh_command}" {source_path} {destination}']
	try:
		result = subprocess.run(command, shell=True, check=True, capture_output=True, text=True)
		print(f'rsynced to {deploy_config["ssh_host"]}')
	except subprocess.CalledProcessError as e:
		print("rsync failed with error:", e.stderr.strip())
	

if __name__ == "__main__":
        main()


