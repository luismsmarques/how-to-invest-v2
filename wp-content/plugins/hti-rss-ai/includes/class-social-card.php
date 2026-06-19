<?php
/**
 * Renders the branded "Notícias · Quadrado" social card (1080×1080) with GD.
 *
 * The card chrome (gradient, logo, badge, typography, disclaimer) is drawn
 * deterministically so the brand stays consistent and the disclaimer is always
 * present; only the photo inside the slot comes from AI/feed. Mirrors the
 * design in "HowToInvest Social Templates" (Notícias · Quadrado).
 *
 * @package HTI_RSS_AI
 */

namespace HTI\RssAI;

defined( 'ABSPATH' ) || exit;

/**
 * GD renderer for the square news card.
 */
class Social_Card {

	private const W   = 1080;
	private const H   = 1080;
	private const PAD = 72;

	/**
	 * Whether GD with TrueType support is available.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' );
	}

	/**
	 * Render the card to PNG bytes.
	 *
	 * @param array{headline:string,kicker:string,badge:string,handle:string,domain:string,disclaimer:string,photo?:?string} $a Card data.
	 * @return string|\WP_Error PNG bytes, or error.
	 */
	public static function render( array $a ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'rssai_no_gd', __( 'GD with TrueType support is required to render the card.', 'hti-rss-ai' ) );
		}

		$img = imagecreatetruecolor( self::W, self::H );
		imagealphablending( $img, true );
		imagesavealpha( $img, true );

		self::gradient( $img, '#1C2150', '#0F1130' );

		$white = self::color( $img, '#ffffff' );

		// ---- Header: logo + wordmark (left), badge (right). -------------------
		$cx = self::PAD;
		$cy = self::PAD + 30; // Row centre (logo is 60px tall).
		self::draw_logo( $img, self::PAD, self::PAD, 60 );
		$wm_font = self::font( 'poppins-700' );
		self::text( $img, $wm_font, 30, self::PAD + 76, $cy + self::ascent( $wm_font, 30 ) / 2 - 2, $white, 'HowToInvest' );

		self::badge( $img, self::upper( (string) $a['badge'] ), self::W - self::PAD, $cy );

		// ---- Footer geometry (anchored to the bottom). ------------------------
		$content_bottom = self::H - self::PAD;
		$disc_font      = self::font( 'plus-jakarta-sans-400' );
		$disc_size      = 14.5;
		$disc_lh        = (int) round( $disc_size * 1.45 );
		$disc_lines     = self::wrap( $disc_font, $disc_size, (string) $a['disclaimer'], self::W - self::PAD * 2 );
		$disc_lines     = array_slice( $disc_lines, 0, 3 );
		$disc_h         = count( $disc_lines ) * $disc_lh;

		$row_h     = 26;
		$pad_top   = 22;
		$gap       = $disc_h > 0 ? 14 : 0;
		$footer_h  = $pad_top + $row_h + $gap + $disc_h;
		$border_y  = $content_bottom - $footer_h;

		// Top border.
		imagefilledrectangle( $img, self::PAD, $border_y, self::W - self::PAD, $border_y + 1, self::color( $img, '#ffffff', 0.12 ) );

		// Handle (left) + domain (right).
		$row_y    = $border_y + $pad_top;
		$hand_fnt = self::font( 'plus-jakarta-sans-600' );
		$hand_col = self::color( $img, '#9BA7E8' );
		$dom_col  = self::color( $img, '#6E76A8' );
		self::text( $img, $hand_fnt, 20, self::PAD, $row_y + self::ascent( $hand_fnt, 20 ), $hand_col, '@' . $a['handle'] );
		$dom_w = self::width( $hand_fnt, 18, (string) $a['domain'] );
		self::text( $img, $hand_fnt, 18, self::W - self::PAD - $dom_w, $row_y + self::ascent( $hand_fnt, 18 ), $dom_col, (string) $a['domain'] );

		// Disclaimer lines.
		$dy = $row_y + $row_h + $gap;
		foreach ( $disc_lines as $i => $line ) {
			self::text( $img, $disc_font, $disc_size, self::PAD, $dy + self::ascent( $disc_font, $disc_size ) + $i * $disc_lh, $dom_col, $line );
		}

		// ---- Image slot (above the footer). -----------------------------------
		$photo_h   = 296;
		$box_h     = $photo_h + 24 + 4; // padding 12*2 + border 2*2.
		$box_bottom = $border_y - 30;
		$box_top   = $box_bottom - $box_h;
		$box_x     = self::PAD;
		$box_w     = self::W - self::PAD * 2;

		// Border ring + inner translucent fill.
		self::filled_round( $img, $box_x, $box_top, $box_w, $box_h, 24, self::color( $img, '#ffffff', 0.13 ) );
		self::filled_round( $img, $box_x + 2, $box_top + 2, $box_w - 4, $box_h - 4, 22, self::color( $img, '#ffffff', 0.04 ) );

		$photo_x = $box_x + 14;
		$photo_y = $box_top + 14;
		$photo_w = $box_w - 28;
		self::place_photo( $img, $a['photo'] ?? null, $photo_x, $photo_y, $photo_w, $photo_h, 14 );

		// ---- Middle block: kicker + headline (vertically centred). ------------
		$avail_top    = self::PAD + 60 + 12;
		$avail_bottom = $box_top - 12;
		$avail_h      = $avail_bottom - $avail_top;
		$avail_w      = self::W - self::PAD * 2;

		$kicker_font = self::font( 'plus-jakarta-sans-700' );
		$kicker_size = 19;
		$head_font   = self::font( 'poppins-800' );

		[ $head_size, $head_lines ] = self::fit_headline( $head_font, (string) $a['headline'], $avail_w, $avail_h - 28 - 24 );
		$head_lh    = (int) round( $head_size * 1.14 );
		$head_h     = count( $head_lines ) * $head_lh;
		$group_h    = 28 + 24 + $head_h;
		$group_top  = $avail_top + (int) max( 0, ( $avail_h - $group_h ) / 2 );

		// Kicker: coral underline + uppercase label.
		$uy = $group_top + 12;
		imagefilledrectangle( $img, self::PAD, $uy, self::PAD + 44, $uy + 4, self::color( $img, '#FF6B5E' ) );
		self::text( $img, $kicker_font, $kicker_size, self::PAD + 60, $group_top + self::ascent( $kicker_font, $kicker_size ), $hand_col, self::upper( (string) $a['kicker'] ), 0.13 );

		// Headline.
		$hy = $group_top + 28 + 24;
		foreach ( $head_lines as $i => $line ) {
			self::text( $img, $head_font, $head_size, self::PAD, $hy + self::ascent( $head_font, $head_size ) + $i * $head_lh, $white, $line );
		}

		ob_start();
		imagepng( $img );
		$png = (string) ob_get_clean();
		imagedestroy( $img );

		return $png;
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Absolute path to a bundled TTF font.
	 *
	 * @param string $name File stem (without extension).
	 */
	private static function font( string $name ): string {
		return RSSAI_PATH . 'assets/fonts/' . $name . '.ttf';
	}

	/**
	 * Multibyte-aware uppercase (so "ção" → "ÇÃO").
	 *
	 * @param string $text Text.
	 */
	private static function upper( string $text ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}

	/**
	 * Parse "#RGB"/"#RRGGBB" to an [r,g,b] triple.
	 *
	 * @param string $hex Hex colour.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function parse_hex( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Allocate a colour. $opacity 1 = opaque, 0 = transparent.
	 *
	 * @param \GdImage $img     Image.
	 * @param string   $hex     Hex colour.
	 * @param float    $opacity 0..1.
	 * @return int
	 */
	private static function color( $img, string $hex, float $opacity = 1.0 ): int {
		[ $r, $g, $b ] = self::parse_hex( $hex );
		$alpha         = (int) round( ( 1.0 - max( 0.0, min( 1.0, $opacity ) ) ) * 127 );
		return (int) imagecolorallocatealpha( $img, $r, $g, $b, $alpha );
	}

	/**
	 * Vertical gradient fill (top → bottom).
	 *
	 * @param \GdImage $img  Image.
	 * @param string   $top  Top colour.
	 * @param string   $bot  Bottom colour.
	 */
	private static function gradient( $img, string $top, string $bot ): void {
		[ $r1, $g1, $b1 ] = self::parse_hex( $top );
		[ $r2, $g2, $b2 ] = self::parse_hex( $bot );
		for ( $y = 0; $y < self::H; $y++ ) {
			$t = $y / ( self::H - 1 );
			$c = imagecolorallocate(
				$img,
				(int) round( $r1 + ( $r2 - $r1 ) * $t ),
				(int) round( $g1 + ( $g2 - $g1 ) * $t ),
				(int) round( $b1 + ( $b2 - $b1 ) * $t )
			);
			imageline( $img, 0, $y, self::W, $y, $c );
		}
	}

	/**
	 * Font ascent (pixels above baseline) at a size.
	 *
	 * @param string $font Font path.
	 * @param float  $size Point size.
	 */
	private static function ascent( string $font, float $size ): int {
		$bbox = imagettfbbox( $size, 0, $font, 'ÁHbdg' );
		return is_array( $bbox ) ? (int) abs( $bbox[7] ) : (int) round( $size * 0.8 );
	}

	/**
	 * Measured width of a string (with optional letter-spacing in em).
	 *
	 * @param string $font Font path.
	 * @param float  $size Point size.
	 * @param string $text Text.
	 * @param float  $ls   Letter-spacing in em.
	 */
	private static function width( string $font, float $size, string $text, float $ls = 0.0 ): int {
		$bbox = imagettfbbox( $size, 0, $font, $text );
		$w    = is_array( $bbox ) ? (int) ( $bbox[2] - $bbox[0] ) : 0;
		if ( $ls > 0.0 ) {
			$n   = max( 0, mb_strlen( $text ) - 1 );
			$w  += (int) round( $ls * $size * $n );
		}
		return $w;
	}

	/**
	 * Draw text at a baseline, optionally letter-spaced.
	 *
	 * @param \GdImage $img   Image.
	 * @param string   $font  Font path.
	 * @param float    $size  Point size.
	 * @param int      $x     Left x.
	 * @param int      $y     Baseline y.
	 * @param int      $color Colour.
	 * @param string   $text  Text.
	 * @param float    $ls    Letter-spacing in em.
	 */
	private static function text( $img, string $font, float $size, int $x, int $y, int $color, string $text, float $ls = 0.0 ): void {
		if ( $ls <= 0.0 ) {
			imagettftext( $img, $size, 0, $x, $y, $color, $font, $text );
			return;
		}
		$gap   = $ls * $size;
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $chars as $ch ) {
			imagettftext( $img, $size, 0, (int) round( $x ), $y, $color, $font, $ch );
			$bbox = imagettfbbox( $size, 0, $font, $ch );
			$x   += ( is_array( $bbox ) ? ( $bbox[2] - $bbox[0] ) : $size ) + $gap;
		}
	}

	/**
	 * Greedy word-wrap to a pixel width.
	 *
	 * @param string $font  Font path.
	 * @param float  $size  Point size.
	 * @param string $text  Text.
	 * @param int    $max_w Max width.
	 * @return array<int,string>
	 */
	private static function wrap( string $font, float $size, string $text, int $max_w ): array {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( '' === $text ) {
			return array();
		}
		$words = explode( ' ', $text );
		$lines = array();
		$cur   = '';
		foreach ( $words as $word ) {
			$try = '' === $cur ? $word : $cur . ' ' . $word;
			if ( self::width( $font, $size, $try ) <= $max_w || '' === $cur ) {
				$cur = $try;
			} else {
				$lines[] = $cur;
				$cur     = $word;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines;
	}

	/**
	 * Pick the largest headline size that fits the available box.
	 *
	 * @param string $font   Font path.
	 * @param string $text   Headline.
	 * @param int    $max_w  Width.
	 * @param int    $max_h  Height.
	 * @return array{0:int,1:array<int,string>}
	 */
	private static function fit_headline( string $font, string $text, int $max_w, int $max_h ): array {
		foreach ( array( 64, 58, 52, 46, 40, 36 ) as $size ) {
			$lines = self::wrap( $font, $size, $text, $max_w );
			$h     = count( $lines ) * (int) round( $size * 1.14 );
			$fits  = $h <= $max_h;
			foreach ( $lines as $line ) {
				if ( self::width( $font, $size, $line ) > $max_w ) {
					$fits = false;
					break;
				}
			}
			if ( $fits ) {
				return array( $size, $lines );
			}
		}
		return array( 36, self::wrap( $font, 36, $text, $max_w ) );
	}

	/**
	 * Coral pill badge, right-aligned to $right_x and centred on $cy.
	 *
	 * @param \GdImage $img     Image.
	 * @param string   $label   Uppercase label.
	 * @param int      $right_x Right edge.
	 * @param int      $cy      Vertical centre.
	 */
	private static function badge( $img, string $label, int $right_x, int $cy ): void {
		$font = self::font( 'plus-jakarta-sans-700' );
		$size = 18;
		$ls   = 0.16;
		$tw   = self::width( $font, $size, $label, $ls );
		$pad_x = 24;
		$pad_y = 11;
		$th   = self::ascent( $font, $size );
		$bh   = $th + $pad_y * 2;
		$bw   = $tw + $pad_x * 2;
		$x1   = $right_x - $bw;
		$y1   = $cy - (int) round( $bh / 2 );
		self::filled_round( $img, $x1, $y1, $bw, $bh, (int) round( $bh / 2 ), self::color( $img, '#FF6B5E' ) );
		self::text( $img, $font, $size, $x1 + $pad_x, $y1 + $pad_y + $th, self::color( $img, '#ffffff' ), $label, $ls );
	}

	/**
	 * Filled rounded rectangle.
	 *
	 * @param \GdImage $img   Image.
	 * @param int      $x     Left.
	 * @param int      $y     Top.
	 * @param int      $w     Width.
	 * @param int      $h     Height.
	 * @param int      $r     Corner radius.
	 * @param int      $color Colour.
	 */
	private static function filled_round( $img, int $x, int $y, int $w, int $h, int $r, int $color ): void {
		$r = (int) max( 0, min( $r, (int) floor( min( $w, $h ) / 2 ) ) );
		imagefilledrectangle( $img, $x + $r, $y, $x + $w - $r, $y + $h, $color );
		imagefilledrectangle( $img, $x, $y + $r, $x + $w, $y + $h - $r, $color );
		$d = $r * 2;
		imagefilledarc( $img, $x + $r, $y + $r, $d, $d, 180, 270, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x + $w - $r, $y + $r, $d, $d, 270, 360, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x + $r, $y + $h - $r, $d, $d, 90, 180, $color, IMG_ARC_PIE );
		imagefilledarc( $img, $x + $w - $r, $y + $h - $r, $d, $d, 0, 90, $color, IMG_ARC_PIE );
	}

	/**
	 * Place a cover-cropped, rounded photo, or a subtle placeholder.
	 *
	 * @param \GdImage    $img   Card image.
	 * @param string|null $bytes Photo bytes.
	 * @param int         $x     Left.
	 * @param int         $y     Top.
	 * @param int         $w     Width.
	 * @param int         $h     Height.
	 * @param int         $r     Corner radius.
	 */
	private static function place_photo( $img, ?string $bytes, int $x, int $y, int $w, int $h, int $r ): void {
		$src = ( null !== $bytes && '' !== $bytes ) ? @imagecreatefromstring( $bytes ) : false;
		if ( false === $src ) {
			// Branded placeholder: slightly lighter rounded panel.
			self::filled_round( $img, $x, $y, $w, $h, $r, self::color( $img, '#9BA7E8', 0.10 ) );
			return;
		}

		$sw = imagesx( $src );
		$sh = imagesy( $src );
		$scale = max( $w / $sw, $h / $sh );
		$nw    = (int) ceil( $sw * $scale );
		$nh    = (int) ceil( $sh * $scale );

		$slot = imagecreatetruecolor( $w, $h );
		imagealphablending( $slot, true );
		imagesavealpha( $slot, true );
		$ox = (int) round( ( $w - $nw ) / 2 );
		$oy = (int) round( ( $h - $nh ) / 2 );
		imagecopyresampled( $slot, $src, $ox, $oy, 0, 0, $nw, $nh, $sw, $sh );
		imagedestroy( $src );

		self::punch_round_corners( $slot, $w, $h, $r );
		imagecopy( $img, $slot, $x, $y, 0, 0, $w, $h );
		imagedestroy( $slot );
	}

	/**
	 * Make the four corners transparent to fake a rounded clip.
	 *
	 * @param \GdImage $im Image.
	 * @param int      $w  Width.
	 * @param int      $h  Height.
	 * @param int      $r  Radius.
	 */
	private static function punch_round_corners( $im, int $w, int $h, int $r ): void {
		$trans = imagecolorallocatealpha( $im, 0, 0, 0, 127 );
		$corners = array(
			array( $r, $r ),
			array( $w - $r - 1, $r ),
			array( $r, $h - $r - 1 ),
			array( $w - $r - 1, $h - $r - 1 ),
		);
		foreach ( $corners as $idx => $c ) {
			[ $ccx, $ccy ] = $c;
			for ( $dx = 0; $dx <= $r; $dx++ ) {
				for ( $dy = 0; $dy <= $r; $dy++ ) {
					if ( ( $dx * $dx + $dy * $dy ) <= $r * $r ) {
						continue;
					}
					$px = 0 === $idx || 2 === $idx ? $ccx - $dx : $ccx + $dx;
					$py = 0 === $idx || 1 === $idx ? $ccy - $dy : $ccy + $dy;
					imagesetpixel( $im, $px, $py, $trans );
				}
			}
		}
	}

	/**
	 * Draw the HowToInvest shield emblem (supersampled for smooth edges).
	 *
	 * @param \GdImage $img  Card image.
	 * @param int      $x    Left.
	 * @param int      $y    Top.
	 * @param int      $size Box size.
	 */
	private static function draw_logo( $img, int $x, int $y, int $size ): void {
		$ss = 4;
		$s  = $size * $ss;
		$e  = imagecreatetruecolor( $s, $s );
		imagealphablending( $e, false );
		imagefill( $e, 0, 0, imagecolorallocatealpha( $e, 0, 0, 0, 127 ) );
		imagealphablending( $e, true );

		$k = $s / 64.0; // SVG viewBox is 0..64.
		$navy   = imagecolorallocate( $e, 0x1E, 0x21, 0x47 );
		$white  = imagecolorallocate( $e, 0xFF, 0xFF, 0xFF );
		$purple = imagecolorallocate( $e, 0x7C, 0x5C, 0xFC );

		imagefilledellipse( $e, (int) ( 32 * $k ), (int) ( 32 * $k ), (int) ( 64 * $k ), (int) ( 64 * $k ), $navy );

		// Shield (approximation of the SVG path).
		$shield = array();
		foreach ( array(
			array( 32, 12 ),
			array( 50, 17.5 ),
			array( 50, 32 ),
			array( 44, 44 ),
			array( 32, 52 ),
			array( 20, 44 ),
			array( 14, 32 ),
			array( 14, 17.5 ),
		) as $pt ) {
			$shield[] = (int) round( $pt[0] * $k );
			$shield[] = (int) round( $pt[1] * $k );
		}
		imagefilledpolygon( $e, $shield, $white );

		// Candlestick wicks + rising bars (purple), from the SVG rects.
		$rects = array(
			array( 20.5, 22, 1, 11 ), array( 19.2, 25.5, 3.6, 5 ),
			array( 25.6, 20, 1, 12.5 ), array( 24.3, 23.5, 3.6, 5.5 ),
			array( 30.2, 21.5, 1, 11 ), array( 28.9, 24.5, 3.6, 4.5 ),
			array( 35.6, 18, 1, 12.5 ), array( 34.3, 21, 3.6, 5.8 ),
			array( 20.4, 40, 3.6, 6 ), array( 25.9, 37.5, 3.6, 8.5 ),
			array( 31.4, 35, 3.6, 11 ), array( 36.9, 32.5, 3.6, 13.5 ),
		);
		foreach ( $rects as $r ) {
			imagefilledrectangle(
				$e,
				(int) round( $r[0] * $k ),
				(int) round( $r[1] * $k ),
				(int) round( ( $r[0] + $r[2] ) * $k ),
				(int) round( ( $r[1] + $r[3] ) * $k ),
				$purple
			);
		}

		imagecopyresampled( $img, $e, $x, $y, 0, 0, $size, $size, $s, $s );
		imagedestroy( $e );
	}
}
