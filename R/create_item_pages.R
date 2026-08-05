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

CHART_COLORS <- c(
	"#7f1d1d", "#b91c1c", "#ea580c", "#f59e0b", "#d6a330", "#6b7280",
	"#5b8def", "#2563eb", "#047857", "#16a34a", "#14532d"
)


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


clean_item_dictionary_dir <- function(item_dictionary_dir) {
	if (!dir.exists(item_dictionary_dir)) {
		dir.create(item_dictionary_dir, recursive = TRUE)
		return()
	}

	stale_files <- list.files(item_dictionary_dir, pattern = "\\.json$", full.names = TRUE)
	if (length(stale_files) > 0) {
		unlink(stale_files)
	}
}


valid_item_data <- function(canonical_data) {
	valid_data <- canonical_data %>%
		filter(
			response_value %in% RESPONSE_VALUES,
			!is.na(statement_text),
			!is.na(survey_item_id),
			!is.na(observation_date)
		)

	invalid_count <- nrow(canonical_data) - nrow(valid_data)
	if (invalid_count > 0) {
		log_message(sprintf("Excluded %d row(s) from item pages because required item page values were missing or invalid.", invalid_count))
	}

	valid_data
}


monthly_counts_for_item <- function(item_data) {
	item_data %>%
		mutate(observation_month = format(observation_date, "%Y-%m")) %>%
		count(observation_month, response_value, name = "count") %>%
		group_by(observation_month) %>%
		tidyr::complete(response_value = RESPONSE_VALUES, fill = list(count = 0L)) %>%
		ungroup() %>%
		left_join(
			item_data %>%
				mutate(observation_month = format(observation_date, "%Y-%m")) %>%
				group_by(observation_month) %>%
				summarise(monthly_mean = mean(response_value), month_n = n(), .groups = "drop"),
			by = "observation_month"
		) %>%
		mutate(percent = if_else(month_n > 0, 100 * count / month_n, 0)) %>%
		arrange(observation_month, response_value)
}


build_monthly_trend_svg <- function(monthly_counts, item_label) {
	months <- unique(monthly_counts$observation_month)
	month_count <- length(months)
	if (month_count == 0) {
		return('<div class="alert alert-info" role="status">No monthly trend is available yet.</div>')
	}

	width <- max(760, 150 + month_count * 78)
	height <- 430
	margin_left <- 64
	margin_right <- 64
	margin_top <- 34
	margin_bottom <- 86
	chart_width <- width - margin_left - margin_right
	chart_height <- height - margin_top - margin_bottom
	slot_width <- chart_width / month_count
	bar_width <- max(26, slot_width * 0.62)

	stack_rects <- c()
	mean_points <- c()
	month_labels <- c()

	for (month_index in seq_along(months)) {
		month <- months[[month_index]]
		month_data <- monthly_counts %>% filter(observation_month == month)
		x <- margin_left + (month_index - 1) * slot_width + (slot_width - bar_width) / 2
		y_bottom <- margin_top + chart_height

		for (response_value in RESPONSE_VALUES) {
			this_row <- month_data %>% filter(response_value == !!response_value)
			percent <- this_row$percent[[1]]
			rect_height <- chart_height * percent / 100
			y_bottom <- y_bottom - rect_height
			stack_rects <- c(
				stack_rects,
				sprintf(
					paste(
						'<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s">',
						'<title>%s response %s: %.1f%%</title>',
						'</rect>',
						sep = ""
					),
					x,
					y_bottom,
					bar_width,
					rect_height,
					CHART_COLORS[[response_value + 1]],
					html_escape(month),
					response_value,
					percent
				)
			)
		}

		monthly_mean <- unique(month_data$monthly_mean)[[1]]
		point_x <- x + bar_width / 2
		point_y <- margin_top + chart_height - chart_height * monthly_mean / 10
		mean_points <- c(mean_points, sprintf("%.2f,%.2f", point_x, point_y))
		month_label <- format(as.Date(paste0(month, "-01")), "%b %Y")
		month_labels <- c(
			month_labels,
			svg_text(point_x, height - 52, month_label, "end", 11, "#374151", sprintf('transform="rotate(-35 %.2f %s)"', point_x, height - 52))
		)
	}

	mean_circles <- purrr::map_chr(mean_points, function(point) {
		parts <- strsplit(point, ",", fixed = TRUE)[[1]]
		sprintf('<circle cx="%s" cy="%s" r="4.5" fill="#000000" />', parts[[1]], parts[[2]])
	})

	legend <- purrr::map_chr(RESPONSE_VALUES, function(response_value) {
		x <- margin_left + response_value * 54
		y <- 10
		sprintf(
			'<rect x="%s" y="%s" width="12" height="12" fill="%s" /><text x="%s" y="%s" font-size="11" fill="#374151">%s</text>',
			x,
			y,
			CHART_COLORS[[response_value + 1]],
			x + 17,
			y + 10,
			response_value
		)
	})

	paste(
		sprintf('<svg class="w-100 h-auto ryerson-chart" role="img" aria-label="%s" viewBox="0 0 %s %s" xmlns="http://www.w3.org/2000/svg">', html_escape(paste("Monthly response trend for", item_label)), width, height),
		paste(legend, collapse = "\n"),
		sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#111827" stroke-width="1" />', margin_left, margin_top + chart_height, width - margin_right, margin_top + chart_height),
		sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#111827" stroke-width="1" />', margin_left, margin_top, margin_left, margin_top + chart_height),
		sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#111827" stroke-width="1" />', width - margin_right, margin_top, width - margin_right, margin_top + chart_height),
		svg_text(margin_left - 8, margin_top + chart_height + 4, "0%", "end"),
		svg_text(margin_left - 8, margin_top + chart_height / 2 + 4, "50%", "end"),
		svg_text(margin_left - 8, margin_top + 4, "100%", "end"),
		svg_text(width - margin_right + 8, margin_top + chart_height + 4, "0", "start"),
		svg_text(width - margin_right + 8, margin_top + chart_height / 2 + 4, "5", "start"),
		svg_text(width - margin_right + 8, margin_top + 4, "10", "start"),
		paste(stack_rects, collapse = "\n"),
		if (length(mean_points) > 1) sprintf('<polyline points="%s" fill="none" stroke="#000000" stroke-width="2" />', paste(mean_points, collapse = " ")) else "",
		paste(mean_circles, collapse = "\n"),
		paste(month_labels, collapse = "\n"),
		svg_text(width / 2, height - 8, "Observation month"),
		svg_text(16, height / 2, "Percent of responses", "middle", 12, "#374151", sprintf('transform="rotate(-90 16 %s)"', height / 2)),
		svg_text(width - 16, height / 2, "Monthly mean", "middle", 12, "#374151", sprintf('transform="rotate(90 %s %s)"', width - 16, height / 2)),
		'</svg>',
		sep = "\n"
	)
}


build_comparison_section <- function(summary_row) {
	if (summary_row$n < 100 || is.na(summary_row$qualifying_rank)) {
		return(paste(
			'<div class="alert alert-info" role="status">',
			'This item does not yet have 100 total observations, so item-to-item rank comparison is not shown.',
			'</div>',
			sep = "\n"
		))
	}

	sprintf(
		paste(
			'<p>Based on all-time mean agreement, this item ranks <strong>%s</strong> out of <strong>%s</strong> qualifying items and is at the <strong>%s percentile</strong>.</p>',
			'<p class="text-body-secondary">Qualifying items have 100 or more total observations. Higher means indicate more agreement and therefore a higher rank.</p>',
			sep = "\n"
		),
		format_count(summary_row$qualifying_rank),
		format_count(summary_row$qualifying_total),
		format_number(summary_row$qualifying_percentile, 1)
	)
}


build_annual_trend_section <- function(item_data, summary_row) {
	if (summary_row$n < 100 || summary_row$observation_date_count <= 1) {
		return(paste(
			'<div class="alert alert-info" role="status">',
			'Annual trend estimation is shown once this item has 100 or more observations across more than one observation date.',
			'</div>',
			sep = "\n"
		))
	}

	model_data <- item_data %>%
		mutate(days_since_start = as.numeric(observation_date - min(observation_date)))

	trend <- tryCatch({
		model <- lm(response_value ~ days_since_start, data = model_data)
		daily_estimate <- unname(coef(model)[["days_since_start"]])
		daily_confidence_interval <- unname(confint(model, "days_since_start", level = 0.95))
		observed_days <- max(model_data$days_since_start)
		period_estimate <- daily_estimate * observed_days
		period_confidence_interval <- daily_confidence_interval * observed_days
		annualized_estimate <- daily_estimate * 365.25
		annualized_confidence_interval <- daily_confidence_interval * 365.25
		direction <- if (period_confidence_interval[[1]] <= 0 && period_confidence_interval[[2]] >= 0) {
			"unchanging"
		} else if (period_estimate > 0) {
			"increasing"
		} else {
			"decreasing"
		}
		regression_table <- paste(capture.output(summary(model)), collapse = "\n")
		list(
			period_estimate = period_estimate,
			period_confidence_interval = period_confidence_interval,
			annualized_estimate = annualized_estimate,
			annualized_confidence_interval = annualized_confidence_interval,
			start_date = min(model_data$observation_date),
			end_date = max(model_data$observation_date),
			direction = direction,
			regression_table = regression_table
		)
	}, error = function(error) {
		log_message(sprintf("Could not estimate annual trend for survey_item_id %s: %s", summary_row$survey_item_id, error$message))
		NULL
	})

	if (is.null(trend)) {
		return(paste(
			'<div class="alert alert-warning" role="status">',
			'Annual trend estimation could not be completed for this item.',
			'</div>',
			sep = "\n"
		))
	}

	paste(
		sprintf(
			"Observations suggest %s agreement over the observed period, from %s to %s. The estimated change over that period is %s points on a 0 to 10 scale. Regression results place a 95%% confidence interval around that observed-period change of [%s, %s].",
			trend$direction,
			html_escape(trend$start_date),
			html_escape(trend$end_date),
			format_number(trend$period_estimate, 3),
			format_number(trend$period_confidence_interval[[1]], 3),
			format_number(trend$period_confidence_interval[[2]], 3)
		),
		'<details class="mt-3">',
		'<summary class="link-primary">Regression table</summary>',
		sprintf(
			'<p class="mt-2 mb-2 text-body-secondary">The daily slope annualized over 365.25 days is %s points per year, with a 95%% confidence interval of [%s, %s]. This short-window annualized number is provided for reference, but the observed-period change above is the primary trend estimate.</p>',
			format_number(trend$annualized_estimate, 3),
			format_number(trend$annualized_confidence_interval[[1]], 3),
			format_number(trend$annualized_confidence_interval[[2]], 3)
		),
		sprintf('<pre class="mt-2 p-3 bg-body-tertiary border rounded overflow-auto">%s</pre>', html_escape(trend$regression_table)),
		'</details>',
		sep = "\n"
	)
}


write_item_dictionary <- function(item_data, summary_row, output_path) {
	counts <- response_counts_for_item(item_data)
	monthly_counts <- monthly_counts_for_item(item_data)

	dictionary <- list(
		ITEM_ID = as.character(summary_row$survey_item_id),
		ITEM_TEXT = html_escape(summary_row$statement_text),
		ITEM_PAGE_TITLE = html_escape(sprintf("Ryerson Project Item %s Report", summary_row$survey_item_id)),
		ITEM_PAGE_DESCRIPTION = html_escape(sprintf("Results report for Ryerson Project survey item: %s", summary_row$statement_text)),
		ITEM_HISTOGRAM_SVG = build_histogram_svg(counts, summary_row$statement_text),
		ITEM_STATS_TABLE = build_stats_table(summary_row),
		ITEM_SUMMARY_SENTENCE = build_summary_sentence(summary_row),
		ITEM_COMPARISON_SECTION = build_comparison_section(summary_row),
		ITEM_MONTHLY_TREND_SVG = build_monthly_trend_svg(monthly_counts, summary_row$statement_text),
		ITEM_ANNUAL_TREND_SECTION = build_annual_trend_section(item_data, summary_row)
	)

	jsonlite::write_json(dictionary, output_path, auto_unbox = FALSE, pretty = TRUE)
}


build_index_table <- function(summaries) {
	if (nrow(summaries) == 0) {
		return('<div class="alert alert-info" role="status">No item reports are available yet.</div>')
	}

	rows <- summaries %>%
		arrange(survey_item_id, statement_text) %>%
		mutate(
			row_html = sprintf(
				paste(
					'<tr>',
					'<td class="text-end">%s</td>',
					'<td>%s</td>',
					'<td class="text-end">%s</td>',
					'<td class="text-end">%s</td>',
					'<td><a href="%s">Report</a></td>',
					'</tr>',
					sep = "\n"
				),
				survey_item_id,
				html_escape(statement_text),
				format_number(mean_response, 2),
				format_count(n),
				html_escape(item_page_filename)
			)
		) %>%
		pull(row_html)

	paste(
		'<div class="table-responsive">',
		'<table class="table table-striped table-hover align-middle">',
		'<caption>Static result reports for each Ryerson Project item.</caption>',
		'<thead class="table-light">',
		'<tr>',
		'<th scope="col" class="text-end">Item ID</th>',
		'<th scope="col">Statement</th>',
		'<th scope="col" class="text-end">Agreement</th>',
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


write_index_dictionary <- function(summaries, output_path) {
	dictionary <- list(
		ITEM_COUNT = format_count(nrow(summaries)),
		ITEM_INDEX_TABLE = build_index_table(summaries)
	)

	jsonlite::write_json(dictionary, output_path, auto_unbox = FALSE, pretty = TRUE)
}


create_item_pages <- function() {
	root_dir <- project_root()
	canonical_path <- file.path(root_dir, "website", "data", "ryerson.csv.gz")
	item_dictionary_dir <- file.path(root_dir, "json", "items")

	log_message(sprintf("Reading canonical data file from %s.", canonical_path))
	canonical_data <- read_canonical_data(canonical_path)
	data <- valid_item_data(canonical_data)
	summaries <- build_item_summaries(data)

	clean_item_dictionary_dir(item_dictionary_dir)

	for (row_number in seq_len(nrow(summaries))) {
		summary_row <- summaries[row_number, ]
		item_data <- data %>%
			filter(survey_item_id == summary_row$survey_item_id)
		output_path <- file.path(item_dictionary_dir, paste0(summary_row$item_page_basename, ".json"))
		write_item_dictionary(item_data, summary_row, output_path)
	}

	write_index_dictionary(summaries, file.path(item_dictionary_dir, "index.json"))
	log_message(sprintf("Wrote %d item dictionary file(s) and index dictionary to %s.", nrow(summaries), item_dictionary_dir))
}


create_item_pages()
