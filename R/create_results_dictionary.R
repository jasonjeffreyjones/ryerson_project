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


build_ranked_results <- function(canonical_data) {
	valid_data <- canonical_data %>%
		filter(
			response_value %in% RESPONSE_VALUES,
			!is.na(statement_text),
			!is.na(survey_item_id)
		)

	invalid_count <- nrow(canonical_data) - nrow(valid_data)
	if (invalid_count > 0) {
		log_message(sprintf("Excluded %d row(s) with response_value outside 0 through 10 from ranked results.", invalid_count))
	}

	display_texts <- item_display_texts(valid_data)

	valid_data %>%
		group_by(survey_item_id) %>%
		summarise(
			agreement = mean(response_value, na.rm = TRUE),
			n = n(),
			earliest_observation_date = min(observation_date, na.rm = TRUE),
			most_recent_observation_date = max(observation_date, na.rm = TRUE),
			.groups = "drop"
		) %>%
		left_join(display_texts, by = "survey_item_id") %>%
		arrange(desc(agreement), desc(n), statement_text, survey_item_id) %>%
		mutate(
			rank = row_number(),
			item_page_filename = item_page_filename(survey_item_id, statement_text)
		)
}


build_empty_table <- function() {
	paste(
		'<div class="alert alert-info" role="status">',
		'No ranked results are available yet.',
		'</div>',
		sep = "\n"
	)
}


build_result_row <- function(row) {
	sprintf(
		paste(
			'<tr>',
				'<td class="text-end">%s</td>',
				'<td class="text-end">%s</td>',
				'<td>',
				'<div>%s</div>',
				'</td>',
				'<td class="text-end">',
				'%s',
				'</td>',
				'<td>',
				'<a href="item-results/%s">Report</a>',
				'</td>',
				'</tr>',
				sep = "\n"
		),
		row$rank,
		sprintf("%.2f", row$agreement),
		html_escape(row$statement_text),
		format_count(row$n),
		html_escape(row$item_page_filename)
	)
}


build_ranked_table <- function(results_data) {
	if (nrow(results_data) == 0) {
		return(build_empty_table())
	}

	rows <- purrr::map_chr(seq_len(nrow(results_data)), function(row_number) {
		build_result_row(results_data[row_number, ])
	})

	paste(
		'<div class="table-responsive">',
		'<table class="table table-striped table-hover align-middle">',
		'<caption>All-time mean agreement by survey item.</caption>',
		'<thead class="table-light">',
		'<tr>',
		'<th scope="col" class="text-end">Rank</th>',
		'<th scope="col" class="text-end">Agreement</th>',
		'<th scope="col">Statement</th>',
		'<th scope="col" class="text-end">Total N</th>',
		'<th scope="col">Report</th>',
		'</tr>',
		'</thead>',
		'<tbody>',
		paste(rows, collapse = "\n"),
		'</tbody>',
		'</table>',
		'</div>',
		sep = "\n"
	)
}


date_or_blank <- function(dates, fn) {
	if (length(dates) == 0 || all(is.na(dates))) {
		return("")
	}
	as.character(fn(dates, na.rm = TRUE))
}


write_results_dictionary <- function(canonical_data, results_data, output_path) {
	dictionary <- list(
		MOST_RECENT_OBS_DATE = date_or_blank(canonical_data$observation_date, max),
		RANKED_BY_AGREEMENT_TABLE = build_ranked_table(results_data),
		RANKED_RESULTS_ITEM_COUNT = format_count(nrow(results_data))
	)

	jsonlite::write_json(dictionary, output_path, auto_unbox = FALSE, pretty = TRUE)
}


create_results_dictionary <- function() {
	root_dir <- project_root()
	canonical_path <- file.path(root_dir, "website", "data", "ryerson.csv.gz")
	dictionary_path <- file.path(root_dir, "json", "results.json")

	log_message(sprintf("Reading canonical data file from %s.", canonical_path))
	canonical_data <- read_canonical_data(canonical_path)

	results_data <- build_ranked_results(canonical_data)
	log_message(sprintf("Built ranked agreement table with %d item(s).", nrow(results_data)))

	write_results_dictionary(canonical_data, results_data, dictionary_path)
	log_message(sprintf("Wrote results dictionary to %s.", dictionary_path))
}


create_results_dictionary()
