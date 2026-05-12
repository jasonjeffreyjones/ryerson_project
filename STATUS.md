# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.
- Deploy and production-test the administration interface and response data export functionality.

## Next

- Create an initial version of the Daily Email feature: one email per day sent to Dr. Jones with some descriptive statistics about what is in the database.
- Create the functionality that causes an approved waiting list member to receive an invitation to join the community.

## Later

- Add community participation features.
- Add Daily Emails features.
- Add automated data refresh, analysis, and publishing pipeline.

## Done

- Moved deploy settings out of the tracked Python script into local ignored configuration.
- Added `.gitignore` entries for local config and Python cache files.
- Added `README.md` and MIT `LICENSE`.
- Initialized a public GitHub repository and pushed the initial commit.
- Implemented the Participate waiting list form backend with validation and database insert behavior.
- Added SQL for the waiting list table.
- Standardized secrets toward one shared `.env` pattern for Python and PHP.
- Confirmed the waiting list form works in production end to end, including browser validation, PHP handling, and MariaDB insert.
- Implemented the Prolific-facing survey flow on Ryerson-owned PHP forms.
- Added response storage with item presentation order.
- Added the public demo survey flow at `website/demo-survey/`.
- Added SQL scaffolding for survey items, respondents, and responses.
- Added the Prolific study creation script for daily recruitment.
- Changed the survey length from 24 items to 36 items.
- Added the protected `website/admin/` interface with an admin home page.
- Replaced the root-level waiting list admin page with `website/admin/waiting_list.php`.
- Added manual response export creation at `website/admin/responses_export.php`.
- Added ignored storage for generated daily response export files under `website/admin/exports/`.

## Known Risks

- Several public pages still contain placeholder content.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
- Response export creation still needs a production run against the live database.
