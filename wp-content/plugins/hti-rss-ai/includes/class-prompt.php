<?php
/**
 * Prompt builder for article generation.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the system + user prompts.
 */
class Prompt {

	/**
	 * System instruction (rules + output schema).
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	public static function system( string $lang ): string {
		$language = 'pt' === $lang ? 'European Portuguese (pt-PT)' : 'English';
		$today    = self::today();
		$year     = (int) substr( $today, 0, 4 );
		$cats     = self::category_rule( $lang );

		return implode(
			"\n",
			array(
				'You are a financial-news editor for an educational financial-literacy platform.',
				'Your job: from the related headlines/summaries provided, research the current facts on the web and write ONE original, neutral, educational news article.',
				'',
				"TODAY'S DATE IS {$today}. The article is published today and must read as current on that date.",
				'',
				'STRICT RULES:',
				"- Write in {$language}.",
				'- Use ONLY facts you can support from your web research. If a detail is uncertain, omit it. Never invent numbers, quotes, dates or names.',
				'- TEMPORAL FRAMING (critical): write from the perspective of today.',
				"  - Prefer the most recent data available (latest full-year results and {$year} estimates). Actively search for figures more recent than those in the source summaries — market-research summaries are often a year or two behind.",
				'  - Never present a past year as the present. Do NOT write "currently", "this year" or "now" about a figure whose base year is in the past.',
				'  - When a figure\'s base year is older than the current year, state that base year explicitly (e.g. "valued at X in 2024") instead of implying it is current.',
				"  - For forecasts/CAGR, anchor the window to today and the future (e.g. {$year}–2034), not to a window that has already started in the past.",
				'  - If you genuinely cannot find data at least as recent as the current year, say so plainly rather than dressing up old figures as current.',
				'- Original synthesis. NEVER copy the source text verbatim; rewrite in your own words and attribute sources.',
				'- Educational and impartial. NO investment advice, NO recommendations, NO "buy/sell/should", NO price targets, NO specific tickers or product/fund names.',
				'- SEO + Google News friendly: a clear, factual headline; a concise meta description (max 155 characters); a short lead (dek); a well-structured body with short paragraphs and the occasional subheading.',
				'- Include the sources you actually used.',
				$cats,
				'',
				'OUTPUT: respond with ONLY a JSON object (no markdown, no commentary) of this exact shape:',
				'{',
				'  "headline": string,',
				'  "slug": string (kebab-case, no accents),',
				'  "meta_description": string (<=155 chars),',
				'  "dek": string (1-2 sentence lead),',
				'  "body_blocks": [ { "type": "paragraph" | "heading", "text": string } ],',
				'  "suggested_category": string,',
				'  "tags": [string],',
				'  "sources": [ { "title": string, "url": string } ],',
				'  "lang": "' . $lang . '"',
				'}',
			)
		);
	}

	/**
	 * User prompt from the group's items.
	 *
	 * @param object            $group Group row.
	 * @param array<int,object> $items Group items.
	 */
	public static function user( object $group, array $items ): string {
		$lines = array();
		foreach ( $items as $item ) {
			$line = '- ' . $item->title;
			$summary = trim( (string) $item->description );
			if ( '' !== $summary ) {
				$line .= ' — ' . wp_trim_words( $summary, 45 );
			}
			if ( ! empty( $item->link ) ) {
				$line .= ' (' . $item->link . ')';
			}
			$lines[] = $line;
		}

		$today = self::today();

		return "Topic: {$group->label}\n\nRelated headlines and summaries:\n" . implode( "\n", $lines )
			. "\n\nNote: the summaries above may be a year or two old. Research the latest facts about this topic on the web as of {$today}, prefer the most recent figures you can verify, and write the article exactly as specified in the system instruction.";
	}

	/**
	 * Supported YouTube → article types.
	 *
	 * @return array<string,string>
	 */
	public static function youtube_types(): array {
		return array(
			'news'     => 'News',
			'quote'    => 'Quote',
			'tutorial' => 'Tutorial',
			'summary'  => 'Summary',
		);
	}

	/**
	 * System prompt for generating an article from a YouTube transcript.
	 *
	 * @param string $lang Language slug.
	 * @param string $type news|quote|tutorial|summary.
	 */
	public static function youtube_system( string $lang, string $type ): string {
		$language = 'pt' === $lang ? 'European Portuguese (pt-PT)' : 'English';
		$today    = self::today();
		$cats     = self::category_rule( $lang );

		$intent = array(
			'news'     => 'Write ONE original, neutral, educational news-style article about the topic discussed in the video. Synthesise the key points; do not transcribe.',
			'quote'    => 'Highlight the single most insightful idea from the speaker. Include a short faithful quote (a sentence or two) clearly attributed to the speaker/channel, then 2-4 short paragraphs of neutral educational context explaining the idea in plain language.',
			'tutorial' => 'Write an educational explainer that teaches the CONCEPTS covered in the video, structured with clear subheadings and short steps/sections. Explain ideas; never give instructions to buy or sell anything.',
			'summary'  => 'Write a concise summary followed by the key takeaways as short, scannable points (use heading + paragraph blocks). Neutral and educational.',
		);
		$kind_line = $intent[ $type ] ?? $intent['news'];

		return implode(
			"\n",
			array(
				'You are an editor for an educational financial-literacy platform. You are given the transcript of a YouTube video.',
				$kind_line,
				'',
				"TODAY'S DATE IS {$today}.",
				'',
				'STRICT RULES:',
				"- Write in {$language}.",
				'- Base the content ONLY on the transcript provided. Do not invent facts, numbers, quotes, dates or names that are not supported by the transcript.',
				'- Original wording. Except for a clearly-marked short quote (quote type only), never copy the transcript verbatim — rewrite in your own words.',
				'- Always attribute the source: name the channel and reference the video.',
				'- Educational and impartial. NO investment advice, NO recommendations, NO "buy/sell/should", NO price targets, NO specific tickers, and do NOT present any specific company/stock/fund as something to buy.',
				'- SEO friendly: a clear factual headline; a concise meta description (max 155 characters); a short lead (dek); well-structured short paragraphs with the occasional subheading.',
				'- The provided video MUST appear in "sources".',
				$cats,
				'',
				'OUTPUT: respond with ONLY a JSON object (no markdown, no commentary) of this exact shape:',
				'{',
				'  "headline": string,',
				'  "slug": string (kebab-case, no accents),',
				'  "meta_description": string (<=155 chars),',
				'  "dek": string (1-2 sentence lead),',
				'  "body_blocks": [ { "type": "paragraph" | "heading", "text": string } ],',
				'  "suggested_category": string,',
				'  "tags": [string],',
				'  "sources": [ { "title": string, "url": string } ],',
				'  "lang": "' . $lang . '"',
				'}',
			)
		);
	}

	/**
	 * User prompt: the video metadata + transcript.
	 *
	 * @param object $item       Item row (title, source, link).
	 * @param string $transcript Transcript text.
	 * @param string $type       Content type.
	 */
	public static function youtube_user( object $item, string $transcript, string $type ): string {
		// Cap the transcript to keep the request within a sane size.
		$transcript = trim( $transcript );
		if ( strlen( $transcript ) > 24000 ) {
			$transcript = substr( $transcript, 0, 24000 ) . ' […]';
		}
		$channel = (string) ( $item->source ?? '' );
		$title   = (string) ( $item->title ?? '' );
		$link    = (string) ( $item->link ?? '' );

		return "Content type: {$type}\n"
			. "Video title: {$title}\n"
			. "Channel: {$channel}\n"
			. "Video URL: {$link}\n\n"
			. "Transcript:\n\"\"\"\n{$transcript}\n\"\"\"\n\n"
			. 'Write the article exactly as specified in the system instruction, and include this video in "sources".';
	}

	/**
	 * Prompt for the featured-image photo (Imagen).
	 *
	 * Conceptual editorial illustration only — no text, no logos, no real
	 * people, no readable charts. Matches the dark navy + coral card it sits in.
	 *
	 * @param array<string,mixed> $data Validated article (headline/tags/category).
	 */
	public static function image_prompt( array $data ): string {
		$headline = trim( (string) ( $data['headline'] ?? '' ) );
		$topic    = trim( (string) ( $data['suggested_category'] ?? '' ) );
		$subject  = '' !== $topic ? $topic . ' — ' . $headline : $headline;

		return 'Editorial conceptual illustration for a financial-news article about: "' . $subject . '". '
			. 'Modern, clean, professional finance and markets theme. Cinematic soft lighting, subtle depth. '
			. 'Colour palette: deep navy blue (#1C2150) with warm coral (#FF6B5E) accents. '
			. 'Absolutely NO text, NO words, NO letters, NO numbers, NO logos, NO watermarks, NO readable charts. '
			. 'No real or recognizable people. Abstract or symbolic imagery is preferred. Wide 16:9 composition.';
	}

	/**
	 * Prompt for image-to-image: reimagine the feed image into the branded
	 * editorial illustration, keeping the general subject/scene.
	 *
	 * @param array<string,mixed> $data Validated article (headline/category).
	 */
	public static function image_edit_prompt( array $data ): string {
		$headline = trim( (string) ( $data['headline'] ?? '' ) );
		$topic    = trim( (string) ( $data['suggested_category'] ?? '' ) );
		$subject  = '' !== $topic ? $topic . ' — ' . $headline : $headline;

		return 'Using the attached photo as the base/reference, create a clean, modern editorial illustration '
			. 'for a financial-news article about: "' . $subject . '". '
			. 'Keep the general subject and scene of the photo, but restyle it: cinematic, professional, soft lighting. '
			. 'Colour palette: deep navy blue (#1C2150) with warm coral (#FF6B5E) accents. '
			. 'Absolutely NO text, NO words, NO letters, NO numbers, NO logos, NO watermarks, NO readable charts. '
			. 'No recognizable real people. Wide 16:9 composition.';
	}

	/**
	 * Build the "choose a category" instruction from the news_category terms
	 * that exist in the article's language. Returns '' (no constraint) when the
	 * taxonomy or its terms aren't available, so generation never breaks.
	 *
	 * @param string $lang 'en' or 'pt'.
	 */
	private static function category_rule( string $lang ): string {
		if ( ! function_exists( 'get_terms' ) || ! taxonomy_exists( 'news_category' ) ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'news_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		// Prefer terms in the article's language when Polylang exposes it;
		// otherwise list every term name (both languages co-exist in the list).
		$names = array();
		foreach ( $terms as $term ) {
			if ( function_exists( 'pll_get_term_language' ) ) {
				$tl = pll_get_term_language( (int) $term->term_id );
				if ( $tl && $tl !== $lang ) {
					continue;
				}
			}
			$names[] = $term->name;
		}
		if ( empty( $names ) ) {
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
		}
		$names = array_values( array_unique( $names ) );

		return '- CATEGORY: set "suggested_category" to EXACTLY one of these existing category names, copied verbatim (do not invent new ones): '
			. implode( '; ', $names ) . '.';
	}

	/**
	 * Current date in the site's timezone, formatted for the prompt.
	 *
	 * @return string e.g. "2026-06-19 (19 June 2026)".
	 */
	private static function today(): string {
		if ( function_exists( 'wp_date' ) ) {
			$iso   = (string) wp_date( 'Y-m-d' );
			$human = (string) wp_date( 'j F Y' );
		} else {
			$iso   = gmdate( 'Y-m-d' );
			$human = gmdate( 'j F Y' );
		}
		return "{$iso} ({$human})";
	}
}
