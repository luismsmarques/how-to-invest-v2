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
	 * Two-stage, per language: (1) each "new" item first tries to join a
	 * recently-active *existing* open group (so a story that keeps getting
	 * coverage across fetch cycles grows one group instead of spawning
	 * duplicates); (2) items that match no existing group are single-link
	 * clustered among themselves, and clusters of 2+ become new groups.
	 *
	 * @return array{groups:int,items:int,joined:int}
	 */
	public static function run(): array {
		$threshold = (float) Settings::get( 'similarity_threshold', 0.4 );
		$open_days = max( 1, (int) Settings::get( 'open_max_days', 14 ) );
		$use_emb   = ! empty( Settings::get( 'enable_embeddings', 0 ) );
		$emb_thr   = (float) Settings::get( 'embedding_threshold', 0.82 );
		$report    = array(
			'groups' => 0,
			'items'  => 0,
			'joined' => 0,
		);

		foreach ( Settings::languages() as $lang ) {
			// Make sure new items in this language have embeddings before we group.
			if ( $use_emb && class_exists( __NAMESPACE__ . '\\Embeddings' ) ) {
				Embeddings::backfill( $lang, (int) Settings::get( 'embed_max_per_run', 200 ) );
			}

			$items = Items::query(
				array(
					'status'   => 'new',
					'lang'     => $lang,
					'per_page' => self::MAX_ITEMS,
					'offset'   => 0,
				)
			);
			if ( count( $items ) < 1 ) {
				continue;
			}

			$tokens = array();
			$vecs   = array();
			foreach ( $items as $item ) {
				$id            = (int) $item->id;
				$tokens[ $id ] = self::tokenize( $item->title . ' ' . $item->description );
				$vecs[ $id ]   = $use_emb ? self::decode_vector( $item->embedding ?? '' ) : null;
			}

			// Existing recent open groups for this language and their member token
			// sets (and vectors), so new items can attach to an in-progress story.
			$existing = self::load_open_groups( $lang, $open_days, $use_emb );

			// IDF weighting over BOTH existing members and the new batch, so token
			// weights are stable whether an item matches an old group or a new one.
			$docs = $tokens;
			foreach ( $existing as $group ) {
				foreach ( $group['members'] as $member ) {
					$docs[] = $member;
				}
			}
			$idf = self::idf( $docs );

			// Stage 1 — attach new items to an existing open group when close enough
			// (lexically OR semantically).
			$joins     = array();
			$unmatched = array();
			foreach ( $items as $item ) {
				$id  = (int) $item->id;
				$set = $tokens[ $id ];
				if ( ! $set ) {
					continue;
				}
				$match = $existing
					? self::best_group_hybrid( $set, $vecs[ $id ], $existing, $idf, $threshold, $emb_thr )
					: array(
						'index'     => -1,
						'sim'       => 0.0,
						'qualifies' => false,
					);
				if ( $match['qualifies'] && $match['index'] >= 0 ) {
					$gid             = (int) $existing[ $match['index'] ]['gid'];
					$joins[ $gid ][] = $id;
					// Let later items in this batch also match on this item.
					$existing[ $match['index'] ]['members'][] = $set;
					$existing[ $match['index'] ]['vecs'][]    = $vecs[ $id ];
				} else {
					$unmatched[] = $item;
				}
			}

			foreach ( $joins as $gid => $ids ) {
				Items::set_group( $ids, (int) $gid );
				Groups::recount( (int) $gid, true );
				$report['joined'] += count( $ids );
				$report['items']  += count( $ids );
			}

			// Stage 2 — single-link clustering over the still-unmatched items.
			if ( count( $unmatched ) < 2 ) {
				continue;
			}
			$clusters = array();
			foreach ( $unmatched as $item ) {
				$id  = (int) $item->id;
				$set = $tokens[ $id ];
				if ( ! $set ) {
					continue;
				}
				$vec   = $vecs[ $id ];
				$match = $clusters
					? self::best_group_hybrid( $set, $vec, $clusters, $idf, $threshold, $emb_thr )
					: array(
						'index'     => -1,
						'sim'       => 0.0,
						'qualifies' => false,
					);
				if ( $match['qualifies'] && $match['index'] >= 0 ) {
					$clusters[ $match['index'] ]['ids'][]     = $id;
					$clusters[ $match['index'] ]['members'][] = $set;
					$clusters[ $match['index'] ]['vecs'][]    = $vec;
					$clusters[ $match['index'] ]['sims'][]    = $match['sim'];
				} else {
					$clusters[] = array(
						'ids'     => array( $id ),
						'members' => array( $set ),
						'vecs'    => array( $vec ),
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

		Logger::log( 'group', sprintf( 'groups=%d joined=%d items=%d', $report['groups'], $report['joined'], $report['items'] ) );
		return $report;
	}

	/**
	 * Decode a stored embedding (JSON array of floats) to a numeric vector, or
	 * null when absent/invalid.
	 *
	 * @param string $json Stored embedding.
	 * @return array<int,float>|null
	 */
	private static function decode_vector( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}
		$vec = json_decode( $json, true );
		if ( ! is_array( $vec ) || ! $vec ) {
			return null;
		}
		return array_map( 'floatval', array_values( $vec ) );
	}

	/**
	 * Load recent open groups for a language with their member token sets, so
	 * the grouper can compare a new item against in-progress stories.
	 *
	 * @param string $lang      Language code.
	 * @param int    $open_days Recency window in days.
	 * @param bool   $with_vecs Also load member embedding vectors.
	 * @return array<int,array{gid:int,members:array<int,array<string,bool>>,vecs:array<int,array<int,float>|null>}>
	 */
	private static function load_open_groups( string $lang, int $open_days, bool $with_vecs = false ): array {
		$groups = Groups::open_recent( $lang, $open_days );
		$out    = array();
		foreach ( $groups as $group ) {
			$members = array();
			$vecs    = array();
			foreach ( Groups::items( (int) $group->id ) as $member ) {
				$set = self::tokenize( $member->title . ' ' . $member->description );
				if ( $set ) {
					$members[] = $set;
					$vecs[]    = $with_vecs ? self::decode_vector( $member->embedding ?? '' ) : null;
				}
			}
			if ( $members ) {
				$out[] = array(
					'gid'     => (int) $group->id,
					'members' => $members,
					'vecs'    => $vecs,
				);
			}
		}
		return $out;
	}

	/**
	 * Best-matching group for a token set: the group whose closest member has
	 * the highest weighted similarity. Pure; testable.
	 *
	 * @param array<string,bool>                                                  $set    Candidate token set.
	 * @param array<int,array{gid:int,members:array<int,array<string,bool>>}>      $groups Groups with member token sets.
	 * @param array<string,float>                                                  $idf    Token weights.
	 * @return array{index:int,sim:float} Index into $groups (-1 if none) and its similarity.
	 */
	public static function best_group( array $set, array $groups, array $idf ): array {
		$best     = -1;
		$best_sim = 0.0;
		foreach ( $groups as $index => $group ) {
			$sim = 0.0;
			foreach ( $group['members'] as $member ) {
				$s = self::weighted_sim( $set, $member, $idf );
				if ( $s > $sim ) {
					$sim = $s;
				}
			}
			if ( $sim > $best_sim ) {
				$best_sim = $sim;
				$best     = (int) $index;
			}
		}
		return array(
			'index' => $best,
			'sim'   => $best_sim,
		);
	}

	/**
	 * Best-matching group using both signals: a group qualifies when any member
	 * is close enough lexically (weighted Jaccard ≥ $threshold) OR semantically
	 * (cosine of embeddings ≥ $emb_threshold). Among qualifying groups the one
	 * with the highest combined rank wins. Reduces to the lexical matcher when
	 * no vectors are supplied. Pure; testable.
	 *
	 * @param array<string,bool>                                                                     $set          Candidate token set.
	 * @param array<int,float>|null                                                                  $vec          Candidate embedding, or null.
	 * @param array<int,array{gid?:int,members:array<int,array<string,bool>>,vecs?:array<int,mixed>}> $groups       Groups with member tokens/vecs.
	 * @param array<string,float>                                                                     $idf          Token weights.
	 * @param float                                                                                   $threshold    Lexical join threshold.
	 * @param float                                                                                   $emb_threshold Cosine join threshold.
	 * @return array{index:int,sim:float,qualifies:bool}
	 */
	public static function best_group_hybrid( array $set, ?array $vec, array $groups, array $idf, float $threshold, float $emb_threshold ): array {
		$best      = -1;
		$best_rank = -1.0;
		$qualifies = false;
		foreach ( $groups as $index => $group ) {
			$group_rank = -1.0;
			$group_qual = false;
			$mvecs      = $group['vecs'] ?? array();
			foreach ( $group['members'] as $mi => $member ) {
				$lex     = self::weighted_sim( $set, $member, $idf );
				$has_cos = is_array( $vec ) && isset( $mvecs[ $mi ] ) && is_array( $mvecs[ $mi ] );
				$cos     = $has_cos ? self::cosine( $vec, $mvecs[ $mi ] ) : 0.0;
				$rank    = max( $lex, $has_cos ? $cos : 0.0 );
				if ( $rank > $group_rank ) {
					$group_rank = $rank;
				}
				if ( $lex >= $threshold || ( $has_cos && $cos >= $emb_threshold ) ) {
					$group_qual = true;
				}
			}
			if ( $group_qual && $group_rank > $best_rank ) {
				$best_rank = $group_rank;
				$best      = (int) $index;
				$qualifies = true;
			}
		}
		return array(
			'index'     => $best,
			'sim'       => $best_rank < 0 ? 0.0 : $best_rank,
			'qualifies' => $qualifies,
		);
	}

	/**
	 * Cosine similarity of two equal-length numeric vectors. Pure; testable.
	 *
	 * @param array<int,float> $a First vector.
	 * @param array<int,float> $b Second vector.
	 */
	public static function cosine( array $a, array $b ): float {
		$a = array_values( $a );
		$b = array_values( $b );
		$n = min( count( $a ), count( $b ) );
		if ( 0 === $n ) {
			return 0.0;
		}
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$x    = (float) $a[ $i ];
			$y    = (float) $b[ $i ];
			$dot += $x * $y;
			$na  += $x * $x;
			$nb  += $y * $y;
		}
		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Normalized-title signature: significant tokens, sorted, hashed. Two
	 * headlines that differ only in case, order or punctuation share a
	 * fingerprint; genuinely different stories do not. Pure; testable.
	 *
	 * @param string $title Item title.
	 */
	public static function fingerprint( string $title ): string {
		$set = array_keys( self::tokenize( $title ) );
		if ( ! $set ) {
			return '';
		}
		sort( $set );
		return sha1( implode( ' ', $set ) );
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
