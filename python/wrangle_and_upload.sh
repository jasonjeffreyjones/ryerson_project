#!/bin/bash

# Diagnostics to know where this bash script looks for things.
#pwd
#which Rscript
#which python3

Rscript /home/ec2-user/ryerson_project/R/update_canonical_data_file.R
# TODO write the following script.  It will upload the canonical data file to Zenodo so we have an external mirror.
#python3 /home/ec2-user/ryerson_project/python/ryerson_project_upload_data.py

echo "completed wrangle_and_upload.sh"