# Ryerson Project Status

## Now

- Build and maintain momentum through small, deployable improvements.
- Keep the website, scripts, and project structure clean enough for steady public development.
- Build the next thin application slice without overcomplicating architecture.
- Under the Join Waiting List form on participate.html, add: Already a Member?  Log in.  This takes user to log in with ORCID.
- The link from Current Items on the Community Member page should go to a page for viewing items.

## Next

- Create an initial version of the Daily Email feature: one email per day sent to Dr. Jones with some descriptive statistics about what is in the database.

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

## Known Risks

- Several public pages still contain placeholder content.
- Production PHP is on version 7.2, so future PHP code must stay compatible with that baseline unless hosting changes.
- Local development environment does not currently include the PHP CLI, so PHP syntax checks must be run on a PHP-equipped machine or production-like host.

### Outstanding DKIM Issue

  Ryerson invitation email now has the correct envelope sender and SPF passes:

  - `Return-Path: <ryerson@jasonjones.ninja>`
  - `SPF: PASS`

  However, Gmail still reports:

  - `DKIM: FAIL with domain jasonjones.ninja`

  DNS appears to contain a DKIM TXT record:

  ```bash
  dig TXT default._domainkey.jasonjones.ninja +short

  returns a visible v=DKIM1; k=rsa; p=... public key.

  The domain’s nameservers are:

  dns1.namecheaphosting.com

  So the issue is probably not a missing DNS record. The likely problem is that the production mail server/Exim is either:

  1. Not DKIM-signing PHP-generated mail correctly for jasonjones.ninja, or
  2. Signing with a private key that does not match the published default._domainkey.jasonjones.ninja public key, or
  3. Using a different DKIM selector than default.

  Next checks:

  1. Inspect the failed email’s DKIM-Signature header and confirm:

     d=jasonjones.ninja
     s=default

  2. In cPanel:
      - Go to Email → Email Deliverability
      - Open jasonjones.ninja
      - Use Repair or regenerate DKIM if offered
      - Confirm the displayed DKIM TXT record matches DNS

  3. If DKIM still fails, ask Namecheap/cPanel support:

  PHP mail from my cPanel account is now sending with Return-Path ryerson@jasonjones.ninja and SPF passes. DNS has a visible DKIM TXT record at default._domainkey.jasonjones.ninja, but Gmail reports DKIM FAIL
  for d=jasonjones.ninja. Please verify that Exim is DKIM-signing PHP-generated mail for jasonjones.ninja using the private key that matches the published default._domainkey public key. Also please confirm
  which DKIM selector Exim is using for this domain.

  If support cannot fix DKIM for PHP-generated mail, the better long-term solution is to switch Ryerson from PHP mail() to authenticated SMTP using ryerson@jasonjones.ninja.
