# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.

## Next

- Replace remaining placeholder page content with Ryerson-specific content.
- Decide which files are source of truth and which are generated artifacts.
- Add a simple admin-facing way for Jason to inspect waiting list submissions.
- Add lightweight production notes for PHP, MariaDB, `.env`, and deployment workflow.
- Add database planning for persistent features such as participation, surveys, and responses.

## Later

- Add persistent database support for user and survey workflows.
- Add migration scripts and production database initialization workflow.
- Add community participation features.
- Add Daily Emails features.
- Add survey collection on Ryerson-owned forms instead of external tools.
- Add automated daily recruitment, data refresh, analysis, and publishing pipeline.

## Done

- Reviewed the current repository and project structure.
- Confirmed template-to-JSON file correspondence.
- Reduced JSON files to smaller Ryerson-relevant placeholders.
- Moved deploy settings out of the tracked Python script into local ignored configuration.
- Added `.gitignore` entries for local config and Python cache files.
- Added `README.md` and MIT `LICENSE`.
- Initialized a public GitHub repository and pushed the initial commit.
- Implemented the Participate waiting list form backend with validation and database insert behavior.
- Added SQL for the waiting list table.
- Standardized secrets toward one shared `.env` pattern for Python and PHP.
- Confirmed the waiting list form works in production end to end, including browser validation, PHP handling, and MariaDB insert.

## Known Risks

- Several public pages still contain placeholder content.
- The current script-driven site generation is simple and works, but it does not yet enforce strong validation.
- Persistent application features will require a database, migrations, and operational backup habits.
- Regression protection is still mostly manual and should improve gradually with lightweight smoke checks.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
