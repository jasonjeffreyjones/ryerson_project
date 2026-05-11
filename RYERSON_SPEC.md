# Ryerson Project Spec and Roadmap.

The goal of the Ryerson Project is to nowcast everything daily.

The primary artifact of the Ryerson Project is the web pages and web apps hosted at https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/

To nowcast everything daily, we follow this automated daily loop:
1. Recruit new respondents.
2. Collect respondents' responses to survey items.
3. Update all data files to include new responses.
4. Share the new data files.
5. Perform analysis and visualization based on the new data files.
6. Share the new analysis.

Dr. Jason Jeffrey Jones is the Benevolent Dictator for Life for the Ryerson Project.  The name is stylized as "the Ryerson Project" - lowercase t for the.  Abbreviate simply as Ryerson or tRP.

Ryerson is an implementation of a Social Science Dashboard Inator.  Social Science Dashboard Inator (SSDI) described here: https://jasonjones.ninja/social-science-dashboard-inator/  Github repository for a *different*  SSDI available here: https://github.com/jasonjeffreyjones/Jason-Jeffrey-Jones-Productions-AI-Daily-Dashboard

After initial development, Dr. Jones' aim is to create a **community of researchers** who support Ryerson with their time, attention and research funding.

## Details: Recruit new respondents.

Use the Prolific API to create a new Study for this day and recruit today's respondents.
This is invisible to users.
This is accomplished by one Python script.  That script is run once daily by a scheduled cron job.
This feature is complete when once a day a new Prolific Study is created and pushed live to respondents.

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

Tier 40 items are in a queue.  For each respondent, choose ITEMS_TO_PRESENT / TIERS items from among the top 8 x (ITEMS_TO_PRESENT / TIERS) Tier 40 items.  Once per day, the items will be resorted and retiered based on community payments, community voting and queuing.   

It is okay and expected that two respondents from the same day have not exactly the same set of items.  Also, the order of items should be a random permutation of the selected items.

This feature is complete when a respondent can come to the website, read the instructions, agree to be truthful and thoughtful, respond to a set of items that meet the requirements, and all responses are saved to the database.

### The Parallel Demonstration Survey feature

A separate but exact mirror of the survey exists as a public demonstration.  It does not collect responses; it simply allows anyone to experience the survey flow.  It uses real statements from the database.  The interface matches exactly.  Tiering and item selection does not need to match exactly.  Length should match exactly.  The Parallel Demonstration Survey is clearly marked as a demonstration.

## Details: Update all data files to include new responses.

Create a Raw Microdata file.  It contains one row per observation.  Response, item text, observation date, respondent data columns.
Pull the data from the database.  Write to a .csv file.  Gzip the file.  Overwrite previous file.  Push a copy of that file to Zenodo.  Also push a copy to GitHub.

This feature is done when a test confirms that a user can download the data file from https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/download.html.  Also, that page has the correct format to be indexed by Google Datasets.

## Details: Share the new data files.

TODO

## Details: Perform analysis and visualization based on the new data files.

TODO

## Details: Share the new analysis.

TODO

## Details: Share the new data files.

TODO

goal
user-visible behavior
important constraints
what can wait
definition of done


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
- Link to a form where Member can suggest a new Tier 4 item.  Maximum 1 submission per day.
- Link to a page where Members see the current items.  Sorted by Tier.  Community ELO score. Button to promote with NedBucks.  Temporary promotion to higher tier.
- Link to item bakeoff page.  Member sees two items.  Expresses preference.  Recorded to db.  Maximum 100 per day.
- Link to view stats.  Member sees their counts of each action and the community total.  Percentile and histogram compares to all other users.
- Link where Member can purchase more NedBucks.

Cost is 100 NEDbucks for this service: Next-day promotion of chosen item to Tier 20. Guaranteed minimum half of total respondents per day, and run for 100 days.  Then demoted to Tier 40 unless further payment is made.

Cost is 10 NEDbucks for this service: Next-day promotion of chosen item to Tier 30. Guaranteed minimum one-quarter of total respondents per day, and run for 30 days.  Then demoted to Tier 40 unless further payment is made.

## Daily Emails

Community members can subscribe to different automated emails.

A data email once per week provides updated response totals and links to the data files.
A detailed log email once per day that contains the content of the daily log.
An automated analysis email once per month that provides an automated blog style dive into the data.

Daily email to Jason logs scripts events.  At the top: alerts if problems occur.  Examples: a script errors, the same respondent somehow responded more than once today.

## Best Practices to Follow

It is important that Ryerson HTML pages and images are discoverable by humans and automated systems on the web.  Follow web standards to describe pages, images, data with appropriate metadata.

We should follow best practices to make the site user-friendly.  For example, be sure an icon that appears in the tab/address bar.

Always use https within jasonjones.ninja. Never add www. in front of jasonjones.ninja

Never put secrets in tracked files, on GitHub, within served directories or other risky places.  Use a config file or files.

Let's keep track of what is Now, Next, Later and Done using STATUS.md

Include a file that illustrates which cron jobs run to make the system work.

In code and data, dates in YYYY-MM-DD format always.  Exceptions are OK for visualizations, e.g. Mar\n2026 on an axis label.

Every day gets one log file.  Each script writes to the daily log any error messages or hopefully its successful completion message.