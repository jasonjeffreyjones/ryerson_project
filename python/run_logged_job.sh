#!/bin/bash
set -euo pipefail

ryerson_project_root="/home/ec2-user/ryerson_project"
ryerson_log_dir="${ryerson_project_root}/logs"
ryerson_log_path="${ryerson_log_dir}/ryerson-$(date -u +%F).log"

mkdir -p "${ryerson_log_dir}"
cd "${ryerson_project_root}"
"$@" >> "${ryerson_log_path}" 2>&1
