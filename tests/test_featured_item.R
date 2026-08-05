#!/usr/bin/env Rscript

Sys.setenv(TZ = "UTC")

suppressPackageStartupMessages(library(tidyverse))

test_path <- normalizePath(sub("^--file=", "", grep("^--file=", commandArgs(trailingOnly = FALSE), value = TRUE)[[1]]))
root_dir <- normalizePath(file.path(dirname(test_path), ".."))
source(file.path(root_dir, "R", "create_index_dictionary.R"))


make_item_rows <- function(survey_item_id, item_n, statement_text = paste("Statement", survey_item_id)) {
	tibble(
		response_value = rep(0, item_n),
		statement_text = rep(statement_text, item_n),
		observation_date = rep(as.Date("2026-07-01"), item_n),
		survey_item_id = rep(survey_item_id, item_n)
	)
}


selection_data <- bind_rows(
	make_item_rows(1, 1),
	make_item_rows(2, 2),
	make_item_rows(3, 3),
	make_item_rows(4, 4)
)
selection_summaries <- build_item_summaries(selection_data)
first_selection <- select_featured_item(selection_summaries, as.Date("2026-07-24"))
second_selection <- select_featured_item(selection_summaries, as.Date("2026-07-24"))

stopifnot(
	first_selection$median_item_n == 2.5,
	first_selection$eligible_count == 2,
	first_selection$summary$survey_item_id %in% c(3, 4),
	first_selection$summary$survey_item_id == second_selection$summary$survey_item_id
)

escaped_data <- tibble(
	response_value = c(0, 10),
	statement_text = c("<script>alert('x')</script>", "<script>alert('x')</script>"),
	observation_date = as.Date(c("2026-07-01", "2026-07-02")),
	survey_item_id = c(99L, 99L)
)
escaped_summary <- build_item_summaries(escaped_data)
escaped_section <- build_featured_item_section(escaped_data, escaped_summary)
escaped_counts <- response_counts_for_item(escaped_data)
escaped_histogram <- build_histogram_svg(escaped_counts, escaped_summary$statement_text)

stopifnot(
	nrow(escaped_counts) == 11,
	all(escaped_counts$response_value == RESPONSE_VALUES),
	stringr::str_count(escaped_histogram, "<rect ") == 11,
	!stringr::str_detect(escaped_section, fixed("<script>")),
	stringr::str_detect(escaped_section, fixed("&lt;script&gt;")),
	stringr::str_detect(escaped_section, fixed('<blockquote class="blockquote"><p>&lt;script&gt;alert(&#39;x&#39;)&lt;/script&gt;</p></blockquote>')),
	stringr::str_locate(escaped_section, fixed("<blockquote"))[[1]] <
		stringr::str_locate(escaped_section, fixed("American adults' average (mean) response was"))[[1]],
	stringr::str_detect(escaped_section, fixed("item-results/99-script-alert-x.html")),
	stringr::str_detect(escaped_section, fixed('href="download.html"')),
	stringr::str_detect(escaped_section, fixed('href="results.html"')),
	stringr::str_count(escaped_section, '<th scope="row">') == 5
)

empty_section <- build_empty_featured_item_section()
stopifnot(
	stringr::str_detect(empty_section, fixed("Featured Item")),
	stringr::str_detect(empty_section, fixed("No Featured Item results are available yet."))
)

message("Featured Item tests passed.")
