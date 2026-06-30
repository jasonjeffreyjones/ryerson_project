# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.
- Verify the Daily Admin Over Email feature in production after deployment.

## Next

- Create a static HTML page that will be used to recruit alpha testers.  This page lives at https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/alpha-testing.html.  It is not linked from the rest of the site.  Instead, Dr. Jones will distribute it through emails and social media posts to recruit qualified Community Members to be alpha testers.

## Later

- Add community participation features.
- Add Daily Emails features.
- Implement all functionality as described in the specification RYERSON_SPEC.md.

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
- Added the Prolific demographic export pull script for daily `.csv.gz` files in `private/demographic_exports/`.
- Added the R script that rebuilds the public canonical microdata file at `website/data/ryerson.csv.gz`.
- Implemented the download data sharing slice with monthly and all-time aggregate files derived from `website/data/ryerson.csv.gz`.
- Added `mean_response`, `sd`, and `n` calculated columns to the monthly and all-time aggregate data files.
- Added Google Dataset-compatible JSON-LD metadata to the download page template.
- Updated the page builder to run existing `R/create_<page>_dictionary.R` scripts and support local single-page updates without deploying.
- Implemented the daily updating Ranked by Agreement table on `results.html`.
- Implemented the best-effort seven day Prolific cooldown feature in daily recruitment.
- Added the community invitation table and admin approval/resend flow for waiting list applicants.
- Implemented strict ORCID-gated invitation acceptance and ORCID-only member login.
- Created the Member Home Page with a welcome by name, NEDbucks balance, and stubbed future member features.
- Added member Suggested Item submission with one suggestion per member per UTC day.
- Added admin Suggested Items review with edit, approve, reject, and member notification emails.
- Approved Suggested Items now become active Tier 40 survey items with future queue logic left unset.
- In the Community Members interface, Members may view Current Items and filter by keyword.
- Added the first Item Bakeoff slice: active members can choose between paired active items, choices are stored with a 100 per UTC day limit, and admin can review bakeoff activity.
- Added nightly Item Retiering: Community Elo is recalculated from completed UTC-day Item Bakeoff results and active items are assigned to score-based tiers.
- Added a dependency-free authenticated SMTP mail sender for community invitation and suggested item moderation emails.
- Added an admin SMTP test page for sending one test message and checking SPF, DKIM and DMARC in the recipient mailbox.
- Added the Daily Admin Over Email feature: a protected admin overview page can send project count emails manually, and a Python script can trigger the page from cron.

## Known Risks

- Several public pages still contain placeholder content.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
- Local development environment does not currently include the PHP CLI, so PHP syntax checks must be run on a PHP-equipped machine or production-like host.
