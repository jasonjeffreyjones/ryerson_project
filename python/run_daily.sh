#!/bin/bash
set -euo pipefail

ryerson_project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ryerson_project_root}"
exec 9>"${ryerson_project_root}/private/daily.lock"
flock -n 9 || { echo "Another daily update is running."; exit 1; }
ryerson_python="${ryerson_project_root}/.venv/bin/python"

"${ryerson_python}" python/ryerson_project_pull_response_exports.py
"${ryerson_python}" python/ryerson_project_pull_demographic_exports.py
Rscript R/update_canonical_data_file.R
"${ryerson_python}" python/ryerson_project_update_all_pages.py
echo "Daily website update completed."
"${ryerson_python}" python/ryerson_project_upload_data_to_zenodo.py
echo "Daily Zenodo publishing completed."
