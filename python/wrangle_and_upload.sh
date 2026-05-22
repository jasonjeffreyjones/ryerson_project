#!/bin/bash
set -euo pipefail

# Diagnostics to know where this bash script looks for things.
#pwd
#which Rscript
#which python3

Rscript /home/ec2-user/ryerson_project/R/update_canonical_data_file.R
Rscript /home/ec2-user/ryerson_project/R/create_download_dictionary.R
python3 /home/ec2-user/ryerson_project/python/ryerson_project_upload_data_to_zenodo.py

echo "completed wrangle_and_upload.sh"
