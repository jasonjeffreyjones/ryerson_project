# Ryerson Automation Schedule

The live crontab is managed on the AWS automation server and is not tracked in Git. This file illustrates the intended UTC schedule and keeps the operational commands discoverable. `python/run_logged_job.sh` appends every job's output to one `logs/ryerson-YYYY-MM-DD.log` file per UTC day.

## Fixed nightly jobs

```cron
CRON_TZ=UTC

5 0 * * * /home/ec2-user/ryerson_project/python/run_logged_job.sh /usr/bin/python3 python/ryerson_project_retier_items.py
10 0 * * * /home/ec2-user/ryerson_project/python/run_logged_job.sh /usr/bin/python3 python/ryerson_project_send_daily_admin_overview.py
15 0 * * * /home/ec2-user/ryerson_project/python/run_logged_job.sh /usr/bin/python3 python/ryerson_project_update_member_stats.py
```

The 00:15 job requests the protected active-member snapshot from production, rebuilds `website/member-stats/` on the automation server, and deploys that directory with stale remote pages removed.

## Other daily automation

These scripts are also designed for scheduled operation, but their production run times are not yet recorded in this repository. Record their real UTC schedules here rather than guessing when the live crontab is audited.

- `python/ryerson_project_pull_response_exports.py`
- `python/ryerson_project_pull_demographic_exports.py`
- `python/wrangle_and_upload.sh`
- `python/ryerson_project_update_all_pages.py`
- `python/ryerson_project_create_prolific_study.py`

## Community Member statistics deployment order

The Community Member statistics job must not be enabled until these steps are complete:

1. Apply `sql/alter_community_members_add_last_login.sql` to the production MariaDB database. This adds `last_login_at_utc` and backfills active members from their latest accepted invitation.
2. Deploy `website/` so successful ORCID callbacks record logins and the protected snapshot endpoint exists.
3. From the automation server, run `python3 python/ryerson_project_update_member_stats.py --skip-deploy` and inspect the generated local pages.
4. Run `python3 python/ryerson_project_update_member_stats.py` once to deploy the pages.
5. Add the 00:15 UTC cron entry and confirm that its output appears in the daily log.
