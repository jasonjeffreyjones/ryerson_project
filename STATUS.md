# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.
- Today we are sprinting toward automated recruitment of twelve respondents per day from Prolific.

## Next

- Configure and smoke-test automated daily recruitment through the Prolific API.
- Construct an admin interface for Dr. Jason Jeffrey Jones.  In an admin directory, there will be an index page.  From the index page, Dr. Jones can CRUD database records.  Begin with the Members table.

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

## Known Risks

- Several public pages still contain placeholder content.
- Persistent application features will require a database, migrations, and operational backup habits.
- Regression protection is still mostly manual and should improve gradually with lightweight smoke checks.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
- The survey flow still needs production smoke testing after database setup and deployment.
