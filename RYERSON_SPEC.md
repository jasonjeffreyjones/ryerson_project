# Ryerson Project Spec and Roadmap.

The goal of the Ryerson Project is to nowcast everything daily.

The primary artifact of the Ryerson Project is the web pages and web apps hosted at https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/

To nowcast everything daily, we follow this automated daily loop:
1. Recruit new respondents.
2. Collect respondents' responses to survey items.
3. Update data file to include new responses.
4. Share the updated data file.
5. Perform analysis and visualization based on the updated data file.
6. Share the new analysis.

Dr. Jason Jeffrey Jones is the Benevolent Dictator for Life for the Ryerson Project.  The name is stylized as "the Ryerson Project" - lowercase t for the.  Abbreviate simply as Ryerson or tRP.

Ryerson is an implementation of a Social Science Dashboard Inator.  Social Science Dashboard Inator (SSDI) described here: https://jasonjones.ninja/social-science-dashboard-inator/  Github repository for a *different*  SSDI available here: https://github.com/jasonjeffreyjones/Jason-Jeffrey-Jones-Productions-AI-Daily-Dashboard

After initial development, Dr. Jones' aim is to create a **community of researchers** who support Ryerson with their time, attention and research funding.

## Details: Recruit new respondents.

Use the Prolific API to create a new Study for this day and recruit today's respondents.
This is invisible to users.
This is accomplished by one Python script.  That script is run once daily by a scheduled cron job.
This feature is complete when once a day a new Prolific Study is created and pushed live to respondents.

The script implements a seven-day cooldown period for repeat participation.  For the most part, we want to sample the Prolific US pool WITH REPLACEMENT.  However, some eager participants acticely seek out surveys they have taken and enjoyed in the past.  We want to avoid these participants becoming too large a portion of the sample.  Therefore, the script creates a 7-day participant group as a rolling blocklist.  Only Ryerson respondents from the previous seven days are placed on today's survey blocklist.  After seven days of no allowed responses, Prolific users roll off and are eligible again.

## Details: Collect respondents' responses to survey items.

Web pages on jasonjones.ninja use PHP and mysql to present survey items and collect responses.
For now, we aim for 36 items on the survey.  We aim for 12 respondents per day.

ITEMS_TO_PRESENT = 36
TIERS = 4
ITEMS_PER_TIER = ITEMS_TO_PRESENT / TIERS

Every survey item has this format: one statement; response options are 11 points on a Likert Agree-Disagree scale. 0 is maximum Disagreement. 10 is maximum Agreement.  This constraint is desired and enforced.  It keeps measurement consistent, persistent and standardized.

Example Item: I believe that shape-shifting reptilian people control our world by taking on human form and gaining political power to manipulate our society.  Interface to respond from 0 to 10.  A response is required.

Behind the scenes, there are Tiers of items.  In the database, statements are stored with their current Tier.  I don't think a Tier history is important yet.

Tier 10 items are always presented to all respondents every day.  In the database, there will be exactly ITEMS_TO_PRESENT / TIERS Tier 10 items.  At the moment, this constraint is manually met and enforced by Dr. Jones.  Look for ways to enforce and support this constraint in code; make suggestions, but do not slow down development momentum.

Tier 20 items are meant to be delivered to half of total respondents per day.  In the database, there will be at least ITEMS_TO_PRESENT / TIERS Tier 20 items and at most 2 x (ITEMS_TO_PRESENT / TIERS) Tier 20 items.  At the moment, this constraint is manually met and enforced by Dr. Jones.  Look for ways to enforce and support this constraint in code; make suggestions, but do not slow down development momentum.

Tier 30 items are meant to be delivered to one-quarter of total respondents per day.  In the database, there will be at least ITEMS_TO_PRESENT / TIERS Tier 30 items and at most 4 x (ITEMS_TO_PRESENT / TIERS) Tier 30 items.  At the moment, this constraint is manually met and enforced by Dr. Jones.  Look for ways to enforce and support this constraint in code; make suggestions, but do not slow down development momentum.

There may be unlimited Tier 40 items.  For each respondent, choose ITEMS_TO_PRESENT / TIERS items from among the top 8 x (ITEMS_TO_PRESENT / TIERS) scoring Tier 40 items.  Once per day, the items will be resorted and retiered based on community payments and community voting.   

It is okay and expected that two respondents from the same day have not exactly the same set of items.  Also, the order of items should be a random permutation of the selected items.

This feature is complete when a respondent can come to the website, read the instructions, agree to be truthful and thoughtful, respond to a set of items that meet the requirements, and all responses are saved to the database.

### The Parallel Demonstration Survey feature

A separate but exact mirror of the survey exists as a public demonstration.  It does not collect responses; it simply allows anyone to experience the survey flow.  It uses real statements from the database.  The interface matches exactly.  Tiering and item selection does not need to match exactly.  Length should match exactly.  The Parallel Demonstration Survey is clearly marked as a demonstration.

## Details: Update data file to include new responses.

### Response data exports

Response data is recorded in the production database in the table named responses.

On the production web server there is a PHP script that creates exports of the response data.  The PHP script lives in admin/ and is named responses_export.php.  Specifically, here is what the script does:
1. Create a list of observation_dates.  The list startes with the first day surveys ran: 2026-05-01.  The list ends with yesterday, defined as one day previous to current server time.
2. For each day in the list, check whether a responses export file exists.  Response export files live in admin/exports/  If a file already exists for an observation_date, do nothing for that observation_date.
3. If a responses export file does not exist for an observation_date, create one.  A responses export file is a csv file that contains all the rows from responses where observation_date equals that date. Fields are in this order: prolific_pid, observation_date, response_value, statement_text, survey_item_id, presented_order. ORDER BY prolific_pid, presented_order.  Include statement_text from survey_items by joining on survey_item_id.
4. Gzip the csv file(s) so that the final form of the file is: admin/exports/responses_YYYY_MM_DD.csv.gz

To be clear, when responses_export.php is run, it attempts to write one file of response exports per day.  It never overwrites existing files, but fills in holes if files are missing.  An administrator can manually run responses_export.php from admin/index.php  

Create a Raw Microdata file.  It contains one row per observation.  Response, item text, observation date, respondent data columns.
Pull the data from the database.  Write to a .csv file.  Gzip the file.  Overwrite previous file.  Push a copy of that file to Zenodo.  Also push a copy to GitHub.

This feature is done when a test confirms that a user can download the data file from https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/download.html.  Also, that page has the correct format to be indexed by Google Datasets.

### Demographics data exports

Demographics data of respondents is recorded on Prolific servers.  Each day, a Ryerson script uses the Prolific API to request the demographics of the respondents from previous days.  If all goes as planned, today we save yesterday's data.  (The script will backfill previous days if a day was missed due to transient network issues, Prolific downtime or Prolific errors.)  The script is similar to Response Data Exports, however, the source of the demographics data is Prolific.

### Update the canonical concatenated data file

Each day, the canonical concatenated data file for Ryerson is expected to grow by receiving new rows.  Each row is a response to a survey item with all respondent data attached.  That means users of the data have full microdata on each observation and do not need to do any joining or merging of files.

The columns of the canonical concatenated data file for Ryerson are:
response_value, statement_text, observation_date, hashed_respondent_id, age, sex, ethnicity, birth_country, residence_country, nationality, language, student, employment, time_on_task, approvals, survey_item_id, presented_order

The canonical concatenated data file is a gzipped csv file.  The columns are in the order specified directly above.  There is one header row at the top of the file.  The rows are sorted by observation_date, hashed_respondent_id, presented_order.

The canonical file is built by R/update_canonical_data_file.R.  update_canonical_data_file.R uses the daily files within private/response_exports and private/demographic_exports as the sources.  The R script may write to a temp file.  The final output replaces website/data/ryerson.csv.gz.  Log progress and issues with print().

A cron job will run R/update_canonical_data_file.R once per day.  The canonical file is reconstructed from scratch each time.  The file lives at website/data/ryerson.csv.gz and it is meant to be shared publicly.  website/data/ryerson.csv.gz may be tracked by git and committed to GitHub, but the true up-to-date version lives on the production website.

If a response export exists for a date but the demographic export is missing or empty, the script should log that issue and continue.

The following R code is used to convert Prolific PID to a hashed_respondent_id.  This is a one-way hash that is non-stochastic.

```r
library(digest)
hash_user_id <- function(user_id) {
  full_hash <- digest(user_id, algo = "sha256", serialize = FALSE)
  short_id <- substr(full_hash, 1, 12)
  return(short_id)
}
```

The script will hash the Prolific PID before joining.  The join is hashed_respondent_id and observation_date.

The script should generate the expected date list from 2026-05-01 through yesterday and report missing response files and/or demographic files.  If any daily input file is malformed, the script should skip that date, log that issue and continue.  Only include rows in the canonical file if there was a completed join from response to demographic.

If a respondent has response rows but no matching demographic row, the script should log that issue and continue.

If a demographic row has values like CONSENT_REVOKED or DATA_EXPIRED, those values should be preserved as-is.

time_on_task is the time in seconds as provided by Prolific. approvals is the value of 'Total approvals'.

The script should detect duplicates by (hashed_respondent_id, observation_date, survey_item_id), log that issue and continue.  If duplicates are found, they are still included in the canonical data file.

## Details: Share the updated data file.

Three data files will be available for download from https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/download.html
1. ryerson.csv.gz This is the the canonical concatenated data file.
2. monthly-aggregated-ryerson.csv.gz Each row contains month of collection, a statement, mean response, standard deviation, N and respondent counts per response.  Recall that responses are the integers 0 through 10.  There will be no missing values.  If no '4' responses occurred for a statement within a month, for example, the column for 4 will contain the true zero count.
2. all-time-aggregated-ryerson.csv.gz Each row contains a statement, mean response, standard deviation, N, and respondent counts per response.  Recall that responses are the integers 0 through 10.  There will be no missing values.  If no '7' responses ever occurred for a statement, for example, the column for 7 will contain the true zero count.

monthly-aggregated-ryerson.csv.gz and all-time-aggregated-ryerson.csv.gz are derived directly from ryerson.csv.gz.  They are created by the script R/create_download_dictionary.R which replaces json/download.json when it is run.  create_download_dictionary.R updates MOST_RECENT_OBS_DATE, MICRODATA_ROWS_COUNT, MONTHLY_AGG_ROWS_COUNT, ALL_TIME_AGG_ROWS_COUNT, OLDEST_OBS_DATE, MOST_RECENT_OBS_DATE.

## Details: Perform analysis and visualization based on the new data file.

There will be pages and sets of pages that display visualizations and present analyses based on the canonical cumulative file.  The page results.html will include navigation into those pages.

### Item Pages

A directory named item-results/ will contain one HTML results page for each item.  Each page will be named with this template: ITEM_ID-FIRST_WORD-SECOND_WORD-THIRD_WORD.html.  ITEM_ID is the numeric id from the database.  FIRST_WORD is the first word from the text of the item that is not a stop word - SECOND_WORD and THIRD_WORD similarly.  Each item page is a static HTML page generated anew each day.  An index page on item-results/index.html will contain a link to each item page.

An item page has the following format. The full text of the item appears in a sticky header.  An 11 bar histogram shows the all-time count of observed responses per each response option.  Descriptive statistics (also all-time) are displayed: mean, median, standard deviation, N, standard error.  A templated sentence explains: American adults' average (mean) response was ALL_TIME_MEAN on a scale of 0 (Disagree) to 10 (Agree).  ITEM_N responses have been collected (so far) from EARLIEST_OBS_DATE to MOST_RECENT_OBS_DATE.

If the item has 100 or more total observations, then a comparison to all other qualifying items is displayed.  Qualifying are all items with 100 or more total observations.  Based on all-time mean, the rank for this item out of total qualifying items and the corresponding percentile are displayed.  Higher means (more agreement) are higher rank, and this is explained briefly.

A monthly trend visualization shows a stacked bar chart - one stacked bar per month.  The bars always total to 100%.  There are 11 bars showing the percentage of each month's total responses that each of the 11 possible values received.  Be aware that some response values can collect zero responses.  For instance, "I am a human" would have mostly high agreement and possibly no disagree values.  Monthly mean values are represented by a black dot horizontally centered within each bar.  Separate y-axis scales appear: percentage on left for the stacked bar chart and 0 to 10 on the right for the monthly mean points.  A path connects the monthly mean points.

An annual trend section presents estimates for the annual trend (as long as the item has more than one observation date and 100 or more observations total).  Annual trend is estimated by OLS at the response level.  A templated paragraph presents the results: Observations suggest a trend of increasing|decreasing|unchanging agreement equally to ANNUAL_CHANGE_ESTIMATE per year.  Regression results place a 95% confidence interval around that estimate of \[CI_LOWER, CI_UPPER\].  The full regression table is placed in a pre tag but folded up unless the user clicks to see it.

### Age Analyses

The page results-by-age.html highlights those items in which Age and agreement are most strongly correlated.  Items with fewer than 100 total observations are not eligible to appear on this page.

Five visualizations illustrate the five items where age and agreement are most strongly positively correlated.  Each visualization has age on the x-axis and agreement on the y-axis.  Zoom the visualization x-axis to the range 18 to 90.  Zoom the y-axis to include all means by age.  Points show means by age.  A line depicts the line of best fit based on a pre-computed linear regression Agreement ~ Age for this item.  The title of the figure is the text of the item.  The subtitle is Agreement by Age, American adults, N = ITEM_N.  A caption reads "Source = Ryerson Project, YYYY-MM-DD." in 10-point, medium grey text.

In addition to the visualizations above, a numbered list contains the text of the five items.  For each item, there is a "See report" link to the item page.

Similarly, five visualizations illustrate the five items where age and agreement are most strongly *negatively* correlated.

### Home page featured item

On the Ryerson Project home page at https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/ there will be one featured item (in addition to the existing content). R/create_index_dictionary.R will create the appropriate content.  That script will run once per day.  See R/create_results_dictionary.R for a similar script.  A featured item will be randomly chosen from those items that meet this criterion: ITEM_N greater than equal to the median of all ITEM_N values.

The featured item content on the home page is a subset of what you would find on the item page.  Specifically, an 11 bar histogram shows the all-time count of observed responses per each response option.  Descriptive statistics (also all-time) are displayed: mean, median, standard deviation, N, standard error.  A templated sentence explains: American adults' average (mean) response was ALL_TIME_MEAN on a scale of 0 (Disagree) to 10 (Agree).  ITEM_N responses have been collected (so far) from EARLIEST_OBS_DATE to MOST_RECENT_OBS_DATE.  See full results for *ITEM_TEXT*.  (*ITEM_TEXT* is a link to the item page.)

## Details: Share the new analysis.

On https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/results.html there will be a "Ranked by Agreement" table.  Primary columns are Rank,Agreement (all-time mean),Statement.  Additional columns (hidden in the interface unless expanded by user are N,Earliest Observation Date,Most Recent Observation Date).

At present, there will be one table containing the summarized results for all items ever observed.  R/create_results_dictionary.R will create the HTML table from the canonical microdata file.  That content will replace RANKED_BY_AGREEMENT_TABLE in the HTML template.

In the future, the Ranked by Agreement will be a paginated table sortable on any column - but that is future functionality.

Search on https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/results.html will remain TODO for now.


## Community Features

Researchers may request to join the community.  A request consists of submitting their email address and ORCID.  Dr. Jones will decide to approve requests or not.  A non=empty ORCID record older than 180 days is required.

Registration and log in with ORCID is the only way to use the community features.

Each new user receives 10 NEDbucks.  Users can earn NEDbucks by participating.

Use Stripe so that researchers can buy guaranteed observation bundles.  They buy NEDbucks.  NEDbucks convert to responses by way of promoting items to a higher Tier.  Example $100 dollars to 100 NEDbucks equals 1000 responses.  NEDbucks instead of simply a dollar balance, because I want to give away NEDbucks for community participation, good citizen actions.

Community features that live on ninja: discussion forum, prediction contest, votes to promote items.

A Community Member can add one new Tier 4 item per day.  Community members may observe the current Tier state of items.  Perhaps a page for each Tier or one page with tabs.

If Dr. Jones accepts a community join request, the following happens:
1. The system pulls ORCID info into local profile.  Stored in the database.
2. The user may now login through ORCID.
3. The new community member receives a welcome email.

When a community member logs in, they see the Member Home Page.  On this page they see:
- Welcome member by name.
- Show member's NedBucks balance.
- Link to a form where Member can suggest a new item.  The item is recorded to the database as a Suggested Item.  Each member may submit a maximum 1 Suggested Item per day.  There is an admin interface for Dr. Jones to edit and approve Suggested Items.  He may edit the item to fix a typo or wording.  He may reject the Suggested Item with a reason.  He may approve the Suggested Item.  Upon reject or approve, the suggesting Member receives an email notifying them of the result.  An approved Suggested Item becomes a Tier 4 item.
- Link to a page where Members see the current items.  Search box so Member can limit to keyword match.  Items are sorted by current Tier.  Tier is visually clear - light background color or a leading small symbol?  Also displayed is Community ELO score - will be a daily-updated ELO score based on temporally discounted Bakeoff results.  Button to promote item with NedBucks.  Promotion temporarily pushes item to higher tier.  Promoted items have Community ELO scores, but score does not determine its Tier.
- Link to item bakeoff page.  Member sees two items.  Expresses preference.  Recorded to db.  Maximum 100 per day.
- Link to view stats.  Member sees their counts of each action and the community total.  Percentile and histogram compares to all other users.
- Link where Member can purchase more NedBucks.

Cost is 100 NEDbucks for this service: Next-day promotion of chosen item to Tier 20. Guaranteed minimum half of total respondents per day, and run for 100 days.  Then demoted to Tier 40 unless further payment is made.

Cost is 10 NEDbucks for this service: Next-day promotion of chosen item to Tier 30. Guaranteed minimum one-quarter of total respondents per day, and run for 30 days.  Then demoted to Tier 40 unless further payment is made.

### Item Bakeoff Details

Once per day, all items are re-sorted into Tiers.  One component of the sorting is Community Member preference.  Community Member preference is an Elo score calculated from temporally discounted head-to-head Item comparisons.  A cron job will call a Python script to kick off the resorting.  The nightly re-sort will be scheduled to run shortly after midnight UTC, e.g. 00:05 UTC, and includes all valid Bakeoff results with timestamps before 00:00 UTC.

There is a Bakeoff page available only to logged in active Community Members.  The page has a simple bakeoff interface: one Item on the left versus one Item on the right.  Within a pair of Items, the assignment to left or right is random.

The bakeoff page presents two active Items, chosen at random with conditions:
- Prefer Items with fewer recent Bakeoff appearances.
- Prefer Items with higher ranking uncertainty.
- Avoid exact pair repeats for the same Member on the same UTC day.
- Avoid Item repeats within the Member's first 20 daily submissions where possible.  In other words, it is desirable that a Member sees 40 unique Items within their first 20 bakeoffs.  If fewer than 40 eligible Items are available, the no-repeat rule is relaxed only as necessary.
- the same Member never sees the same exact Item pairing twice in one day.
- The system should attempt to balance exposure so that Items receive roughly comparable numbers of Bakeoff appearances over time.

The Community Member may click on one Item or the other.  They click in response to the instruction: "Click on the Item you prefer to be prioritized higher. Choose one. Close calls are expected."  Upon clicking on one Item, it is recorded: which Member, each Item presented, which Item the Member chose and a timestamp.  It is important that each Member is limited to 100 bakeoff choices per day maximum.  It is important that Members must use the interface to choose and not tamper with the IDs as chosen by the system.  We must avoid an over-enthusiastic Community Member from stuffing the ballot for their Item of choice.

Each Member may submit 100 Bakeoff results per day maximum.  All Bakeoff timestamps are stored in UTC. Daily limits are calculated by UTC calendar day. The nightly re-sort runs just after the UTC day closes.  If a user is submitting bakeoff choices around midnight UTC, they may notice their count toward the 100 limit reset to zero, and that is as desired.

During the re-sort, each item receives a temporally discounted Elo score.  The discount function is simple rather than smooth.  Any Bakeoff match result 366 days old or older counts with weight 1.0 only.  A Bakeoff match result from within the last 24 hours counts with weight 100.0.  A Bakeoff match that is less than 366 days old AND more than 365 days old counts with weight 2.0.  Use a **linear** discount for match results between 1 and 365 days old.  Use a small number of constants so the discounting might be easily adjusted if needed in the future.  The intent is that match results never fade completely to zero, but newer match results are more important for tomorrow's Item ranking.

By "weight" above, I mean treat matches with this relative value.  I used integers for clarity, but you can scale within a 0.0 to 1.0 range if that is necessary.  The Elo score is intended to fall within reasonable, usual ranges.  Those familiar with chess Elo scores should understand.

All item Elo scores are recomputed from scratch during the nightly job.  Initial Elo for every active Item is INITIAL_ELO.  Bakeoff records are processed from oldest to newest by timestamp, with a deterministic tie-breaker using bakeoff_result_id.

### Nightly Retiering of Items

On the production web server there is a PHP script that modifies current_tier and current_community_score.  The PHP script lives in admin/ and is named items_retier.php.  Specifically, here is what the script does:
1. Recalculate the Community Elo Score for every active item.  Community Elo Score is calculated from Item Bakeoff results; newer wins and losses affect the score more than older wins and losses.  The recalculated score of each item is stored in the database.
2. Re-assign Items to Tiers.  The highest rated items fill the top tiers.  Currently, Tier 10 is the topmost.  The section `Details: Collect respondents' responses to survey items` in this document describes tiering and tier sizes.  Fill each Tier to max items before moving to the next.  For instance, there should be 2 x (ITEMS_TO_PRESENT / TIERS) Tier 20 items before there are any Tier 30 items.  Within Tier 40, there may be unlimited items.  Only some items in Tier 40 are eligible for presentation in the survey (as described elsewhere).  It is okay and expected that some Tier 40 items will not be presented in the survey.
3. Future functionality will allow Community Members to use NEDbucks to promote Items to any Tier, regardless of community score.  items_retier.php will implement this later.

An administrator can manually run items_retier.php from admin/index.php.  However, the expected functionality is that a cron job on the AWS automation server will run a Python script that visits items_retier.php.  This is similar to the way response exports are generated once daily.


## Administration Interface

An interface for human administration exists in admin/  The main interface is admin/index.php.  For now, Dr. Jones is the only one who may access the administration interface.  The admin/ directory is behind HTTPS authentication.

From the admin interface, one can:
- View existing waiting list entries.
- Create the response data exports

When a waiting list applicant is approved to be a community member, an email is sent to them.  The email contains a link to a member account creation page.  On the member account creation page, the user logs in with an ORCID.  The ORCID details are pulled in to their local profile.

## Daily Automated Emails

Emails are sent from this address: "Ryerson Project <ryerson@jasonjones.ninja>"

One daily email message is the Admin Overview.  The Admin Overview logs the state of the Ryerson Project through counts.  The Admin Overview email message is sent to Dr. Jones at this address: jason.j.jones@stonybrook.edu.  An admin page has two functions: a logged-in administrator can send the email manually, or a Python script from the automation server can cause the email to be sent.  A cron job will be set up so the admin email is sent once per day at 10 minutes past UTC midnight.

At minimum, all of the following counts are included in the Admin Overview:
- Total unique responent-observation_date all-time and previous day.
- Total unique prolific_pid within respondents table all-time.
- Total responses all-time, previous day and subtotals per response_value.
- Total active survey_items and subtotals by current_tier.
- Total waiting_list_requests.
- Total Invitations and subtotals by status.
- Total Community Members.
- Total all-time Item Bakeoff results, Item Bakeoff results from previous day, Total all-time unique members who have ever submitted an Item Bakeoff, total unique members who submitted a bakeoff from previous day.
- Total suggested_items and subtotals by moderation_status.

In the Admin Overview email message, also include these links for convenience:
- [Ryerson Home](https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/)
- [Ryerson Administration](https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/admin/)
- [Zenodo Data](https://zenodo.org/records/21058880)

Community members can subscribe to different automated emails.

A data email once per week provides updated response totals and links to the data files.
A detailed log email once per day that contains the content of the daily log.
An automated analysis email once per month that provides an automated blog style dive into the data.


## Best Practices to Follow

It is important that Ryerson HTML pages and images are discoverable by humans and automated systems on the web.  Follow web standards to describe pages, images, data with appropriate metadata.

We should follow best practices to make the site user-friendly.  For example, be sure an icon that appears in the tab/address bar.

Always use https within jasonjones.ninja. Never add www. in front of jasonjones.ninja

Never put secrets in tracked files, on GitHub, within served directories or other risky places.  Use a config file or files.

Let's keep track of what is Now, Next, Later and Done using STATUS.md

In R code, use tidyverse conventions for all data work.

Include a file that illustrates which cron jobs run to make the system work.
In code and data, dates in YYYY-MM-DD format always.  Exceptions are OK for visualizations, e.g. Mar\n2026 on an axis label.

Every day gets one log file.  Each script writes to the daily log any error messages or hopefully its successful completion message.