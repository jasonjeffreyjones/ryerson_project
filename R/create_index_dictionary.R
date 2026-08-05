#!/usr/bin/env Rscript

Sys.setenv(TZ = "UTC")

suppressPackageStartupMessages(library(tidyverse))
suppressPackageStartupMessages(library(jsonlite))


script_path <- function() {
	file_args <- grep("^--file=", commandArgs(trailingOnly = FALSE), value = TRUE)
	if (length(file_args) == 0) {
		return(normalizePath(getwd()))
	}
	normalizePath(sub("^--file=", "", file_args[[1]]))
}


project_root <- function() {
	normalizePath(file.path(dirname(script_path()), ".."))
}


source(file.path(project_root(), "R", "ryerson_item_helpers.R"))


log_message <- function(message) {
	print(sprintf("%s %s", format(Sys.time(), "%Y-%m-%dT%H:%M:%SZ", tz = "UTC"), message))
}


required_columns <- function(data, columns, source_name) {
	missing <- setdiff(columns, names(data))
	if (length(missing) > 0) {
		stop(sprintf("%s is missing column(s): %s", source_name, paste(missing, collapse = ", ")))
	}
}


read_canonical_data <- function(path) {
	if (!file.exists(path)) {
		stop(sprintf("Canonical data file does not exist: %s", path))
	}

	data <- readr::read_csv(
		path,
		col_types = readr::cols(
			response_value = readr::col_double(),
			statement_text = readr::col_character(),
			observation_date = readr::col_date(format = "%Y-%m-%d"),
			survey_item_id = readr::col_integer(),
			.default = readr::col_character()
		),
		na = character(),
		show_col_types = FALSE,
		progress = FALSE
	)

	required_columns(
		data,
		c("response_value", "statement_text", "observation_date", "survey_item_id"),
		path
	)

	data
}


valid_featured_item_data <- function(canonical_data) {
	valid_data <- canonical_data %>%
		filter(
			response_value %in% RESPONSE_VALUES,
			!is.na(statement_text),
			!is.na(survey_item_id),
			!is.na(observation_date)
		)

	invalid_count <- nrow(canonical_data) - nrow(valid_data)
	if (invalid_count > 0) {
		log_message(sprintf("Excluded %d row(s) because required Featured Item values were missing or invalid.", invalid_count))
	}

	valid_data
}


select_featured_item <- function(summaries, selection_date) {
	if (nrow(summaries) == 0) {
		return(NULL)
	}

	median_item_n <- median(summaries$n)
	eligible <- summaries %>%
		filter(n >= median_item_n) %>%
		arrange(survey_item_id, statement_text)

	selection_seed <- as.integer(format(as.Date(selection_date), "%Y%m%d"))
	set.seed(selection_seed)
	selected_position <- sample.int(nrow(eligible), size = 1)

	list(
		summary = eligible[selected_position, ],
		eligible_count = nrow(eligible),
		median_item_n = median_item_n
	)
}


build_empty_featured_item_section <- function() {
	paste(
		'<section class="mt-5 pt-4 border-top" aria-labelledby="featured-item">',
		'<h2 id="featured-item">Featured Item</h2>',
		'<div class="alert alert-info" role="status">No Featured Item results are available yet.</div>',
		'</section>',
		sep = "\n"
	)
}


build_featured_item_section <- function(data, summary_row) {
	item_data <- data %>%
		filter(survey_item_id == summary_row$survey_item_id)
	counts <- response_counts_for_item(item_data)

	paste(
		'<section class="mt-5 pt-4 border-top" aria-labelledby="featured-item">',
		'<h2 id="featured-item">Featured Item</h2>',
		sprintf(
			'<blockquote class="blockquote"><p>%s</p></blockquote>',
			html_escape(summary_row$statement_text)
		),
		sprintf('<p class="lead">%s</p>', build_summary_sentence(summary_row)),
		'<h3 class="h4 mt-4">Descriptive Statistics</h3>',
		build_stats_table(summary_row),
		'<h3 class="h4 mt-4">All-Time Distribution</h3>',
		build_histogram_svg(counts, summary_row$statement_text),
		sprintf(
			'<p>See full results for <a href="item-results/%s"><em>%s</em></a>.</p>',
			html_escape(summary_row$item_page_filename),
			html_escape(summary_row$statement_text)
		),
		'<p>You may <a href="download.html">download the data</a> for your own analysis.</p>',
		'<p>Explore more <a href="results.html">Ryerson Project results</a>.</p>',
		'</section>',
		sep = "\n"
	)
}


write_index_dictionary <- function(featured_item_section, output_path) {
	dictionary <- list(FEATURED_ITEM_SECTION = featured_item_section)
	jsonlite::write_json(dictionary, output_path, auto_unbox = FALSE, pretty = TRUE)
}


create_index_dictionary <- function() {
	root_dir <- project_root()
	canonical_path <- file.path(root_dir, "website", "data", "ryerson.csv.gz")
	dictionary_path <- file.path(root_dir, "json", "index.json")
	selection_date <- Sys.Date()

	log_message(sprintf("Reading canonical data file from %s.", canonical_path))
	canonical_data <- read_canonical_data(canonical_path)
	data <- valid_featured_item_data(canonical_data)

	if (nrow(data) == 0) {
		featured_item_section <- build_empty_featured_item_section()
		log_message("No valid observations were available for Featured Item selection.")
	} else {
		summaries <- build_item_summaries(data)
		selection <- select_featured_item(summaries, selection_date)
		summary_row <- selection$summary
		featured_item_section <- build_featured_item_section(data, summary_row)
		log_message(sprintf(
			"Selected survey_item_id %s from %d eligible item(s) with N greater than or equal to the median N of %s for %s.",
			summary_row$survey_item_id,
			selection$eligible_count,
			format_number(selection$median_item_n, 1),
			selection_date
		))
	}

	write_index_dictionary(featured_item_section, dictionary_path)
	log_message(sprintf("Wrote index dictionary to %s.", dictionary_path))
}


if (sys.nframe() == 0) {
	create_index_dictionary()
}
