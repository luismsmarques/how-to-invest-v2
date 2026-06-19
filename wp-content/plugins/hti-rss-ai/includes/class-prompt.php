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
