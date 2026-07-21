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
