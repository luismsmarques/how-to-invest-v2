<?php
/**
 * The last resort: a brand illustration of our own, drawn on the spot.
 *
 * When every AI route fails the article used to fall back to the agency
 * photograph that came down the feed. Republishing someone else's photo is the
 * one outcome worth avoiding here, so the feed image is now read (it is what
 * the image brief is written from) and never published. What lands on the post
 * instead is this: a geometric card in the site's own palette, deterministic
 * from the headline so the same article always draws the same picture and two
 * articles never draw the same one.
 *
 * Drawn with GD rather than shipped as PNG files, because GD is present on
 * every WordPress host and files in the repository would need re-cutting every
 * time the palette moves. There is deliberately no text on the card: the plugin
 * ships no TTF, and GD's built-in bitmap fonts at 1200px wide look like a
 * mistake rather than a design.
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic brand card generator.
 */
class Fallback_Card {

	public const WIDTH  = 1200;
	public const HEIGHT = 675;

	/**
	 * Supersampling factor — draw big, shrink down, get smooth edges without
	 * fighting GD's antialiasing rules for filled shapes.
	 */
	private const SCALE = 2;

	/**
	 * Brand tokens (theme.json): cream ground, ink, coral, purple.
	 */
	private const CREAM  = array( 0xFF, 0xF6, 0xF1 );
	private const INK    = array( 0x2A, 0x24, 0x38 );
	private const CORAL  = array( 0xFF, 0x6B, 0x5E );
	private const PURPLE = array( 0x7C, 0x5C, 0xFC );

	/**
	 * The motifs, in the order the hash indexes them.
	 */
	public const MOTIFS = array( 'bars', 'waves', 'rings', 'lattice', 'chevrons' );

	/**
	 * Whether this host can draw the card.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagepng' )
			&& function_exists( 'imagecopyresampled' );
	}

	/**
	 * Decide what to draw. Pure — the same inputs always plan the same card.
	 *
	 * The category picks the motif so a section reads consistently; the
	 * headline varies everything else so two articles in one section still look
	 * different.
	 *
	 * @param string $title    Article headline.
	 * @param string $category Category name ('' when unknown).
	 * @return array{motif:string,accent:string,density:int,angle:int,offset:int,seed:int}
	 */
	public static function plan( string $title, string $category ): array {
		$title    = trim( $title );
		$category = trim( $category );

		$seed  = crc32( strtolower( $title . '|' . $category ) );
		$state = $seed;

		// The motif follows the headline so no two articles look alike; the
		// accent follows the category so a section still reads as a section.
		$accent_seed = crc32( '' !== $category ? strtolower( $category ) : strtolower( $title ) );

		return array(
			'motif'   => self::MOTIFS[ abs( crc32( strtolower( $title ) ) ) % count( self::MOTIFS ) ],
			'accent'  => 0 === $accent_seed % 2 ? 'coral' : 'purple',
			'density' => 4 + (int) floor( self::next( $state ) * 5 ),
			'angle'   => -22 + (int) floor( self::next( $state ) * 45 ),
			'offset'  => (int) floor( self::next( $state ) * 200 ),
			'seed'    => $seed,
		);
	}

	/**
	 * Draw the card.
	 *
	 * @param string $title    Article headline.
	 * @param string $category Category name.
	 * @return string|null PNG bytes, or null when GD is unavailable.
	 */
	public static function render( string $title, string $category = '' ): ?string {
		if ( ! self::available() ) {
			return null;
		}

		$plan = self::plan( $title, $category );
		$w    = self::WIDTH * self::SCALE;
		$h    = self::HEIGHT * self::SCALE;

		$canvas = imagecreatetruecolor( $w, $h );
		imagealphablending( $canvas, true );

		$cream  = self::colour( $canvas, self::CREAM );
		$accent = 'coral' === $plan['accent'] ? self::CORAL : self::PURPLE;
		$second = 'coral' === $plan['accent'] ? self::PURPLE : self::CORAL;

		imagefilledrectangle( $canvas, 0, 0, $w, $h, $cream );

		self::draw_wash( $canvas, $w, $h, $plan, $accent );
		self::draw_motif( $canvas, $w, $h, $plan, $accent, $second );
		self::mask_margin( $canvas, $w, $h, $cream );
		self::draw_frame( $canvas, $w, $h );

		// Down-sample to the final size: this is where the edges get smooth.
		$out = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		imagecopyresampled( $out, $canvas, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, $w, $h );
		imagedestroy( $canvas );

		ob_start();
		imagepng( $out, null, 6 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $out );

		return '' !== $bytes ? $bytes : null;
	}

	/* -------------------------------------------------------------------------
	 * Drawing
	 * ---------------------------------------------------------------------- */

	/**
	 * Dispatch to the planned motif.
	 *
	 * @param \GdImage           $im     Canvas.
	 * @param int                $w      Width.
	 * @param int                $h      Height.
	 * @param array<string,mixed> $plan   Plan from plan().
	 * @param array<int,int>     $accent Dominant accent RGB.
	 * @param array<int,int>     $second Secondary accent RGB.
	 */
	private static function draw_motif( $im, int $w, int $h, array $plan, array $accent, array $second ): void {
		$state = (int) $plan['seed'];
		$n     = (int) $plan['density'];

		switch ( $plan['motif'] ) {
			case 'bars':
				$slot = (int) ( $w / ( $n + 1 ) );
				$bar  = (int) ( $slot * 0.52 );
				$base = (int) ( $h * 0.84 );
				for ( $i = 0; $i < $n; $i++ ) {
					$x   = (int) ( $slot * ( $i + 0.75 ) );
					$top = $base - (int) ( $h * ( 0.16 + 0.58 * ( ( $i + 1 ) / $n ) * ( 0.7 + 0.5 * self::next( $state ) ) ) );
					$rgb = 0 === $i % 2 ? $accent : $second;
					imagefilledrectangle( $im, $x, $top, $x + $bar, $base, self::colour( $im, $rgb, 0 === $i % 3 ? 0 : 30 ) );
				}
				break;

			case 'waves':
				for ( $i = $n; $i >= 0; $i-- ) {
					$rgb = 0 === $i % 2 ? $accent : $second;
					$col = self::colour( $im, $rgb, 12 + $i * 14 );
					$rx  = (int) ( $w * ( 1.05 + 0.2 * $i ) );
					$ry  = (int) ( $h * ( 0.42 + 0.13 * $i ) );
					$cy  = (int) ( $h * 0.98 ) + ( $plan['offset'] / 3 ) - $i * (int) ( $h * 0.085 );
					imagefilledellipse( $im, (int) ( $w / 2 ), $cy + $ry, $rx, $ry * 2, $col );
				}
				break;

			case 'rings':
				$cx = (int) ( $w * 0.58 );
				$cy = (int) ( $h * 0.5 );
				for ( $i = $n; $i > 0; $i-- ) {
					$rgb = 0 === $i % 2 ? $accent : $second;
					$d   = (int) ( $h * ( 0.2 + 0.21 * $i ) );
					imagefilledellipse( $im, $cx, $cy, $d, $d, self::colour( $im, $rgb, 18 + ( $n - $i ) * 5 ) );
				}
				break;

			case 'lattice':
				$step = (int) ( $w / max( 6, $n + 4 ) );
				for ( $x = $step; $x < $w; $x += $step ) {
					for ( $y = $step; $y < $h; $y += $step ) {
						$rgb  = ( $x + $y ) % ( $step * 4 ) < $step * 2 ? $accent : $second;
						$size = (int) ( $step * ( 0.22 + 0.42 * self::next( $state ) ) );
						imagefilledellipse( $im, $x, $y, $size, $size, self::colour( $im, $rgb, 10 + (int) ( 55 * self::next( $state ) ) ) );
					}
				}
				break;

			default: // chevrons.
				$band = (int) ( $w / ( $n * 2 ) );
				$skew = (int) ( $h * tan( deg2rad( (float) $plan['angle'] ) ) );
				for ( $i = -3; $i < $n * 2 + 3; $i++ ) {
					$x   = $i * $band * 2 + ( $plan['offset'] * 2 ) - $skew;
					$rgb = 0 === ( $i + 8 ) % 2 ? $accent : $second;
					imagefilledpolygon(
						$im,
						array( $x, 0, $x + $band, 0, $x + $band + $skew, $h, $x + $skew, $h ),
						self::colour( $im, $rgb, 0 === ( $i + 8 ) % 4 ? 22 : 55 )
					);
				}
				break;
		}
	}

	/**
	 * A soft corner wash of the accent, so no motif ever leaves the card empty.
	 *
	 * @param \GdImage            $im     Canvas.
	 * @param int                 $w      Width.
	 * @param int                 $h      Height.
	 * @param array<string,mixed> $plan   Plan from plan().
	 * @param array<int,int>      $accent Dominant accent RGB.
	 */
	private static function draw_wash( $im, int $w, int $h, array $plan, array $accent ): void {
		$left = 0 === ( (int) $plan['seed'] ) % 2;
		$cx   = $left ? 0 : $w;
		imagefilledellipse( $im, $cx, (int) ( $h * 0.15 ), (int) ( $w * 1.1 ), (int) ( $h * 1.3 ), self::colour( $im, $accent, 108 ) );
	}

	/**
	 * Paint the margin back to cream, so a motif that runs to the edge is
	 * clipped to the frame instead of bleeding past it.
	 *
	 * @param \GdImage $im    Canvas.
	 * @param int      $w     Width.
	 * @param int      $h     Height.
	 * @param int      $cream Allocated cream colour.
	 */
	private static function mask_margin( $im, int $w, int $h, int $cream ): void {
		$i = self::inset( $h );
		imagealphablending( $im, false );
		imagefilledrectangle( $im, 0, 0, $w, $i, $cream );
		imagefilledrectangle( $im, 0, $h - $i, $w, $h, $cream );
		imagefilledrectangle( $im, 0, 0, $i, $h, $cream );
		imagefilledrectangle( $im, $w - $i, 0, $w, $h, $cream );
		imagealphablending( $im, true );
	}

	/**
	 * The margin the motif is clipped to.
	 *
	 * @param int $h Canvas height.
	 */
	private static function inset( int $h ): int {
		return (int) ( $h * 0.055 );
	}

	/**
	 * A thin ink frame so the card reads as deliberate, not as a broken image.
	 *
	 * @param \GdImage $im Canvas.
	 * @param int      $w  Width.
	 * @param int      $h  Height.
	 */
	private static function draw_frame( $im, int $w, int $h ): void {
		$inset = self::inset( $h );
		$col   = self::colour( $im, self::INK, 78 );
		imagesetthickness( $im, max( 2, (int) ( $h / 340 ) ) );
		imagerectangle( $im, $inset, $inset, $w - $inset, $h - $inset, $col );
		imagesetthickness( $im, 1 );
	}

	/**
	 * Allocate an RGB(A) colour.
	 *
	 * @param \GdImage       $im    Canvas.
	 * @param array<int,int> $rgb   RGB triplet.
	 * @param int            $alpha 0 (opaque) to 127 (transparent).
	 * @return int
	 */
	private static function colour( $im, array $rgb, int $alpha = 0 ) {
		$alpha = max( 0, min( 127, $alpha ) );
		if ( 0 === $alpha ) {
			return (int) imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
		}
		return (int) imagecolorallocatealpha( $im, $rgb[0], $rgb[1], $rgb[2], $alpha );
	}

	/**
	 * A small LCG: stable across PHP versions, unlike seeding mt_rand.
	 *
	 * @param int $state Running state, updated in place.
	 */
	private static function next( int &$state ): float {
		$state = ( $state * 1103515245 + 12345 ) & 0x7FFFFFFF;
		return $state / 0x7FFFFFFF;
	}
}
