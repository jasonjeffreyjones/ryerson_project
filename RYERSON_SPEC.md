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
This feature is complete when once a day a new Prolific Study is created once per day, and responses are entering the database.

## Details: Collect respondents' responses to survey items.

Web pages on jasonjones.ninja use PHP and mysql to present survey items and collect responses.
For now, we aim for 32 items on the survey.  We aim for 12 respondents per day.

Every survey item has this format: one statement; response options are 11 points on a Likert Agree-Disagree scale. 0 is maximum Disagreement. 10 is maximum Agreement.  This constraint is desired and enforced.  It keeps measurement consistent, persistent and standardized.

Example Item: I believe that shape-shifting reptilian people control our world by taking on human form and gaining political power to manipulate our society.  Interface to respond from 0 to 10.  A response is required.

Behind the scenes, there are Tiers of items.  In the database, statements are stored with their current Tier.  I don't think a Tier history is important.

Tier 10 items are always presented to all respondents every day.
Tier 20 items are guaranteed minimum half of total respondents per day, and run for 100 days.  Then demoted to Tier 4 unless further payment is made.  Cost is 100 NEDbucks.
Tier 30 items are guaranteed minimum one-quarter of total respondents per day, and run for 30 days.  Then demoted to Tier 4 unless further payment is made.  Cost is 10 NEDbucks.
Tier 40 items are in a queue.  The top 8 items are guaranteed 1 respondent per day, and run for 5 days.  After that, the item goes to the back of the Tier 4 queue.

For now, there are exactly 8 items allowed at Tier 10.  The remaining Tiers may have more.

Respondents see 8 items from Tier 10, 8 items from Tier 20, 8 items from Tier 30 and 8 items from Tier 40.

It is okay and expected that two respondents from the same day have not exactly the same set of items.  The order of items is a random permutation.

This feature is complete when a respondent can come to the website, read the instructions, agree to be truthful and throughtful and respond to a set of items that meet the requirements.

### The Parallel Demonstration Survey feature

A separate but exact mirror of the survey exists as a public demonstration.  It does not collect responses; it simply allows anyone to experience the survey flow.  It uses real statements from the database.  The interface matches exactly.  Tiering and item selection does not need to match exactly.  Length should match exactly.  The Parallel Demonstration Survey is clearly marked as a demonstration.

## Details: Update all data files to include new responses.

TODO

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