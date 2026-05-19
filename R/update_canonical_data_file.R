#!/usr/bin/env Rscript

library(digest)

FIRST_OBSERVATION_DATE <- as.Date("2026-05-01")
CANONICAL_COLUMNS <- c(
	"response_value",
	"statement_text",
	"observation_date",
	"hashed_respondent_id",
	"age",
	"sex",
	"ethnicity",
	"birth_country",
	"residence_country",
	"nationality",
	"language",
	"student",
	"employment",
	"time_on_task",
	"approvals",
	"survey_item_id",
	"presented_order"
)

RESPONSE_COLUMNS <- c(
	"prolific_pid",
	"observation_date",
	"response_value",
	"statement_text",
	"survey_item_id",
	"presented_order"
)

DEMOGRAPHIC_COLUMNS <- c(
	"Participant id",
	"Age",
	"Sex",
	"Ethnicity simplified",
	"Country of birth",
	"Country of residence",
	"Nationality",
	"Language",
	"Student status",
	"Employment status",
	"Time taken",
	"Total approvals"
)


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


hash_user_id <- function(user_id) {
	full_hash <- digest(user_id, algo = "sha256", serialize = FALSE)
	short_id <- substr(full_hash, 1, 12)
	return(short_id)
}


hash_user_ids <- function(user_ids) {
	vapply(as.character(user_ids), hash_user_id, character(1), USE.NAMES = FALSE)
}


expected_dates <- function() {
	yesterday <- Sys.Date() - 1
	if (yesterday < FIRST_OBSERVATION_DATE) {
		return(as.Date(character()))
	}
	seq(FIRST_OBSERVATION_DATE, yesterday, by = "day")
}


date_token <- function(observation_date) {
	gsub("-", "_", as.character(observation_date), fixed = TRUE)
}


response_export_path <- function(root_dir, observation_date) {
	file.path(
		root_dir,
		"private",
		"response_exports",
		sprintf("responses_%s.csv.gz", date_token(observation_date))
	)
}


demographic_export_path <- function(root_dir, observation_date) {
	file.path(
		root_dir,
		"private",
		"demographic_exports",
		sprintf("demographics_%s.csv.gz", date_token(observation_date))
	)
}


read_gzip_csv <- function(path) {
	connection <- gzfile(path, open = "rt")
	on.exit(close(connection), add = TRUE)
	read.csv(
		connection,
		stringsAsFactors = FALSE,
		check.names = FALSE,
		na.strings = character(),
		quote = "\"",
		comment.char = ""
	)
}


gzip_csv_is_empty <- function(path) {
	connection <- gzfile(path, open = "rt")
	on.exit(close(connection), add = TRUE)
	length(readLines(connection, n = 1, warn = FALSE)) == 0
}


missing_columns <- function(data, required_columns) {
	setdiff(required_columns, names(data))
}


format_column_list <- function(columns) {
	paste(columns, collapse = ", ")
}


read_response_export <- function(path, observation_date) {
	responses <- read_gzip_csv(path)
	missing <- missing_columns(responses, RESPONSE_COLUMNS)
	if (length(missing) > 0) {
		stop(sprintf("response export is missing column(s): %s", format_column_list(missing)))
	}

	responses <- responses[, RESPONSE_COLUMNS, drop = FALSE]
	responses$observation_date <- as.character(responses$observation_date)
	expected_date <- as.character(observation_date)
	wrong_date_rows <- !is.na(responses$observation_date) & responses$observation_date != expected_date
	if (any(wrong_date_rows)) {
		stop(sprintf(
			"response export contains %d row(s) with observation_date other than %s",
			sum(wrong_date_rows),
			expected_date
		))
	}

	responses$hashed_respondent_id <- hash_user_ids(responses$prolific_pid)
	responses
}


read_demographic_export <- function(path, observation_date) {
	demographics <- read_gzip_csv(path)
	missing <- missing_columns(demographics, DEMOGRAPHIC_COLUMNS)
	if (length(missing) > 0) {
		stop(sprintf("demographic export is missing column(s): %s", format_column_list(missing)))
	}

	demographics <- demographics[, DEMOGRAPHIC_COLUMNS, drop = FALSE]
	demographics$hashed_respondent_id <- hash_user_ids(demographics[["Participant id"]])
	demographics$observation_date <- as.character(observation_date)
	demographics
}


build_daily_rows <- function(responses, demographics, observation_date) {
	demographic_subset <- data.frame(
		hashed_respondent_id = demographics$hashed_respondent_id,
		observation_date = demographics$observation_date,
		age = demographics[["Age"]],
		sex = demographics[["Sex"]],
		ethnicity = demographics[["Ethnicity simplified"]],
		birth_country = demographics[["Country of birth"]],
		residence_country = demographics[["Country of residence"]],
		nationality = demographics[["Nationality"]],
		language = demographics[["Language"]],
		student = demographics[["Student status"]],
		employment = demographics[["Employment status"]],
		time_on_task = demographics[["Time taken"]],
		approvals = demographics[["Total approvals"]],
		stringsAsFactors = FALSE,
		check.names = FALSE
	)

	duplicated_demographics <- duplicated(demographic_subset[c("hashed_respondent_id", "observation_date")])
	if (any(duplicated_demographics)) {
		log_message(sprintf(
			"%s has %d duplicate demographic row(s) by hashed_respondent_id and observation_date; using the first match.",
			observation_date,
			sum(duplicated_demographics)
		))
		demographic_subset <- demographic_subset[!duplicated_demographics, , drop = FALSE]
	}

	joined <- merge(
		responses,
		demographic_subset,
		by = c("hashed_respondent_id", "observation_date"),
		all.x = FALSE,
		all.y = FALSE,
		sort = FALSE
	)

	unmatched_response_keys <- !paste(responses$hashed_respondent_id, responses$observation_date) %in%
		paste(demographic_subset$hashed_respondent_id, demographic_subset$observation_date)
	if (any(unmatched_response_keys)) {
		log_message(sprintf(
			"%s has %d response row(s) without a matching demographic row.",
			observation_date,
			sum(unmatched_response_keys)
		))
	}

	if (nrow(joined) == 0) {
		log_message(sprintf("%s produced zero joined canonical row(s).", observation_date))
		return(data.frame(matrix(ncol = length(CANONICAL_COLUMNS), nrow = 0, dimnames = list(NULL, CANONICAL_COLUMNS))))
	}

	joined <- joined[, c(
		"response_value",
		"statement_text",
		"observation_date",
		"hashed_respondent_id",
		"age",
		"sex",
		"ethnicity",
		"birth_country",
		"residence_country",
		"nationality",
		"language",
		"student",
		"employment",
		"time_on_task",
		"approvals",
		"survey_item_id",
		"presented_order"
	), drop = FALSE]

	duplicate_keys <- duplicated(joined[c("hashed_respondent_id", "observation_date", "survey_item_id")])
	if (any(duplicate_keys)) {
		log_message(sprintf(
			"%s has %d duplicate canonical row(s) by hashed_respondent_id, observation_date, and survey_item_id; keeping them.",
			observation_date,
			sum(duplicate_keys)
		))
	}

	joined
}


process_date <- function(root_dir, observation_date) {
	response_path <- response_export_path(root_dir, observation_date)
	demographic_path <- demographic_export_path(root_dir, observation_date)

	response_exists <- file.exists(response_path)
	demographic_exists <- file.exists(demographic_path)
	if (!response_exists) {
		log_message(sprintf("%s missing response export: %s", observation_date, response_path))
	}
	if (!demographic_exists) {
		log_message(sprintf("%s missing demographic export: %s", observation_date, demographic_path))
	}
	if (!response_exists || !demographic_exists) {
		return(NULL)
	}
	if (file.info(demographic_path)$size == 0 || gzip_csv_is_empty(demographic_path)) {
		log_message(sprintf("%s demographic export is empty: %s", observation_date, demographic_path))
		return(NULL)
	}

	tryCatch(
		{
			responses <- read_response_export(response_path, observation_date)
			demographics <- read_demographic_export(demographic_path, observation_date)
			if (nrow(demographics) == 0) {
				log_message(sprintf("%s demographic export contains zero row(s); skipping date.", observation_date))
				return(NULL)
			}
			daily_rows <- build_daily_rows(responses, demographics, observation_date)
			log_message(sprintf("%s added %d canonical row(s).", observation_date, nrow(daily_rows)))
			daily_rows
		},
		error = function(error) {
			log_message(sprintf("%s malformed input; skipping date. %s", observation_date, conditionMessage(error)))
			NULL
		}
	)
}


empty_canonical_data <- function() {
	data.frame(matrix(ncol = length(CANONICAL_COLUMNS), nrow = 0, dimnames = list(NULL, CANONICAL_COLUMNS)))
}


sort_canonical_data <- function(data) {
	if (nrow(data) == 0) {
		return(data)
	}

	data[order(data$observation_date, data$hashed_respondent_id, as.integer(data$presented_order)), CANONICAL_COLUMNS, drop = FALSE]
}


write_canonical_data <- function(data, output_path) {
	output_dir <- dirname(output_path)
	if (!dir.exists(output_dir)) {
		dir.create(output_dir, recursive = TRUE)
	}

	temp_path <- paste0(output_path, ".tmp")
	connection <- gzfile(temp_path, open = "wt")
	write.csv(data, connection, row.names = FALSE, quote = TRUE, na = "")
	close(connection)
	if (!file.rename(temp_path, output_path)) {
		stop(sprintf("Could not replace canonical data file at %s.", output_path))
	}
}


update_canonical_data_file <- function() {
	root_dir <- project_root()
	output_path <- file.path(root_dir, "website", "data", "ryerson.csv.gz")
	dates <- expected_dates()

	log_message(sprintf("Rebuilding canonical data file for %d expected date(s).", length(dates)))

	daily_data <- lapply(dates, function(observation_date) {
		process_date(root_dir, observation_date)
	})
	daily_data <- Filter(Negate(is.null), daily_data)

	if (length(daily_data) == 0) {
		canonical_data <- empty_canonical_data()
	} else {
		canonical_data <- do.call(rbind, daily_data)
		canonical_data <- sort_canonical_data(canonical_data)
	}

	write_canonical_data(canonical_data, output_path)
	log_message(sprintf("Wrote %d row(s) to %s.", nrow(canonical_data), output_path))
}


update_canonical_data_file()
