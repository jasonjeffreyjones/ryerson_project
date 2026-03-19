# Ryerson Project

The Ryerson Project aims to nowcast everything daily.

This repository contains the source files for the Ryerson Project website at:

`https://jasonjones.ninja/social-science-dashboard-inator/ryerson-project/`

## Repository Structure

- `RYERSON_SPEC.md`: project notes, roadmap, and product direction
- `python/`: scripts used to build and publish the site
- `templates-html/`: HTML templates used as source files
- `json/`: page-specific JSON dictionaries used during template rendering
- `website/`: rendered HTML output and static image assets

## How It Works

The main script is `python/ryerson_project_update_all_pages.py`.

It currently:

1. Reads each HTML template from `templates-html/`
2. Reads the corresponding JSON file from `json/`
3. Replaces template variables and the current date
4. Writes rendered files into `website/`
5. Syncs `website/` to the live server using `rsync`

## Environment Configuration

Secrets and environment-specific settings live in a local `.env` file.

That file is intentionally ignored by git. A safe example file is included at:

`.env.example`

Both the Python deployment script and the PHP waiting list handler read the same `.env` pattern.

## License

This project is released under the MIT License. See `LICENSE`.
