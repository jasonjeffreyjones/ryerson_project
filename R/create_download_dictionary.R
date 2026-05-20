#!/usr/bin/env Rscript

Sys.setenv(TZ = "UTC")

suppressPackageStartupMessages(library(tidyverse))
suppressPackageStartupMessages(library(jsonlite))

RESPONSE_VALUES <- 0:10
RESPONSE_COLUMNS <- paste0("response", RESPONSE_VALUES)


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
			response_value = readr::col_integer(),
			statement_text = readr::col_character(),
			observation_date = readr::col_date(format = "%Y-%m-%d"),
			.default = readr::col_character()
		),
		na = character(),
		show_col_types = FALSE,
		progress = FALSE
	)

	required_columns(data, c("response_value", "statement_text", "observation_date"), path)
	data
}


add_missing_response_columns <- function(data) {
	for (column_name in RESPONSE_COLUMNS) {
		if (!column_name %in% names(data)) {
			data[[column_name]] <- integer(nrow(data))
		}
	}
	data
}


aggregate_response_counts <- function(data, group_columns) {
	if (nrow(data) == 0) {
		empty_columns <- c(
			setNames(rep(list(character()), length(group_columns)), group_columns),
			setNames(rep(list(integer()), length(RESPONSE_COLUMNS)), RESPONSE_COLUMNS)
		)
		return(tibble::as_tibble(empty_columns))
	}

	valid_data <- data %>%
		filter(response_value %in% RESPONSE_VALUES) %>%
		mutate(response_column = factor(paste0("response", response_value), levels = RESPONSE_COLUMNS))

	invalid_count <- nrow(data) - nrow(valid_data)
	if (invalid_count > 0) {
		log_message(sprintf("Excluded %d row(s) with response_value outside 0 through 10 from aggregate files.", invalid_count))
	}

	group_keys <- data %>%
		distinct(across(all_of(group_columns)))

	response_counts <- valid_data %>%
		count(across(all_of(group_columns)), response_column, name = "respondent_count")

	group_keys %>%
		tidyr::expand_grid(response_column = factor(RESPONSE_COLUMNS, levels = RESPONSE_COLUMNS)) %>%
		left_join(response_counts, by = c(group_columns, "response_column")) %>%
		mutate(respondent_count = replace_na(respondent_count, 0L)) %>%
		pivot_wider(
			names_from = response_column,
			values_from = respondent_count,
			values_fill = 0L
		) %>%
		add_missing_response_columns() %>%
		select(all_of(group_columns), all_of(RESPONSE_COLUMNS)) %>%
		arrange(across(all_of(group_columns)))
}


build_monthly_aggregate <- function(canonical_data) {
	canonical_data %>%
		mutate(observation_month = format(observation_date, "%Y-%m")) %>%
		aggregate_response_counts(c("observation_month", "statement_text"))
}


build_all_time_aggregate <- function(canonical_data) {
	canonical_data %>%
		aggregate_response_counts(c("statement_text"))
}


write_gzip_csv <- function(data, output_path) {
	output_dir <- dirname(output_path)
	if (!dir.exists(output_dir)) {
		dir.create(output_dir, recursive = TRUE)
	}

	temp_path <- tempfile(tmpdir = output_dir, fileext = ".csv.gz")
	readr::write_csv(data, temp_path, na = "")
	if (!file.rename(temp_path, output_path)) {
		stop(sprintf("Could not replace data file at %s.", output_path))
	}
}


format_count <- function(value) {
	format(value, big.mark = ",", scientific = FALSE, trim = TRUE)
}


date_or_blank <- function(dates, fn) {
	if (length(dates) == 0 || all(is.na(dates))) {
		return("")
	}
	as.character(fn(dates, na.rm = TRUE))
}


write_download_dictionary <- function(canonical_data, monthly_data, all_time_data, output_path) {
	dictionary <- list(
		MOST_RECENT_OBS_DATE = date_or_blank(canonical_data$observation_date, max),
		MICRODATA_ROWS_COUNT = format_count(nrow(canonical_data)),
		MONTHLY_AGG_ROWS_COUNT = format_count(nrow(monthly_data)),
		ALL_TIME_AGG_ROWS_COUNT = format_count(nrow(all_time_data)),
		OLDEST_OBS_DATE = date_or_blank(canonical_data$observation_date, min)
	)

	jsonlite::write_json(dictionary, output_path, auto_unbox = FALSE, pretty = TRUE)
}


create_download_dictionary <- function() {
	root_dir <- project_root()
	canonical_path <- file.path(root_dir, "website", "data", "ryerson.csv.gz")
	monthly_path <- file.path(root_dir, "website", "data", "monthly-aggregated-ryerson.csv.gz")
	all_time_path <- file.path(root_dir, "website", "data", "all-time-aggregated-ryerson.csv.gz")
	dictionary_path <- file.path(root_dir, "json", "download.json")

	log_message(sprintf("Reading canonical data file from %s.", canonical_path))
	canonical_data <- read_canonical_data(canonical_path)

	monthly_data <- build_monthly_aggregate(canonical_data)
	all_time_data <- build_all_time_aggregate(canonical_data)

	write_gzip_csv(monthly_data, monthly_path)
	log_message(sprintf("Wrote %d monthly aggregate row(s) to %s.", nrow(monthly_data), monthly_path))

	write_gzip_csv(all_time_data, all_time_path)
	log_message(sprintf("Wrote %d all-time aggregate row(s) to %s.", nrow(all_time_data), all_time_path))

	write_download_dictionary(canonical_data, monthly_data, all_time_data, dictionary_path)
	log_message(sprintf("Wrote download dictionary to %s.", dictionary_path))
}


create_download_dictionary()
