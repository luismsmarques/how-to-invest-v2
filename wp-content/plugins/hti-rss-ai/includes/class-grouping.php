<?php
/**
 * Clustering of similar draft items into groups.
 *
 * Single-pass single-link clustering per language over the ungrouped ("new")
 * items. Similarity is an IDF-weighted Jaccard on normalized title+description
 * tokens (rare tokens — names, years — count more), and an item joins the
 * cluster it is most similar to by its best member (not a drifting token
 * union). Four-digit years are kept; the threshold comes from Settings. Only
 * clusters of 2+ items become groups. Cheap, deterministic, no external calls.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds groups from ungrouped items.
 */
class Grouping {

	/**
	 * Max items considered per run/language (caps the O(n²) comparison).
	 */
	private const MAX_ITEMS = 500;

	/**
	 * Cluster ungrouped items.
	 *
	 * @return array{groups:int,items:int}
	 */
	public static function run(): array {
		$threshold = (float) Settings::get( 'similarity_threshold', 0.5 );
		$report    = array(
			'groups' => 0,
			'items'  => 0,
		);

		foreach ( Settings::languages() as $lang ) {
			$items = Items::query(
				array(
					'status'   => 'new',
					'lang'     => $lang,
					'per_page' => self::MAX_ITEMS,
					'offset'   => 0,
				)
			);
			if ( count( $items ) < 2 ) {
				continue;
			}

			$tokens = array();
			foreach ( $items as $item ) {
				$tokens[ (int) $item->id ] = self::tokenize( $item->title . ' ' . $item->description );
			}

			// IDF weighting: rare tokens (a name, a number) carry more signal than
			// common ones, so two stories sharing them count as more similar.
			$idf = self::idf( $tokens );

			// Single-link clustering: an item joins the cluster it is most similar
			// to (best similarity to ANY member, not the diluted token union).
			$clusters = array();
			foreach ( $items as $item ) {
				$id  = (int) $item->id;
				$set = $tokens[ $id ];
				if ( ! $set ) {
					continue;
				}
				$best     = -1;
				$best_sim = 0.0;
				foreach ( $clusters as $index => $cluster ) {
					$sim = 0.0;
					foreach ( $cluster['members'] as $member ) {
						$s = self::weighted_sim( $set, $member, $idf );
						if ( $s > $sim ) {
							$sim = $s;
						}
					}
					if ( $sim > $best_sim ) {
						$best_sim = $sim;
						$best     = $index;
					}
				}
				if ( $best >= 0 && $best_sim >= $threshold ) {
					$clusters[ $best ]['ids'][]     = $id;
					$clusters[ $best ]['members'][] = $set;
					$clusters[ $best ]['sims'][]    = $best_sim;
				} else {
					$clusters[] = array(
						'ids'     => array( $id ),
						'members' => array( $set ),
						'sims'    => array(),
					);
				}
			}

			foreach ( $clusters as $cluster ) {
				if ( count( $cluster['ids'] ) < 2 ) {
					continue;
				}
				$score = $cluster['sims'] ? array_sum( $cluster['sims'] ) / count( $cluster['sims'] ) : 0.0;
				$gid   = Groups::insert(
					array(
						'label'  => self::label( $cluster['ids'], $items ),
						'lang'   => $lang,
						'status' => 'open',
						'score'  => $score,
						'size'   => count( $cluster['ids'] ),
					)
				);
				if ( $gid ) {
					Items::set_group( $cluster['ids'], $gid );
					++$report['groups'];
					$report['items'] += count( $cluster['ids'] );
				}
			}
		}

		Logger::log( 'group', sprintf( 'groups=%d items=%d', $report['groups'], $report['items'] ) );
		return $report;
	}

	/**
	 * Normalize text into a set of significant tokens. Public for testing.
	 *
	 * @param string $text Title + description.
	 * @return array<string,bool>
	 */
	public static function tokenize( string $text ): array {
		$text  = strtolower( remove_accents( $text ) );
		$text  = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		$stop  = self::stopwords();
		$set   = array();
		foreach ( (array) $words as $word ) {
			if ( isset( $stop[ $word ] ) ) {
				continue;
			}
			if ( ctype_digit( $word ) ) {
				// Keep 4-digit years (a strong story signal); drop other bare numbers.
				if ( ! preg_match( '/^(19|20)\d\d$/', $word ) ) {
					continue;
				}
			} elseif ( strlen( $word ) < 3 ) {
				continue;
			}
			$set[ $word ] = true;
		}
		return $set;
	}

	/**
	 * Inverse document frequency per token across the batch. Public for testing.
	 *
	 * @param array<int,array<string,bool>> $docs Token sets keyed by item id.
	 * @return array<string,float>
	 */
	public static function idf( array $docs ): array {
		$n  = max( 1, count( $docs ) );
		$df = array();
		foreach ( $docs as $set ) {
			foreach ( $set as $token => $_ ) {
				$df[ $token ] = ( $df[ $token ] ?? 0 ) + 1;
			}
		}
		$idf = array();
		foreach ( $df as $token => $count ) {
			$idf[ $token ] = log( 1 + ( $n / $count ) );
		}
		return $idf;
	}

	/**
	 * IDF-weighted Jaccard similarity of two token sets. Public for testing.
	 *
	 * @param array<string,bool>  $a   First set.
	 * @param array<string,bool>  $b   Second set.
	 * @param array<string,float> $idf Token weights.
	 */
	public static function weighted_sim( array $a, array $b, array $idf ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$inter = 0.0;
		$union = 0.0;
		foreach ( $a as $token => $_ ) {
			$w      = $idf[ $token ] ?? 0.0;
			$union += $w;
			if ( isset( $b[ $token ] ) ) {
				$inter += $w;
			}
		}
		foreach ( $b as $token => $_ ) {
			if ( ! isset( $a[ $token ] ) ) {
				$union += $idf[ $token ] ?? 0.0;
			}
		}
		return $union > 0 ? $inter / $union : 0.0;
	}

	/**
	 * Jaccard similarity of two token sets. Public for testing.
	 *
	 * @param array<string,bool> $a First set.
	 * @param array<string,bool> $b Second set.
	 */
	public static function jaccard( array $a, array $b ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$intersection = count( array_intersect_key( $a, $b ) );
		if ( 0 === $intersection ) {
			return 0.0;
		}
		$union = count( $a + $b );
		return $union ? $intersection / $union : 0.0;
	}

	/**
	 * Pick the most descriptive (longest) title as the group label.
	 *
	 * @param array<int,int>    $ids   Item ids in the cluster.
	 * @param array<int,object> $items All items.
	 */
	private static function label( array $ids, array $items ): string {
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item->id ] = $item;
		}
		$label = '';
		foreach ( $ids as $id ) {
			$title = (string) ( $by_id[ $id ]->title ?? '' );
			if ( mb_strlen( $title ) > mb_strlen( $label ) ) {
				$label = $title;
			}
		}
		return $label;
	}

	/**
	 * Small EN + PT stopword set.
	 *
	 * @return array<string,bool>
	 */
	private static function stopwords(): array {
		static $stop = null;
		if ( null !== $stop ) {
			return $stop;
		}
		$words = array(
			// EN.
			'the', 'and', 'for', 'are', 'with', 'that', 'this', 'from', 'have', 'has', 'was', 'will',
			'its', 'their', 'about', 'into', 'than', 'then', 'they', 'them', 'were', 'over', 'after',
			'what', 'when', 'which', 'while', 'your', 'you', 'our', 'not', 'but', 'can', 'could', 'would',
			'should', 'how', 'why', 'who', 'all', 'any', 'more', 'most', 'new', 'now', 'one', 'two',
			// PT.
			'que', 'com', 'para', 'uma', 'dos', 'das', 'por', 'mais', 'como', 'mas', 'foi', 'são', 'sao',
			'pelo', 'pela', 'isso', 'este', 'esta', 'esse', 'essa', 'seu', 'sua', 'nos', 'nas', 'aos',
			'sobre', 'entre', 'ser', 'ter', 'até', 'ate', 'também', 'tambem', 'sem', 'já',
		);
		$stop = array_fill_keys( $words, true );
		return $stop;
	}
}
