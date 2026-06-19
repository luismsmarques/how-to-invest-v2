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

		return implode(
			"\n",
			array(
				'You are a financial-news editor for an educational financial-literacy platform.',
				'Your job: from the related headlines/summaries provided, research the current facts on the web and write ONE original, neutral, educational news article.',
				'',
				'STRICT RULES:',
				"- Write in {$language}.",
				'- Use ONLY facts you can support from your web research. If a detail is uncertain, omit it. Never invent numbers, quotes, dates or names.',
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

		return "Topic: {$group->label}\n\nRelated headlines and summaries:\n" . implode( "\n", $lines )
			. "\n\nResearch the current facts about this topic on the web, then write the article exactly as specified in the system instruction.";
	}
}
