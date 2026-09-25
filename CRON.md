# Ryerson Automation Schedule

This documents the Ryerson entries installed and verified on the AWS Lightsail automation server on September 9, 2026. The server clock is UTC. The live crontab belongs to `ubuntu`; the project lives at `/home/ubuntu/ryerson-project`. Other projects also have entries in that crontab and are omitted here.

## Installed daily schedule

```cron
# Ryerson Project — server clock is UTC.

# Recalculate Community Elo and retier survey items.
5 0 * * * /home/ubuntu/ryerson-project/python/run_logged_job.sh /home/ubuntu/ryerson-project/.venv/bin/python python/ryerson_project_retier_items.py

# Send the daily admin overview email.
10 0 * * * /home/ubuntu/ryerson-project/python/run_logged_job.sh /home/ubuntu/ryerson-project/.venv/bin/python python/ryerson_project_send_daily_admin_overview.py

# Generate and deploy Community Member statistics pages.
15 0 * * * /home/ubuntu/ryerson-project/python/run_logged_job.sh /home/ubuntu/ryerson-project/.venv/bin/python python/ryerson_project_update_member_stats.py

# Download exports, rebuild data and pages, and deploy the website.
25 6 * * * /home/ubuntu/ryerson-project/python/run_logged_job.sh /bin/bash /home/ubuntu/ryerson-project/python/run_daily.sh

# Create and publish today's Prolific study.
5 17 * * * /home/ubuntu/ryerson-project/python/run_logged_job.sh /home/ubuntu/ryerson-project/.venv/bin/python /home/ubuntu/ryerson-project/python/ryerson_project_create_prolific_study.py
```

`python/run_logged_job.sh` changes to the project root before running each command and appends stdout and stderr to `logs/ryerson-YYYY-MM-DD.log`, using the UTC date when the job starts. Python jobs use the project's `.venv/bin/python`.

## Daily sequence

- **00:05:** Recalculate Community Elo from completed UTC-day bakeoffs and update item tiers through the protected production endpoint.
- **00:10:** Request the daily admin overview email through the protected production endpoint.
- **00:15:** Request the protected active-member snapshot, rebuild `website/member-stats/`, and deploy that directory with stale remote pages removed.
- **06:25:** Run `python/run_daily.sh` to refresh the public data and website, then publish the datasets to Zenodo.
- **17:05:** Create and publish today's Prolific study, using recent local response exports for the best-effort seven-day participant cooldown.

These are separate cron jobs; their start times do not enforce dependencies between them. Within the 06:25 job, the following steps run sequentially, and a failed command stops the remaining steps:

1. `python/ryerson_project_pull_response_exports.py`: request missing production response exports and download them over SSH.
2. `python/ryerson_project_pull_demographic_exports.py`: fetch missing Prolific demographic exports through yesterday in UTC. Existing files, including empty files, are skipped unless explicitly overwritten during a manual retry.
3. `R/update_canonical_data_file.R`: rebuild `website/data/ryerson.csv.gz` from the local exports.
4. `python/ryerson_project_update_all_pages.py`: rebuild the page dictionaries, aggregate datasets, featured item, results, and item reports; render the HTML; then deploy `website/`.
5. `python/ryerson_project_upload_data_to_zenodo.py`: publish the three datasets as a new Zenodo version. This runs only after website deployment succeeds; an upload failure is logged and fails the job, but the website has already been updated.

The daily wrapper uses `flock` on `private/daily.lock` to prevent overlapping runs. The canonical builder can log and skip missing or malformed inputs without failing, so a successful job exit alone does not prove complete date coverage.

## Zenodo publishing

Zenodo publishing runs as the final step of the 06:25 daily job. The credentials were restored and a manual publication succeeded on September 9, 2026: [version 2026-09-09](https://doi.org/10.5281/zenodo.22678890), containing 52,416 canonical response rows and the monthly and all-time aggregates. Upload checksums were verified by the uploader.

The uploader reads `RYERSON_ZENODO_ACCESS_TOKEN` and initially uses `RYERSON_ZENODO_LATEST_DEPOSITION_ID` from `.env`. After publishing, it saves the latest deposition ID and file signatures in ignored `private/zenodo_upload_state.json`; subsequent runs use that state. It skips a file set already recorded as published on the same UTC date.

`python/wrangle_and_upload.sh` remains available to rebuild canonical and aggregate data and upload to Zenodo, but is not called by the installed crontab or `python/run_daily.sh`.

## Community Member statistics prerequisite and verification

The production database must have `community_members.last_login_at_utc`, introduced by `sql/alter_community_members_add_last_login.sql`, before deploying PHP that uses it. Do not blindly reapply the migration to an existing database.

On September 9, 2026, the production snapshot endpoint successfully returned member statistics and the local generator built four member pages. Those pages were included in the successful website deployment. Retiering and the admin overview email endpoint also reported successful manual runs.

The installed schedule has been read back and verified. Confirm the first scheduled executions in the daily logs; manual success does not establish that cron has run successfully.
