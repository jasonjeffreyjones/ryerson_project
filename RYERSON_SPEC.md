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


## Random notes below.  Pay them little attention for now.  Eventually will become detailed spec and roadmap.

Use the Prolific API to create a new Study for this day and recruit today's respondents.
The respondents visit the survey portion of the website and indicate their agreement with items.
After the expected minimum number of respondents have completed the survey


Every item is: one statement, response is 11-point Agree-Disagree.

Tier 10 items are always presented to all 21 respondents every day.
Tier 20 items are guaranteed 10 respondents per day, and run for 100 days.  Then demoted to Tier 4 unless further payment is made.  Cost is 100 NEDbucks.
Tier 30 items are guaranteed 10 respondents per day, and run for 10 days.  Then demoted to Tier 4 unless further payment is made.  Cost is 10 NEDbucks.
Tier 40 items are guaranteed 5 respondents per day, and run for 5 days.  After that, the item goes to the back of the Tier 4 queue.

To sign up, a user must have an ORCID that is at least nine months old and not empty.
Each new user receives 10 NEDbucks.
Give free NEDbucks to users for participating.

Cut out Qualtrics.  Forms on my own site.

It is important that Ryerson HTML pages and images are discoverable by humans and automated systems on the web.  Follow web standards to describe pages, images, data with appropriate metadata.

We should follow best practices to make the site user-friendly.  For example, be sure an icon that appears in the tab/address bar.

Daily email to Jason logs scripts events.  Visible alerts if problems occur.  Examples: a script errors, the same respondent somehow responded more than once today.

#### Big Dreams for Later Versions

- Use Stripe so that researchers can buy guaranteed observation bundles.  They buy NEDbucks.
- Community features that live on ninja: discussion forum, prediction contest, votes to promote items (might need a new Tier).