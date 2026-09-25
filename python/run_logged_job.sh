#!/bin/bash

set -euo pipefail

ryerson_project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ryerson_log_dir="${ryerson_project_root}/logs"
ryerson_log_path="${ryerson_log_dir}/ryerson-$(date -u +%F).log"

mkdir -p "${ryerson_log_dir}"
cd "${ryerson_project_root}"

"$@" >> "${ryerson_log_path}" 2>&1
