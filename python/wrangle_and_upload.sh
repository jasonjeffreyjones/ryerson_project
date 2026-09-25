#!/bin/bash

set -euo pipefail

ryerson_project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

Rscript "${ryerson_project_root}/R/update_canonical_data_file.R"
Rscript "${ryerson_project_root}/R/create_download_dictionary.R"
"${ryerson_project_root}/.venv/bin/python" \
    "${ryerson_project_root}/python/ryerson_project_upload_data_to_zenodo.py"

echo "completed wrangle_and_upload.sh"
