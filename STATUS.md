# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.
- We are in an unusual state. The previous automation server (an AWS ec2) became unresponsive and irrecoverable. I have migrated the repo to this new automation server (an AWS LightSail). We are trying to recover full operation.
- Autonomus posting of new daily surveys (via cron scheduled python script) is currently working.
- Daily updates of the website content are not happening. There is no scheduled cron for this at present on this new automation server.
- CRON.md may NOT be a reliable description of current or past functionality. Let's figure that out together.

## Next

- Add community participation features.
- Add Daily Emails features.

## Later

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
- Implemented static Item Pages generated daily from one item template and per-item JSON dictionaries.
- Updated the Results page table to link directly to Item Reports and added Results navigation with stubs for age analysis and item search.
- Added a daily Featured Item to the home page with summary statistics, an all-time response histogram, and links to the full report and data.
- Implemented public daily Community Member statistics pages, an active-member index, ORCID identity links, active-member comparisons, successful-login tracking, and an AWS automation generator.

## Known Risks

- Several public pages still contain placeholder content.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
- Local development environment does not currently include the PHP CLI, so PHP syntax checks must be run on a PHP-equipped machine or production-like host.
- The Community Member statistics code requires its tracked last-login migration to be applied to production before the updated PHP is deployed.
