RESPONSE_VALUES <- 0:10
RESPONSE_COLUMNS <- paste0("response", RESPONSE_VALUES)

ITEM_STOP_WORDS <- c(
	"a", "an", "and", "are", "as", "at", "be", "been", "by", "for", "from",
	"has", "have", "i", "in", "is", "it", "its", "of", "on", "or", "our",
	"should", "that", "the", "their", "there", "this", "to", "was", "were",
	"will", "with", "within", "who"
)


html_escape <- function(value) {
	value <- as.character(value)
	value <- stringr::str_replace_all(value, "&", "&amp;")
	value <- stringr::str_replace_all(value, "<", "&lt;")
	value <- stringr::str_replace_all(value, ">", "&gt;")
	value <- stringr::str_replace_all(value, '"', "&quot;")
	value <- stringr::str_replace_all(value, "'", "&#39;")
	value
}


format_count <- function(value) {
	format(value, big.mark = ",", scientific = FALSE, trim = TRUE)
}


format_number <- function(value, digits = 2) {
	if (length(value) == 0) {
		return("")
	}
	formatted <- sprintf(paste0("%.", digits, "f"), value)
	formatted[is.na(value)] <- ""
	formatted
}


slug_words <- function(statement_text, word_count = 3) {
	ascii_text <- iconv(statement_text, from = "", to = "ASCII//TRANSLIT", sub = "")
	lower_text <- stringr::str_to_lower(ascii_text)
	words <- stringr::str_extract_all(lower_text, "[a-z0-9]+")[[1]]
	words <- words[!words %in% ITEM_STOP_WORDS]
	if (length(words) == 0) {
		words <- c("item")
	}
	words[seq_len(min(word_count, length(words)))]
}


item_page_basename_one <- function(survey_item_id, statement_text) {
	words <- slug_words(statement_text)
	sprintf("%s-%s", survey_item_id, paste(words, collapse = "-"))
}


item_page_basename <- function(survey_item_id, statement_text) {
	mapply(item_page_basename_one, survey_item_id, statement_text, USE.NAMES = FALSE)
}


item_page_filename <- function(survey_item_id, statement_text) {
	paste0(item_page_basename(survey_item_id, statement_text), ".html")
}


item_display_texts <- function(data) {
	data %>%
		group_by(survey_item_id, statement_text) %>%
		summarise(
			statement_most_recent_observation_date = max(observation_date, na.rm = TRUE),
			statement_n = n(),
			.groups = "drop"
		) %>%
		arrange(survey_item_id, desc(statement_most_recent_observation_date), desc(statement_n), statement_text) %>%
		group_by(survey_item_id) %>%
		slice(1) %>%
		ungroup() %>%
		select(survey_item_id, statement_text)
}


build_item_summaries <- function(data) {
	display_texts <- item_display_texts(data)

	summaries <- data %>%
		group_by(survey_item_id) %>%
		summarise(
			mean_response = mean(response_value),
			median_response = median(response_value),
			sd_response = sd(response_value),
			n = n(),
			standard_error = sd_response / sqrt(n),
			earliest_observation_date = min(observation_date),
			most_recent_observation_date = max(observation_date),
			observation_date_count = n_distinct(observation_date),
			.groups = "drop"
		) %>%
		left_join(display_texts, by = "survey_item_id") %>%
		mutate(
			sd_response = if_else(is.na(sd_response), 0, sd_response),
			standard_error = if_else(is.na(standard_error), 0, standard_error),
			item_page_filename = item_page_filename(survey_item_id, statement_text),
			item_page_basename = item_page_basename(survey_item_id, statement_text)
		)

	qualifying <- summaries %>%
		filter(n >= 100) %>%
		arrange(desc(mean_response), desc(n), statement_text, survey_item_id) %>%
		mutate(
			qualifying_rank = row_number(),
			qualifying_total = n(),
			qualifying_percentile = if_else(
				qualifying_total == 1,
				100,
				100 * (qualifying_total - qualifying_rank) / (qualifying_total - 1)
			)
		) %>%
		select(survey_item_id, statement_text, qualifying_rank, qualifying_total, qualifying_percentile)

	summaries %>%
		left_join(qualifying, by = c("survey_item_id", "statement_text"))
}


response_counts_for_item <- function(item_data) {
	tibble(response_value = RESPONSE_VALUES) %>%
		left_join(
			item_data %>% count(response_value, name = "count"),
			by = "response_value"
		) %>%
		mutate(count = replace_na(count, 0L))
}


svg_text <- function(x, y, label, anchor = "middle", size = 12, fill = "#374151", extra = "") {
	sprintf(
		'<text x="%s" y="%s" text-anchor="%s" font-size="%s" fill="%s" %s>%s</text>',
		x,
		y,
		anchor,
		size,
		fill,
		extra,
		html_escape(label)
	)
}


build_histogram_svg <- function(counts, item_label) {
	width <- 760
	height <- 330
	margin_left <- 58
	margin_right <- 18
	margin_top <- 28
	margin_bottom <- 58
	chart_width <- width - margin_left - margin_right
	chart_height <- height - margin_top - margin_bottom
	max_count <- max(counts$count, 1)
	slot_width <- chart_width / length(RESPONSE_VALUES)
	bar_width <- max(12, slot_width - 10)

	bars <- purrr::pmap_chr(counts, function(response_value, count) {
		bar_height <- if_else(max_count > 0, chart_height * count / max_count, 0)
		x <- margin_left + response_value * slot_width + (slot_width - bar_width) / 2
		y <- margin_top + chart_height - bar_height
		sprintf(
			paste(
				'<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="#2563eb">',
				'<title>Response %s: %s</title>',
				'</rect>',
				sep = ""
			),
			x,
			y,
			bar_width,
			bar_height,
			response_value,
			format_count(count)
		)
	})

	x_labels <- purrr::map_chr(RESPONSE_VALUES, function(response_value) {
		x <- margin_left + response_value * slot_width + slot_width / 2
		svg_text(x, height - 34, response_value)
	})

	y_labels <- paste(
		svg_text(margin_left - 8, margin_top + chart_height + 4, "0", "end"),
		svg_text(margin_left - 8, margin_top + 4, format_count(max_count), "end"),
		sep = "\n"
	)

	paste(
		sprintf('<svg class="w-100 h-auto ryerson-chart" role="img" aria-label="%s" viewBox="0 0 %s %s" xmlns="http://www.w3.org/2000/svg">', html_escape(paste("All-time response histogram for", item_label)), width, height),
		sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#111827" stroke-width="1" />', margin_left, margin_top + chart_height, width - margin_right, margin_top + chart_height),
		sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#111827" stroke-width="1" />', margin_left, margin_top, margin_left, margin_top + chart_height),
		paste(bars, collapse = "\n"),
		paste(x_labels, collapse = "\n"),
		y_labels,
		svg_text(width / 2, height - 8, "Response value"),
		svg_text(14, height / 2, "Count", "middle", 12, "#374151", 'transform="rotate(-90 14 165)"'),
		'</svg>',
		sep = "\n"
	)
}


build_stats_table <- function(summary_row) {
	paste(
		'<div class="table-responsive">',
		'<table class="table table-sm align-middle">',
		'<tbody>',
		sprintf('<tr><th scope="row">Mean</th><td>%s</td></tr>', format_number(summary_row$mean_response, 2)),
		sprintf('<tr><th scope="row">Median</th><td>%s</td></tr>', format_number(summary_row$median_response, 2)),
		sprintf('<tr><th scope="row">Standard deviation</th><td>%s</td></tr>', format_number(summary_row$sd_response, 2)),
		sprintf('<tr><th scope="row">N</th><td>%s</td></tr>', format_count(summary_row$n)),
		sprintf('<tr><th scope="row">Standard error</th><td>%s</td></tr>', format_number(summary_row$standard_error, 3)),
		'</tbody>',
		'</table>',
		'</div>',
		sep = "\n"
	)
}


build_summary_sentence <- function(summary_row) {
	sprintf(
		"American adults' average (mean) response was %s on a scale of 0 (Disagree) to 10 (Agree). %s responses have been collected (so far) from %s to %s.",
		format_number(summary_row$mean_response, 2),
		format_count(summary_row$n),
		html_escape(summary_row$earliest_observation_date),
		html_escape(summary_row$most_recent_observation_date)
	)
}
